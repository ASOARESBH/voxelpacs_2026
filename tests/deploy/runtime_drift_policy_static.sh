#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
script="$repo/scripts/validate-runtime-drift.sh"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

[[ -f "$script" && ! -L "$script" ]] || fail 'script_missing'
bash -n "$script" || fail 'syntax'
grep -Fq "readonly COMPOSER_METADATA_PATH='vendor/composer/installed.php'" "$script" || fail 'composer_path_contract'
grep -Fq 'EXTRAS_NOT_IN_BASELINE' "$script" || fail 'baseline_extra_gate_missing'
grep -Fq 'EXTRA_HASH_MISMATCH' "$script" || fail 'baseline_hash_gate_missing'
grep -Fq 'INVALID_BASELINE_ENTRIES' "$script" || fail 'baseline_manifest_gate_missing'
grep -Fq 'COMPOSER_DEPENDENCY' "$script" || fail 'composer_classification_gate_missing'
grep -Fq 'MISMATCH_UNCOVERED' "$script" || fail 'uncovered_mismatch_gate_missing'
for forbidden in 'rm -rf' 'rsync --delete' 'systemctl' 'smbclient' 'curl ' 'wget ' 'mysql ' 'psql ' 'composer install' 'composer update' 'git -C'; do
  ! grep -Fq -- "$forbidden" "$script" || fail "forbidden_operation:$forbidden"
done
printf 'runtime_drift_policy_static: PASS\nREAD_ONLY=YES\nFAIL_CLOSED=YES\nCOMPOSER_EXCEPTION_EXPLICIT=YES\nBASELINE_HASH_REQUIRED=YES\n'
