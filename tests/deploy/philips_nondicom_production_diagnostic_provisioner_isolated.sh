#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_provisioner="$repo/scripts/provision-philips-nondicom-production-diagnostic.sh"
source_diagnostic="$repo/bin/philips_nondicom_production_diagnostic.php"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }
run_as_root() {
  if [[ "$EUID" -eq 0 ]]; then "$@"; else sudo -n "$@"; fi
}

fixture_root="$(run_as_root mktemp -d /run/voxelpacs-philips-nondicom-diagnostic.XXXXXX)"
cleanup() {
  [[ "$fixture_root" == /run/voxelpacs-philips-nondicom-diagnostic.* && -d "$fixture_root" ]] || return 0
  run_as_root find "$fixture_root" -depth -type f -delete
  run_as_root find "$fixture_root" -depth -type l -delete
  run_as_root find "$fixture_root" -depth -type d -empty -delete
}
trap cleanup EXIT

run_as_root mkdir -p "$fixture_root/scripts" "$fixture_root/bin" "$fixture_root/target" "$fixture_root/backups"
run_as_root cp -- "$source_provisioner" "$fixture_root/scripts/provision-philips-nondicom-production-diagnostic.sh"
run_as_root cp -- "$source_diagnostic" "$fixture_root/bin/philips_nondicom_production_diagnostic.php"
run_as_root sed -i \
  -e "s|readonly TARGET_PARENT='/opt/voxelpacs/diagnostics/philips-nondicom'|readonly TARGET_PARENT='$fixture_root/target'|" \
  -e "s|readonly BACKUP_ROOT='/var/backups/voxelpacs/diagnostics/philips-nondicom-production-diagnostic'|readonly BACKUP_ROOT='$fixture_root/backups/diagnostic'|" \
  -e "s|readonly LOCK_DIR='/run/voxelpacs-philips-nondicom-production-diagnostic-provision'|readonly LOCK_DIR='$fixture_root/lock'|" \
  "$fixture_root/scripts/provision-philips-nondicom-production-diagnostic.sh"
run_as_root chmod 0755 "$fixture_root/scripts/provision-philips-nondicom-production-diagnostic.sh"
run_as_root chmod 0644 "$fixture_root/bin/philips_nondicom_production_diagnostic.php"
run_as_root sh -c "printf '%s\\n' '/target/philips_nondicom_production_diagnostic.php' '/backups/' '/lock/' > '$fixture_root/.gitignore'"
run_as_root chown -R root:root "$fixture_root"
run_as_root git -C "$fixture_root" init -q
run_as_root git -C "$fixture_root" config user.email test@example.invalid
run_as_root git -C "$fixture_root" config user.name 'VOXEL Test'
run_as_root git -C "$fixture_root" add .gitignore bin scripts
run_as_root git -C "$fixture_root" commit -q -m 'test fixture'
sha="$(run_as_root git -C "$fixture_root" rev-parse HEAD)"
provisioner="$fixture_root/scripts/provision-philips-nondicom-production-diagnostic.sh"
fixture_source="$fixture_root/bin/philips_nondicom_production_diagnostic.php"
target="$fixture_root/target/philips_nondicom_production_diagnostic.php"
backup_root="$fixture_root/backups/diagnostic"

run_as_root sh -c "printf '%s\\n' 'SOURCE_TAMPER' >> '$fixture_source'"
run_as_root git -C "$fixture_root" update-index --assume-unchanged bin/philips_nondicom_production_diagnostic.php
set +e
source_tamper_output="$(run_as_root "$provisioner" --expected-sha "$sha" --dry-run 2>&1)"
source_tamper_rc=$?
set -e
[[ "$source_tamper_rc" -ne 0 ]] || fail source_tamper_accepted
grep -Fq 'SOURCE_BLOB_MISMATCH' <<<"$source_tamper_output" || fail source_tamper_reason_missing
run_as_root sh -c "git -C '$fixture_root' show '$sha:bin/philips_nondicom_production_diagnostic.php' > '$fixture_source'"
run_as_root chmod 0644 "$fixture_source"
run_as_root git -C "$fixture_root" update-index --no-assume-unchanged bin/philips_nondicom_production_diagnostic.php

