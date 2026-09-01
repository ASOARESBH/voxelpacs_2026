# VOXEL Report Delivery Hub

## Finalidade

O Report Delivery Hub registra a devolutiva de um laudo no momento em que ele é **liberado**, sem realizar conexões externas dentro do fluxo do médico. A transação clínica cria um evento idempotente na outbox; um worker separado obtém jobs, registra cada tentativa e executa o conector configurado para o destino do cliente.

```text
Laudo liberado
    │ transação única
    ▼
Outbox + jobs por destino
    │
    ▼
Worker local supervisionado
    │
    ├─ DICOM Encapsulated PDF
    ├─ DICOM Structured Report
    ├─ HL7 ORU^R01
    ├─ HTTPS Webhook/API
    └─ SFTP/FTPS
```

## Proteções aplicadas

| Controle | Implementação |
|---|---|
| Gatilho clínico | `Assinar e Fechar` ou a ação posterior **Liberar** após assinatura. Assinatura simples não cria evento de devolução. |
| Consistência | A outbox é criada dentro da mesma transação do laudo, assinatura, versão e situação do estudo. |
| Idempotência | A chave SHA-256 usa tenant, laudo, versão, tipo do evento e hash clínico; jobs também têm chave por destino. |
| Isolamento | Registros são associados a tenant e, opcionalmente, a estabelecimento/unidade. |
| Segurança de segredos | JSON sensível do destino é cifrado com AES-256-GCM derivado de `APP_SECRET`; não é devolvido na tela administrativa. |
| Proteção do worker | Serviço local sem rota pública de despacho, executado com usuário de serviço, diretório temporário privado e identificador auditável. A API de worker preexistente permanece protegida, mas não é necessária para o serviço local. |
| Elegibilidade | Job novo só é consumido quando `worker_eligible_at` é gravado pelo fluxo autorizado. Jobs legados ficam inelegíveis até ação explícita. |
| Segurança operacional | Destinos novos começam desativados e em `homologacao`; produção exige confirmação explícita no painel antes de ser habilitada. |
| Falhas | Tentativas, backoff exponencial, erro técnico e DLQ são persistidos por job. |

## Tabelas

| Tabela | Função |
|---|---|
| `pacs_report_delivery_destinations` | Perfil de entrega por tenant/unidade e canal. |
| `pacs_report_delivery_destination_issuers` | Vínculos normalizados de Issuer por destino de entrega. |
| `pacs_report_delivery_outbox` | Evento imutável de liberação/correção de laudo. |
| `pacs_report_delivery_jobs` | Uma unidade de trabalho para cada destino selecionado, com marca explícita de elegibilidade do worker. |
| `pacs_report_delivery_attempts` | Histórico técnico de cada tentativa. |
| `pacs_report_delivery_artifacts` | Hash e metadados de PDF, SR, HL7 ou manifesto gerados, incluindo a referência `released_pdf` do snapshot. |
| `report_pdf_snapshots` | Referência tenant-scoped, hash e tamanho do PDF imutável criado no instante da liberação. |

## Configuração de destino

A tela administrativa fica em:

```text
/platform/negocios/{tenant_id}/report-delivery
```

A tela usa **campos guiados**, sem exigir JSON do usuário. Ao selecionar o canal, o formulário exibe somente as informações pertinentes e gera a configuração interna automaticamente. Senhas, tokens e chaves devem ser inseridos nos respectivos campos de credencial; esses valores são cifrados e não são mostrados novamente.

| Canal | Campos apresentados ao administrador |
|---|---|
| DICOM Encapsulated PDF / DICOM SR | Endereço do PACS, porta DICOM, AE Title do cliente, AE Title do VOXEL e TLS opcional. |
| HL7 ORU^R01 | Servidor, porta MLLP, aplicação e instituição remetente/destinatária. |
| HTTPS Webhook/API | URL HTTPS, tipo de autenticação e token Bearer opcional. |
| SFTP/FTPS | Protocolo seguro, servidor, porta, pasta remota, usuário e senha/chave. |

A validação ocorre tanto no navegador quanto no servidor. Destinos já existentes continuam compatíveis: ao clicar em **Editar**, as configurações internas conhecidas são convertidas novamente para os campos visuais.

## Serviço local do worker

O processo local é instalado como `voxelpacs-report-delivery-worker.service`, supervisionado pelo `systemd`. Ele é a alternativa recomendada ao cron externo porque mantém o loop, o lease exclusivo, a recuperação automática e as credenciais no próprio servidor, sem publicar token de execução para terceiros.

O worker atual implementa **DICOM Encapsulated PDF**. Ele gera o PDF a partir da versão imutável, encapsula o documento em objeto DICOM mantendo o Study UID do evento e executa C-STORE. O processo grava apenas estados técnicos sanitizados em tentativas, usa armazenamento privado para artefatos e falha de modo fechado se parâmetros obrigatórios, identificação do estudo ou perfil TLS solicitado não estiverem completos.

