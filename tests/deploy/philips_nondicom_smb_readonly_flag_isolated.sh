#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_helper="$repo/ops/deploy/voxelpacs-philips-nondicom-smb-readonly-flag"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }
run_as_root() {
  if [[ "$EUID" -eq 0 ]]; then "$@"; else sudo -n "$@"; fi
}

fixture_root="$(mktemp -d /tmp/voxelpacs-smb-readonly-flag.XXXXXX)"
cleanup() {
  [[ "$fixture_root" == /tmp/voxelpacs-smb-readonly-flag.* && -d "$fixture_root" ]] || return 0
  run_as_root find "$fixture_root" -depth -type f -delete
  run_as_root find "$fixture_root" -depth -type l -delete
  run_as_root find "$fixture_root" -depth -type d -empty -delete
}
trap cleanup EXIT

env_file="$fixture_root/app/.env"
backup_root="$fixture_root/backups/config"
lock_path="$fixture_root/lock/helper.lock"
mkdir -p "$(dirname "$env_file")" "$(dirname "$lock_path")" "$(dirname "$backup_root")"
cat > "$env_file" <<'EOF'
SAFE_FLAG=true
PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED=true
OTHER_FLAG=false
SECRET_SENTINEL=not-exposed
EOF
chmod 0640 "$env_file"

fixture_helper="$fixture_root/helper"
sed \
  -e "s|readonly ENV_FILE='/var/www/voxelpacs/app/.env'|readonly ENV_FILE='$env_file'|" \
  -e "s|readonly BACKUP_ROOT='/var/backups/voxelpacs/config'|readonly BACKUP_ROOT='$backup_root'|" \
  -e "s|readonly LOCK_PATH='/run/lock/voxelpacs-philips-nondicom-smb-readonly-flag.lock'|readonly LOCK_PATH='$lock_path'|" \
  "$source_helper" > "$fixture_helper"
chmod 0755 "$fixture_helper"

run_helper() {
  if [[ "$EUID" -eq 0 ]]; then
    env -u SUDO_USER bash "$fixture_helper" "$@"
  else
    sudo -n env -u SUDO_USER bash "$fixture_helper" "$@"
  fi
}

before_env="$(sha256sum "$env_file")"
status_output="$(run_helper --status)"
grep -Fxq 'TARGET_STATE=ON' <<<"$status_output" || fail status_state
grep -Fxq 'OTHER_FLAGS_PRESERVED=YES' <<<"$status_output" || fail status_integrity
! grep -Fq 'SECRET_SENTINEL' <<<"$status_output" || fail status_exposed_secret

dry_output="$(run_helper --dry-run)"
grep -Fxq 'DRY_RUN=PASS' <<<"$dry_output" || fail dry_run
[[ "$(sha256sum "$env_file")" == "$before_env" ]] || fail dry_run_mutated_env
[[ ! -d "$backup_root" ]] || fail dry_run_created_backup

apply_output="$(run_helper --apply)"
grep -Fxq 'APPLY=PASS' <<<"$apply_output" || fail apply
backup_id="$(awk -F= '$1 == "BACKUP_ID" {print $2; exit}' <<<"$apply_output")"
[[ "$backup_id" =~ ^[0-9a-f]{64}$ ]] || fail backup_id
backup_dir="$backup_root/$backup_id"
run_as_root test -d "$backup_dir" || fail backup_missing
grep -Fxq 'PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED=false' "$env_file" || fail target_not_off
grep -Fxq 'SAFE_FLAG=true' "$env_file" || fail other_flag_changed
grep -Fxq 'OTHER_FLAG=false' "$env_file" || fail other_flag_changed_2
! grep -Fq 'SECRET_SENTINEL' <<<"$apply_output" || fail apply_exposed_secret
[[ "$(stat -c '%a' "$env_file")" == '640' ]] || fail env_mode_not_preserved
[[ "$(stat -c '%u:%g' "$env_file")" == "$(stat -c '%u:%g' "$fixture_root/app/.env")" ]] || fail env_owner_unstable

run_as_root test -f "$backup_dir/.env.before" || fail backup_env_missing
run_as_root test -f "$backup_dir/.env.before.sha256" || fail backup_checksum_missing
[[ "$(run_as_root stat -c '%u:%a' "$backup_dir")" == '0:700' ]] || fail backup_dir_security

set +e
invalid_output="$(run_helper --rollback --backup-id ../escape 2>&1)"
invalid_rc=$?
set -e
[[ "$invalid_rc" -ne 0 ]] || fail traversal_accepted
grep -Fq 'BACKUP_ID_INVALID' <<<"$invalid_output" || fail traversal_reason

rollback_output="$(run_helper --rollback --backup-id "$backup_id")"
grep -Fxq 'ROLLBACK=PASS' <<<"$rollback_output" || fail rollback
[[ "$(sha256sum "$env_file")" == "$before_env" ]] || fail rollback_not_exact

grep -Fq 'TARGET_STATE=ON' <<<"$(run_helper --status)" || fail rollback_state

printf 'TEST=PASS\nISOLATED=PASS\nDRY_RUN_NON_MUTATING=PASS\nAPPLY_SINGLE_KEY=PASS\nBACKUP_ROOT_ONLY=PASS\nROLLBACK=PASS\nTRAVERSAL_REJECTED=PASS\nRAW_SECRET=NOT_EXPOSED\nPRODUCTION_CHANGED=NO\n'
