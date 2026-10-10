#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/voxelpacs-baseline-capture-test.XXXXXX")"
cleanup() {
  case "$test_root" in
    /tmp/voxelpacs-baseline-capture-test.*)
      rm -rf -- "$test_root"
      ;;
    *)
      printf 'unsafe_test_cleanup_path\n' >&2
      exit 1
      ;;
  esac
}
trap cleanup EXIT

source="$test_root/source"
output="$test_root/output"
mkdir -p "$source/app" "$source/public" "$source/storage/private" "$source/vendor/pkg"
printf 'runtime\n' > "$source/app/index.php"
printf 'public\n' > "$source/public/index.php"
printf 'secret\n' > "$source/.env"
printf 'clinical\n' > "$source/storage/private/report.pdf"
printf 'dependency\n' > "$source/vendor/pkg/autoload.php"
printf 'persistent\n' > "$source/public/uploads.txt"
ln -s ../app/index.php "$source/public/linked.php"

result="$(bash "$root/scripts/capture-production-baseline.sh" --source-root "$source" --output-dir "$output")"
grep -Fxq 'BASELINE_CAPTURE=PASS' <<<"$result"
grep -Fxq 'SECRETS_CAPTURED=NO' <<<"$result"
grep -Fxq 'PERSISTENT_CONTENT_CAPTURED=NO' <<<"$result"
grep -Fxq 'CLINICAL_CONTENT_CAPTURED=NO' <<<"$result"

baseline_dir="$(awk -F= '$1 == "OUTPUT_DIR" {print $2}' <<<"$result")"
[[ -d "$baseline_dir" ]]
[[ -f "$baseline_dir/manifest.tsv" ]]
[[ -f "$baseline_dir/symlinks.tsv" ]]
[[ -f "$baseline_dir/exclusions.tsv" ]]
[[ -f "$baseline_dir/metadata.txt" ]]
! grep -Fq $'storage/private/report.pdf' "$baseline_dir/manifest.tsv"
! grep -Fq $'.env' "$baseline_dir/manifest.tsv"
grep -Fq $'public/linked.php\t../app/index.php' "$baseline_dir/symlinks.tsv"
grep -Fq $'storage\tEXCLUDED_PERSISTENT_OR_SECRET' "$baseline_dir/exclusions.tsv"
grep -Fq $'.env\tEXCLUDED_PERSISTENT_OR_SECRET' "$baseline_dir/exclusions.tsv"

grep -Fq $'production_changed=NO' "$baseline_dir/metadata.txt"
grep -Fq $'database_changed=NO' "$baseline_dir/metadata.txt"
grep -Fq $'worker_started=NO' "$baseline_dir/metadata.txt"
grep -Fq $'transmission=NO' "$baseline_dir/metadata.txt"
printf 'production_baseline_capture_isolated: PASS\n'
