#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
helper="$repo/scripts/provision-composer-profile-approval.sh"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

[[ -f "$helper" && ! -L "$helper" ]] || fail helper_missing
bash -n "$helper" || fail helper_syntax

for required in \
  "readonly APPROVAL_PARENT='/etc/voxelpacs-composer-profile'" \
  "readonly APPROVAL_FILE='/etc/voxelpacs-composer-profile/voxelpacs-composer-profile-approved-main-sha'" \
  "readonly CANONICAL_REMOTE='https://github.com/ASOARESBH/voxelpacs_2026.git'" \
  "readonly MAIN_REF='refs/heads/main'" \
  "validate_fixed_parent_parent" \
  "validate_parent" \
  "validate_anchor" \
  "validate_repo"; do
  grep -Fq -- "$required" "$helper" || fail "missing_contract:$required"
done

for forbidden in \
  'eval ' \
  'systemctl' \
  'smbclient' \
  'curl ' \
  'mysql' \
  'psql' \
  'composer ' \
  'git push' \
  'git checkout' \
  'rm -rf' \
  'sudoers' \
  'APPROVAL_PARENT="\$' \
  'APPROVAL_FILE="\$'; do
  ! grep -Fq -- "$forbidden" "$helper" || fail "forbidden:$forbidden"
done

grep -Fq "[[ \"\$#\" -ge 3 && \"\$1\" == '--expected-sha' ]]" "$helper" || fail fixed_expected_sha_contract
grep -Fq -- "--inspect|--dry-run|--apply" "$helper" || fail fixed_modes
! grep -Eq '^[[:space:]]*(mkdir|install|ln|mv|cp|rm|chmod|chown)[[:space:]].*\$[0-9]' "$helper" || fail caller_path_in_command

grep -Fq "APPROVAL_ANCHOR_DIVERGENT" "$helper" || fail divergent_anchor_guard
grep -Fq "APPROVAL_ANCHOR_CREATE_RACE" "$helper" || fail create_race_guard
grep -Fq "FINAL_PARENT_VALIDATION_FAILED" "$helper" || fail final_validation_guard
grep -Fq "ROLLBACK=PASS" "$helper" || fail rollback_guard

grep -Fq "git ls-remote --refs \"\$CANONICAL_REMOTE\" \"\$MAIN_REF\"" "$helper" || fail independent_main_source

grep -Fq "ln -- \"\$temporary_anchor\" \"\$APPROVAL_FILE\"" "$helper" || fail no_replace_anchor

grep -Fq "CONFIG_CHANGED=NO" "$helper" || fail dry_run_result

grep -Fq "APPLICATION_CHANGED=NO" "$helper" || fail application_scope

printf 'TEST=PASS\nSTATIC=PASS\nFIXED_PATHS=PASS\nCANONICAL_SOURCE=PASS\nNO_REPLACE=PASS\nROLLBACK_CONTRACT=PASS\nFINAL_VALIDATION=PASS\nNO_GENERIC_OPERATIONS=PASS\nAPPLICATION_SCOPE=PASS\n'
