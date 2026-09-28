#!/usr/bin/env bash
# Configuração oficial e reversível das flags do Report Delivery.
# Este script não reinicia serviços, não acessa banco, não altera a Bridge,
# não cria jobs e não executa qualquer transporte externo.
set -Eeuo pipefail

readonly DEFAULT_ENV_FILE='/var/www/voxelpacs/app/.env'
readonly DEFAULT_BACKUP_ROOT='/var/backups/voxelpacs/config'
readonly MANAGED_KEYS=(
  VOXEL_REPORT_DELIVERY_HUB_ENABLED
  VOXEL_REPORT_DELIVERY_REQUESTS_ENABLED
  PHILIPS_FOLDER_DELIVERY_ENABLED
  PHILIPS_NON_DICOM_DELIVERY_ENABLED
  PHILIPS_NON_DICOM_SMB_TEST_ENABLED
  PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED
  VOXEL_REPORT_DELIVERY_WORKER_KILL_SWITCH
)

mode=''
rollback_dir=''
env_file="$DEFAULT_ENV_FILE"
backup_root="${VOXEL_RUNTIME_FLAGS_BACKUP_ROOT:-$DEFAULT_BACKUP_ROOT}"
declare -a requested=()
declare -a normalized=()
declare -A requested_seen=()

usage() {
  cat <<'USAGE'
Uso:
  configure-report-delivery-runtime.sh --dry-run KEY=VALUE [...]
  configure-report-delivery-runtime.sh --apply KEY=VALUE [...]
  configure-report-delivery-runtime.sh --rollback BACKUP_DIRECTORY

Somente flags booleanas allowlisted são aceitas. --dry-run não grava nada.
--apply cria backup root-only e altera apenas as chaves informadas.
--rollback restaura o .env.before de um backup criado por --apply.
Nenhuma forma executa reload/restart; isso permanece uma etapa autorizada separada.
USAGE
}

fail() {
  printf 'CONFIGURATION_ERROR=%s\n' "$1" >&2
  exit 64
}

is_managed_key() {
  local candidate="$1"
  local key
  for key in "${MANAGED_KEYS[@]}"; do
    [[ "$candidate" == "$key" ]] && return 0
  done
  return 1
}

normalize_assignment() {
  local assignment="$1"
  local key value normalized_value
  [[ "$assignment" == *=* ]] || fail 'assignment_invalid'
  key="${assignment%%=*}"
  value="${assignment#*=}"
  is_managed_key "$key" || fail 'key_not_allowlisted'
  [[ -z "${requested_seen[$key]:-}" ]] || fail 'duplicate_assignment'
  requested_seen[$key]=1
  case "${value,,}" in
    1|true|yes|on) normalized_value='true' ;;
    0|false|no|off) normalized_value='false' ;;
    *) fail 'boolean_value_invalid' ;;
  esac
  requested+=("$key")
  normalized+=("$key=$normalized_value")
}

validate_source() {
  [[ -f "$env_file" && -r "$env_file" ]] || fail 'environment_file_unavailable'
  local key count
  for key in "${requested[@]}"; do
    count="$(awk -F= -v key="$key" '$1 ~ /^[[:space:]]*#/ { next } { candidate=$1; gsub(/^[[:space:]]+|[[:space:]]+$/, "", candidate); if (candidate == key) count++ } END { print count + 0 }' "$env_file")"
    [[ "$count" -le 1 ]] || fail 'duplicate_managed_key'
  done
}

print_plan() {
  local item key value
  printf 'REPORT_DELIVERY_RUNTIME_CONFIGURATION=%s\n' "${mode^^}"
  printf 'TARGET_ENV=validated\n'
  for item in "${normalized[@]}"; do
    key="${item%%=*}"
    value="${item#*=}"
    printf 'PLAN_%s=%s\n' "$key" "${value^^}"
  done
}