### Identidade DICOM no retorno de laudo

O retorno deve preservar **Patient ID** `(0010,0020)` e **Issuer of Patient ID** `(0010,0021)` como atributos distintos. O Patient ID não deve concatenar o issuer com separadores de componentes. Alguns receptores validam a combinação contra o estudo existente e recusam o C-STORE quando qualquer atributo diverge.

O `pdf2dcm --study-from` utilizado no encapsulamento pode não reter `(0010,0021)` no objeto final. Portanto, quando o perfil do destino definir `issuer_of_patient_id`, o worker o reaplica explicitamente no Encapsulated PDF com `--key 0010,0021=<issuer>` antes do C-STORE. A configuração de issuer de saída é uma regra do **destino receptor** e pode divergir do issuer que foi usado para selecionar a origem/routing da outbox. Essa regra precisa ser homologada por destino e validada com PDF sintético antes de qualquer transmissão clínica.

### Snapshot PDF imutável na liberação

No instante de `Assinar e Fechar` ou de **Liberar**, o serviço monta o mesmo contexto de impressão do viewer, incluindo o layout de unidade, o template personalizado já congelado, logo, assinatura visual, conteúdo assinado e dados institucionais autorizados. Em seguida gera um PDF binário e o armazena em diretório privado com permissões mínimas. A tabela `report_pdf_snapshots` registra `tenant_id`, laudo, versão, unidade, caminho, SHA-256 e tamanho; `pacs_report_delivery_artifacts` recebe a referência `released_pdf` da outbox.

O binário não é armazenado como BLOB no PostgreSQL. O banco mantém a referência transacional e a integridade; o arquivo permanece no storage privado. Essa separação reduz impacto no banco e nos backups, permite validação por hash e impede que uma nova renderização posterior altere um documento já liberado.

O worker lê exclusivamente o snapshot associado simultaneamente à mesma outbox, tenant, laudo e versão. Ele confirma existência, tamanho e SHA-256 antes de encapsular o PDF. Se o snapshot estiver ausente, ilegível ou divergente, a entrega falha em modo fechado; **nunca** reconstrói um PDF simplificado a partir do estado atual do laudo.

> Uma configuração que solicita TLS não é rebaixada silenciosamente para TCP. Enquanto não houver perfil de certificados configurado, o job falhará com estado técnico sanitizado e seguirá a política de retentativa/DLQ.

## Variáveis de ambiente

No HostGator, manter inicialmente:

```env
VOXEL_REPORT_DELIVERY_HUB_ENABLED=false
VOXEL_REPORT_DELIVERY_WORKER_ID=local-dicom-worker
VOXEL_REPORT_DELIVERY_WORKER_IDLE_SECONDS=3
```

As variáveis efetivas permanecem no ambiente privado do servidor e nenhum arquivo de ambiente deve ser versionado.

## Migração

A migration é `database/migrations/2026-08-14_voxel_report_delivery_hub.sql`.

> Antes de aplicar em produção, faça backup, execute em horário de baixo movimento e rode as consultas de validação incluídas no próprio arquivo. O recurso permanece desligado enquanto `VOXEL_REPORT_DELIVERY_HUB_ENABLED=false`.

## Homologação segura

1. Aplicar a migration e publicar o backend com a feature flag desligada.
2. Instalar o serviço local do worker e validar `--check`, sem criar job ou abrir associação externa.
3. Cadastrar um destino em homologação e mantê-lo inicialmente desativado.
4. Validar bearer token, leasing, idempotência, tentativa e reprocessamento com dados não clínicos quando possível.
5. Habilitar o Hub somente em ambiente de homologação e criar um laudo de teste.
6. Homologar o conector do cliente e o comportamento de correção/adendo.
7. Obter aceite técnico e clínico antes de qualquer ativação de produção.

## Estado dos conectores

| Canal | Estado nesta entrega |
|---|---|
| HTTPS Webhook/API | Contrato e configuração prontos; consumidor específico ainda não foi instalado pelo worker local. |
| DICOM Encapsulated PDF | Worker local implementado com encapsulamento e C-STORE; requer configuração e homologação técnica por destino. |
| DICOM SR | Contrato e rastreabilidade prontos; requer mapeamento DICOM SR/TID 2000 e homologação. |
| HL7 ORU^R01 | Contrato e rastreabilidade prontos; requer profile e interface do RIS/HIS receptor. |
| SFTP/FTPS | Contrato e rastreabilidade prontos; requer geração de PDF, manifesto e credencial/chave por cliente. |

Nenhum destino clínico é habilitado pela implantação: a habilitação e a confirmação de produção ocorrem exclusivamente pelo painel de superadmin.

