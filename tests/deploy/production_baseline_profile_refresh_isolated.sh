#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/voxelpacs-baseline-profile-refresh-test.XXXXXX")"
cleanup() {
  case "$test_root" in
    /tmp/voxelpacs-baseline-profile-refresh-test.*)
      sudo -n rm -rf -- "$test_root"
      ;;
    *)
      printf 'unsafe_test_cleanup_path\n' >&2
      exit 1
      ;;
  esac
}
trap cleanup EXIT

source="$test_root/source"
output="$test_root/baselines"
backup="$test_root/backups"
config="$test_root/etc/voxelpacs-composer-profile"
profile_file="$config/voxelpacs-composer-profile-approved-main-sha"
lock_parent="$test_root/run/lock"
wrapper="$test_root/wrapper.sh"
profile_sha='0123456789abcdef0123456789abcdef01234567'

mkdir -p "$source/vendor/composer" "$output" "$backup" "$config" "$lock_parent"
printf '%s\n' "$profile_sha" > "$profile_file"
sudo -n chown root:root "$config" "$profile_file"
sudo -n chmod 0700 "$config"
sudo -n chmod 0600 "$profile_file"
sudo -n chown root:root "$backup" "$lock_parent"
sudo -n chmod 0700 "$backup"
sudo -n chmod 0755 "$lock_parent"
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

legacy_result="$(bash "$root/scripts/capture-production-baseline.sh" \
  --source-root "$source" \
  --output-dir "$output" \
  --composer-profile-sha "$profile_sha")"
legacy_dir="$(awk -F= '$1 == "OUTPUT_DIR" {print $2}' <<<"$legacy_result")"
[[ -d "$legacy_dir" ]]
sudo -n rm -f -- "$legacy_dir/composer-profile.tsv"
sudo -n sed -i '/^composer_profile=/d;/^composer_profile_source=/d' "$legacy_dir/metadata.txt"
sudo -n chown root:root "$output" "$legacy_dir"
sudo -n find -P "$legacy_dir" -maxdepth 1 -type f -exec chown root:root {} +
sudo -n chmod 0700 "$output" "$legacy_dir"
sudo -n find -P "$legacy_dir" -maxdepth 1 -type f -exec chmod 0600 {} +

cp -- "$root/ops/deploy/voxelpacs-production-baseline-capture" "$wrapper"
python3 - "$wrapper" "$source" "$output" "$root/scripts/capture-production-baseline.sh" "$profile_file" "$backup" "$lock_parent" <<'PY'
from pathlib import Path
import sys

path, source, output, capture, profile, backup, lock_parent = sys.argv[1:]
text = Path(path).read_text()
replacements = {
    "readonly SOURCE_ROOT='/var/www/voxelpacs/app'": f"readonly SOURCE_ROOT={source!r}",
    "readonly OUTPUT_ROOT='/var/lib/voxelpacs/baselines'": f"readonly OUTPUT_ROOT={output!r}",
    "readonly CAPTURE_SCRIPT='/usr/local/sbin/voxelpacs-capture-production-baseline'": f"readonly CAPTURE_SCRIPT={capture!r}",
    "readonly COMPOSER_PROFILE_SHA_FILE='/etc/voxelpacs-composer-profile/voxelpacs-composer-profile-approved-main-sha'": f"readonly COMPOSER_PROFILE_SHA_FILE={profile!r}",
    "readonly REFRESH_BACKUP_PARENT='/var/lib/voxelpacs/admin-backups/composer-profile-refresh'": f"readonly REFRESH_BACKUP_PARENT={backup!r}",
    "readonly LOCK_PATH='/run/lock/voxelpacs-production-baseline-capture-provision.lock'": f"readonly LOCK_PATH={lock_parent!r}/capture.lock",
}
for old, new in replacements.items():
    if old not in text:
        raise SystemExit(f'missing replacement marker: {old}')
    text = text.replace(old, new, 1)
Path(path).write_text(text)
PY
chmod 0755 "$wrapper"

result="$(sudo -n env -u SUDO_USER bash "$wrapper" refresh-profile)"
grep -Fxq 'PROFILE_REFRESH=PASS' <<<"$result"
grep -Fxq 'BASELINE_PROFILE=CREATED' <<<"$result"
grep -Fxq 'BACKUP=PASS' <<<"$result"
grep -Fxq 'PROFILE_FORMAT=STATE_ONLY_V1' <<<"$result"
grep -Fxq 'RAW_VALUES_EXPOSED=NO' <<<"$result"
grep -Fxq 'HASHES_EXPOSED=NO' <<<"$result"
grep -Fxq 'APPLICATION_CHANGED=NO' <<<"$result"
! grep -Eq '^[0-9a-f]{40}$' <<<"$result"

profile="$legacy_dir/composer-profile.tsv"
[[ "$(sudo -n stat -c '%u:%g:%a:%F' "$profile")" == '0:0:600:regular file' ]]
grep -Fxq $'profile_source\tofficial_root_controlled_baseline_profile_v1' <(sudo -n cat "$profile")
grep -Fxq $'profile_baseline_id\t'"$(basename "$legacy_dir")" <(sudo -n cat "$profile")
grep -Fxq $'profile_artifact_sha\t'"$profile_sha" <(sudo -n cat "$profile")
grep -Fxq $'root_reference_state\tSOURCE_SHA' <(sudo -n cat "$profile")
grep -Fxq $'package_reference_state\tSOURCE_SHA' <(sudo -n cat "$profile")
! sudo -n grep -Fq "'reference'" "$profile"
grep -Fxq 'composer_profile=STATE_ONLY_V1' <(sudo -n cat "$legacy_dir/metadata.txt")
grep -Fxq 'composer_profile_source=official_root_controlled_baseline_profile_v1' <(sudo -n cat "$legacy_dir/metadata.txt")

backup_dir="$(sudo -n find -P "$backup" -mindepth 1 -maxdepth 1 -type d -name 'refresh.*' -print)"
[[ -n "$backup_dir" && "$(printf '%s\n' "$backup_dir" | wc -l)" -eq 1 ]]
backup_metadata="$backup_dir/metadata.txt"
[[ "$(sudo -n stat -c '%u:%g:%a:%F' "$backup_metadata")" == '0:0:600:regular file' ]]
! sudo -n grep -Fq 'composer_profile=' "$backup_metadata"
! sudo -n grep -Fq 'composer_profile_source=' "$backup_metadata"

set +e
second="$(sudo -n env -u SUDO_USER bash "$wrapper" refresh-profile 2>&1)"
second_rc=$?
set -e
[[ "$second_rc" -eq 64 ]]
grep -Fxq 'REASON=BASELINE_PROFILE_REFRESH_CANDIDATE_AMBIGUOUS' <<<"$second"

printf 'production_baseline_profile_refresh_isolated: PASS\nBACKUP=PASS\nATOMIC_PROFILE=PASS\nNO_REPLACE=PASS\nLEGACY_PARENT_PRESERVED=PASS\nSANITIZED_OUTPUT=PASS\nAPPLICATION_CHANGED=NO\n'
