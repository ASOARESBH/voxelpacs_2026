#!/usr/bin/env bash
set -euo pipefail

# Patch cirúrgico da API para retry manual auditável e monitor somente leitura.
# Não inicia worker, timer, bridge ou qualquer entrega clínica.
APP_DIR=${APP_DIR:-/var/www/voxelpacs/app}
STAGE_DIR=${1:?usage: apply_report_delivery_retry_monitoring_api.sh STAGE_DIR}
DB_NAME=${DB_NAME:-voxelpacs_homolog}
BACKUP_ROOT=${BACKUP_ROOT:-/var/backups/voxelpacs}

if [[ $EUID -ne 0 ]]; then
  echo 'must_run_as_root' >&2
  exit 2
fi
if [[ ! -d "$APP_DIR" || ! -d "$STAGE_DIR" ]]; then
  echo 'app_or_stage_missing' >&2
  exit 3
fi

stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_dir="$BACKUP_ROOT/report-delivery-retry-monitoring-$stamp"
install -d -m 0700 "$backup_dir"

files=(
  app/Controllers/Platform/ReportDeliveryController.php
  app/Repositories/ReportDeliveryRepository.php
  app/Repositories/ReportDeliveryWorkerRepository.php
  app/Services/ReportDeliveryGatewayBridgeClient.php
  app/Services/ReportDeliveryGatewayBridgeFailure.php
  app/Services/ReportDeliveryManualQueueService.php
  bin/report_delivery_worker.php
  bin/report_delivery_monitor.php
  ops/systemd/voxelpacs-report-delivery-worker.service
  deploy/voxelpacs-report-delivery-monitor.service
  deploy/voxelpacs-report-delivery-monitor.timer
  deploy/install_report_delivery_monitor_api.sh
)

for relative in "${files[@]}"; do
  [[ -f "$STAGE_DIR/$relative" ]] || { echo "stage_file_missing:$relative" >&2; exit 4; }
  if [[ -f "$APP_DIR/$relative" ]]; then
    install -d -m 0700 "$backup_dir/$(dirname "$relative")"
    cp -a "$APP_DIR/$relative" "$backup_dir/$relative"
  fi
done

# A migration é aditiva e executa em transação. Verifica previamente a tabela esperada.
[[ -f "$STAGE_DIR/database/migrations/2026-09-01_report_delivery_manual_retry_audit_postgresql.sql" ]] || {
  echo 'postgres_migration_missing' >&2
  exit 5
}
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d "$DB_NAME" -Atqc \
  "SELECT to_regclass('voxelpacs_mysql_source.pacs_report_delivery_jobs') IS NOT NULL" | grep -qx 't'
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d "$DB_NAME" \
  -f "$STAGE_DIR/database/migrations/2026-09-01_report_delivery_manual_retry_audit_postgresql.sql" >/dev/null

for relative in "${files[@]}"; do
  install -D -m 0644 "$STAGE_DIR/$relative" "$APP_DIR/$relative"
done
chmod 0755 "$APP_DIR/bin/report_delivery_monitor.php" "$APP_DIR/deploy/install_report_delivery_monitor_api.sh"
install -m 0644 "$APP_DIR/deploy/voxelpacs-report-delivery-monitor.service" /etc/systemd/system/voxelpacs-report-delivery-monitor.service
install -m 0644 "$APP_DIR/deploy/voxelpacs-report-delivery-monitor.timer" /etc/systemd/system/voxelpacs-report-delivery-monitor.timer

for relative in \
  app/Controllers/Platform/ReportDeliveryController.php \
  app/Repositories/ReportDeliveryRepository.php \
  app/Repositories/ReportDeliveryWorkerRepository.php \
  app/Services/ReportDeliveryGatewayBridgeClient.php \
  app/Services/ReportDeliveryGatewayBridgeFailure.php \
  app/Services/ReportDeliveryManualQueueService.php \
  bin/report_delivery_worker.php \
  bin/report_delivery_monitor.php; do
  /usr/bin/php -l "$APP_DIR/$relative" >/dev/null
done

systemctl daemon-reload
[[ "$(systemctl is-active voxelpacs-report-delivery-worker.service || true)" != 'active' ]] || {
  echo 'worker_unexpectedly_active' >&2
  exit 6
}
[[ "$(systemctl is-enabled voxelpacs-report-delivery-monitor.timer 2>/dev/null || true)" != 'enabled' ]] || {
  echo 'monitor_timer_unexpectedly_enabled' >&2
  exit 7
}

printf 'api_patch_applied\nbackup=%s\nworker_state=%s\nmonitor_timer_enabled=%s\n' \
  "$backup_dir" \
  "$(systemctl is-active voxelpacs-report-delivery-worker.service || true)" \
  "$(systemctl is-enabled voxelpacs-report-delivery-monitor.timer 2>/dev/null || true)"
