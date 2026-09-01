#!/usr/bin/env bash
set -euo pipefail

# Instala somente o monitor local de leitura. Não altera bridge, worker ou filas.
# Passe --enable apenas após aprovação operacional explícita para habilitar o timer.
APP_DIR=${APP_DIR:-/var/www/voxelpacs/app}
SYSTEMD_DIR=${SYSTEMD_DIR:-/etc/systemd/system}
ENABLE=${1:-}

if [[ $EUID -ne 0 ]]; then
  echo 'must_run_as_root' >&2
  exit 2
fi
if [[ ! -f "$APP_DIR/bin/report_delivery_monitor.php" ]]; then
  echo 'monitor_script_missing' >&2
  exit 3
fi

install -m 0644 "$APP_DIR/deploy/voxelpacs-report-delivery-monitor.service" "$SYSTEMD_DIR/voxelpacs-report-delivery-monitor.service"
install -m 0644 "$APP_DIR/deploy/voxelpacs-report-delivery-monitor.timer" "$SYSTEMD_DIR/voxelpacs-report-delivery-monitor.timer"
systemctl daemon-reload

if [[ "$ENABLE" == '--enable' ]]; then
  systemctl enable --now voxelpacs-report-delivery-monitor.timer
  systemctl is-active --quiet voxelpacs-report-delivery-monitor.timer
  echo 'report_delivery_monitor_enabled'
else
  echo 'report_delivery_monitor_installed_not_enabled'
fi