apply_changes() {
  [[ "$EUID" -eq 0 ]] || fail 'root_required_for_apply'
  local stamp backup_dir assignment_file temp_env owner_uid owner_gid file_mode item
  stamp="$(date -u +%Y%m%dT%H%M%SZ)"
  install -d -o root -g root -m 0700 "$backup_root"
  backup_dir="$(mktemp -d "$backup_root/report-delivery-runtime-$stamp.XXXXXX")"
  cp -p "$env_file" "$backup_dir/.env.before"
  chown root:root "$backup_dir/.env.before"
  chmod 0600 "$backup_dir/.env.before"
  sha256sum "$backup_dir/.env.before" > "$backup_dir/.env.before.sha256"
  chown root:root "$backup_dir/.env.before.sha256"
  chmod 0600 "$backup_dir/.env.before.sha256"
  assignment_file="$(mktemp "$backup_dir/assignments.XXXXXX")"
  printf '%s\n' "${normalized[@]}" > "$assignment_file"
  chmod 0600 "$assignment_file"
  temp_env="$(mktemp "${env_file}.runtime.XXXXXX")"
  trap 'rm -f "$assignment_file" "$temp_env"' RETURN

  awk -F= -v assignment_file="$assignment_file" '
    BEGIN {
      while ((getline line < assignment_file) > 0) {
        split(line, pair, "=")
        desired[pair[1]] = pair[2]
      }
      close(assignment_file)
    }
    {
      candidate = $1
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", candidate)
      if (candidate in desired) {
        print candidate "=" desired[candidate]
        seen[candidate]++
        next
      }
      print $0
    }
    END {
      for (key in desired) {
        if (!seen[key]) print key "=" desired[key]
      }
    }
  ' "$env_file" > "$temp_env"

  owner_uid="$(stat -c '%u' "$env_file")"
  owner_gid="$(stat -c '%g' "$env_file")"
  file_mode="$(stat -c '%a' "$env_file")"
  chown "$owner_uid:$owner_gid" "$temp_env"
  chmod "$file_mode" "$temp_env"
  mv -f "$temp_env" "$env_file"
  trap - RETURN
  rm -f "$assignment_file"
  printf 'BACKUP_ID=%s\n' "$(basename "$backup_dir")"
  printf 'BACKUP=CREATED\n'
  printf 'APPLY=PASS\n'
  printf 'RELOAD=NOT_EXECUTED\n'
}

rollback_changes() {
  [[ "$EUID" -eq 0 ]] || fail 'root_required_for_rollback'
  [[ -n "$rollback_dir" ]] || fail 'rollback_directory_required'
  local source temp_env owner_uid owner_gid file_mode backup_root_real rollback_dir_real
  backup_root_real="$(realpath "$backup_root" 2>/dev/null || true)"
  rollback_dir_real="$(realpath "$rollback_dir" 2>/dev/null || true)"
  [[ -n "$backup_root_real" && -n "$rollback_dir_real" ]] || fail 'rollback_directory_unavailable'
  [[ "$(stat -c '%u:%a' "$backup_root_real")" == '0:700' ]] || fail 'rollback_backup_root_not_root_only'
  [[ "$(stat -c '%u:%a' "$rollback_dir_real")" == '0:700' ]] || fail 'rollback_directory_not_root_only'
  [[ "$rollback_dir_real" == "$backup_root_real"/* ]] || fail 'rollback_directory_outside_backup_root'
  source="$rollback_dir_real/.env.before"
  [[ -f "$source" && -r "$source" ]] || fail 'rollback_backup_unavailable'
  [[ "$(stat -c '%u:%a' "$source")" == '0:600' ]] || fail 'rollback_backup_not_root_only'
  temp_env="$(mktemp "${env_file}.rollback.XXXXXX")"
  cp "$source" "$temp_env"
  owner_uid="$(stat -c '%u' "$env_file")"
  owner_gid="$(stat -c '%g' "$env_file")"
  file_mode="$(stat -c '%a' "$env_file")"
  chown "$owner_uid:$owner_gid" "$temp_env"
  chmod "$file_mode" "$temp_env"
  mv -f "$temp_env" "$env_file"
  printf 'ROLLBACK=PASS\n'
  printf 'RELOAD=NOT_EXECUTED\n'
}

[[ "$#" -gt 0 ]] || { usage; exit 64; }
case "$1" in
  --dry-run|--apply)
    mode="${1#--}"
    shift
    [[ "$#" -gt 0 ]] || fail 'assignment_required'
    for assignment in "$@"; do
      normalize_assignment "$assignment"
    done
    validate_source
    print_plan
    [[ "$mode" == 'dry-run' ]] && exit 0
    apply_changes
    ;;
  --rollback)
    mode='rollback'
    [[ "$#" -eq 2 ]] || fail 'rollback_argument_required'
    rollback_dir="$2"
    validate_source
    print_plan
    rollback_changes
    ;;
  --help|-h)
    usage
    ;;
  *)
    usage >&2
    exit 64
    ;;
esac
