#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
script="$repo/scripts/validate-runtime-drift.sh"
tmp="$(mktemp -d /tmp/voxelpacs-runtime-drift-test.XXXXXX)"
cleanup() { rm -rf -- "$tmp"; }
trap cleanup EXIT

fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

runtime="$tmp/runtime"
mkdir -p "$runtime/app/Services" "$runtime/vendor/composer" "$runtime/legacy"
printf '%s\n' 'owned-code' > "$runtime/app/ok.php"
printf '%s\n' 'runtime-old-metadata-resolver' > "$runtime/app/Services/PhilipsSubmissionMetadataResolver.php"
printf '%s\n' 'runtime-old-patient-name-service' > "$runtime/app/Services/ReportVersionPatientNameService.php"
printf '%s\n' 'composer-generated-runtime' > "$runtime/vendor/composer/installed.php"
printf '%s\n' 'baseline-extra' > "$runtime/legacy/allowed.txt"

artifact="$tmp/artifact.tsv"
baseline="$tmp/baseline.tsv"
code_hash="$(sha256sum "$runtime/app/ok.php" | awk '{print $1}')"
metadata_runtime_hash="$(sha256sum "$runtime/app/Services/PhilipsSubmissionMetadataResolver.php" | awk '{print $1}')"
metadata_artifact_hash="$(printf '%s\n' 'artifact-new-metadata-resolver' | sha256sum | awk '{print $1}')"
patient_name_runtime_hash="$(sha256sum "$runtime/app/Services/ReportVersionPatientNameService.php" | awk '{print $1}')"
patient_name_artifact_hash="$(printf '%s\n' 'artifact-new-patient-name-service' | sha256sum | awk '{print $1}')"
composer_runtime_hash="$(sha256sum "$runtime/vendor/composer/installed.php" | awk '{print $1}')"
composer_artifact_hash="$(printf '%s\n' 'composer-generated-artifact' | sha256sum | awk '{print $1}')"
extra_hash="$(sha256sum "$runtime/legacy/allowed.txt" | awk '{print $1}')"
printf 'app/ok.php\t%s\n' "$code_hash" > "$artifact"
printf 'app/Services/PhilipsSubmissionMetadataResolver.php\t%s\n' "$metadata_artifact_hash" >> "$artifact"
printf 'app/Services/ReportVersionPatientNameService.php\t%s\n' "$patient_name_artifact_hash" >> "$artifact"
printf 'vendor/composer/installed.php\t%s\n' "$composer_artifact_hash" >> "$artifact"
printf 'app/ok.php\t%s\t%s\troot\troot\t644\t2026-01-01T00:00:00Z\tOWNED_RUNTIME_CODE\tREADABLE\n' \
  "$(stat -c '%s' "$runtime/app/ok.php")" "$code_hash" > "$baseline"
printf 'app/Services/PhilipsSubmissionMetadataResolver.php\t%s\t%s\troot\troot\t644\t2026-01-01T00:00:00Z\tOWNED_RUNTIME_CODE\tREADABLE\n' \
  "$(stat -c '%s' "$runtime/app/Services/PhilipsSubmissionMetadataResolver.php")" "$metadata_runtime_hash" >> "$baseline"
printf 'app/Services/ReportVersionPatientNameService.php\t%s\t%s\troot\troot\t644\t2026-01-01T00:00:00Z\tOWNED_RUNTIME_CODE\tREADABLE\n' \
  "$(stat -c '%s' "$runtime/app/Services/ReportVersionPatientNameService.php")" "$patient_name_runtime_hash" >> "$baseline"
printf 'vendor/composer/installed.php\t%s\t%s\troot\troot\t644\t2026-01-01T00:00:00Z\tCOMPOSER_DEPENDENCY\tREADABLE\n' \
  "$(stat -c '%s' "$runtime/vendor/composer/installed.php")" "$composer_runtime_hash" >> "$baseline"
printf 'legacy/allowed.txt\t%s\t%s\troot\troot\t644\t2026-01-01T00:00:00Z\tDEVELOPMENT_OR_OPERATIONS\tREADABLE\n' \
  "$(stat -c '%s' "$runtime/legacy/allowed.txt")" "$extra_hash" >> "$baseline"

positive="$tmp/positive.out"
"$script" --runtime-root "$runtime" --artifact-manifest "$artifact" --baseline-manifest "$baseline" > "$positive" || fail 'positive_rejected'
grep -Fxq 'DRIFT_GATE=PASS' "$positive" || fail 'positive_gate'
grep -Fxq 'COMPOSER_METADATA_EXCEPTION=1' "$positive" || fail 'composer_exception_not_counted'
grep -Fxq 'EXPECTED_TARGET_DELTAS=2' "$positive" || fail 'approved_code_delta_not_counted'
grep -Fxq 'EXTRAS_IN_BASELINE=1' "$positive" || fail 'baseline_extra_not_counted'
grep -Fxq 'EXTRAS_NOT_IN_BASELINE=0' "$positive" || fail 'unknown_extra_count'

