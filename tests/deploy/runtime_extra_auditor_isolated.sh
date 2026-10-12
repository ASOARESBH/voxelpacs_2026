#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_helper="$repo/ops/deploy/voxelpacs-runtime-extra-auditor"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }
run_as_root() { if [[ "$EUID" -eq 0 ]]; then "$@"; else sudo -n "$@"; fi; }
run_helper() {
  local helper="$1" manifest="$2" baseline_id="$3" artifact_sha="$4" output="$5" error="$6"
  run_as_root env -u SUDO_USER bash -c 'cat "$1" | "$2" audit --baseline-id "$3" --artifact-sha "$4"' bash "$manifest" "$helper" "$baseline_id" "$artifact_sha" >"$output" 2>"$error"
}

root_dir="$(mktemp -d /tmp/voxelpacs-runtime-extra-auditor.XXXXXX)"
cleanup() {
  [[ "$root_dir" == /tmp/voxelpacs-runtime-extra-auditor.* && -d "$root_dir" ]] || return 0
  run_as_root find "$root_dir" -depth -type f -delete
  run_as_root find "$root_dir" -depth -type l -delete
  run_as_root find "$root_dir" -depth -type d -empty -delete
}
trap cleanup EXIT

make_case() {
  local case_name="$1" classification="$2"
  local case_root="$root_dir/$case_name" app_root="$root_dir/$case_name/app-root" baseline_root="$root_dir/$case_name/baselines"
  local helper="$root_dir/$case_name/helper" manifest="$root_dir/$case_name/artifact.tsv" baseline_id
  mkdir -p "$case_root" "$app_root/app" "$app_root/ops" "$baseline_root"
  printf '%s\n' 'SYNTHETIC_EXPECTED_CONTENT_NOT_EXPOSED' > "$app_root/app/index.php"
  printf '%s\n' 'SYNTHETIC_EXTRA_CONTENT_NOT_EXPOSED' > "$app_root/ops/legacy.txt"
  if [[ "$classification" == 'UNCLASSIFIED_RUNTIME_FILE' ]]; then
    printf '%s\n' 'SYNTHETIC_UNKNOWN_CONTENT_NOT_EXPOSED' > "$app_root/misc.txt"
  fi
  local expected_hash extra_hash unknown_hash expected_size extra_size unknown_size
  expected_hash="$(sha256sum "$app_root/app/index.php" | awk '{print $1}')"
  extra_hash="$(sha256sum "$app_root/ops/legacy.txt" | awk '{print $1}')"
  unknown_hash=''
  [[ ! -e "$app_root/misc.txt" ]] || unknown_hash="$(sha256sum "$app_root/misc.txt" | awk '{print $1}')"
  expected_size="$(stat -c '%s' "$app_root/app/index.php")"
  extra_size="$(stat -c '%s' "$app_root/ops/legacy.txt")"
  unknown_size='0'
  [[ ! -e "$app_root/misc.txt" ]] || unknown_size="$(stat -c '%s' "$app_root/misc.txt")"
  local expected_mtime='2026-01-01T00:00:00Z'
  touch -d "$expected_mtime" "$app_root/app/index.php" "$app_root/ops/legacy.txt"
  [[ ! -e "$app_root/misc.txt" ]] || touch -d "$expected_mtime" "$app_root/misc.txt"
  run_as_root chown -R root:root "$app_root"
  printf 'app/index.php\t%s\n' "$expected_hash" > "$manifest"
  local manifest_sha
  manifest_sha="$(sha256sum "$manifest" | awk '{print $1}')"
  baseline_id="${manifest_sha:0:40}"
  local baseline_dir="$baseline_root/$baseline_id"
  mkdir -p "$baseline_dir"
  {
    printf 'app/index.php\t%s\t%s\troot\troot\t644\t%s\tOWNED_RUNTIME_CODE\tREADABLE\n' "$expected_size" "$expected_hash" "$expected_mtime"
    printf 'ops/legacy.txt\t%s\t%s\troot\troot\t644\t%s\t%s\tREADABLE\n' "$extra_size" "$extra_hash" "$expected_mtime" "$classification"
    if [[ -n "$unknown_hash" ]]; then
      printf 'misc.txt\t%s\t%s\troot\troot\t644\t%s\tUNCLASSIFIED_RUNTIME_FILE\tREADABLE\n' "$unknown_size" "$unknown_hash" "$expected_mtime"
    fi
  } > "$baseline_dir/manifest.tsv"
  manifest_sha="$(sha256sum "$baseline_dir/manifest.tsv" | awk '{print $1}')"
  baseline_id="${manifest_sha:0:40}"
  mv "$baseline_dir" "$baseline_root/$baseline_id.tmp"
  baseline_dir="$baseline_root/$baseline_id.tmp"
  mv "$baseline_dir" "$baseline_root/$baseline_id"
  printf '%s\n' "$manifest_sha" > "$baseline_root/$baseline_id/manifest.sha256"
  printf '%s\n' "$baseline_id" > "$baseline_root/$baseline_id/baseline-id"
  cat > "$baseline_root/$baseline_id/metadata.txt" <<EOF
mechanism_version=1
captured_at_utc=2026-01-01T00:00:00Z
hostname=fixture
source_root=/var/www/voxelpacs/app
manifest_sha256=$manifest_sha
baseline_id=$baseline_id
file_count=2
symlink_count=0
excluded_count=0
hash_unavailable=0
metadata_unavailable=0
secrets_content=NOT_CAPTURED
persistent_content=NOT_CAPTURED
clinical_content=NOT_CAPTURED
EOF
  : > "$baseline_root/$baseline_id/symlinks.tsv"
  : > "$baseline_root/$baseline_id/exclusions.tsv"
  chmod 0700 "$baseline_root" "$baseline_root/$baseline_id"
  chmod 0600 "$baseline_root/$baseline_id"/*
  run_as_root chown -R root:root "$baseline_root"

  sed \
    -e "s|readonly APP_ROOT='/var/www/voxelpacs/app'|readonly APP_ROOT='$app_root'|" \
    -e "s|readonly BASELINE_ROOT='/var/lib/voxelpacs/baselines'|readonly BASELINE_ROOT='$baseline_root'|" \
    -e "s|/var/tmp/voxelpacs-runtime-extra-auditor|$case_root/artifact-input|g" \
    "$source_helper" > "$helper"
  chmod 0555 "$helper"
  run_as_root chown root:root "$helper"
  printf 'app/index.php\t%s\n' "$expected_hash" > "$manifest"
  printf '%s\t%s\n' "$helper" "$manifest" > "$case_root/paths"
  printf '%s\n' "$baseline_id" > "$case_root/baseline-id"
}

assert_no_sensitive_output() {
  local file="$1"
  ! grep -Eq 'SYNTHETIC_|[0-9a-f]{64}|/tmp/voxelpacs-runtime-extra-auditor\.' "$file" || fail sensitive_output_detected
  ! grep -Eq 'app/index\.php|ops/legacy\.txt|misc\.txt' "$file" || fail path_output_detected
}

make_case pass DEVELOPMENT_OR_OPERATIONS
read -r pass_helper pass_manifest < "$root_dir/pass/paths"
pass_id="$(cat "$root_dir/pass/baseline-id")"
pass_out="$root_dir/pass/out"; pass_err="$root_dir/pass/err"
run_helper "$pass_helper" "$pass_manifest" "$pass_id" 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' "$pass_out" "$pass_err"
grep -Fqx 'AUDIT=PASS' "$pass_out" || fail pass_not_reported
grep -Fqx 'CATEGORY_KNOWN_RUNTIME_ARTIFACT=1' "$pass_out" || fail known_extra_not_counted
grep -Fqx 'INDIVIDUAL_HASHES_EXPOSED=NO' "$pass_out" || fail hash_disclosure_contract
assert_no_sensitive_output "$pass_out"
assert_no_sensitive_output "$pass_err"

make_case incomplete UNCLASSIFIED_RUNTIME_FILE
read -r incomplete_helper incomplete_manifest < "$root_dir/incomplete/paths"
incomplete_id="$(cat "$root_dir/incomplete/baseline-id")"
incomplete_out="$root_dir/incomplete/out"; incomplete_err="$root_dir/incomplete/err"
set +e
run_helper "$incomplete_helper" "$incomplete_manifest" "$incomplete_id" 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' "$incomplete_out" "$incomplete_err"
incomplete_rc=$?
set -e
[[ "$incomplete_rc" -ne 0 ]] || fail unclassified_extra_accepted
grep -Fqx 'AUDIT=INCOMPLETE' "$incomplete_out" || fail incomplete_not_reported
grep -Fqx 'REASON=BASELINE_EXTRA_UNCLASSIFIED' "$incomplete_out" || fail incomplete_reason_missing
assert_no_sensitive_output "$incomplete_out"
assert_no_sensitive_output "$incomplete_err"

printf '%s\n' 'SYNTHETIC_UNBASELINED_CONTENT_NOT_EXPOSED' >> "$root_dir/pass/app-root/ops/unknown.txt"
set +e
pass_unknown_out="$root_dir/pass/unknown-out"; pass_unknown_err="$root_dir/pass/unknown-err"
run_helper "$pass_helper" "$pass_manifest" "$pass_id" 'cccccccccccccccccccccccccccccccccccccccc' "$pass_unknown_out" "$pass_unknown_err"
unknown_rc=$?
set -e
[[ "$unknown_rc" -ne 0 ]] || fail unbaselined_extra_accepted
grep -Fqx 'AUDIT=FAIL' "$pass_unknown_out" || fail unbaselined_extra_not_reported
grep -Fqx 'CATEGORY_MISSING_FROM_BASELINE=1' "$pass_unknown_out" || fail unbaselined_extra_category_missing
assert_no_sensitive_output "$pass_unknown_out"
assert_no_sensitive_output "$pass_unknown_err"

printf '%s\n' 'tampered' | run_as_root tee "$root_dir/pass/baselines/$pass_id/manifest.sha256" >/dev/null
set +e
tampered_out="$root_dir/pass/tampered-out"; tampered_err="$root_dir/pass/tampered-err"
run_helper "$pass_helper" "$pass_manifest" "$pass_id" 'dddddddddddddddddddddddddddddddddddddddd' "$tampered_out" "$tampered_err"
tampered_rc=$?
set -e
[[ "$tampered_rc" -ne 0 ]] || fail tampered_baseline_accepted
grep -Fq 'BASELINE_MANIFEST_CHECKSUM_MISMATCH' "$tampered_err" || fail tampered_baseline_reason_missing
assert_no_sensitive_output "$tampered_out"
assert_no_sensitive_output "$tampered_err"

printf 'TEST=PASS\nISOLATED=PASS\nPASS_CLASSIFIED_EXTRA=PASS\nINCOMPLETE_UNCLASSIFIED=PASS\nUNBASELINED_EXTRA_FAIL_CLOSED=PASS\nTAMPERED_BASELINE_FAIL_CLOSED=PASS\nRAW_CONTENT_EXPOSED=NO\nINDIVIDUAL_HASHES_EXPOSED=NO\nINDIVIDUAL_PATHS_EXPOSED=NO\nPRODUCTION_CHANGED=NO\n'
