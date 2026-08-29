#!/usr/bin/env bash
# Kill switch do worker de devolutiva. Executar como root no host da API.
set -Eeuo pipefail
umask 077

APP_ROOT=/var/www/voxelpacs/app
ENV_FILE="$APP_ROOT/.env"
WORKER_SERVICE=voxelpacs-report-delivery-worker.service
BACKUP_DIR="/var/backups/voxelpacs/report-delivery-worker-killswitch-$(date -u +%Y%m%dT%H%M%SZ)"

[[ $EUID -eq 0 ]] || { echo 'Execução requer root' >&2; exit 2; }
systemctl disable --now "$WORKER_SERVICE" 2>/dev/null || true

if test -r "$ENV_FILE"; then
  install -d -m 0700 "$BACKUP_DIR"
  cp -a "$ENV_FILE" "$BACKUP_DIR/app.env.pre-killswitch"
  TMP=$(mktemp)
  trap 'rm -f "$TMP"' EXIT
  awk '!/^VOXEL_REPORT_DELIVERY_HUB_ENABLED=/' "$ENV_FILE" > "$TMP"
  printf 'VOXEL_REPORT_DELIVERY_HUB_ENABLED=false\n' >> "$TMP"
  install -o root -g root -m 0600 "$TMP" "$ENV_FILE"
fi

systemctl daemon-reload
printf 'API_AUTOMATION_DISABLED worker='
systemctl is-active "$WORKER_SERVICE" || true
printf 'worker_enabled='
systemctl is-enabled "$WORKER_SERVICE" || true
