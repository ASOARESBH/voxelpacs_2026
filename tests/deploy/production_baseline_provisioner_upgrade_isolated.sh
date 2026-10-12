#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_provisioner="$repo/scripts/provision-production-baseline-capture.sh"
source_capture="$repo/scripts/capture-production-baseline.sh"
source_wrapper="$repo/ops/deploy/voxelpacs-production-baseline-capture"
source_sudoers="$repo/ops/sudoers/voxelpacs-production-baseline-capture"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }
run_as_root() {
  if [[ "$EUID" -eq 0 ]]; then "$@"; else sudo -n "$@"; fi
}

fixture_root="$(mktemp -d /tmp/voxelpacs-baseline-upgrade.XXXXXX)"
cleanup() {
  case "$fixture_root" in
    /tmp/voxelpacs-baseline-upgrade.*)
      run_as_root find "$fixture_root" -depth -type f -delete
      run_as_root find "$fixture_root" -depth -type l -delete
      run_as_root find "$fixture_root" -depth -type d -empty -delete
      ;;
    *)
      printf 'unsafe_test_cleanup_path\n' >&2
      exit 1
      ;;
  esac
}
trap cleanup EXIT

create_fixture_repo() {
  local name="$1" root target capture_parent sudoers_parent operation_parent lock_path
  root="$fixture_root/repos/$name"
  target="$fixture_root/targets/$name"
  capture_parent="$target/usr/local/sbin"
  sudoers_parent="$target/etc/sudoers.d"
  operation_parent="$target/var/tmp"
  lock_path="$target/run/lock/provision.lock"
  mkdir -p "$root/scripts" "$root/ops/deploy" "$root/ops/sudoers" \
    "$capture_parent" "$sudoers_parent" "$operation_parent" "$(dirname "$lock_path")"
  cp -- "$source_provisioner" "$root/scripts/provision-production-baseline-capture.sh"
  cp -- "$source_capture" "$root/scripts/capture-production-baseline.sh"
  cp -- "$source_wrapper" "$root/ops/deploy/voxelpacs-production-baseline-capture"
  cp -- "$source_sudoers" "$root/ops/sudoers/voxelpacs-production-baseline-capture"
  python3 - "$root/scripts/provision-production-baseline-capture.sh" "$capture_parent" "$sudoers_parent" "$operation_parent" "$lock_path" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text()
replacements = {
    "readonly CAPTURE_PARENT='/usr/local/sbin'": f"readonly CAPTURE_PARENT={sys.argv[2]!r}",
    "readonly SUDOERS_PARENT='/etc/sudoers.d'": f"readonly SUDOERS_PARENT={sys.argv[3]!r}",
    "readonly OPERATION_PARENT='/var/tmp'": f"readonly OPERATION_PARENT={sys.argv[4]!r}",
    "readonly LOCK_PATH='/run/lock/voxelpacs-production-baseline-capture-provision.lock'": f"readonly LOCK_PATH={sys.argv[5]!r}",
}
for old, new in replacements.items():
    if old not in text:
        raise SystemExit(f'missing replacement marker: {old}')
    text = text.replace(old, new, 1)
path.write_text(text)
PY
  chmod 0755 "$root/scripts/provision-production-baseline-capture.sh" "$root/scripts/capture-production-baseline.sh" "$root/ops/deploy/voxelpacs-production-baseline-capture"
  chmod 0644 "$root/ops/sudoers/voxelpacs-production-baseline-capture"
  chmod 0755 "$capture_parent" "$sudoers_parent" "$operation_parent" "$(dirname "$lock_path")"
  run_as_root chown root:root "$capture_parent" "$sudoers_parent"
  git -C "$root" init -q
  git -C "$root" config user.email 'test@example.invalid'
  git -C "$root" config user.name 'VOXEL PACS test'
  git -C "$root" remote add origin 'https://github.com/ASOARESBH/voxelpacs_2026.git'
  git -C "$root" add scripts ops
  git -C "$root" commit -q -m 'test: baseline provisioner current sources'
  git -C "$root" branch -M main
  git -C "$root" update-ref refs/remotes/origin/main HEAD
  printf '%s\n' "$root"
}

