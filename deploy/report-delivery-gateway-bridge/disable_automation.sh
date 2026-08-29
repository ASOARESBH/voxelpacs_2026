#!/usr/bin/env bash
# Kill switch da bridge de devolutiva. Executar como root no gateway.
set -Eeuo pipefail
umask 077

SERVICE=voxelpacs-report-delivery-bridge.service
ENV_FILE=/etc/voxelpacs/report-delivery-gateway-bridge.env
BACKUP_DIR="/var/backups/voxelpacs/report-delivery-bridge-killswitch-$(date -u +%Y%m%dT%H%M%SZ)"

[[ $EUID -eq 0 ]] || { echo 'Execução requer root' >&2; exit 2; }
systemctl disable --now "$SERVICE" 2>/dev/null || true

if test -r "$ENV_FILE"; then
  install -d -m 0700 "$BACKUP_DIR"
  cp -a "$ENV_FILE" "$BACKUP_DIR/bridge.env.pre-killswitch"
  TMP=$(mktemp)
  trap 'rm -f "$TMP"' EXIT
  awk '!/^BRIDGE_AUTOMATION_ENABLED=/' "$ENV_FILE" > "$TMP"
  printf 'BRIDGE_AUTOMATION_ENABLED=false\n' >> "$TMP"
  install -o root -g root -m 0600 "$TMP" "$ENV_FILE"
fi

systemctl daemon-reload
printf 'GATEWAY_AUTOMATION_DISABLED bridge='
systemctl is-active "$SERVICE" || true
printf 'listener_9443='
ss -ltnH "sport = :9443" | wc -l
