#!/usr/bin/env bash
set -euo pipefail

# Patch cirúrgico da bridge: adiciona idempotência por tentativa e telemetria sanitizada.
# Não altera Docker, Orthanc, WireGuard, firewall, policy ou receptor DICOM.
STAGED_SCRIPT=${1:?usage: apply_report_delivery_retry_monitoring_gateway.sh STAGED_BRIDGE_SCRIPT TARGET_BRIDGE_SCRIPT}
TARGET_SCRIPT=${2:?usage: apply_report_delivery_retry_monitoring_gateway.sh STAGED_BRIDGE_SCRIPT TARGET_BRIDGE_SCRIPT}
SERVICE=${SERVICE:-voxelpacs-report-delivery-bridge.service}
BACKUP_ROOT=${BACKUP_ROOT:-/var/backups/voxelpacs}

if [[ $EUID -ne 0 ]]; then
  echo 'must_run_as_root' >&2
  exit 2
fi
if [[ ! -f "$STAGED_SCRIPT" || ! -f "$TARGET_SCRIPT" ]]; then
  echo 'bridge_stage_or_target_missing' >&2
  exit 3
fi
if ! systemctl is-active --quiet "$SERVICE"; then
  echo 'bridge_not_active_before_patch' >&2
  exit 4
fi

/usr/bin/python3 -m py_compile "$STAGED_SCRIPT"
stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_dir="$BACKUP_ROOT/report-delivery-retry-monitoring-$stamp"
install -d -m 0700 "$backup_dir"
cp -a "$TARGET_SCRIPT" "$backup_dir/bridge_server.py"

install -m 0644 "$STAGED_SCRIPT" "$TARGET_SCRIPT"
/usr/bin/python3 -m py_compile "$TARGET_SCRIPT"
systemctl restart "$SERVICE"
systemctl is-active --quiet "$SERVICE"

printf 'gateway_patch_applied\nbackup=%s\nbridge_state=%s\n' \
  "$backup_dir" "$(systemctl is-active "$SERVICE")"
