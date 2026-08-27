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
Worker persistente autenticado
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
| Proteção do worker | Endpoints sem sessão usam bearer token privado, comparado com `hash_equals`, e identificador de worker auditável. |
| Segurança operacional | Destinos novos começam desativados e em `homologacao`; a interface bloqueia habilitação direta de produção. |
| Falhas | Tentativas, backoff exponencial, erro técnico e DLQ são persistidos por job. |

## Tabelas

| Tabela | Função |
|---|---|
| `pacs_report_delivery_destinations` | Perfil de entrega por tenant/unidade e canal. |
| `pacs_report_delivery_destination_issuers` | Vínculos normalizados de Issuer por destino de entrega. |
| `pacs_report_delivery_outbox` | Evento imutável de liberação/correção de laudo. |
| `pacs_report_delivery_jobs` | Uma unidade de trabalho para cada destino selecionado. |
| `pacs_report_delivery_attempts` | Histórico técnico de cada tentativa. |
| `pacs_report_delivery_artifacts` | Hash e metadados de PDF, SR, HL7 ou manifesto gerados. |

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

## Variáveis de ambiente

No HostGator, manter inicialmente:

```env
VOXEL_REPORT_DELIVERY_HUB_ENABLED=false
VOXEL_REPORT_DELIVERY_WORKER_TOKEN=<64_caracteres_hex_gerados_com_openssl_rand_hex_32>
```

No VPS, o mesmo token é configurado no `.env` privado do worker. Nenhum dos dois arquivos deve ser versionado.

## Migração

A migration é `database/migrations/2026-08-14_voxel_report_delivery_hub.sql`.

> Antes de aplicar em produção, faça backup, execute em horário de baixo movimento e rode as consultas de validação incluídas no próprio arquivo. O recurso permanece desligado enquanto `VOXEL_REPORT_DELIVERY_HUB_ENABLED=false`.

## Homologação segura

1. Aplicar a migration e publicar o backend com a feature flag desligada.
2. Instalar o worker no VPS com `DELIVERY_HUB_DRY_RUN=true`.
3. Cadastrar um destino em homologação e mantê-lo inicialmente desativado.
4. Validar bearer token, leasing, idempotência, tentativa e reprocessamento com dados não clínicos quando possível.
5. Habilitar o Hub somente em ambiente de homologação e criar um laudo de teste.
6. Homologar o conector do cliente e o comportamento de correção/adendo.
7. Obter aceite técnico e clínico antes de qualquer ativação de produção.

## Estado dos conectores

| Canal | Estado nesta entrega |
|---|---|
| HTTPS Webhook/API | Worker implementado, protegido por `DRY_RUN` e restrito a HTTPS. |
| DICOM Encapsulated PDF | Contrato, outbox e configuração prontos; requer gerador de artefato e homologação C-STORE/Storage Commitment. |
| DICOM SR | Contrato e rastreabilidade prontos; requer mapeamento DICOM SR/TID 2000 e homologação. |
| HL7 ORU^R01 | Contrato e rastreabilidade prontos; requer profile e interface do RIS/HIS receptor. |
| SFTP/FTPS | Contrato e rastreabilidade prontos; requer geração de PDF, manifesto e credencial/chave por cliente. |

Nenhum destino clínico é ativado automaticamente nesta entrega.

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
| **Na fila** | Há job `queued`, `retrying` ou `processing`. | Reenviar, sujeito à confirmação e à regra de destino. |
| **Falha** | Existem somente jobs terminais `failed` ou `dead_letter`. | Reenviar reativa os jobs terminais. |
| **Destino desativado** | O Issuer prioritário, ou o InstitutionName de fallback quando não há Issuer, está vinculado a um destino, mas ele não está elegível (`enabled=0` ou sem disparo na liberação). | Nenhuma; a configuração precisa ser ativada em processo homologado. |
| **Pronto para reenviar** | Há destino configurado e elegível, mas ainda não existe job do laudo. | Reenviar reavalia a mesma origem e cria somente jobs idempotentes. |
| **Sem destino** | Não existe vínculo configurado para a origem aplicável do laudo. | Reenviar reavalia Issuer e InstitutionName de fallback contra destinos ativos. |

O botão **Reenviar** exige confirmação no navegador, CSRF, sessão de superadmin e escopo do tenant da tela. Para falhas terminais, ele somente muda os jobs daquele laudo de volta para `queued` quando o destino correspondente continua ativo e habilitado para liberação; um formulário desatualizado ou uma chamada direta não consegue reenfileirar destinos desativados. Caso não haja job terminal, reutiliza a outbox e a mesma regra de criação idempotente de jobs da liberação clínica. O endpoint não abre conexão DICOM, HTTPS, HL7 ou SFTP: a transmissão ocorre exclusivamente no worker autenticado.

Se não houver destino ativo e compatível, o reenvio retorna sem criar job. Quando um artefato é transmitido e confirmado pelo worker, o job passa para `delivered` e a lista reflete **Entregue**. Um reenvio clínico real deve ser confirmado separadamente antes da ação administrativa.