root="$(create_fixture_repo normal)"
sha="$(git -C "$root" rev-parse HEAD)"
provisioner="$root/scripts/provision-production-baseline-capture.sh"
capture_target="$fixture_root/targets/normal/usr/local/sbin/voxelpacs-capture-production-baseline"
wrapper_target="$fixture_root/targets/normal/usr/local/sbin/voxelpacs-production-baseline-capture"
sudoers_target="$fixture_root/targets/normal/etc/sudoers.d/voxelpacs-production-baseline-capture"
run_provisioner() {
  run_as_root env -u SUDO_USER bash "$provisioner" --expected-sha "$sha" "$@"
}

dry_output="$(run_provisioner --dry-run)"
grep -Fxq 'TARGETS_ABSENT=PASS' <<<"$dry_output" || fail normal_dry_run
grep -Fxq 'DRY_RUN=PASS' <<<"$dry_output" || fail normal_dry_status
[[ ! -e "$capture_target" && ! -e "$wrapper_target" && ! -e "$sudoers_target" ]] || fail normal_dry_mutated

install_output="$(run_provisioner --install)"
grep -Fxq 'INSTALL=PASS' <<<"$install_output" || fail normal_install
grep -Fxq 'UPGRADE_APPLIED=NO' <<<"$install_output" || fail normal_upgrade_marker
[[ "$(stat -c '%u:%g:%a' "$capture_target")" == '0:0:555' ]] || fail normal_capture_mode
[[ "$(stat -c '%u:%g:%a' "$wrapper_target")" == '0:0:555' ]] || fail normal_wrapper_mode
[[ "$(stat -c '%u:%g:%a' "$sudoers_target")" == '0:0:440' ]] || fail normal_sudoers_mode
run_as_root visudo -cf "$sudoers_target" >/dev/null || fail normal_sudoers_syntax
before_capture="$(sha256sum "$capture_target")"
before_wrapper="$(sha256sum "$wrapper_target")"
before_sudoers="$(run_as_root sha256sum "$sudoers_target")"
set +e
second_output="$(run_provisioner --install 2>&1)"
second_rc=$?
set -e
[[ "$second_rc" -eq 64 ]] || fail normal_existing_rejected
grep -Fq 'CAPTURE_ALREADY_INSTALLED' <<<"$second_output" || fail normal_existing_reason
[[ "$(sha256sum "$capture_target")" == "$before_capture" ]] || fail normal_capture_changed
[[ "$(sha256sum "$wrapper_target")" == "$before_wrapper" ]] || fail normal_wrapper_changed
[[ "$(run_as_root sha256sum "$sudoers_target")" == "$before_sudoers" ]] || fail normal_sudoers_changed