run_as_root sh -c "printf '%s\\n' 'PROVISIONER_TAMPER' >> '$provisioner'"
run_as_root git -C "$fixture_root" update-index --assume-unchanged scripts/provision-philips-nondicom-production-diagnostic.sh
set +e
provisioner_tamper_output="$(run_as_root "$provisioner" --expected-sha "$sha" --dry-run 2>&1)"
provisioner_tamper_rc=$?
set -e
[[ "$provisioner_tamper_rc" -ne 0 ]] || fail provisioner_tamper_accepted
grep -Fq 'PROVISIONER_BLOB_MISMATCH' <<<"$provisioner_tamper_output" || fail provisioner_tamper_reason_missing
run_as_root sh -c "git -C '$fixture_root' show '$sha:scripts/provision-philips-nondicom-production-diagnostic.sh' > '$provisioner'"
run_as_root chmod 0755 "$provisioner"
run_as_root git -C "$fixture_root" update-index --no-assume-unchanged scripts/provision-philips-nondicom-production-diagnostic.sh

before_count="$(run_as_root find "$fixture_root" -mindepth 1 -maxdepth 2 -type f | wc -l)"
dry_run_output="$(run_as_root "$provisioner" --expected-sha "$sha" --dry-run)"
grep -Fxq 'DRY_RUN=PASS' <<<"$dry_run_output" || fail dry_run_not_pass
[[ ! -e "$fixture_root/lock" ]] || fail dry_run_created_lock
[[ ! -e "$backup_root" ]] || fail dry_run_created_backup
after_count="$(run_as_root find "$fixture_root" -mindepth 1 -maxdepth 2 -type f | wc -l)"
[[ "$before_count" == "$after_count" ]] || fail dry_run_changed_fixture

run_as_root ln -s "$fixture_root/target/missing-target" "$target"
set +e
symlink_output="$(run_as_root "$provisioner" --expected-sha "$sha" --dry-run 2>&1)"
symlink_rc=$?
set -e
[[ "$symlink_rc" -ne 0 ]] || fail dangling_symlink_accepted
grep -Fq 'TARGET_SYMLINK_INVALID' <<<"$symlink_output" || fail dangling_symlink_reason_missing
run_as_root unlink -- "$target"

backup_link_target="$fixture_root/backups/pointed-away"
run_as_root mkdir -- "$backup_link_target"
run_as_root chmod 0700 -- "$backup_link_target"
run_as_root ln -s "$backup_link_target" "$backup_root"
before_backup_link_metadata="$(run_as_root stat -c '%u:%g:%a' "$backup_link_target")"
set +e
backup_symlink_output="$(run_as_root "$provisioner" --expected-sha "$sha" --install 2>&1)"
backup_symlink_rc=$?
set -e
[[ "$backup_symlink_rc" -ne 0 ]] || fail backup_symlink_accepted
grep -Fq 'BACKUP_ROOT_PATH_INVALID' <<<"$backup_symlink_output" || fail backup_symlink_reason_missing
after_backup_link_metadata="$(run_as_root stat -c '%u:%g:%a' "$backup_link_target")"
[[ "$before_backup_link_metadata" == "$after_backup_link_metadata" ]] || fail backup_symlink_target_mutated
run_as_root unlink -- "$backup_root"

install_output="$(run_as_root "$provisioner" --expected-sha "$sha" --install)"
grep -Fxq 'DIAGNOSTIC_PROVISION=PASS' <<<"$install_output" || fail install_not_pass
grep -Fxq 'BACKUP=CREATED' <<<"$install_output" || fail backup_not_created
target_metadata="$(run_as_root stat -c '%u:%g:%a' "$target" 2>/dev/null || true)"
[[ "$target_metadata" == '0:0:644' ]] || fail target_security_invalid
backup_id="$(awk -F= '$1 == "BACKUP_ID" {print $2; exit}' <<<"$install_output")"
[[ "$backup_id" =~ ^[0-9a-f]{64}$ ]] || fail backup_id_invalid
backup_metadata="$(run_as_root stat -c '%u:%g:%a' "$backup_root/$backup_id" 2>/dev/null || true)"
[[ "$backup_metadata" == '0:0:700' ]] || fail backup_directory_missing