## Roteamento por Issuer e PACS de origem

Um mesmo negócio pode receber estudos de vários PACS. Cada destino pode vincular um ou mais **Issuers** e, opcionalmente, InstitutionNames de fallback. O Issuer é normalizado antes da comparação e vem de `bi_pacs_estudos.issuer_of_patient_id`; o InstitutionName é o valor DICOM `(0008,0080)` armazenado em `bi_pacs_estudos.institution_name`.

Ao liberar ou reenviar um laudo, o Hub aplica **Issuer como chave prioritária**. InstitutionName só é consultado quando o estudo não tem Issuer utilizável. O snapshot da outbox registra o valor recebido, o Issuer normalizado, o InstitutionName canônico e a base da decisão de roteamento.

| Situação | Resultado do Hub |
|---|---|
| Estudo com Issuer vinculado ao destino | Cria job apenas para os destinos ativos vinculados àquele Issuer. |
| Estudo com Issuer não vinculado | Não usa InstitutionName; não cria job externo. |
| Estudo sem Issuer e InstitutionName de fallback vinculado | Cria job apenas para os destinos ativos associados ao InstitutionName canônico. |
| Estudo sem Issuer e sem fallback ativo | Não cria job externo; registra outbox como `no_destination` e log administrativo. |
| Destino legado sem origem vinculada | Não recebe novos jobs até que a origem seja selecionada no painel. |

No painel **Devolutiva de Laudos**, selecione os Issuers dos servidores PACS antes de salvar o destino. InstitutionNames podem ser selecionados somente como fallback para estudos sem Issuer. A lista de destinos mostra ambos os vínculos e a prioridade aplicada.

> A seleção de origem é independente do canal de entrega. Um mesmo Issuer pode ter destinos distintos para DICOM, HTTPS, HL7 ou SFTP, desde que cada integração seja homologada e habilitada de forma explícita.

## Laudos liberados, estados e reenvio controlado

O painel de cada negócio apresenta até 100 laudos com situação `liberado`, mesmo quando ainda não existe job de integração. A lista é sempre filtrada por tenant e permite filtrar por nome do paciente, modalidade ou Issuer. Não exibe conteúdo do laudo, token público, credenciais nem configuração sensível do destino.

| Estado exibido | Critério | Ação disponível |
|---|---|---|
| **Entregue** | Todos os jobs daquele laudo foram concluídos pelo worker com `delivered`. | Nenhuma. |
| **Na fila** | Há job `queued`, `retrying` ou `processing`. | Aguardar o worker; retry manual já aceito não pode ser duplicado. |
| **Falha** | Existem somente jobs terminais `failed` ou `dead_letter`. | **Reenviar após falha** cria uma exceção manual auditável somente na rota de produção tenant/destino vinculada ou na homologação compatível. |
| **Destino desativado** | O Issuer prioritário, ou o InstitutionName de fallback quando não há Issuer, está vinculado a um destino, mas ele não está elegível (`enabled=0` ou sem disparo na liberação). | Nenhuma; a configuração precisa ser ativada em processo homologado. |
| **Pronto para reenviar** | Há destino de homologação configurado e elegível, mas ainda não existe job do laudo. | Reenviar reavalia a mesma origem e cria somente jobs idempotentes de homologação. |
| **Automático na liberação** | Há somente destino de produção elegível e ainda não existe job de laudo histórico. | Nenhuma; novos laudos liberados criam job de produção automaticamente. |
| **Sem destino** | Não existe vínculo configurado para a origem aplicável do laudo. | Reenviar reavalia Issuer e InstitutionName de fallback contra destinos ativos. |

O botão **Reenviar após falha** exige confirmação no navegador, CSRF, sessão de superadmin e escopo do tenant da tela. Para produção, ele só aceita **um** job terminal na rota DICOM vinculada ao tenant, ao servidor PACS de origem, à policy `tenant_destination` da bridge e a um snapshot PDF íntegro. O reenvio recebe marcação manual, administrador solicitante e limite de três solicitações por job; nunca altera um item já entregue, destino desativado, rota sem origem ou rota sem bridge. O endpoint não abre conexão DICOM, HTTPS, HL7 ou SFTP: a transmissão ocorre exclusivamente no worker.

Se não houver destino ativo e compatível, o reenvio retorna sem criar job. Quando um artefato é transmitido e confirmado pelo worker, o job passa para `delivered` e a lista reflete **Entregue**. Um reenvio clínico real deve ser confirmado separadamente antes da ação administrativa.

## Janela automática e fallback após falha

Um job criado automaticamente por uma liberação em produção recebe a **data clínica da liberação**. O worker processa esse job somente enquanto essa data corresponder ao dia clínico corrente. Ao iniciar um novo dia, pendências automáticas anteriores são finalizadas como falha sanitizada e deixam de ser consumidas automaticamente. Essa regra impede que indisponibilidades antigas resultem em transmissão retardada sem revisão operacional.

