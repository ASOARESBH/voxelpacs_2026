#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_reader="$repo/ops/deploy/voxelpacs-runtime-drift-manifest-reader"
source_gate="$repo/ops/deploy/voxelpacs-runtime-drift-gate"
source_comparator="$repo/scripts/validate-runtime-drift.sh"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

run_as_root() {
  if [[ "$EUID" -eq 0 ]]; then "$@"; else sudo -n "$@"; fi
}

run_helper_as_root() {
  if [[ "$EUID" -eq 0 ]]; then env SUDO_USER=manus-deploy "$@"; else sudo -n env SUDO_USER=manus-deploy "$@"; fi
}

fixture_root="$(mktemp -d /tmp/voxelpacs-runtime-drift-reader.XXXXXX)"
cleanup() {
  [[ "$fixture_root" == /tmp/voxelpacs-runtime-drift-reader.* && -d "$fixture_root" ]] || return 0
  run_as_root find "$fixture_root" -depth -type f -delete
  run_as_root find "$fixture_root" -depth -type l -delete
  run_as_root find "$fixture_root" -depth -type d -empty -delete
}
trap cleanup EXIT

baseline_root="$fixture_root/baselines"
baseline_work="$fixture_root/baseline-work"
runtime_root="$fixture_root/runtime"
mkdir -p "$baseline_work" "$runtime_root/app" "$runtime_root/bin" "$runtime_root/public" "$runtime_root/routes" "$runtime_root/lang" "$runtime_root/config"

runtime_file="$runtime_root/app/example.php"
printf '%s\n' 'SYNTHETIC_RUNTIME_CONTENT_NOT_EXPOSED' > "$runtime_file"
hash="$(sha256sum "$runtime_file" | awk '{print $1}')"
size="$(stat -c '%s' "$runtime_file")"
manifest="$baseline_work/manifest.tsv"
printf 'app/example.php\t%s\t%s\troot\troot\t644\t2026-01-01T00:00:00Z\tOWNED_RUNTIME_CODE\tREADABLE\n' "$size" "$hash" > "$manifest"
manifest_sha="$(sha256sum "$manifest" | awk '{print $1}')"
baseline_id="${manifest_sha:0:40}"
baseline_dir="$baseline_root/$baseline_id"
mkdir -p "$baseline_dir"
cp "$manifest" "$baseline_dir/manifest.tsv"
printf '%s\n' "$manifest_sha" > "$baseline_dir/manifest.sha256"
printf '%s\n' "$baseline_id" > "$baseline_dir/baseline-id"
printf 'mechanism_version=1\ncaptured_at_utc=2026-01-01T00:00:00Z\nhostname=fixture\nsource_root=/var/www/voxelpacs/app\nmanifest_sha256=%s\nbaseline_id=%s\nfile_count=1\nsymlink_count=0\nexcluded_count=0\nhash_unavailable=0\nmetadata_unavailable=0\nsecrets_content=NOT_CAPTURED\npersistent_content=NOT_CAPTURED\nclinical_content=NOT_CAPTURED\nproduction_changed=NO\ndatabase_changed=NO\nworker_started=NO\ntransmission=NO\n' "$manifest_sha" "$baseline_id" > "$baseline_dir/metadata.txt"
: > "$baseline_dir/symlinks.tsv"
: > "$baseline_dir/exclusions.tsv"
chmod 0700 "$baseline_root" "$baseline_dir"
chmod 0600 "$baseline_dir"/*
run_as_root chown -R root:root "$baseline_root"

reader="$fixture_root/reader"
gate="$fixture_root/gate"
comparator="$fixture_root/comparator"
sed "s|readonly BASELINE_ROOT='/var/lib/voxelpacs/baselines'|readonly BASELINE_ROOT='$baseline_root'|" "$source_reader" > "$reader"
sed \
  -e "s|readonly RUNTIME_ROOT='/var/www/voxelpacs/app'|readonly RUNTIME_ROOT='$runtime_root'|" \
  -e "s|readonly READER='/usr/local/sbin/voxelpacs-runtime-drift-manifest-reader'|readonly READER='$reader'|" \
  -e "s|readonly COMPARATOR='/usr/local/sbin/voxelpacs-validate-runtime-drift'|readonly COMPARATOR='$comparator'|" \
  -e "s|readonly TEMP_ROOT='/var/tmp'|readonly TEMP_ROOT='$fixture_root'|" \
  "$source_gate" > "$gate"
cp "$source_comparator" "$comparator"
run_as_root chown root:root "$reader" "$gate" "$comparator"
run_as_root chmod 0555 "$reader" "$gate" "$comparator"

reader_output="$fixture_root/reader.out"
reader_error="$fixture_root/reader.err"
run_helper_as_root "$reader" read --baseline-id "$baseline_id" >"$reader_output" 2>"$reader_error"
grep -Fq "app/example.php" "$reader_output" || fail reader_row_missing
grep -Fq 'MANIFEST_READER=PASS' "$reader_error" || fail reader_status_missing
! grep -Fq 'SYNTHETIC_RUNTIME_CONTENT_NOT_EXPOSED' "$reader_output" || fail reader_exposed_content

artifact_manifest="$fixture_root/artifact.tsv"
printf 'app/example.php\t%s\n' "$hash" > "$artifact_manifest"
gate_output="$fixture_root/gate.out"
gate_error="$fixture_root/gate.err"
run_helper_as_root bash -c 'cat "$1" | "$2" --baseline-id "$3"' bash "$artifact_manifest" "$gate" "$baseline_id" >"$gate_output" 2>"$gate_error"
grep -Fq 'DRIFT_GATE=PASS' "$gate_output" || fail gate_pass_missing
grep -Fq 'RUNTIME_DRIFT_MANIFEST_READER=PASS' "$gate_output" || fail gate_reader_marker_missing
! grep -Fq 'SYNTHETIC_RUNTIME_CONTENT_NOT_EXPOSED' "$gate_output" || fail gate_exposed_content

printf '%s\n' 'MUTATED_RUNTIME_CONTENT_NOT_EXPOSED' > "$runtime_file"
set +e
blocked_output="$(run_helper_as_root bash -c 'cat "$1" | "$2" --baseline-id "$3"' bash "$artifact_manifest" "$gate" "$baseline_id" 2>&1)"
blocked_rc=$?
set -e
[[ "$blocked_rc" -ne 0 ]] || fail mismatch_accepted
grep -Fq 'DRIFT_GATE=FAIL' <<<"$blocked_output" || fail mismatch_not_reported
! grep -Fq 'MUTATED_RUNTIME_CONTENT_NOT_EXPOSED' <<<"$blocked_output" || fail mismatch_exposed_content

set +e
invalid_output="$(run_helper_as_root "$reader" read --baseline-id '../escape' 2>&1)"
invalid_rc=$?
set -e
[[ "$invalid_rc" -ne 0 ]] || fail traversal_accepted
grep -Fq 'BASELINE_ID_INVALID' <<<"$invalid_output" || fail traversal_reason

printf 'TEST=PASS\nISOLATED=PASS\nREADER_INTEGRITY=PASS\nGATE_PASS=PASS\nMISMATCH_FAIL_CLOSED=PASS\nTRAVERSAL_REJECTED=PASS\nRAW_CONTENT=NOT_EXPOSED\nPRODUCTION_CHANGED=NO\n'
