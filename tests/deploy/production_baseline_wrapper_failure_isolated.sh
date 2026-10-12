#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd -- "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
test_root="$(mktemp -d "${TMPDIR:-/tmp}/voxelpacs-baseline-wrapper-test.XXXXXX")"
cleanup() {
  case "$test_root" in
    /tmp/voxelpacs-baseline-wrapper-test.*)
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
output="$test_root/output"
parent="$test_root/etc"
profile_parent="$parent/voxelpacs-composer-profile"
profile_file="$profile_parent/voxelpacs-composer-profile-approved-main-sha"
fake_capture="$test_root/fake-capture.sh"
wrapper="$test_root/wrapper.sh"
profile_sha='0123456789abcdef0123456789abcdef01234567'

mkdir -p "$source" "$output" "$profile_parent"
printf '%s\n' "$profile_sha" > "$profile_file"
sudo -n chown root:root "$profile_parent" "$profile_file"
sudo -n chmod 0700 "$profile_parent"
sudo -n chmod 0600 "$profile_file"
cat > "$fake_capture" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
printf 'BASELINE_CAPTURE=INCOMPLETE\nREASON=COMPOSER_PROFILE_STATE_AMBIGUOUS\nHASH_UNAVAILABLE=3\nOUTPUT_NOT_PUBLISHED=YES\n' >&2
exit 65
EOF
chmod 0755 "$fake_capture"

cp -- "$root/ops/deploy/voxelpacs-production-baseline-capture" "$wrapper"
python3 - "$wrapper" "$source" "$output" "$fake_capture" "$profile_file" <<'PY'
from pathlib import Path
import sys

path, source, output, capture, profile = sys.argv[1:]
text = Path(path).read_text()
replacements = {
    "readonly SOURCE_ROOT='/var/www/voxelpacs/app'": f"readonly SOURCE_ROOT='{source}'",
    "readonly OUTPUT_ROOT='/var/lib/voxelpacs/baselines'": f"readonly OUTPUT_ROOT='{output}'",
    "readonly CAPTURE_SCRIPT='/usr/local/sbin/voxelpacs-capture-production-baseline'": f"readonly CAPTURE_SCRIPT='{capture}'",
    "readonly COMPOSER_PROFILE_SHA_FILE='/etc/voxelpacs-composer-profile/voxelpacs-composer-profile-approved-main-sha'": f"readonly COMPOSER_PROFILE_SHA_FILE='{profile}'",
}
for old, new in replacements.items():
    if old not in text:
        raise SystemExit(f'missing replacement marker: {old}')
    text = text.replace(old, new, 1)
Path(path).write_text(text)
PY
chmod 0755 "$wrapper"

set +e
result="$(sudo -n env -u SUDO_USER bash "$wrapper" capture 2>&1)"
rc=$?
set -e
[[ "$rc" -eq 65 ]]
grep -Fxq 'BASELINE_CAPTURE=INCOMPLETE' <<<"$result"
grep -Fxq 'REASON=COMPOSER_PROFILE_STATE_AMBIGUOUS' <<<"$result"
grep -Fxq 'HASH_UNAVAILABLE=3' <<<"$result"
grep -Fxq 'OUTPUT_PUBLISHED=NO' <<<"$result"
grep -Fxq 'PRODUCTION_CHANGED=NO' <<<"$result"
! grep -Fq 'reference' <<<"$result"
! grep -Fq "$profile_sha" <<<"$result"

printf 'production_baseline_wrapper_failure_isolated: PASS\n'
