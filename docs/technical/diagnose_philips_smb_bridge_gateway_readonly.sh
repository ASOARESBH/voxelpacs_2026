#!/usr/bin/env bash
# Diagnóstico fechado e somente leitura da bridge Philips SMB.
# Não inicia ou interrompe serviços, não abre conexão SMB e não altera rede, firewall, WireGuard ou SSH.
set -euo pipefail

if [[ "${EUID}" -ne 0 || "$#" -ne 0 ]]; then
  printf 'Uso não permitido. Execute localmente como root, sem argumentos.\n' >&2
  exit 64
fi

readonly ENV_FILE='/etc/voxelpacs/philips-folder-bridge.env'
readonly STATE_ROOT='/var/lib/voxelpacs/philips-folder-bridge'
readonly TARGET_ROOT='/var/lib/voxelpacs/philips-folder-target'

env_value() {
  local key="$1"
  [[ -r "$ENV_FILE" ]] || return 0
  sed -nE "s/^[[:space:]]*${key}=(.*)$/\1/p" "$ENV_FILE" | tail -n 1 | sed -E "s/^['\"](.*)['\"]$/\1/"
}

secure_root_file() {
  local path="$1" owner mode
  [[ -n "$path" && -f "$path" && ! -L "$path" ]] || { printf 'absent'; return; }
  owner="$(stat -c '%u' "$path" 2>/dev/null || true)"
  mode="$(stat -c '%a' "$path" 2>/dev/null || true)"
  if [[ "$owner" == '0' && "$mode" =~ ^[0-7]{3,4}$ ]] && (( 8#${mode: -2} == 0 )); then
    printf 'ready'
  else
    printf 'invalid'
  fi
}

secure_directory() {
  local path="$1" owner mode
  [[ -d "$path" && ! -L "$path" ]] || { printf 'absent'; return; }
  owner="$(stat -c '%u' "$path" 2>/dev/null || true)"
  mode="$(stat -c '%a' "$path" 2>/dev/null || true)"
  if [[ "$owner" == '0' && "$mode" =~ ^[0-7]{3,4}$ ]] && (( (8#$mode & 7) == 0 )); then
    printf 'ready'
  else
    printf 'invalid'
  fi
}

bridge_unit() {
  systemctl list-unit-files --type=service --no-legend 2>/dev/null \
    | awk 'tolower($1) ~ /philips.*folder.*bridge.*\.service/ {print $1; exit}'
}

last_bridge_test() {
  local unit="$1" result
  [[ -n "$unit" ]] || { printf 'not_available'; return; }
  result="$(journalctl -u "$unit" --since '24 hours ago' --no-pager 2>/dev/null \
    | sed -nE \
      -e 's/.*event=philips_smb_test_success.*/success:none/p' \
      -e 's/.*event=philips_smb_test_failed.*reason_category=(connectivity|timeout|authentication|permission|remote_io|configuration|credentials_unavailable).*/failed:\1/p' \
    | tail -n 1)"
  case "$result" in
    success:none) printf 'success;category=none;request_reached=yes;envelope=accepted_inferred;smbclient=executed_inferred;tcp445=remote_operation_completed' ;;
    failed:connectivity) printf 'failed;category=connectivity;request_reached=yes;envelope=accepted_inferred;smbclient=executed_inferred;tcp445=smbclient_network_phase' ;;
    failed:timeout) printf 'failed;category=timeout;request_reached=yes;envelope=accepted_inferred;smbclient=executed_inferred;tcp445=smbclient_network_phase' ;;
    failed:authentication|failed:permission|failed:remote_io) printf 'failed;category=${result#failed:};request_reached=yes;envelope=accepted_inferred;smbclient=executed_inferred;tcp445=post_connection_or_remote_phase' ;;
    failed:configuration) printf 'failed;category=configuration;request_reached=yes;envelope=accepted_inferred;smbclient=not_confirmed;tcp445=not_reached' ;;
    failed:credentials_unavailable) printf 'failed;category=credentials_unavailable;request_reached=yes;envelope=rejected;smbclient=not_reached;tcp445=not_reached' ;;
    *) printf 'no_event' ;;
  esac
}

