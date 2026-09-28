# Report Delivery — Flags Runtime Oficiais

## Escopo

`app/Config/ReportDeliveryRuntimeConfig.php` é a fonte única de leitura das flags operacionais do Report Delivery. A classe não grava configuração, não acessa banco e não contém credenciais.

O bootstrap do VOXEL PACS carrega as fontes de ambiente aprovadas antes dos consumidores. A precedência de leitura é a mesma usada pelo `Database`: `$_ENV`, `$_SERVER` e, por fim, `getenv()`.

A ausência de qualquer flag é segura: **OFF**. Valores booleanos aceitos são `1/true/yes/on` e `0/false/no/off`.

## Flags

| Flag | Consumidor | Ausente | Valor inválido |
|---|---|---:|---:|
| `VOXEL_REPORT_DELIVERY_HUB_ENABLED` | `ReportDeliveryOutboxService` | OFF | OFF |
| `VOXEL_REPORT_DELIVERY_REQUESTS_ENABLED` | `ReportDeliveryWorkerRepository` | OFF | OFF |
| `PHILIPS_FOLDER_DELIVERY_ENABLED` | transporte Philips Folder legado | OFF | OFF |
| `PHILIPS_NON_DICOM_DELIVERY_ENABLED` | transporte Philips Non-DICOM e Worker | OFF | OFF |
| `PHILIPS_NON_DICOM_SMB_TEST_ENABLED` | teste de conectividade SMB opt-in | OFF | OFF |
| `PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED` | teste SMB somente leitura opt-in | OFF | OFF |
| `VOXEL_REPORT_DELIVERY_WORKER_KILL_SWITCH` | entrypoint do Worker contínuo e one-shot | OFF | **ON** |

O kill switch é fail-closed: valor inválido também impede o processamento. O guard ocorre antes de `enableOneShotForJob()`, `claimJobById()` e `claimNextJob()`. Ele não cancela jobs, não modifica Outbox/Request, não cria attempts e não executa retry.

## Aplicação de configuração

`scripts/configure-report-delivery-runtime.sh` é o mecanismo versionado e allowlisted para alterar apenas as chaves acima em uma fonte de ambiente autorizada.

```text
--dry-run KEY=VALUE [...]
--apply KEY=VALUE [...]
--rollback BACKUP_DIRECTORY
```

- `--dry-run` valida o arquivo, chaves, valores e duplicidades sem gravar.
- `--apply` exige root, cria backup root-only, preserva owner/group/mode e altera somente as chaves informadas.
- `--rollback` exige root e restaura somente um backup criado pelo próprio mecanismo.
- Nenhuma opção executa reload/restart, acessa banco, Bridge, SMB, Windows ou Philips.
- O mecanismo não imprime valores de secrets e não aceita chaves fora da allowlist.

Para retirar o teste SMB em produção, a aplicação futura deve ser feita pelo workflow oficial, com a mudança explícita:

```text
PHILIPS_NON_DICOM_SMB_TEST_ENABLED=false
PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED=false
```

A configuração de produção de Hub, Requests e Non-DICOM permanece uma decisão operacional separada e não é habilitada pelo script por padrão. O kill switch também não é alterado por uma aplicação de perfil de teste sem declaração explícita.

## Rollback operacional futuro

1. executar `--dry-run` com o arquivo de ambiente aprovado;
2. obter autorização separada para aplicar;
3. executar `--apply` somente com as chaves necessárias;
4. validar a presença sanitizada e as permissões do arquivo;
5. fazer reload/restart apenas do processo afetado, se autorizado;
6. se necessário, executar `--rollback` apontando para o backup root-only;
7. revalidar flags e manter Worker/Bridge no estado operacional previamente autorizado.

Esta implementação não altera o runtime atual. A existência do mecanismo versionado não prova que a configuração foi aplicada em produção.
