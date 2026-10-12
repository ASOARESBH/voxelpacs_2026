#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
capture="$repo/scripts/capture-production-baseline.sh"
provisioner="$repo/scripts/provision-production-baseline-capture.sh"
wrapper="$repo/ops/deploy/voxelpacs-production-baseline-capture"
sudoers="$repo/ops/sudoers/voxelpacs-production-baseline-capture"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

for file in "$capture" "$provisioner" "$wrapper" "$sudoers"; do
  [[ -f "$file" && ! -L "$file" ]] || fail "missing:$file"
done

bash -n "$capture" || fail capture_syntax
bash -n "$provisioner" || fail provisioner_syntax
bash -n "$wrapper" || fail wrapper_syntax
visudo -cf "$sudoers" >/dev/null || fail sudoers_syntax

grep -Fqx "readonly SOURCE_ROOT='/var/www/voxelpacs/app'" "$wrapper" || fail fixed_source_root
grep -Fqx "readonly OUTPUT_ROOT='/var/lib/voxelpacs/baselines'" "$wrapper" || fail fixed_output_root
grep -Fqx "readonly CAPTURE_SCRIPT='/usr/local/sbin/voxelpacs-capture-production-baseline'" "$wrapper" || fail fixed_capture_path
grep -Fqx "readonly COMPOSER_PROFILE_SHA_FILE='/etc/voxelpacs-composer-profile/voxelpacs-composer-profile-approved-main-sha'" "$wrapper" || fail fixed_profile_approval_path
grep -Fq "[[ \"\$#\" -eq 1 && \"\$1\" == 'capture' ]]" "$wrapper" || fail fixed_arguments
grep -Fq -- '--composer-profile-sha' "$wrapper" || fail fixed_profile_argument
grep -Fq 'read_approved_composer_profile_sha' "$wrapper" || fail fixed_profile_approval_reader
grep -Fq '/usr/local/sbin/voxelpacs-production-baseline-capture capture' "$sudoers" || fail sudoers_command
grep -Eq '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:' "$sudoers" || fail sudoers_caller

for file in "$wrapper" "$sudoers"; do
  for forbidden in 'eval' 'systemctl' 'smbclient' 'curl ' 'mysql' 'psql' 'composer ' 'git push' 'git checkout' 'NOPASSWD: ALL'; do
    ! grep -Fq -- "$forbidden" "$file" || fail "forbidden:$forbidden:$file"
  done
done

grep -Fq 'COMPOSER_PROFILE=PASS' "$wrapper" || fail profile_result_contract

! grep -Eq '^[[:space:]]*eval([[:space:]]|$)' "$provisioner" || fail executable_eval
! grep -Eq '^[[:space:]]*(systemctl|smbclient|curl|mysql|psql|composer|git[[:space:]]+(push|checkout))([[:space:]]|$)' "$provisioner" || fail executable_generic_operation
! grep -Fq 'rm -rf' "$provisioner" || fail destructive_cleanup
! grep -Fq 'rm -rf' "$wrapper" || fail destructive_wrapper
grep -Fq -- '--upgrade' "$provisioner" || fail upgrade_argument_missing
grep -Fq 'source_history_contains_blob' "$provisioner" || fail historical_provenance_missing
grep -Fq 'validate_existing_provenance' "$provisioner" || fail existing_provenance_gate_missing
grep -Fq 'CAPTURE_PROVENANCE_UNPROVEN' "$provisioner" || fail capture_provenance_reason_missing
grep -Fq 'WRAPPER_PROVENANCE_UNPROVEN' "$provisioner" || fail wrapper_provenance_reason_missing
grep -Fq 'SUDOERS_PROVENANCE_UNPROVEN' "$provisioner" || fail sudoers_provenance_reason_missing
grep -Fq 'cp -a -- "$INSTALLED_CAPTURE" "$backup_dir/capture"' "$provisioner" || fail capture_backup_missing
grep -Fq 'cp -a -- "$INSTALLED_WRAPPER" "$backup_dir/wrapper"' "$provisioner" || fail wrapper_backup_missing
grep -Fq 'cp -a -- "$INSTALLED_SUDOERS" "$backup_dir/sudoers"' "$provisioner" || fail sudoers_backup_missing
grep -Fq 'ROLLBACK=PASS' "$provisioner" || fail rollback_contract_missing
grep -Fq 'readonly CAPTURE_PARENT=' "$provisioner" || fail capture_parent_contract_missing
grep -Fq 'readonly SUDOERS_PARENT=' "$provisioner" || fail sudoers_parent_contract_missing
grep -Fq 'mv -f -- "$stage_capture" "$INSTALLED_CAPTURE"' "$provisioner" || fail capture_atomic_move_missing
grep -Fq 'mv -f -- "$stage_wrapper" "$INSTALLED_WRAPPER"' "$provisioner" || fail wrapper_atomic_move_missing
grep -Fq 'mv -f -- "$stage_sudoers" "$INSTALLED_SUDOERS"' "$provisioner" || fail sudoers_atomic_move_missing

printf 'TEST=PASS\nSTATIC=PASS\nSUDOERS_SYNTAX=PASS\nFIXED_SCOPE=PASS\nUPGRADE_CONTRACT=PASS\nHISTORICAL_PROVENANCE=PASS\nROLLBACK_CONTRACT=PASS\nGENERIC_OPERATIONS=ABSENT\nPRODUCTION_CHANGED=NO\n'
