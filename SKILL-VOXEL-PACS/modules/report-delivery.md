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
| `app/Services/PhilipsFolderDeliveryService.php` | Transporte `philips_non_dicom` PDF-only |
| `bin/report_delivery_worker.php` | Execução normal dos jobs elegíveis |
| `tests/report_delivery_manual_retry_static.php` | Contratos estáticos do retry manual homologatório |

## Retry manual de homologação

A rota `POST /platform/negocios/{tenantId}/report-delivery/jobs/{jobId}/retry-homologation` reativa somente um Job ID terminal (`failed` ou `dead_letter`) do tenant informado. A operação exige administrador de plataforma, CSRF e confirmação explícita; valida destino habilitado, ambiente `homologacao`, transporte `philips_non_dicom`, vínculo tenant/job/destino e artifact PDF íntegro por tamanho e SHA-256.

A operação usa `SELECT ... FOR UPDATE` e uma atualização condicional de estado para impedir reativação concorrente. Preserva `attempt_count`, histórico, erro anterior, outbox, artifact e identidade do job; não cria job, não insere attempt e não depende de `disparar_na_liberacao`. A próxima attempt só é criada pelo worker ao reivindicar o job.

O método existente `retryJob()` permanece reservado ao retry condicionado ao fluxo automático e continua exigindo `disparar_na_liberacao = 1`. Não usar a operação por relatório para esse caso, pois ela pode reativar múltiplos jobs terminais.

## Dependências

- Depende de: `pacs_report_delivery_jobs`, `pacs_report_delivery_outbox`, `pacs_report_delivery_destinations`, `pacs_report_delivery_artifacts`, `App\Core\Audit\AuditLogger` e `ReportDeliveryWorkerRepository`.
- Consumido por: tela administrativa `/platform/negocios/{id}/report-delivery`, rota manual por Job ID e worker de entrega.

## Padrões seguidos

Aplica `padrao-sql.md`, `padrao-i18n.md` e o fluxo Controller/Repository existente do Delivery Hub. Toda query mantém filtro de tenant e toda ação mutável usa POST, CSRF e autorização administrativa.

## Riscos / pontos frágeis conhecidos

A reativação manual torna o job elegível para o worker e pode iniciar transporte externo quando o worker for executado. Por isso a UI não oferece a ação para produção, DICOM, jobs concluídos, jobs ativos ou destinos desabilitados. Falhas posteriores do transporte devem seguir a máquina de estados e o limite de tentativas configurado no destino.

## Última análise

2026-09-14 — implementado e validado estaticamente; nenhum deploy, retry, chamada Bridge ou SMB foi executado nesta fase.
