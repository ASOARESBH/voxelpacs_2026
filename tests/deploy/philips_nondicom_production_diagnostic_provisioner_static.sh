#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
provisioner="$repo/scripts/provision-philips-nondicom-production-diagnostic.sh"
source="$repo/bin/philips_nondicom_production_diagnostic.php"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

[[ -f "$provisioner" && ! -L "$provisioner" ]] || fail provisioner_missing
[[ -f "$source" && ! -L "$source" ]] || fail diagnostic_source_missing

bash -n "$provisioner" || fail "syntax:$provisioner"

grep -Fqx "readonly SOURCE_RELATIVE_PATH='bin/philips_nondicom_production_diagnostic.php'" "$provisioner" || fail source_target_not_fixed
grep -Fqx "readonly TARGET_PARENT='/opt/voxelpacs/diagnostics/philips-nondicom'" "$provisioner" || fail install_target_not_fixed
grep -Fqx "readonly BACKUP_ROOT='/var/backups/voxelpacs/diagnostics/philips-nondicom-production-diagnostic'" "$provisioner" || fail backup_root_not_fixed
grep -Fq -- "--expected-sha" "$provisioner" || fail expected_sha_interface_missing
grep -Fq -- "--dry-run" "$provisioner" || fail dry_run_interface_missing
grep -Fq -- "--upgrade" "$provisioner" || fail upgrade_interface_missing
grep -Fq -- "--rollback" "$provisioner" || fail rollback_interface_missing
grep -Fq 'WORKTREE_NOT_CLEAN' "$provisioner" || fail clean_tree_gate_missing
grep -Fq 'SOURCE_NOT_IN_SHA' "$provisioner" || fail source_sha_gate_missing
grep -Fq "validate_blob \"\$SOURCE_RELATIVE_PATH\" 'SOURCE'" "$provisioner" || fail source_blob_check_missing
grep -Fq "validate_blob \"\$PROVISIONER_RELATIVE_PATH\" 'PROVISIONER'" "$provisioner" || fail provisioner_blob_check_missing
grep -Fq 'BLOB_MISMATCH' "$provisioner" || fail blob_mismatch_reason_missing
grep -Fq "git -C \"\$ROOT\" show \"\$expected_sha:\$SOURCE_RELATIVE_PATH\"" "$provisioner" || fail git_show_materialization_missing
grep -Fq 'DRIFT_GATE=NOT_MODIFIED' "$provisioner" || fail drift_separation_missing
grep -Fq 'APPLICATION_DEPLOY=NOT_EXECUTED' "$provisioner" || fail application_separation_missing
grep -Fq 'DATABASE_CHANGED=NO' "$provisioner" || fail database_separation_missing
grep -Fq 'TRANSMISSION=NO' "$provisioner" || fail transmission_separation_missing
grep -Fq 'BACKUP=CREATED' "$provisioner" || fail backup_contract_missing
grep -Fq 'ROLLBACK=PASS' "$provisioner" || fail rollback_contract_missing
grep -Fq 'ROLLBACK_METADATA_INVALID' "$provisioner" || fail rollback_metadata_check_missing
grep -Fq 'ROLLBACK_METADATA_MODE_INVALID' "$provisioner" || fail rollback_mode_policy_missing
grep -Fq 'ROLLBACK_TARGET_CHANGED' "$provisioner" || fail rollback_target_guard_missing
grep -Fq 'ROLLBACK=FAILED' "$provisioner" || fail rollback_failure_visible_missing
grep -Fq "chmod \"\$expected_mode\"" "$provisioner" || fail rollback_mode_restore_missing
grep -Fq 'TARGET_MTIME' "$provisioner" || fail backup_mtime_missing
grep -Fq 'PUBLISHED_SHA256' "$provisioner" || fail published_sha_anchor_missing
grep -Fq 'validate_secure_chain' "$provisioner" || fail symlink_chain_guard_missing
grep -Fq 'TARGET_SYMLINK_INVALID' "$provisioner" || fail target_symlink_guard_missing
grep -Fq "validate_secure_chain \"\$BACKUP_ROOT\" 'BACKUP_ROOT'" "$provisioner" || fail backup_root_symlink_guard_missing
grep -Fq 'LOCK_FILE_SECURITY_INVALID' "$provisioner" || fail lock_security_missing
grep -Fqx "readonly LOCK_DIR='/run/voxelpacs-philips-nondicom-production-diagnostic-provision'" "$provisioner" || fail lock_path_not_fixed
grep -Fq 'PUBLISHED_SOURCE_HASH_MISMATCH' "$provisioner" || fail published_hash_check_missing
grep -Fq 'BACKUP_ROOT=NOT_CREATED' "$provisioner" || fail dry_run_backup_mutation_missing
grep -Fq 'INSTALL=NOT_EXECUTED' "$provisioner" || fail dry_run_install_mutation_missing

for forbidden in \
  '--path' '--source' '--destination' '--command' 'eval ' 'smbclient' \
  'systemctl' 'curl ' 'mysql' 'psql' 'composer' 'git push' 'git checkout' \
  'rm -rf' 'NOPASSWD:[[:space:]]+ALL'; do
  if grep -Fq -- "$forbidden" "$provisioner"; then fail "forbidden:$forbidden"; fi
done

printf 'TEST=PASS\nSTATIC=PASS\nFIXED_TARGET=PASS\nSOURCE_SHA_REQUIRED=PASS\nBACKUP_ROLLBACK=PASS\nAPPLICATION_DEPLOY=NOT_EXECUTED\nDRIFT_GATE=NOT_MODIFIED\nPRODUCTION_CHANGED=NO\n'