run_as_root sh -c "printf '%s\\n' 'LATER_ADMINISTRATIVE_CHANGE' > '$target'"
set +e
changed_rollback_output="$(run_as_root "$provisioner" --rollback --backup-id "$backup_id" 2>&1)"
changed_rollback_rc=$?
set -e
[[ "$changed_rollback_rc" -ne 0 ]] || fail changed_target_rollback_accepted
grep -Fq 'ROLLBACK_TARGET_CHANGED' <<<"$changed_rollback_output" || fail changed_target_reason_missing

run_as_root cp -- "$source_diagnostic" "$target"
run_as_root chown root:root "$target"
run_as_root chmod 0644 "$target"
rollback_output="$(run_as_root "$provisioner" --rollback --backup-id "$backup_id")"
grep -Fxq 'ROLLBACK=PASS' <<<"$rollback_output" || fail rollback_not_pass
target_after_rollback="$(run_as_root stat -c '%F' "$target" 2>/dev/null || true)"
[[ -z "$target_after_rollback" ]] || fail absent_target_not_restored

run_as_root sh -c "printf '%s\\n' 'LEGACY_DIAGNOSTIC_CONTENT' > '$target'"
run_as_root chown root:root "$target"
run_as_root chmod 0600 "$target"
run_as_root touch -d '@1700000000' "$target"
legacy_hash="$(run_as_root sha256sum "$target" | awk '{print $1}')"
upgrade_output="$(run_as_root "$provisioner" --expected-sha "$sha" --install --upgrade)"
grep -Fxq 'DIAGNOSTIC_PROVISION=PASS' <<<"$upgrade_output" || fail upgrade_not_pass
upgrade_backup_id="$(awk -F= '$1 == "BACKUP_ID" {print $2; exit}' <<<"$upgrade_output")"
[[ "$upgrade_backup_id" =~ ^[0-9a-f]{64}$ ]] || fail upgrade_backup_id_invalid
upgrade_backup_file="$backup_root/$upgrade_backup_id/target.php"
upgrade_backup_hash="$(run_as_root sha256sum "$upgrade_backup_file" | awk '{print $1}')"
[[ "$upgrade_backup_hash" == "$legacy_hash" ]] || fail backup_checksum_mismatch
upgrade_target_metadata="$(run_as_root stat -c '%u:%g:%a' "$target")"
[[ "$upgrade_target_metadata" == '0:0:644' ]] || fail upgrade_target_security_invalid
upgrade_rollback_output="$(run_as_root "$provisioner" --rollback --backup-id "$upgrade_backup_id")"
grep -Fxq 'ROLLBACK=PASS' <<<"$upgrade_rollback_output" || fail upgrade_rollback_not_pass
restored_metadata="$(run_as_root stat -c '%u:%g:%a:%Y' "$target")"
[[ "$restored_metadata" == '0:0:600:1700000000' ]] || fail metadata_not_restored
restored_hash="$(run_as_root sha256sum "$target" | awk '{print $1}')"
[[ "$restored_hash" == "$legacy_hash" ]] || fail content_not_restored

set +e
invalid_output="$(run_as_root "$provisioner" --expected-sha '0000000000000000000000000000000000000000' --dry-run 2>&1)"
invalid_rc=$?
set -e
[[ "$invalid_rc" -ne 0 ]] || fail invalid_sha_accepted
grep -Fq 'GIT_SHA_MISMATCH' <<<"$invalid_output" || fail invalid_sha_reason_missing
target_after_invalid_hash="$(run_as_root sha256sum "$target" | awk '{print $1}')"
[[ "$target_after_invalid_hash" == "$legacy_hash" ]] || fail invalid_sha_mutated_target

printf 'TEST=PASS\nISOLATED=PASS\nDRY_RUN_NO_MUTATION=PASS\nINSTALL_BACKUP=PASS\nROLLBACK=PASS\nINVALID_SHA_FAIL_CLOSED=PASS\nPRODUCTION_CHANGED=NO\n'
