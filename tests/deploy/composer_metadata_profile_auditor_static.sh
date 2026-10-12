#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
helper="$repo/ops/deploy/voxelpacs-composer-metadata-profile-auditor"
provisioner="$repo/scripts/provision-composer-metadata-profile-auditor.sh"
profile_builder="$repo/scripts/build-composer-metadata-profile.sh"
sudoers="$repo/ops/sudoers/voxelpacs-composer-metadata-profile-auditor"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

for file in "$helper" "$provisioner" "$profile_builder" "$sudoers"; do
  [[ -f "$file" && ! -L "$file" ]] || fail "missing_$file"
done

bash -n "$helper" || fail helper_syntax
bash -n "$provisioner" || fail provisioner_syntax
bash -n "$profile_builder" || fail profile_builder_syntax
visudo -cf "$sudoers" >/dev/null || fail sudoers_syntax

grep -Fqx "readonly APP_ROOT='/var/www/voxelpacs/app'" "$helper" || fail fixed_app_root
grep -Fqx "readonly BASELINE_ROOT='/var/lib/voxelpacs/baselines'" "$helper" || fail fixed_baseline_root
grep -Fqx "readonly TARGET_RELATIVE_PATH='vendor/composer/installed.php'" "$helper" || fail fixed_target
grep -Fqx "readonly BASELINE_PROFILE_NAME='composer-profile.tsv'" "$helper" || fail fixed_profile_name
grep -Fq 'PROFILE_FORMAT=STATE_ONLY_V1' "$helper" || fail profile_format_contract
grep -Fq 'RAW_VALUES_EXPOSED=NO' "$helper" || fail raw_value_contract
grep -Fq 'HASHES_EXPOSED=NO' "$helper" || fail hash_contract
grep -Fq 'READY_FOR_DEPLOY=NO' "$helper" || fail deploy_guard
grep -Fq "audit_status='INCOMPLETE'" "$helper" || fail incomplete_audit_contract
grep -Fq 'profile_manifest_sha256' "$helper" || fail baseline_manifest_binding
grep -Fq 'BASELINE_METADATA_HASH_INCOMPLETE' "$helper" || fail baseline_metadata_completeness
grep -Fq 'BASELINE_SYMLINKS_SECURITY_INVALID' "$helper" || fail baseline_auxiliary_files
grep -Fq 'BASELINE_ID_DERIVATION_MISMATCH' "$helper" || fail baseline_id_derivation
grep -Fq 'composer_profile=STATE_ONLY_V1' "$helper" || fail baseline_composer_profile_metadata
grep -Fq 'composer_profile_source=official_root_controlled_baseline_profile_v1' "$helper" || fail baseline_composer_profile_source

grep -Fq '/usr/local/sbin/voxelpacs-composer-metadata-profile-auditor --baseline-id' "$sudoers" || fail sudoers_command
! grep -Eq 'NOPASSWD:[[:space:]]+ALL([[:space:]]|$)' "$sudoers" || fail generic_sudo
! grep -Eq '/bin/(ba|d)?sh|/usr/(bin|sbin)/(cp|mv|rm|rsync|install|tee|python|php|composer|systemctl)' "$sudoers" || fail generic_command

for forbidden in 'smbclient' 'systemctl' 'composer ' 'curl ' 'wget ' 'mysql ' 'psql ' 'ssh ' 'scp ' 'git -C'; do
  ! grep -Fq "$forbidden" "$helper" || fail "forbidden_helper_$forbidden"
done

! grep -Eq -- '--path|--source|--destination|--command|eval|NOPASSWD:[[:space:]]+ALL|rm -rf' "$helper" || fail generic_helper_operation

grep -Fq "readonly CANONICAL_REMOTE='https://github.com/ASOARESBH/voxelpacs_2026.git'" "$provisioner" || fail canonical_remote_contract
grep -Fq 'validate_admin_directory' "$provisioner" || fail admin_directory_contract
grep -Fq 'link_no_replace' "$provisioner" || fail no_replace_contract
grep -Fq 'EXPECTED_SHA_NOT_ORIGIN_MAIN' "$provisioner" || fail origin_main_contract
grep -Fq "readonly APPROVAL_FILE='/etc/voxelpacs/voxelpacs-composer-metadata-profile-approved-main-shas'" "$provisioner" || fail approval_file_contract
grep -Fq 'EXPECTED_SHA_NOT_APPROVED' "$provisioner" || fail approval_contract

grep -Fq 'profile_artifact_sha' "$profile_builder" || fail builder_profile_key
grep -Fq 'root_reference_state' "$profile_builder" || fail builder_root_state
grep -Fq 'package_reference_state' "$profile_builder" || fail builder_package_state
! grep -Eq 'sha256sum.*input|sha256sum.*installed|print.*digest|print.*hash' "$profile_builder" || fail builder_hash_exposure

printf 'TEST=PASS\nSTATIC=PASS\nFIXED_TARGET=PASS\nSUDOERS_SCOPE=PASS\nNO_GENERIC_OPERATIONS=PASS\nNO_HASH_CONTENT_EXPOSURE=PASS\n'