create_historical_fixture() {
  local name="$1" root target capture_parent sudoers_parent operation_parent lock_path
  root="$fixture_root/repos/$name"
  target="$fixture_root/targets/$name"
  capture_parent="$target/usr/local/sbin"
  sudoers_parent="$target/etc/sudoers.d"
  operation_parent="$target/var/tmp"
  lock_path="$target/run/lock/provision.lock"
  mkdir -p "$root/scripts" "$root/ops/deploy" "$root/ops/sudoers" \
    "$capture_parent" "$sudoers_parent" "$operation_parent" "$(dirname "$lock_path")"
  git init -q "$root"
  git -C "$root" config user.email 'test@example.invalid'
  git -C "$root" config user.name 'VOXEL PACS test'
  git -C "$root" remote add origin 'https://github.com/ASOARESBH/voxelpacs_2026.git'
  for path in scripts/capture-production-baseline.sh scripts/provision-production-baseline-capture.sh ops/deploy/voxelpacs-production-baseline-capture ops/sudoers/voxelpacs-production-baseline-capture; do
    mkdir -p "$root/$(dirname "$path")"
    cp -- "$repo/$path" "$root/$path"
  done
  printf '# historical synthetic capture revision\n' >> "$root/scripts/capture-production-baseline.sh"
  printf '# historical synthetic wrapper revision\n' >> "$root/ops/deploy/voxelpacs-production-baseline-capture"
  chmod 0755 "$root/scripts/provision-production-baseline-capture.sh" "$root/scripts/capture-production-baseline.sh" "$root/ops/deploy/voxelpacs-production-baseline-capture"
  chmod 0644 "$root/ops/sudoers/voxelpacs-production-baseline-capture"
  chmod 0755 "$capture_parent" "$sudoers_parent" "$operation_parent" "$(dirname "$lock_path")"
  run_as_root chown root:root "$capture_parent" "$sudoers_parent"
  git -C "$root" add scripts ops
  git -C "$root" commit -q -m 'test: baseline provisioner historical sources'
  cp -- "$source_provisioner" "$root/scripts/provision-production-baseline-capture.sh"
  cp -- "$source_capture" "$root/scripts/capture-production-baseline.sh"
  cp -- "$source_wrapper" "$root/ops/deploy/voxelpacs-production-baseline-capture"
  cp -- "$source_sudoers" "$root/ops/sudoers/voxelpacs-production-baseline-capture"
  python3 - "$root/scripts/provision-production-baseline-capture.sh" "$capture_parent" "$sudoers_parent" "$operation_parent" "$lock_path" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text()
replacements = {
    "readonly CAPTURE_PARENT='/usr/local/sbin'": f"readonly CAPTURE_PARENT={sys.argv[2]!r}",
    "readonly SUDOERS_PARENT='/etc/sudoers.d'": f"readonly SUDOERS_PARENT={sys.argv[3]!r}",
    "readonly OPERATION_PARENT='/var/tmp'": f"readonly OPERATION_PARENT={sys.argv[4]!r}",
    "readonly LOCK_PATH='/run/lock/voxelpacs-production-baseline-capture-provision.lock'": f"readonly LOCK_PATH={sys.argv[5]!r}",
}
for old, new in replacements.items():
    if old not in text:
        raise SystemExit(f'missing replacement marker: {old}')
    text = text.replace(old, new, 1)
path.write_text(text)
PY
  chmod 0755 "$root/scripts/provision-production-baseline-capture.sh" "$root/scripts/capture-production-baseline.sh" "$root/ops/deploy/voxelpacs-production-baseline-capture"
  chmod 0644 "$root/ops/sudoers/voxelpacs-production-baseline-capture"
  run_as_root chown root:root "$capture_parent" "$sudoers_parent"
  git -C "$root" add scripts ops
  git -C "$root" commit -q -m 'test: baseline provisioner updated sources'
  printf 'fixture-history-marker\n' > "$root/fixture-history-marker"
  git -C "$root" add fixture-history-marker
  git -C "$root" commit -q -m 'test: create synthetic approved current revision'
  git -C "$root" branch -M main
  git -C "$root" update-ref refs/remotes/origin/main HEAD
  printf '%s\n' "$root"
}

