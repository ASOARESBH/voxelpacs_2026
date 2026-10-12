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
profile_sha='0123456789abcdef0123456789abcdef01234567'
mkdir -p "$source/app" "$source/public" "$source/storage/private" "$source/vendor/pkg"
mkdir -p "$source/vendor/composer"
printf 'runtime\n' > "$source/app/index.php"
printf 'public\n' > "$source/public/index.php"
printf 'secret\n' > "$source/.env"
printf 'clinical\n' > "$source/storage/private/report.pdf"
printf 'dependency\n' > "$source/vendor/pkg/autoload.php"
printf 'persistent\n' > "$source/public/uploads.txt"
cat > "$source/vendor/composer/installed.php" <<EOF
<?php
return array(
  'root' => array(
    'reference' => '$profile_sha',
  ),
  'versions' => array(
    'voxel/bi' => array(
      'reference' => '$profile_sha',
    ),
  ),
);
EOF
ln -s ../app/index.php "$source/public/linked.php"

result="$(bash "$root/scripts/capture-production-baseline.sh" \
  --source-root "$source" \
  --output-dir "$output" \
  --composer-profile-sha "$profile_sha")"
grep -Fxq 'BASELINE_CAPTURE=PASS' <<<"$result"
grep -Fxq 'COMPOSER_PROFILE=PASS' <<<"$result"
grep -Fxq 'PROFILE_FORMAT=STATE_ONLY_V1' <<<"$result"
grep -Fxq 'SECRETS_CAPTURED=NO' <<<"$result"
grep -Fxq 'PERSISTENT_CONTENT_CAPTURED=NO' <<<"$result"
grep -Fxq 'CLINICAL_CONTENT_CAPTURED=NO' <<<"$result"

baseline_dir="$(awk -F= '$1 == "OUTPUT_DIR" {print $2}' <<<"$result")"
[[ -d "$baseline_dir" ]]
[[ -f "$baseline_dir/manifest.tsv" ]]
[[ -f "$baseline_dir/symlinks.tsv" ]]
[[ -f "$baseline_dir/exclusions.tsv" ]]
[[ -f "$baseline_dir/metadata.txt" ]]
[[ -f "$baseline_dir/composer-profile.tsv" ]]
! grep -Fq $'storage/private/report.pdf' "$baseline_dir/manifest.tsv"
! grep -Fq $'.env' "$baseline_dir/manifest.tsv"
grep -Fq $'public/linked.php\t../app/index.php' "$baseline_dir/symlinks.tsv"
grep -Fq $'storage\tEXCLUDED_PERSISTENT_OR_SECRET' "$baseline_dir/exclusions.tsv"
grep -Fq $'.env\tEXCLUDED_PERSISTENT_OR_SECRET' "$baseline_dir/exclusions.tsv"
grep -Fqx $'profile_source\tofficial_root_controlled_baseline_profile_v1' "$baseline_dir/composer-profile.tsv"
grep -Fqx $'profile_baseline_id\t'"$(basename "$baseline_dir")" "$baseline_dir/composer-profile.tsv"
grep -Fq $'profile_manifest_sha256\t' "$baseline_dir/composer-profile.tsv"
grep -Fqx $'profile_artifact_sha\t'"$profile_sha" "$baseline_dir/composer-profile.tsv"
grep -Fqx $'root_reference_state\tSOURCE_SHA' "$baseline_dir/composer-profile.tsv"
grep -Fqx $'package_reference_state\tSOURCE_SHA' "$baseline_dir/composer-profile.tsv"
! grep -Fq "'reference'" "$baseline_dir/composer-profile.tsv"

ambiguous_source="$test_root/ambiguous-source"
ambiguous_output="$test_root/ambiguous-output"
ambiguous_log="$test_root/ambiguous-capture.out"
mkdir -p "$ambiguous_source/vendor/composer"
cat > "$ambiguous_source/vendor/composer/installed.php" <<EOF
<?php
return array(
  'root' => array(
    'reference' => '$profile_sha',
    'reference' => '$profile_sha',
  ),
  'versions' => array(
    'voxel/bi' => array(
      'reference' => '$profile_sha',
    ),
  ),
);
EOF
if bash "$root/scripts/capture-production-baseline.sh" \
  --source-root "$ambiguous_source" \
  --output-dir "$ambiguous_output" \
  --composer-profile-sha "$profile_sha" >"$ambiguous_log" 2>&1; then
  printf 'expected_ambiguous_profile_failure\n' >&2
  exit 1
fi
grep -Fq 'COMPOSER_PROFILE_STATE_AMBIGUOUS' "$ambiguous_log"
! compgen -G "$ambiguous_output/*" >/dev/null

grep -Fq $'production_changed=NO' "$baseline_dir/metadata.txt"
grep -Fq $'database_changed=NO' "$baseline_dir/metadata.txt"
grep -Fq $'worker_started=NO' "$baseline_dir/metadata.txt"
grep -Fq $'transmission=NO' "$baseline_dir/metadata.txt"
printf 'production_baseline_capture_isolated: PASS\n'
