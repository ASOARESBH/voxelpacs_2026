#!/usr/bin/env bash
# Política temporária para permitir exclusivamente o job Philips 272 na bridge.
# --preview não altera arquivos; --apply/--rollback exigem autorização operacional separada.
# Nenhuma opção chama worker, bridge, SMB, rede ou systemctl.
set -euo pipefail
umask 077

readonly EXPECTED_HOST='gateway-dicom-01'
readonly ENV_FILE='/etc/voxelpacs/philips-folder-bridge.env'
readonly CONFIG_DIR='/etc/voxelpacs/philips-folder'
readonly BACKUP_ROOT='/var/backups/voxelpacs/philips-folder-policy'
readonly TARGET_JOB_ID='272'
readonly EXPECTED_DESTINATION_ID='6'

usage() {
  printf 'Uso: %s --preview|--apply|--rollback\n' "${0##*/}" >&2
  exit 64
}

require_root_and_host() {
  [[ "${EUID}" -eq 0 ]] || { printf 'POLICY_PRIVILEGE=root_required\n' >&2; exit 77; }
  [[ "$(hostname -s)" == "$EXPECTED_HOST" ]] || { printf 'POLICY_GATEWAY=identity_mismatch\n' >&2; exit 65; }
  [[ -f "$ENV_FILE" ]] || { printf 'POLICY_ENV=absent\n' >&2; exit 66; }
  [[ "$(stat -c '%U:%G:%a' "$ENV_FILE")" == 'root:root:600' ]] || { printf 'POLICY_ENV=invalid_protection\n' >&2; exit 67; }
  grep -Fxq 'PHILIPS_FOLDER_MODE=single_test' "$ENV_FILE" || { printf 'POLICY_MODE=not_single_test\n' >&2; exit 68; }
  grep -Fxq "PHILIPS_FOLDER_DESTINATION_ID=$EXPECTED_DESTINATION_ID" "$ENV_FILE" || { printf 'POLICY_DESTINATION=not_expected\n' >&2; exit 68; }
  for protected in "$CONFIG_DIR/ca.key" "$CONFIG_DIR/server.key" "$CONFIG_DIR/client.key" "$CONFIG_DIR/hmac" "$CONFIG_DIR/envelope-private.b64" "$CONFIG_DIR/envelope-public.b64"; do
    [[ -f "$protected" ]] || { printf 'POLICY_PROTECTED_MATERIAL=absent\n' >&2; exit 69; }
  done
}

current_allowed_job() {
  local value
  value="$(sed -n 's/^PHILIPS_FOLDER_ALLOW_JOB_ID=//p' "$ENV_FILE" | tail -n 1)"
  case "$value" in
    ''|0) printf '%s' 'none' ;;
    "$TARGET_JOB_ID") printf '%s' 'target' ;;
    *) printf '%s' 'other' ;;
  esac
}

emit_preview() {
  printf '%s\n' '=== PHILIPS_FOLDER_CONTROLLED_JOB_PREVIEW ==='
  printf '%s\n' 'POLICY_GATEWAY=ready'
  printf '%s\n' 'POLICY_MODE=single_test'
  printf '%s\n' "POLICY_CURRENT_JOB=$(current_allowed_job)"
  printf '%s\n' 'POLICY_TARGET_JOB=authorized_internal_job'
  printf '%s\n' 'POLICY_DESTINATION=ready'
  printf '%s\n' 'POLICY_CHANGE=env_allow_job_only'
  printf '%s\n' 'RUNTIME_RELOAD=not_performed'
  printf '%s\n' 'WORKER_EXECUTION=not_performed'
  printf '%s\n' 'SMB=not_performed'
  printf '%s\n' 'PHILIPS_FOLDER_CONTROLLED_JOB_PREVIEW_OK'
}

backup_policy() {
  local timestamp backup_dir
  timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
  backup_dir="$BACKUP_ROOT/job-${TARGET_JOB_ID}-${timestamp}"
  install -d -o root -g root -m 0700 "$backup_dir"
  install -o root -g root -m 0600 "$ENV_FILE" "$backup_dir/philips-folder-bridge.env"
  printf '%s\n' "$backup_dir" > "$BACKUP_ROOT/latest-job-${TARGET_JOB_ID}"
  chown root:root "$BACKUP_ROOT/latest-job-${TARGET_JOB_ID}"
  chmod 0600 "$BACKUP_ROOT/latest-job-${TARGET_JOB_ID}"
  printf '%s\n' "$backup_dir"
}

apply_policy() {
  local backup_dir temporary
  [[ "$(current_allowed_job)" == 'none' ]] || { printf 'POLICY_APPLY=precondition_failed\n' >&2; exit 70; }
  install -d -o root -g root -m 0700 "$BACKUP_ROOT"
  backup_dir="$(backup_policy)"
  temporary="$(mktemp "${ENV_FILE}.tmp.XXXXXX")"
  trap 'rm -f "$temporary"' EXIT
  awk -v target="$TARGET_JOB_ID" '
    /^PHILIPS_FOLDER_ALLOW_JOB_ID=/ {next}
    {print}
    END {print "PHILIPS_FOLDER_ALLOW_JOB_ID=" target}
  ' "$ENV_FILE" > "$temporary"
  chown root:root "$temporary"
  chmod 0600 "$temporary"
  mv -f "$temporary" "$ENV_FILE"
  trap - EXIT
  grep -Fxq "PHILIPS_FOLDER_ALLOW_JOB_ID=$TARGET_JOB_ID" "$ENV_FILE" || { printf 'POLICY_APPLY=verification_failed\n' >&2; exit 71; }
  printf '%s\n' 'POLICY_APPLY=ready_for_separate_reload'
  printf '%s\n' 'POLICY_BACKUP=recorded'
  printf '%s\n' 'RUNTIME_RELOAD=not_performed'
}

rollback_policy() {
  local pointer backup_dir source temporary
  pointer="$BACKUP_ROOT/latest-job-${TARGET_JOB_ID}"
  [[ -f "$pointer" ]] || { printf 'POLICY_ROLLBACK=backup_unavailable\n' >&2; exit 72; }
  backup_dir="$(cat "$pointer")"
  source="$backup_dir/philips-folder-bridge.env"
  [[ -f "$source" ]] || { printf 'POLICY_ROLLBACK=backup_unavailable\n' >&2; exit 72; }
  [[ "$(stat -c '%U:%G:%a' "$source")" == 'root:root:600' ]] || { printf 'POLICY_ROLLBACK=invalid_backup\n' >&2; exit 73; }
  temporary="$(mktemp "${ENV_FILE}.tmp.XXXXXX")"
  trap 'rm -f "$temporary"' EXIT
  install -o root -g root -m 0600 "$source" "$temporary"
  mv -f "$temporary" "$ENV_FILE"
  trap - EXIT
  printf '%s\n' 'POLICY_ROLLBACK=completed'
  printf '%s\n' 'RUNTIME_RELOAD=not_performed'
}

[[ "$#" -eq 1 ]] || usage
require_root_and_host
case "$1" in
  --preview) emit_preview ;;
  --apply) apply_policy ;;
  --rollback) rollback_policy ;;
  *) usage ;;
esac