upgrade_root="$(create_historical_fixture upgrade)"
upgrade_sha="$(git -C "$upgrade_root" rev-parse HEAD)"
upgrade_provisioner="$upgrade_root/scripts/provision-production-baseline-capture.sh"
upgrade_capture="$fixture_root/targets/upgrade/usr/local/sbin/voxelpacs-capture-production-baseline"
upgrade_wrapper="$fixture_root/targets/upgrade/usr/local/sbin/voxelpacs-production-baseline-capture"
upgrade_sudoers="$fixture_root/targets/upgrade/etc/sudoers.d/voxelpacs-production-baseline-capture"
for path in scripts/capture-production-baseline.sh ops/deploy/voxelpacs-production-baseline-capture ops/sudoers/voxelpacs-production-baseline-capture; do
  target="$upgrade_capture"
  case "$path" in
    ops/deploy/*) target="$upgrade_wrapper" ;;
    ops/sudoers/*) target="$upgrade_sudoers" ;;
  esac
  mkdir -p "$(dirname "$target")"
  git -C "$upgrade_root" show "$upgrade_sha^^:$path" | run_as_root tee "$target" >/dev/null
  run_as_root chown root:root "$target"
  case "$target" in
    "$upgrade_sudoers") run_as_root chmod 0440 "$target" ;;
    *) run_as_root chmod 0555 "$target" ;;
  esac
done
upgrade_run() {
  run_as_root env -u SUDO_USER bash "$upgrade_provisioner" --expected-sha "$upgrade_sha" --upgrade "$@"
}
upgrade_dry="$(upgrade_run --dry-run)"
grep -Fxq 'EXISTING_PROVENANCE=PASS' <<<"$upgrade_dry" || fail upgrade_provenance
grep -Fxq 'TARGETS_EXIST=PASS' <<<"$upgrade_dry" || fail upgrade_targets
grep -Fxq 'DRY_RUN=PASS' <<<"$upgrade_dry" || fail upgrade_dry
old_upgrade_capture="$(sha256sum "$upgrade_capture")"
old_upgrade_wrapper="$(sha256sum "$upgrade_wrapper")"
old_upgrade_sudoers="$(run_as_root sha256sum "$upgrade_sudoers")"
upgrade_output="$(upgrade_run --install)"
grep -Fxq 'UPGRADE_APPLIED=YES' <<<"$upgrade_output" || fail upgrade_applied
grep -Fxq 'ROLLBACK=NOT_NEEDED' <<<"$upgrade_output" || fail upgrade_no_rollback
[[ "$(sha256sum "$upgrade_capture")" != "$old_upgrade_capture" ]] || fail capture_not_updated
[[ "$(sha256sum "$upgrade_wrapper")" != "$old_upgrade_wrapper" ]] || fail wrapper_not_updated
[[ "$(run_as_root sha256sum "$upgrade_sudoers")" == "$old_upgrade_sudoers" ]] || fail sudoers_not_preserved
[[ "$(stat -c '%u:%g:%a' "$upgrade_capture")" == '0:0:555' ]] || fail upgrade_capture_mode
[[ "$(stat -c '%u:%g:%a' "$upgrade_wrapper")" == '0:0:555' ]] || fail upgrade_wrapper_mode
[[ "$(stat -c '%u:%g:%a' "$upgrade_sudoers")" == '0:0:440' ]] || fail upgrade_sudoers_mode

unknown_root="$(create_fixture_repo unknown)"
unknown_sha="$(git -C "$unknown_root" rev-parse HEAD)"
unknown_provisioner="$unknown_root/scripts/provision-production-baseline-capture.sh"
unknown_capture="$fixture_root/targets/unknown/usr/local/sbin/voxelpacs-capture-production-baseline"
unknown_wrapper="$fixture_root/targets/unknown/usr/local/sbin/voxelpacs-production-baseline-capture"
unknown_sudoers="$fixture_root/targets/unknown/etc/sudoers.d/voxelpacs-production-baseline-capture"
printf 'foreign\n' | run_as_root tee "$unknown_capture" >/dev/null
run_as_root cp -- "$source_wrapper" "$unknown_wrapper"
run_as_root cp -- "$source_sudoers" "$unknown_sudoers"
run_as_root chown root:root "$unknown_capture" "$unknown_wrapper" "$unknown_sudoers"
run_as_root chmod 0555 "$unknown_capture" "$unknown_wrapper"
run_as_root chmod 0440 "$unknown_sudoers"
set +e
unknown_output="$(run_as_root env -u SUDO_USER bash "$unknown_provisioner" --expected-sha "$unknown_sha" --upgrade --dry-run 2>&1)"
unknown_rc=$?
set -e
[[ "$unknown_rc" -eq 64 ]] || fail unknown_provenance_accepted
grep -Fq 'CAPTURE_PROVENANCE_UNPROVEN' <<<"$unknown_output" || fail unknown_provenance_reason

auth_root="$(create_historical_fixture bad-mode)"
auth_sha="$(git -C "$auth_root" rev-parse HEAD)"
auth_provisioner="$auth_root/scripts/provision-production-baseline-capture.sh"
auth_capture="$fixture_root/targets/bad-mode/usr/local/sbin/voxelpacs-capture-production-baseline"
auth_wrapper="$fixture_root/targets/bad-mode/usr/local/sbin/voxelpacs-production-baseline-capture"
auth_sudoers="$fixture_root/targets/bad-mode/etc/sudoers.d/voxelpacs-production-baseline-capture"
git -C "$auth_root" show "$auth_sha^^:scripts/capture-production-baseline.sh" | run_as_root tee "$auth_capture" >/dev/null
git -C "$auth_root" show "$auth_sha^^:ops/deploy/voxelpacs-production-baseline-capture" | run_as_root tee "$auth_wrapper" >/dev/null
git -C "$auth_root" show "$auth_sha^^:ops/sudoers/voxelpacs-production-baseline-capture" | run_as_root tee "$auth_sudoers" >/dev/null
run_as_root chown root:root "$auth_capture" "$auth_wrapper" "$auth_sudoers"
run_as_root chmod 0755 "$auth_capture" "$auth_wrapper"
run_as_root chmod 0440 "$auth_sudoers"
set +e
mode_output="$(run_as_root env -u SUDO_USER bash "$auth_provisioner" --expected-sha "$auth_sha" --upgrade --dry-run 2>&1)"
mode_rc=$?
set -e
[[ "$mode_rc" -eq 64 ]] || fail invalid_mode_accepted
grep -Fq 'CAPTURE_PERMISSIONS_INVALID_FOR_UPGRADE' <<<"$mode_output" || fail invalid_mode_reason

failure_root="$(create_fixture_repo partial)"
failure_sha="$(git -C "$failure_root" rev-parse HEAD)"
failure_provisioner="$failure_root/scripts/provision-production-baseline-capture.sh"
python3 - "$failure_provisioner" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text()
needle = '  ln -- "$stage_wrapper" "$INSTALLED_WRAPPER" || fail \'WRAPPER_INSTALL_LINK_FAILED\'\n'
replacement = "  fail 'TEST_INJECTED_SECOND_INSTALL_FAILURE'\n" + needle
if needle not in text:
    raise SystemExit('injection marker not found')
path.write_text(text.replace(needle, replacement, 1))
PY
git -C "$failure_root" add scripts/provision-production-baseline-capture.sh
git -C "$failure_root" commit -q -m 'test: inject partial install failure'
git -C "$failure_root" update-ref refs/remotes/origin/main HEAD
failure_sha="$(git -C "$failure_root" rev-parse HEAD)"
set +e
partial_output="$(run_as_root env -u SUDO_USER bash "$failure_provisioner" --expected-sha "$failure_sha" --install 2>&1)"
partial_rc=$?
set -e
[[ "$partial_rc" -eq 64 ]] || fail partial_failure_not_blocked
grep -Fq 'TEST_INJECTED_SECOND_INSTALL_FAILURE' <<<"$partial_output" || fail partial_failure_reason
grep -Fq 'PARTIAL_INSTALL_CLEANED=YES' <<<"$partial_output" || fail partial_cleanup_missing
[[ ! -e "$fixture_root/targets/partial/usr/local/sbin/voxelpacs-capture-production-baseline" ]] || fail partial_capture_left
[[ ! -e "$fixture_root/targets/partial/usr/local/sbin/voxelpacs-production-baseline-capture" ]] || fail partial_wrapper_left
[[ ! -e "$fixture_root/targets/partial/etc/sudoers.d/voxelpacs-production-baseline-capture" ]] || fail partial_sudoers_left

rollback_root="$(create_historical_fixture rollback)"
rollback_sha="$(git -C "$rollback_root" rev-parse HEAD)"
rollback_provisioner="$rollback_root/scripts/provision-production-baseline-capture.sh"
rollback_capture="$fixture_root/targets/rollback/usr/local/sbin/voxelpacs-capture-production-baseline"
rollback_wrapper="$fixture_root/targets/rollback/usr/local/sbin/voxelpacs-production-baseline-capture"
rollback_sudoers="$fixture_root/targets/rollback/etc/sudoers.d/voxelpacs-production-baseline-capture"
for path in scripts/capture-production-baseline.sh ops/deploy/voxelpacs-production-baseline-capture ops/sudoers/voxelpacs-production-baseline-capture; do
  target="$rollback_capture"
  case "$path" in
    ops/deploy/*) target="$rollback_wrapper" ;;
    ops/sudoers/*) target="$rollback_sudoers" ;;
  esac
  git -C "$rollback_root" show "$rollback_sha^^:$path" | run_as_root tee "$target" >/dev/null
  run_as_root chown root:root "$target"
  case "$target" in
    "$rollback_sudoers") run_as_root chmod 0440 "$target" ;;
    *) run_as_root chmod 0555 "$target" ;;
  esac
done
rollback_before_capture="$(sha256sum "$rollback_capture")"
rollback_before_wrapper="$(sha256sum "$rollback_wrapper")"
rollback_before_sudoers="$(run_as_root sha256sum "$rollback_sudoers")"
python3 - "$rollback_provisioner" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text()
needle = "  mv -f -- \"$stage_sudoers\" \"$INSTALLED_SUDOERS\"\n"
replacement = "  fail 'TEST_INJECTED_UPGRADE_FAILURE'\n" + needle
if needle not in text:
    raise SystemExit('upgrade injection marker not found')
path.write_text(text.replace(needle, replacement, 1))
PY
git -C "$rollback_root" add scripts/provision-production-baseline-capture.sh
git -C "$rollback_root" commit -q -m 'test: inject upgrade rollback failure'
git -C "$rollback_root" update-ref refs/remotes/origin/main HEAD
rollback_sha="$(git -C "$rollback_root" rev-parse HEAD)"
set +e
rollback_output="$(run_as_root env -u SUDO_USER bash "$rollback_provisioner" --expected-sha "$rollback_sha" --upgrade --install 2>&1)"
rollback_rc=$?
set -e
[[ "$rollback_rc" -eq 64 ]] || fail upgrade_failure_not_blocked
grep -Fq 'TEST_INJECTED_UPGRADE_FAILURE' <<<"$rollback_output" || fail upgrade_failure_reason
grep -Fq 'ROLLBACK=PASS' <<<"$rollback_output" || fail upgrade_rollback_missing
[[ "$(sha256sum "$rollback_capture")" == "$rollback_before_capture" ]] || fail rollback_capture_not_restored
[[ "$(sha256sum "$rollback_wrapper")" == "$rollback_before_wrapper" ]] || fail rollback_wrapper_not_restored
[[ "$(run_as_root sha256sum "$rollback_sudoers")" == "$rollback_before_sudoers" ]] || fail rollback_sudoers_not_restored

printf 'TEST=PASS\nPROVISIONER_UPGRADE_ISOLATED=PASS\nDRY_RUN=PASS\nINSTALL=PASS\nUPGRADE=PASS\nHISTORICAL_PROVENANCE=PASS\nNO_REPLACE=PASS\nPREEXISTING_PRESERVED=PASS\nSUDOERS_PRESERVED=PASS\nINVALID_MODE_REJECTED=PASS\nUNKNOWN_PROVENANCE_REJECTED=PASS\nPARTIAL_INSTALL_CLEANUP=PASS\nUPGRADE_ROLLBACK=PASS\nPRODUCTION_CHANGED=NO\n'
