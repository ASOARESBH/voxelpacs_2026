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
| Gatilho clínico | Apenas `Assinar e Fechar` (`modo=fechar`), que coloca o estudo em `liberado`. |
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

## Roteamento por PACS de origem (InstitutionName)

Um mesmo negócio pode receber estudos de vários PACS. Por isso, cada destino de devolutiva deve ser vinculado a um ou mais **InstitutionNames** ativos daquele tenant. O InstitutionName é o valor DICOM `(0008,0080)` armazenado em `bi_pacs_estudos.institution_name` quando o estudo é importado.

Ao liberar um laudo, o Hub normaliza o InstitutionName recebido e o compara com os InstitutionNames cadastrados no negócio. Ele cria jobs apenas para destinos que possuam vínculo explícito com a origem canônica do estudo. O snapshot da outbox registra tanto o valor recebido quanto o valor canônico usado no roteamento.

| Situação | Resultado do Hub |
|---|---|
| Estudo `ORIX_ORTHO` e destino associado a `ORIX_ORTHO` | Cria job apenas para esse destino. |
| Estudo `ORIX_RAIO_X` e outro destino associado a `ORIX_RAIO_X` | Cria job apenas para o segundo destino. |
| Destino associado a vários InstitutionNames | Cria um job quando o estudo vier de qualquer origem associada. |
| InstitutionName sem cadastro ativo no tenant | Não cria job externo; registra outbox como `no_destination` e log administrativo. |
| Destino legado sem origem vinculada | Não recebe novos jobs até que a origem seja selecionada no painel. |

No painel **Devolutiva de Laudos**, use a seção **PACS de origem dos estudos** para marcar uma ou mais origens antes de salvar o destino. Essa associação é obrigatória e fica visível na lista de destinos.

> A seleção por InstitutionName é independente do canal de entrega. Um mesmo InstitutionName pode ter destinos distintos para DICOM, HTTPS, HL7 ou SFTP, desde que cada integração seja homologada e habilitada de forma explícita.
