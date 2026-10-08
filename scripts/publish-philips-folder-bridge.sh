#!/usr/bin/env bash
# VOXEL PACS — publicação root-controlled do código da Philips Folder Bridge.
# Busca somente um arquivo público pinado a uma SHA da main; não acessa banco,
# SMB, DICOM, Worker, allowlist ou conteúdo clínico.
set -Eeuo pipefail
umask 077

export PATH='/usr/sbin:/usr/bin:/sbin:/bin'
readonly EXPECTED_HOST='gateway-dicom-01'
readonly RUNTIME_DIR='/opt/voxelpacs/report-delivery-gateway'
readonly RUNTIME_FILE="$RUNTIME_DIR/philips_folder_bridge.py"
readonly BRIDGE_UNIT='voxelpacs-philips-folder-bridge.service'
readonly BACKUP_ROOT='/var/backups/voxelpacs/philips-folder-bridge'
readonly REPOSITORY='https://raw.githubusercontent.com/ASOARESBH/voxelpacs_2026'
readonly SOURCE_PATH='deploy/report-delivery-gateway-bridge/philips_folder_bridge.py'
readonly LOCK_PATH='/run/lock/voxelpacs-philips-folder-bridge-publish.lock'
readonly TEMP_PARENT='/run/voxelpacs'
readonly TEMP_ROOT='/run/voxelpacs/philips-folder-bridge-publish'

operation=''
source_sha=''
source_sha256=''
backup_sha=''
reload_bridge='NO'
workdir=''
lock_fd=''

fail() {
  printf 'BRIDGE_PUBLISH=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

usage() {
  cat >&2 <<'USAGE'
uso: publish-philips-folder-bridge.sh --sha <40-hex-sha> --source-sha256 <64-hex-sha256> --dry-run
uso: publish-philips-folder-bridge.sh --sha <40-hex-sha> --source-sha256 <64-hex-sha256> --apply [--reload]
uso: publish-philips-folder-bridge.sh --rollback-sha <64-hex-sha256>
USAGE
}

is_sha() { [[ "${1:-}" =~ ^[0-9a-f]{40}$ ]]; }
is_sha256() { [[ "${1:-}" =~ ^[0-9a-f]{64}$ ]]; }

cleanup() {
  if [[ -n "$workdir" && -d "$workdir" ]]; then
    /usr/bin/rm -rf -- "$workdir"
  fi
}
trap cleanup EXIT

[[ "$EUID" -eq 0 ]] || fail 'ROOT_REQUIRED'
[[ "$(hostname -s)" == "$EXPECTED_HOST" ]] || fail 'HOST_IDENTITY_MISMATCH'
[[ -d "$RUNTIME_DIR" && ! -L "$RUNTIME_DIR" ]] || fail 'RUNTIME_DIR_INVALID'
[[ -f "/etc/systemd/system/$BRIDGE_UNIT" && ! -L "/etc/systemd/system/$BRIDGE_UNIT" ]] || fail 'BRIDGE_UNIT_NOT_FOUND'
[[ "$(grep -F 'ExecStart=/usr/bin/python3 /opt/voxelpacs/report-delivery-gateway/philips_folder_bridge.py' "/etc/systemd/system/$BRIDGE_UNIT" || true)" != '' ]] || fail 'BRIDGE_UNIT_BINDING_INVALID'

while [[ $# -gt 0 ]]; do
  case "$1" in
    --sha)
      [[ $# -ge 2 ]] || fail 'SHA_ARGUMENT_MISSING'
      source_sha="$2"
      shift 2
      ;;
    --source-sha256)
      [[ $# -ge 2 ]] || fail 'SOURCE_SHA256_ARGUMENT_MISSING'
      source_sha256="$2"
      shift 2
      ;;
    --dry-run)
      [[ -z "$operation" ]] || fail 'MULTIPLE_OPERATIONS'
      operation='dry-run'
      shift
      ;;
    --apply)
      [[ -z "$operation" ]] || fail 'MULTIPLE_OPERATIONS'
      operation='apply'
      shift
      ;;
    --reload)
      [[ "$operation" == 'apply' ]] || fail 'RELOAD_REQUIRES_APPLY'
      reload_bridge='YES'
      shift
      ;;
    --rollback-sha)
      [[ $# -ge 2 ]] || fail 'BACKUP_SHA256_ARGUMENT_MISSING'
      [[ -z "$operation" ]] || fail 'MULTIPLE_OPERATIONS'
      backup_sha="$2"
      operation='rollback'
      shift 2
      ;;
    *)
      usage
      fail 'ARGUMENTS_INVALID'
      ;;
  esac
done

case "$operation" in
  dry-run|apply)
    is_sha "$source_sha" || fail 'SHA_INVALID'
    is_sha256 "$source_sha256" || fail 'SOURCE_SHA256_INVALID'
    ;;
  rollback)
    is_sha256 "$backup_sha" || fail 'BACKUP_SHA256_INVALID'
    [[ "$reload_bridge" == 'NO' ]] || fail 'ROLLBACK_RELOAD_NOT_SUPPORTED'
    ;;
  *)
    usage
    fail 'OPERATION_REQUIRED'
    ;;
