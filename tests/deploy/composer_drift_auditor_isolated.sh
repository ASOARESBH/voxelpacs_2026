#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_helper="$repo/ops/deploy/voxelpacs-composer-drift-auditor"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

run_as_root() {
  if [[ "$EUID" -eq 0 ]]; then "$@"; else sudo -n "$@"; fi
}

fixture_root="$(mktemp -d /tmp/voxelpacs-composer-drift-auditor.XXXXXX)"
cleanup() {
  [[ "$fixture_root" == /tmp/voxelpacs-composer-drift-auditor.* && -d "$fixture_root" ]] || return 0
  run_as_root find "$fixture_root" -depth -type f -delete
  run_as_root find "$fixture_root" -depth -type l -delete
  run_as_root find "$fixture_root" -depth -type d -empty -delete
}
trap cleanup EXIT

fixture_helper="$fixture_root/helper"
app="$fixture_root/app"
baseline_root="$fixture_root/baselines"
baseline_sha='0123456789abcdef0123456789abcdef01234567'
artifact_sha='abcdef0123456789abcdef0123456789abcdef01'
target='vendor/composer/installed.php'

sed \
  -e "s|readonly APP_ROOT='/var/www/voxelpacs/app'|readonly APP_ROOT='$app'|" \
  -e "s|readonly BASELINE_ROOT='/var/lib/voxelpacs/baselines'|readonly BASELINE_ROOT='$baseline_root'|" \
  "$source_helper" > "$fixture_helper"
chmod 0755 "$fixture_helper"
mkdir -p "$app/vendor/composer" "$baseline_root/$baseline_sha"
printf '%s\n' 'runtime-composer-metadata' > "$app/$target"
chmod 0644 "$app/$target"
touch -d '@1700000000' "$app/$target"

size="$(stat -c '%s' "$app/$target")"
hash="$(sha256sum "$app/$target" | awk '{print $1}')"
mode="$(stat -c '%a' "$app/$target")"
owner="$(stat -c '%U' "$app/$target")"
group="$(stat -c '%G' "$app/$target")"
mtime="$(date -u -d "@$(stat -c '%Y' "$app/$target")" '+%Y-%m-%dT%H:%M:%SZ')"
printf '%s\n' "$baseline_sha" > "$baseline_root/$baseline_sha/baseline-id"
printf 'vendor/composer/installed.php\t%s\t%s\t%s\t%s\t%s\t%s\tCOMPOSER_DEPENDENCY\tREADABLE\n' "$size" "$hash" "$owner" "$group" "$mode" "$mtime" > "$baseline_root/$baseline_sha/manifest.tsv"
manifest_sha="$(sha256sum "$baseline_root/$baseline_sha/manifest.tsv" | awk '{print $1}')"
printf '%s\n' "$manifest_sha" > "$baseline_root/$baseline_sha/manifest.sha256"
cat > "$baseline_root/$baseline_sha/metadata.txt" <<EOF
baseline_id=$baseline_sha
manifest_sha256=$manifest_sha
hash_unavailable=0
metadata_unavailable=0
secrets_content=NOT_CAPTURED
persistent_content=NOT_CAPTURED
clinical_content=NOT_CAPTURED
EOF
chmod 0700 "$baseline_root" "$baseline_root/$baseline_sha"
chmod 0600 "$baseline_root/$baseline_sha"/*
run_as_root chown -R root:root "$baseline_root"

artifact_hash="$(printf '%s\n' 'artifact-composer-metadata' | sha256sum | awk '{print $1}')"
positive_input="$fixture_root/artifact.tsv"
printf '%s\t%s\n' "$target" "$artifact_hash" > "$positive_input"

run_helper_as_root() {
  if [[ "$EUID" -eq 0 ]]; then env -u SUDO_USER bash "$@"; else sudo -n env -u SUDO_USER bash "$@"; fi
}

output="$(cat "$positive_input" | run_helper_as_root "$fixture_helper" --baseline-id "$baseline_sha" --artifact-sha "$artifact_sha")"
grep -Fxq 'AUDIT=PASS' <<<"$output" || fail positive_audit
grep -Fxq 'ARTIFACT_RUNTIME_HASH_EQUAL=NO' <<<"$output" || fail artifact_runtime_equal
 grep -Fxq 'ARTIFACT_BASELINE_HASH_EQUAL=NO' <<<"$output" || fail artifact_baseline_equal
grep -Fxq 'RUNTIME_BASELINE_HASH_EQUAL=YES' <<<"$output" || fail runtime_baseline_equal
grep -Fxq 'BASELINE_CLASSIFICATION=COMPOSER_DEPENDENCY' <<<"$output" || fail classification
 grep -Fxq 'COMPOSER_METADATA_EXCEPTION_ELIGIBLE=YES' <<<"$output" || fail exception_eligible
grep -Fxq 'DIAGNOSIS=GENERATED_METADATA_BASELINE_MATCH_ARTIFACT_DIFFERS' <<<"$output" || fail diagnosis
! grep -Fq 'runtime-composer-metadata' <<<"$output" || fail raw_runtime_exposed
! grep -Fq "$hash" <<<"$output" || fail runtime_hash_exposed
! grep -Fq "$artifact_hash" <<<"$output" || fail artifact_hash_exposed

printf '%s\n' 'runtime-drift-mismatch' > "$app/$target"
set +e
mismatch_output="$(cat "$positive_input" | run_helper_as_root "$fixture_helper" --baseline-id "$baseline_sha" --artifact-sha "$artifact_sha" 2>&1)"
mismatch_rc=$?
set -e
[[ "$mismatch_rc" -eq 0 ]] || fail mismatch_evidence_blocked
grep -Fxq 'AUDIT=PASS' <<<"$mismatch_output" || fail mismatch_audit
 grep -Fxq 'RUNTIME_BASELINE_HASH_EQUAL=NO' <<<"$mismatch_output" || fail mismatch_not_detected
grep -Fxq 'READY_FOR_DEPLOY=NO' <<<"$mismatch_output" || fail deploy_guard_missing_after_mismatch

set +e
invalid_output="$(printf '%s\n' 'invalid/path\tbad' | run_helper_as_root "$fixture_helper" --baseline-id "$baseline_sha" --artifact-sha "$artifact_sha" 2>&1)"
invalid_rc=$?
set -e
[[ "$invalid_rc" -ne 0 ]] || fail invalid_artifact_accepted
grep -Fq 'ARTIFACT_MANIFEST_ROW_INVALID' <<<"$invalid_output" || fail invalid_artifact_reason

printf 'TEST=PASS\nISOLATED=PASS\nTHREE_WAY_COMPARISON=PASS\nCOMPOSER_EXCEPTION_SCOPED=PASS\nRUNTIME_MISMATCH_REPORTED=PASS\nHASH_CONTENT=NOT_EXPOSED\nPRODUCTION_CHANGED=NO\n'
