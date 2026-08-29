#!/usr/bin/env bash
# Ativa uma única policy tenant-scoped no gateway. Executar como root.
# Não registra segredos, AEs, IPs ou material criptográfico.
set -Eeuo pipefail
umask 077

TENANT_ID=${1:?Informe o tenant_id positivo}
DESTINATION_ID=${2:?Informe o destination_id positivo}
SERVICE=voxelpacs-report-delivery-bridge.service
ENV_FILE=/etc/voxelpacs/report-delivery-gateway-bridge.env
BACKUP_DIR="/var/backups/voxelpacs/report-delivery-bridge-automation-$(date -u +%Y%m%dT%H%M%SZ)"

[[ "$TENANT_ID" =~ ^[1-9][0-9]*$ ]] || { echo 'tenant_id inválido' >&2; exit 2; }
[[ "$DESTINATION_ID" =~ ^[1-9][0-9]*$ ]] || { echo 'destination_id inválido' >&2; exit 2; }
[[ $EUID -eq 0 ]] || { echo 'Execução requer root' >&2; exit 2; }
test -r "$ENV_FILE"
test -d /sys/class/net/wg0

systemctl disable --now "$SERVICE" 2>/dev/null || true
install -d -m 0700 "$BACKUP_DIR"
cp -a "$ENV_FILE" "$BACKUP_DIR/bridge.env.pre-automation"

bash -ceu "
  set -a
  . \"$ENV_FILE\"
  test -n \"\${BRIDGE_BIND_IP}\"
  test -n \"\${BRIDGE_BIND_PORT}\"
  test -n \"\${BRIDGE_TARGET_HOST}\"
  test -n \"\${BRIDGE_TARGET_PORT}\"
  test -n \"\${BRIDGE_CALLING_AE}\"
  test -n \"\${BRIDGE_CALLED_AE}\"
  test -r \"\${BRIDGE_HMAC_FILE}\"
  test -r \"\${BRIDGE_CLIENT_CA_FILE}\"
  test -r \"\${BRIDGE_SERVER_CERT_FILE}\"
  test -r \"\${BRIDGE_SERVER_KEY_FILE}\"
"

TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT
awk '!/^(BRIDGE_MODE|BRIDGE_ALLOW_JOB_ID|BRIDGE_ALLOW_TENANT_ID|BRIDGE_ALLOW_DESTINATION_ID|BRIDGE_AUTOMATION_ENABLED)=/' "$ENV_FILE" > "$TMP"
cat >> "$TMP" <<EOF
BRIDGE_MODE=tenant_destination
BRIDGE_ALLOW_JOB_ID=0
BRIDGE_ALLOW_TENANT_ID=${TENANT_ID}
BRIDGE_ALLOW_DESTINATION_ID=${DESTINATION_ID}
BRIDGE_AUTOMATION_ENABLED=true
EOF
install -o root -g root -m 0600 "$TMP" "$ENV_FILE"

systemctl daemon-reload
systemctl enable --now "$SERVICE"
sleep 1
systemctl is-active --quiet "$SERVICE"
ss -ltnH "sport = :9443" | awk '$4 ~ /^10\.0\.0\.4:9443$/ {found=1} END {exit(found ? 0 : 1)}'
printf 'AUTOMATION_GATEWAY_ENABLED tenant=%s destination=%s backup=%s\n' "$TENANT_ID" "$DESTINATION_ID" "$BACKUP_DIR"
