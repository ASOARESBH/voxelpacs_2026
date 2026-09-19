# Módulo — Report Delivery Hub

## Propósito

O Report Delivery Hub mantém destinos, outboxes, jobs, artifacts PDF e tentativas de entrega separados por tenant. O worker é o único componente que executa o transporte externo; o control-plane apenas valida e enfileira operações autorizadas.

## Arquivos principais

| Arquivo | Papel |
|---|---|
| `app/Repositories/ReportDeliveryRepository.php` | Persistência tenant-scoped de destinos, outboxes, jobs e retry manual/automático |
| `app/Repositories/ReportDeliveryWorkerRepository.php` | Claim concorrente, attempts, artifacts e finalização do worker |
| `app/Services/ReportDeliveryManualQueueService.php` | Criação manual homologatória de outbox/job |
| `app/Controllers/Platform/ReportDeliveryController.php` | Administração, CSRF, autorização e auditoria das ações |
| `app/Views/platform/negocios/report_delivery.php` | Configuração de destinos e ações manuais |
| `app/Services/PhilipsFolderDeliveryService.php` | Transporte `philips_non_dicom` PDF-only e `submission_document` |
| `app/Services/PhilipsSubmissionMetadataResolver.php` | Resolução explícita e fail-closed de metadata do submission XML |
| `app/Services/ReportVersionPatientNameService.php` | Parser DICOM PN e validação da identidade estruturada congelada na versão |
| `app/Services/PhilipsSubmissionDocumentGenerator.php` | Geração determinística do documento XML Philips |
| `app/Services/PhilipsSubmissionPackageProducer.php` | Composição imutável do package PDF + XML |
| `docs/PHILIPS_SUBMISSION_DOCUMENT_CONTRACT.md` | Contrato de campos, origens e ativação do profile XML |
| `bin/report_delivery_worker.php` | Execução normal dos jobs elegíveis |
| `tests/report_delivery_manual_retry_static.php` | Contratos estáticos do retry manual homologatório |

## Retry manual de homologação

A rota `POST /platform/negocios/{tenantId}/report-delivery/jobs/{jobId}/retry-homologation` reativa somente um Job ID terminal (`failed` ou `dead_letter`) do tenant informado. A operação exige administrador de plataforma, CSRF e confirmação explícita; valida destino habilitado, ambiente `homologacao`, transporte `philips_non_dicom`, vínculo tenant/job/destino e artifact PDF íntegro por tamanho e SHA-256.

A operação usa `SELECT ... FOR UPDATE` e uma atualização condicional de estado para impedir reativação concorrente. Preserva `attempt_count`, histórico, erro anterior, outbox, artifact e identidade do job; não cria job, não insere attempt e não depende de `disparar_na_liberacao`. A próxima attempt só é criada pelo worker ao reivindicar o job.

O método existente `retryJob()` permanece reservado ao retry condicionado ao fluxo automático e continua exigindo `disparar_na_liberacao = 1`. Não usar a operação por relatório para esse caso, pois ela pode reativar múltiplos jobs terminais.

## Profile `submission_document`

O profile `submission_document` é opt-in e compõe PDF + XML somente quando a configuração explícita `philips_submission` está validada. O profile `pdf_only` permanece o padrão compatível e não é convertido retroativamente. A configuração administra uma pasta lógica; o `PhilipsSubmissionPackageProducer` anexa o basename de transporte validado do PDF antes de gerar o XML, preservando o separador Windows ou POSIX detectado. Na assinatura/liberação, o `ReportVersionPatientNameService` congela `patient_name_family`, `patient_name_given`, `patient_name_middle` e `patient_name_source` na `report_versions`; PN DICOM válido usa `dicom_pn`, e nome plano exige confirmação manual explícita. O snapshot tenant-scoped transporta esses campos e o resolver os prioriza; versões antigas continuam usando `tags_raw.PatientName` e override histórico como fallback. `released_at` continua vindo de `reports.liberado_em`, sem criar nova data, e timestamps com offset/fração são normalizados para UTC antes da serialização XML. O gerador falha fechado com o campo técnico não resolvido. A Bridge valida estrutura, campos, encoding ISO-8859-1, hash/tamanho e linkage PDF/XML antes de retornar `package_verified=PASS`, e preserva os arquivos finais remotos; apenas temporários `.part` são removidos. Novos jobs usam chave de idempotência profile-aware sem recalcular históricos. O contrato detalhado está em `docs/PHILIPS_SUBMISSION_DOCUMENT_CONTRACT.md`.

