#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
script="$repo/scripts/validate-runtime-drift.sh"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

[[ -f "$script" && ! -L "$script" ]] || fail 'script_missing'
bash -n "$script" || fail 'syntax'
grep -Fqx "readonly MECHANISM_VERSION='3'" "$script" || fail 'mechanism_version'
grep -Fqx "readonly REFERENCE_POLICY='LAST_PUBLISHED_COMMIT'" "$script" || fail 'reference_policy'
grep -Fq -- '--published-manifest' "$script" || fail 'published_manifest_argument_missing'
grep -Fq "readonly COMPOSER_METADATA_PATH='vendor/composer/installed.php'" "$script" || fail 'composer_path_contract'
grep -Fq 'RUNTIME_PUBLISHED_SHA' "$script" || fail 'published_sha_output_missing'
grep -Fq 'EXTRAS_NOT_IN_BASELINE' "$script" || fail 'baseline_extra_gate_missing'
grep -Fq 'EXTRA_HASH_MISMATCH' "$script" || fail 'baseline_hash_gate_missing'
grep -Fq 'INVALID_BASELINE_ENTRIES' "$script" || fail 'baseline_manifest_gate_missing'
grep -Fq 'COMPOSER_DEPENDENCY' "$script" || fail 'composer_classification_gate_missing'
grep -Fq 'MISMATCH_UNCOVERED' "$script" || fail 'uncovered_mismatch_gate_missing'
# A allowlist manual de deltas de código foi removida na versão 3.
! grep -Fq 'APPROVED_CODE_DELTA' "$script" || fail 'legacy_allowlist_present'
! grep -Fq -- '--artifact-manifest' "$script" || fail 'legacy_artifact_argument_present'
for forbidden in 'rm -rf' 'rsync --delete' 'systemctl' 'smbclient' 'curl ' 'wget ' 'mysql ' 'psql ' 'composer install' 'composer update' 'git -C'; do
  ! grep -Fq -- "$forbidden" "$script" || fail "forbidden_operation:$forbidden"
done
printf 'runtime_drift_policy_static: PASS\nREAD_ONLY=YES\nFAIL_CLOSED=YES\nREFERENCE=LAST_PUBLISHED_COMMIT\nMANUAL_ALLOWLIST=ABSENT\nCOMPOSER_EXCEPTION_EXPLICIT=YES\nBASELINE_HASH_REQUIRED=YES\n'
