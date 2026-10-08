# Philips Non-DICOM — validação PDF/XML `read-only-no-send`

## Objetivo

`bin/philips_nondicom_pdf_readonly.php` fecha o gate técnico de PDF do profile
`submission_document` sem transmitir ou alterar o Delivery Hub. A operação é
explicitamente tenant-scoped e aceita somente o Job informado.

Exemplo:

```bash
php bin/philips_nondicom_pdf_readonly.php \
  --tenant-id=2 \
  --job-id=516 \
  --read-only-no-send
```

## Garantias

A execução:

- abre uma transação PostgreSQL `READ ONLY` e faz `ROLLBACK`;
- exige Job `queued`, Outbox `queued`, `attempt_count=0` e sem lock;
- aplica os mesmos gates temporais do Worker: `worker_eligible_at <= NOW()`, `next_attempt_at <= NOW()` e `automatic_dispatch_date` nula ou igual à data corrente; não converte `worker_eligible_at` pelo timezone local do PHP;
- valida tenant, Destination 7, transporte, profile e ambiente; `controlled_production` exige Request `armed`, enquanto `automatic_production` exige Outbox direto `report.released` sem Request;
- em `controlled_production`, compara separadamente o digest autorizado da Request com o snapshot atual e o digest do Destination;
- em `controlled_production`, valida separadamente que `destination_config_observed_at` ainda corresponde ao `updated_at` do Destination; em `automatic_production`, esses digests de autorização são `NOT_APPLICABLE`;
- valida o vínculo canônico `servidor_pacs_id` ↔ estudo ↔ servidor ativo do tenant;
- confirma que o alias ASCII está congelado no Destination, Request e payload em `controlled_production`, ou somente no contexto do Destination em `automatic_production`;
- lê o snapshot PDF canônico imutável, ou a revisão PDF explicitamente ligada ao Job;
- valida assinatura `%PDF-`, tamanho, SHA-256 e marcador estrutural `%%EOF`;
- renderiza uma cópia do contexto visual oficial somente em memória;
- compõe o XML com `PhilipsSubmissionPackageProducer::composeNoSend()`;
- valida XML bem-formado, encoding declarado e correlação filename PDF/XML/alias;
- não grava PDF, XML, artifact, attempt, lock, Job, Request ou Outbox;
- não chama Worker, Bridge, SMB, DICOM, C-STORE ou endpoint externo.

A renderização em memória é uma validação técnica do caminho oficial do
renderer. O PDF efetivamente utilizado pelo Worker continua sendo o snapshot
imutável lido e validado; o diagnóstico não substitui esse snapshot nem cria
uma nova versão clínica. Jobs automáticos mantêm a identidade direta do
Outbox/Job e não recebem Request, alias ou digest artificial.

## Saída sanitizada

O CLI retorna somente estados e IDs técnicos:

```text
STATUS
MODE
TENANT_ID
JOB_ID
REQUEST_ID
OUTBOX_ID
DESTINATION_ID
JOB_STATUS
REQUEST_STATUS
OUTBOX_STATUS
ATTEMPT_COUNT
SNAPSHOT
SNAPSHOT_DIGEST
DESTINATION_DIGEST
DESTINATION_TIMESTAMP_VALIDATION
TASK_SITE_ID
TASK_SITE_ID_VALIDATION
TASK_SITE_ID_ALIAS
TASK_SITE_ID_ALIAS_VALIDATION
SOURCE
PDF_GENERATION
PDF_VALIDATION
XML_GENERATION
XML_VALIDATION
PDF_XML_CORRELATION
ARTIFACT_WRITTEN
ATTEMPT_CREATED
JOB_CLAIMED
BRIDGE_CALLED
SMB_CALLED
TRANSMISSION
DATABASE_CHANGED
FAILURE_CODE
```

O valor literal do alias, o conteúdo do PDF/XML, nomes clínicos, Patient ID,
UID, caminho privado, segredo e hashes não são retornados.

`SNAPSHOT_DIGEST=PASS` e `DESTINATION_DIGEST=PASS` confirmam os bytes
canônicos congelados. `DESTINATION_TIMESTAMP_VALIDATION=FAIL` significa que o
Destination foi atualizado depois da autorização da Request; esse caso não é
uma divergência do digest e deve ser reportado como
`DESTINATION_CHANGED_AFTER_AUTHORIZATION`. O diagnóstico permanece bloqueado
até que exista uma nova autorização/identidade pelo fluxo oficial; não se deve
alterar a Request ou recalcular/gravar digests em produção.

## Decisão

`STATUS=PASS` significa apenas que a validação read-only dos elementos
imutáveis passou. Não autoriza Worker, allowlist, Bridge, SMB write, retry ou
transmissão. A primeira entrega PDF+XML permanece uma etapa separada e exige
autorização explícita.

## Teste e rollback

A mudança é somente de código/documentação, sem migration. O rollback é a
reversão do commit/PR antes de qualquer deploy; nenhuma tabela ou configuração
operacional é alterada por este diagnóstico.