esac

/usr/bin/mkdir -p -- "$(/usr/bin/dirname "$LOCK_PATH")" "$TEMP_PARENT"
/usr/bin/chown root:root -- "$TEMP_PARENT"
/usr/bin/chmod 700 -- "$TEMP_PARENT"
exec {lock_fd}>"$LOCK_PATH"
/usr/bin/flock -n "$lock_fd" || fail 'PUBLISH_LOCK_BUSY'

current_sha256=''
if [[ -f "$RUNTIME_FILE" && ! -L "$RUNTIME_FILE" ]]; then
  current_sha256="$(/usr/bin/sha256sum -- "$RUNTIME_FILE" | /usr/bin/awk '{print $1}')"
else
  fail 'RUNTIME_FILE_INVALID'
fi

if [[ "$operation" == 'rollback' ]]; then
  backup_dir="$BACKUP_ROOT/$backup_sha"
  [[ -d "$backup_dir" && ! -L "$backup_dir" ]] || fail 'BACKUP_NOT_FOUND'
  backup_file="$backup_dir/philips_folder_bridge.py"
  [[ -f "$backup_file" && ! -L "$backup_file" ]] || fail 'BACKUP_FILE_NOT_FOUND'
  [[ "$(/usr/bin/stat -c '%u:%g:%a' "$backup_dir")" == '0:0:700' ]] || fail 'BACKUP_DIRECTORY_SECURITY_INVALID'
  [[ "$(/usr/bin/stat -c '%u:%g:%a' "$backup_file")" == '0:0:555' ]] || fail 'BACKUP_FILE_SECURITY_INVALID'
  [[ "$(/usr/bin/sha256sum -- "$backup_file" | /usr/bin/awk '{print $1}')" == "$backup_sha" ]] || fail 'BACKUP_CHECKSUM_INVALID'
  workdir="$(/usr/bin/mktemp -d "$TEMP_ROOT.XXXXXX")"
  /usr/bin/install -o root -g root -m 0555 -- "$backup_file" "$workdir/philips_folder_bridge.py.new"
  /usr/bin/mv -f -- "$workdir/philips_folder_bridge.py.new" "$RUNTIME_FILE"
  restored_sha256="$(/usr/bin/sha256sum -- "$RUNTIME_FILE" | /usr/bin/awk '{print $1}')"
  [[ "$restored_sha256" == "$backup_sha" ]] || fail 'ROLLBACK_CHECKSUM_MISMATCH'
  printf 'ROLLBACK=PASS\nRESTORED_SHA256_PREFIX=%s…\nBRIDGE_RELOAD=NOT_EXECUTED\nDICOM_CSTORE=NOT_CHANGED\nSMB=NOT_EXECUTED\nTRANSMISSION=NO\n' "${restored_sha256:0:12}"
  exit 0
fi

workdir="$(/usr/bin/mktemp -d "$TEMP_ROOT.XXXXXX")"
source_file="$workdir/philips_folder_bridge.py"
source_url="$REPOSITORY/$source_sha/$SOURCE_PATH"
/usr/bin/curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 "$source_url" --output "$source_file"
[[ "$(/usr/bin/sha256sum -- "$source_file" | /usr/bin/awk '{print $1}')" == "$source_sha256" ]] || fail 'SOURCE_CHECKSUM_MISMATCH'
/usr/bin/python3 - "$source_file" <<'PY'
import ast
import sys
ast.parse(open(sys.argv[1], encoding='utf-8').read(), filename=sys.argv[1])
PY