Ao editar um destino, o serializador da view remove da cópia de configuração todas as chaves raiz com prefixo `task_` antes de reconstruir `philips_submission`. Isso elimina duplicidades legadas de versões que persistiam esses campos fora do objeto aninhado, sem remover configurações não relacionadas, e mantém `pdf_only` sem `philips_submission`.

## Delivery Request

A `pacs_report_delivery_requests` representa uma autorização operacional explícita, distinta de `report_versions`, outbox, job e attempt. Na primeira versão, ela aceita somente o tenant-scoped `submission_document` do Destination 6 em homologação, referencia uma versão clínica explícita, calcula digests canônicos sem armazenar conteúdo clínico ou segredos e materializa exatamente uma nova outbox e um job `queued` com `worker_eligible_at = NULL`. O armamento é separado e exige confirmação administrativa; o worker só reclama a request em `armed` e bloqueia, antes de qualquer conector, divergência de snapshot ou configuração.

O override `patient_name` é uma extensão aditiva e request-scoped: os componentes ficam cifrados em `pacs_report_delivery_request_patient_name_overrides`, com digest incluído no `authorized_snapshot_digest`, escopo exato da homologação controlada (tenant 2, report 74/V11, estudo 1704 e Destination 6), expiração curta, aprovação, imutabilidade pós-aprovação e consumo único. O resolver prioriza componentes congelados na `report_versions`; para versões antigas usa PatientName DICOM estruturado do snapshot (`tags_raw.PatientName`, depois `patient_name_dicom`) e só usa o override quando nenhuma fonte clínica estruturada válida existe. O snapshot service aplica o override somente no package da request; replay read-only usa `consumeOverride=false`. O worker aplica `max_attempts=1` ao job que possui override, sem alterar o limite de jobs históricos. Não há fallback para nome plano e o Destination 6 não recebe esses valores. A auditoria expõe apenas estados sanitizados de presença, escopo, par, origem, aprovação, expiração e consumo.

Requests ligadas usam `outbox.delivery_request_id`; não existe nem deve ser presumida uma coluna correspondente em `pacs_report_delivery_jobs`. Outboxes e jobs históricos permanecem com vínculo nulo e fora desse fluxo. A feature flag `VOXEL_REPORT_DELIVERY_REQUESTS_ENABLED` permanece `false` por padrão.

O recovery administrativo usa `ReportDeliveryRequestService::prepareRecovery()`: gera um UUID v4 novo no servidor, referencia apenas `report_id`/`report_version` explícitos, registra auditoria sanitizada e termina em `prepared`. Não aceita `job_id` histórico, não chama retry, não materializa outbox/job e não inicia transporte; aprovar, materializar, armar e executar continuam sendo fases separadas.

## Dependências

- Depende de: `pacs_report_delivery_jobs`, `pacs_report_delivery_outbox`, `pacs_report_delivery_destinations`, `pacs_report_delivery_artifacts`, `App\Core\Audit\AuditLogger` e `ReportDeliveryWorkerRepository`.
- Consumido por: tela administrativa `/platform/negocios/{id}/report-delivery`, rota manual por Job ID e worker de entrega.

## Padrões seguidos

Aplica `padrao-sql.md`, `padrao-i18n.md` e o fluxo Controller/Repository existente do Delivery Hub. Toda query mantém filtro de tenant e toda ação mutável usa POST, CSRF e autorização administrativa.

## Riscos / pontos frágeis conhecidos

A reativação manual torna o job elegível para o worker e pode iniciar transporte externo quando o worker for executado. Por isso a UI não oferece a ação para produção, DICOM, jobs concluídos, jobs ativos ou destinos desabilitados. Falhas posteriores do transporte devem seguir a máquina de estados e o limite de tentativas configurado no destino.

## Última análise

2026-09-19 — Fase 74.3: parser DICOM PN, campos congelados em `report_versions`, confirmação manual para nome plano, precedência do resolver, snapshot e UI de liberação foram implementados e validados somente no clone. As migrations são arquivos versionados e não foram executadas; os valores reais do report 74/V11 não foram preenchidos; nenhum banco, Request, Job, retry, deploy, chamada Bridge ou SMB foi executado nesta fase.