unit="$(bridge_unit)"
bind_ip="$(env_value PHILIPS_FOLDER_BIND_IP)"
bind_port="$(env_value PHILIPS_FOLDER_BIND_PORT)"
destination_id="$(env_value PHILIPS_FOLDER_DESTINATION_ID)"
mode="$(env_value PHILIPS_FOLDER_MODE)"
transport="$(env_value PHILIPS_FOLDER_TRANSPORT)"
peer_host="$(env_value PHILIPS_FOLDER_VPN_PEER_HOST)"
smb_host="$(env_value PHILIPS_SMB_HOST)"
smb_share="$(env_value PHILIPS_SMB_SHARE)"
smb_user="$(env_value PHILIPS_SMB_USER)"
envelope_key="$(env_value PHILIPS_NON_DICOM_ENVELOPE_PRIVATE_KEY_FILE)"
fallback_credentials="$(env_value PHILIPS_SMB_CREDENTIALS_FILE)"

printf '%s\n' '=== PHILIPS_SMB_BRIDGE_READONLY ==='
printf 'DIAGNOSTIC_SCHEMA=%s\n' '1'
printf 'BRIDGE_UNIT=%s\n' "$( [[ -n "$unit" ]] && printf 'detected' || printf 'not_detected' )"
printf 'BRIDGE_PROCESS=%s\n' "$( [[ -n "$unit" ]] && systemctl is-active "$unit" 2>/dev/null || printf 'not_available' )"
if [[ -n "$bind_ip" && "$bind_port" =~ ^[0-9]{1,5}$ ]] && ss -H -ltn 2>/dev/null | awk -v ip="$bind_ip" -v port="$bind_port" '$4 == ip ":" port {found=1} END {exit(found ? 0 : 1)}'; then
  printf 'BRIDGE_LISTENER=matching_private_policy\n'
else
  printf 'BRIDGE_LISTENER=not_confirmed\n'
fi
printf 'BRIDGE_ENV_FILE=%s\n' "$(secure_root_file "$ENV_FILE")"
printf 'BRIDGE_MODE=%s\n' "$( case "$mode" in single_test|destination) printf '%s' "$mode" ;; *) printf 'invalid_or_unavailable' ;; esac )"
printf 'BRIDGE_DESTINATION_POLICY=%s\n' "$( [[ "$destination_id" =~ ^[1-9][0-9]*$ ]] && printf 'configured' || printf 'invalid_or_unavailable' )"
printf 'BRIDGE_TRANSPORT=%s\n' "$( case "$transport" in smb|sftp) printf '%s' "$transport" ;; *) printf 'invalid_or_unavailable' ;; esac )"
printf 'SMB_CONTRACT=%s\n' "$( [[ -n "$peer_host" && "$peer_host" == "$smb_host" && -n "$smb_share" && -n "$smb_user" ]] && printf 'configured_peer_matched' || printf 'invalid_or_unavailable' )"
printf 'SMB_PORT_POLICY=%s\n' 'fixed_445_by_bridge_contract'
printf 'SMBCLIENT=%s\n' "$( command -v smbclient >/dev/null 2>&1 && printf 'available' || printf 'absent' )"
printf 'STATE_DIRECTORY=%s\n' "$(secure_directory "$STATE_ROOT")"
printf 'TARGET_DIRECTORY_ROOT=%s\n' "$(secure_directory "$TARGET_ROOT")"
printf 'ENVELOPE_PRIVATE_KEY=%s\n' "$(secure_root_file "$envelope_key")"
printf 'FALLBACK_CREDENTIAL_FILE=%s\n' "$(secure_root_file "$fallback_credentials")"
printf 'LAST_BRIDGE_TEST=%s\n' "$(last_bridge_test "$unit")"
printf '%s\n' 'PHILIPS_SMB_BRIDGE_READONLY_OK'