if [[ "$operation" == 'dry-run' ]]; then
  printf 'DRY_RUN=PASS\nSOURCE_SHA=%s\nSOURCE_SHA256_PREFIX=%s…\nCURRENT_SHA256_PREFIX=%s…\nBACKUP=NOT_CREATED\nINSTALL=NOT_EXECUTED\nBRIDGE_RELOAD=NOT_EXECUTED\nBRIDGE_POLICY_CHANGED=NO\nDICOM_CSTORE=NOT_CHANGED\nSMB=NOT_EXECUTED\nTRANSMISSION=NO\n' "$source_sha" "${source_sha256:0:12}" "${current_sha256:0:12}"
  exit 0
fi

[[ ! -L "$BACKUP_ROOT" ]] || fail 'BACKUP_ROOT_SYMLINK'
/usr/bin/mkdir -p -- "$BACKUP_ROOT"
/usr/bin/chown root:root -- "$BACKUP_ROOT"
/usr/bin/chmod 700 -- "$BACKUP_ROOT"
[[ "$(/usr/bin/stat -c '%u:%g:%a' "$BACKUP_ROOT")" == '0:0:700' ]] || fail 'BACKUP_ROOT_SECURITY_INVALID'
backup_dir="$BACKUP_ROOT/$current_sha256"
if [[ -e "$backup_dir" ]]; then
  [[ -d "$backup_dir" && ! -L "$backup_dir" ]] || fail 'BACKUP_EXISTING_INVALID'
  backup_file="$backup_dir/philips_folder_bridge.py"
  [[ -f "$backup_file" && "$(/usr/bin/sha256sum -- "$backup_file" | /usr/bin/awk '{print $1}')" == "$current_sha256" ]] || fail 'BACKUP_EXISTING_CHECKSUM_INVALID'
else
  backup_tmp="$BACKUP_ROOT/.tmp.$current_sha256.$$.${RANDOM}"
  /usr/bin/mkdir -- "$backup_tmp"
  /usr/bin/chown root:root -- "$backup_tmp"
  /usr/bin/chmod 700 -- "$backup_tmp"
  /usr/bin/install -o root -g root -m 0555 -- "$RUNTIME_FILE" "$backup_tmp/philips_folder_bridge.py"
  printf '%s\n' "$current_sha256" > "$backup_tmp/source-sha256"
  /usr/bin/chown root:root -- "$backup_tmp/source-sha256"
  /usr/bin/chmod 600 -- "$backup_tmp/source-sha256"
  /usr/bin/mv -- "$backup_tmp" "$backup_dir"
fi

/usr/bin/install -o root -g root -m 0555 -- "$source_file" "$workdir/philips_folder_bridge.py.new"
/usr/bin/mv -f -- "$workdir/philips_folder_bridge.py.new" "$RUNTIME_FILE"
installed_sha256="$(/usr/bin/sha256sum -- "$RUNTIME_FILE" | /usr/bin/awk '{print $1}')"
[[ "$installed_sha256" == "$source_sha256" ]] || fail 'INSTALLED_CHECKSUM_MISMATCH'

if [[ "$reload_bridge" == 'YES' ]]; then
  /usr/bin/systemctl restart "$BRIDGE_UNIT"
  /usr/bin/systemctl is-active --quiet "$BRIDGE_UNIT" || fail 'BRIDGE_RESTART_FAILED'
  [[ "$(/usr/bin/pgrep -fc '[p]hilips_folder_bridge.py')" -eq 1 ]] || fail 'BRIDGE_INSTANCE_COUNT_INVALID'
  bridge_reload='PASS'
else
  bridge_reload='NOT_EXECUTED'
fi

printf 'APPLY=PASS\nSOURCE_SHA=%s\nSOURCE_SHA256_PREFIX=%s…\nINSTALLED_SHA256_PREFIX=%s…\nBACKUP=PASS\nBACKUP_SHA256_PREFIX=%s…\nBRIDGE_RELOAD=%s\nBRIDGE_INSTANCES=%s\nBRIDGE_POLICY_CHANGED=NO\nDICOM_CSTORE=NOT_CHANGED\nSMB=NOT_EXECUTED\nTRANSMISSION=NO\n' "$source_sha" "${source_sha256:0:12}" "${installed_sha256:0:12}" "${current_sha256:0:12}" "$bridge_reload" "$(/usr/bin/pgrep -fc '[p]hilips_folder_bridge.py')"
