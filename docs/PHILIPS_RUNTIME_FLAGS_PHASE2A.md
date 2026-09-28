# Fase 2A — Flags Runtime Philips Non-DICOM

A leitura das flags do Report Delivery está centralizada em `App\Config\ReportDeliveryRuntimeConfig`. A ausência é sempre OFF para os recursos; o `VOXEL_REPORT_DELIVERY_WORKER_KILL_SWITCH` trata valor inválido como ON para falhar fechado.

O teste SMB legado e o teste SMB read-only permanecem opt-in. A configuração oficial e reversível é `scripts/configure-report-delivery-runtime.sh`, que oferece `--dry-run`, `--apply` com backup root-only e `--rollback`. O script não reinicia serviços nem executa transporte.

A aplicação em produção exige etapa posterior autorizada do workflow de deploy. Esta fase não altera `.env`, EnvironmentFile, systemd, Bridge, banco, filas, Worker ou Destination.