Após `failed` ou `dead_letter`, o superadmin pode acionar **Reenviar após falha**. A ação exige CSRF e confirmação no navegador, preserva tenant e unidade, registra a solicitação manual e torna somente aquele job novamente elegível fora da janela automática. O fallback não cria job novo para laudo histórico, não reenvia itens entregues, não contorna a prioridade Issuer–InstitutionName e não altera outras rotas.

| Situação | Automação | Ação humana |
|---|---|---|
| Laudo liberado hoje em produção | Job elegível e processado pelo worker. | Não há botão prévio de reenvio. |
| Job automático pendente após a data clínica | Finalizado como falha sanitizada; não transmite no dia seguinte. | **Reenviar após falha**, mediante confirmação. |
| Falha terminal de homologação | Não há nova criação automática fora da liberação. | **Reenviar após falha**, mediante confirmação. |
| Entrega concluída | Não é reenfileirada. | Nenhuma. |

## Operação automática controlada por tenant

A automação de produção deve ser habilitada somente depois de um piloto unitário aceito pelo receptor. Cada ativação é restrita a **um** `tenant_id`, **um** `servidor_pacs_id` vinculado ao negócio, Issuers normalizados do destino e **um** `destination_id`. A ponte não aceita um host, AE Title, destino ou tenant fornecido pelo worker: o receptor é definido pela policy root-only do gateway, e o pedido mTLS/HMAC deve transportar o mesmo job, tenant e destino autorizados.

| Controle | Regra operacional |
|---|---|
| Feature flag da API | `VOXEL_REPORT_DELIVERY_HUB_ENABLED=true` somente enquanto a automação estiver aprovada. |
| Worker local | Consome jobs automáticos somente na data clínica corrente e exceções manuais explicitamente marcadas. Em ambos os casos exige destino de produção vinculado, origem idêntica ao estudo e policy `tenant_destination` coerente. |
| Limite de entrega | O destino de produção mantém `max_attempts=1` para o fluxo automático. O retry manual é solicitado pelo superadmin, limitado a três solicitações por job e auditado. A ponte persiste trava por **job e número de tentativa** antes da associação externa. |
| Ponte do gateway | Opera apenas em `tenant_destination`, com `BRIDGE_AUTOMATION_ENABLED=true`, mTLS/HMAC e listener privado. A bridge executa C-ECHO imediatamente antes de C-STORE. |
| Listener | O serviço deve escutar exclusivamente no endereço privado da API; não se publica porta DICOM externa, endpoint HTTP público ou fallback pela Internet. |
| WireGuard | A rota ao receptor precisa sair pelo peer exclusivo do tenant. Handshake ausente, rota inválida ou container DICOM inativo impedem ativação. |
| B/C e demais destinos | Permanecem sem policy, listener, peer, rota, container ou automação ativa até aprovação independente. |

### Kill switch e resposta a incidente

Para interromper novas transmissões, execute primeiro o kill switch da **API**, que desliga o worker e a feature flag; em seguida execute o kill switch do **gateway**, que para e desabilita a ponte e registra `BRIDGE_AUTOMATION_ENABLED=false`. A rota e o histórico de auditoria permanecem preservados, mas não há consumo de novos jobs.

```text
API:     /usr/local/sbin/voxelpacs-disable-report-delivery-api
Gateway: /usr/local/sbin/voxelpacs-disable-report-delivery-gateway
```

A reativação exige nova validação de tenant, servidor PACS, Issuer, policy, mTLS/HMAC, listener privado, container DICOM, handshake WireGuard e ausência de jobs históricos elegíveis. Não se reativa automaticamente uma fila anterior; cada falha terminal ou pendência fora da janela clínica deve ser revisada e autorizada no fluxo administrativo controlado.

### Observabilidade sanitizada

A operação deve acompanhar somente estados técnicos: unidade do worker, unidade da ponte, estado do handshake, listener privado, resultado C-ECHO/C-STORE, contagem de jobs por estado e tentativas. O monitor local executa periodicamente como leitura e registra somente contagens de jobs automáticos atuais, retries manuais, leases obsoletos e rotas não conformes; ele nunca faz claim, requeue, C-ECHO, C-STORE ou inicialização do worker. Logs não devem registrar PDF, conteúdo do laudo, Patient ID, nome, UIDs completos, endereço do receptor, AE Titles, chaves ou segredos. O identificador interno do job é o correlacionador operacional permitido.

> A ativação de produção não é uma autorização para reenviar pendências. Somente uma nova liberação compatível cria seu próprio evento idempotente, snapshot PDF imutável e job tenant-scoped elegível.