# Baseline capturado antes da coluna READABLE: o hash SHA-256 completo prova
# que o arquivo era legível; somente essa forma legada exata é aceita.
legacy_baseline="$tmp/baseline-legacy.tsv"
awk -F '\t' 'BEGIN {OFS="\t"} {print $1,$2,$3,$4,$5,$6,$7,$8}' "$baseline" > "$legacy_baseline"
if ! "$script" --runtime-root "$runtime" --artifact-manifest "$artifact" --baseline-manifest "$legacy_baseline" > "$tmp/legacy.out"; then fail 'legacy_baseline_rejected'; fi
grep -Fxq 'DRIFT_GATE=PASS' "$tmp/legacy.out" || fail 'legacy_gate'
grep -Fxq 'BASELINE_FORMAT=LEGACY_8_COLUMN' "$tmp/legacy.out" || fail 'legacy_format_not_reported'
grep -Fxq 'BASELINE_LEGACY_ENTRIES=5' "$tmp/legacy.out" || fail 'legacy_count_not_reported'

# Extra não presente no baseline: deve bloquear.
printf '%s\n' 'unknown' > "$runtime/unknown.txt"
if "$script" --runtime-root "$runtime" --artifact-manifest "$artifact" --baseline-manifest "$baseline" > "$tmp/unknown.out" 2>&1; then fail 'unknown_extra_accepted'; fi
grep -Fxq 'DRIFT_GATE=FAIL' "$tmp/unknown.out" || fail 'unknown_extra_reason'
rm -f "$runtime/unknown.txt"

# Extra baseline-only com hash divergente: deve bloquear.
printf '%s\n' 'tampered' > "$runtime/legacy/allowed.txt"
if "$script" --runtime-root "$runtime" --artifact-manifest "$artifact" --baseline-manifest "$baseline" > "$tmp/tampered-extra.out" 2>&1; then fail 'baseline_hash_mismatch_accepted'; fi
grep -Fxq 'DRIFT_GATE=FAIL' "$tmp/tampered-extra.out" || fail 'baseline_hash_mismatch_reason'
printf '%s\n' 'baseline-extra' > "$runtime/legacy/allowed.txt"

# Metadata Composer com hash fora do baseline: a exceção não é genérica.
printf '%s\n' 'composer-tampered' > "$runtime/vendor/composer/installed.php"
if "$script" --runtime-root "$runtime" --artifact-manifest "$artifact" --baseline-manifest "$baseline" > "$tmp/tampered-composer.out" 2>&1; then fail 'composer_hash_mismatch_accepted'; fi
grep -Fxq 'DRIFT_GATE=FAIL' "$tmp/tampered-composer.out" || fail 'composer_hash_mismatch_reason'
printf '%s\n' 'composer-generated-runtime' > "$runtime/vendor/composer/installed.php"

# Delta de código aprovado com hash que não coincide com a baseline: deve bloquear.
printf '%s\n' 'tampered-approved-code' > "$runtime/app/Services/PhilipsSubmissionMetadataResolver.php"
if "$script" --runtime-root "$runtime" --artifact-manifest "$artifact" --baseline-manifest "$baseline" > "$tmp/tampered-approved-code.out" 2>&1; then fail 'approved_code_hash_mismatch_accepted'; fi
grep -Fxq 'DRIFT_GATE=FAIL' "$tmp/tampered-approved-code.out" || fail 'approved_code_hash_mismatch_reason'
printf '%s\n' 'runtime-old-metadata-resolver' > "$runtime/app/Services/PhilipsSubmissionMetadataResolver.php"

# Código versionado divergente: deve bloquear mesmo quando há baseline.
printf '%s\n' 'changed-code' > "$runtime/app/ok.php"
if "$script" --runtime-root "$runtime" --artifact-manifest "$artifact" --baseline-manifest "$baseline" > "$tmp/code-mismatch.out" 2>&1; then fail 'code_mismatch_accepted'; fi
grep -Fxq 'DRIFT_GATE=FAIL' "$tmp/code-mismatch.out" || fail 'code_mismatch_reason'
printf '%s\n' 'owned-code' > "$runtime/app/ok.php"

# Arquivo esperado ausente: deve bloquear.
rm -f "$runtime/app/ok.php"
if "$script" --runtime-root "$runtime" --artifact-manifest "$artifact" --baseline-manifest "$baseline" > "$tmp/missing.out" 2>&1; then fail 'missing_file_accepted'; fi
grep -Fxq 'DRIFT_GATE=FAIL' "$tmp/missing.out" || fail 'missing_file_reason'

printf 'runtime_drift_policy_isolated: PASS\nPOSITIVE_COMPOSER_EXCEPTION=PASS\nPOSITIVE_APPROVED_CODE_DELTAS=PASS\nUNKNOWN_EXTRA=BLOCKED\nBASELINE_HASH_MISMATCH=BLOCKED\nCOMPOSER_HASH_MISMATCH=BLOCKED\nAPPROVED_CODE_HASH_MISMATCH=BLOCKED\nCODE_MISMATCH=BLOCKED\nMISSING_FILE=BLOCKED\nPRODUCTION_CHANGED=NO\n'
