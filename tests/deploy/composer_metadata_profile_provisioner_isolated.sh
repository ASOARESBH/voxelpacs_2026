#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_provisioner="$repo/scripts/provision-composer-metadata-profile-auditor.sh"
source_helper="$repo/ops/deploy/voxelpacs-composer-metadata-profile-auditor"
source_sudoers="$repo/ops/sudoers/voxelpacs-composer-metadata-profile-auditor"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

run_as_root() {
  if [[ "$EUID" -eq 0 ]]; then "$@"; else sudo -n "$@"; fi
}

fixture_root="$(mktemp -d /tmp/voxelpacs-composer-metadata-provisioner.XXXXXX)"
cleanup() {
  [[ "$fixture_root" == /tmp/voxelpacs-composer-metadata-provisioner.* && -d "$fixture_root" ]] || return 0
  run_as_root find "$fixture_root" -depth -type f -delete
  run_as_root find "$fixture_root" -depth -type l -delete
  run_as_root find "$fixture_root" -depth -type d -empty -delete
}
trap cleanup EXIT

create_fixture_repo() {
  local name="$1"
  local root="$fixture_root/$name"
  local target_root="$fixture_root/targets/$name"
  local helper_parent="$target_root/usr/local/sbin"
  local sudoers_parent="$target_root/etc/sudoers.d"
  local lock_root="$fixture_root/locks/$name"
  mkdir -p "$root/scripts" "$root/ops/deploy" "$root/ops/sudoers" "$helper_parent" "$sudoers_parent" "$lock_root"
  cp -- "$source_provisioner" "$root/scripts/provision-composer-metadata-profile-auditor.sh"
  cp -- "$source_helper" "$root/ops/deploy/voxelpacs-composer-metadata-profile-auditor"
  cp -- "$source_sudoers" "$root/ops/sudoers/voxelpacs-composer-metadata-profile-auditor"
  sed -i \
    -e "s|readonly HELPER_PARENT='/usr/local/sbin'|readonly HELPER_PARENT='$helper_parent'|" \
    -e "s|readonly SUDOERS_PARENT='/etc/sudoers.d'|readonly SUDOERS_PARENT='$sudoers_parent'|" \
    -e "s|readonly INSTALLED_HELPER='/usr/local/sbin/voxelpacs-composer-metadata-profile-auditor'|readonly INSTALLED_HELPER='$helper_parent/voxelpacs-composer-metadata-profile-auditor'|" \
    -e "s|readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-composer-metadata-profile-auditor'|readonly INSTALLED_SUDOERS='$sudoers_parent/voxelpacs-composer-metadata-profile-auditor'|" \
    -e "s|readonly LOCK_PATH='/run/lock/voxelpacs-composer-metadata-profile-auditor-provision.lock'|readonly LOCK_PATH='$lock_root/provision.lock'|" \
    "$root/scripts/provision-composer-metadata-profile-auditor.sh"
  chmod 0755 "$root/scripts/provision-composer-metadata-profile-auditor.sh" "$root/ops/deploy/voxelpacs-composer-metadata-profile-auditor"
  chmod 0644 "$root/ops/sudoers/voxelpacs-composer-metadata-profile-auditor"
  chmod 0755 "$helper_parent" "$sudoers_parent"
  git -C "$root" init -q
  git -C "$root" config user.email 'test@example.invalid'
  git -C "$root" config user.name 'VOXEL PACS test'
  git -C "$root" remote add origin 'https://github.com/ASOARESBH/voxelpacs_2026.git'
  git -C "$root" add scripts ops
  git -C "$root" commit -q -m 'test: isolated composer provisioner'
  git -C "$root" branch -M main
  git -C "$root" update-ref refs/remotes/origin/main HEAD
  printf '%s\n' "$root"
}

root="$(create_fixture_repo normal)"
sha="$(git -C "$root" rev-parse HEAD)"
provisioner="$root/scripts/provision-composer-metadata-profile-auditor.sh"
helper_target="$fixture_root/targets/normal/usr/local/sbin/voxelpacs-composer-metadata-profile-auditor"
sudoers_target="$fixture_root/targets/normal/etc/sudoers.d/voxelpacs-composer-metadata-profile-auditor"

run_provisioner() {
  run_as_root env -u SUDO_USER bash "$provisioner" --expected-sha "$sha" "$1"
}

dry_run="$(run_provisioner --dry-run)"
grep -Fxq 'CANONICAL_REMOTE=PASS' <<<"$dry_run" || fail canonical_remote
grep -Fxq 'ORIGIN_MAIN_MATCH=PASS' <<<"$dry_run" || fail origin_main
 grep -Fxq 'ADMIN_DIRECTORIES=PASS' <<<"$dry_run" || fail admin_directories
 grep -Fxq 'DRY_RUN=PASS' <<<"$dry_run" || fail dry_run
[[ ! -e "$helper_target" && ! -e "$sudoers_target" ]] || fail dry_run_mutated_targets

install_output="$(run_provisioner --install)"
grep -Fxq 'INSTALL=PASS' <<<"$install_output" || fail install_pass
[[ "$(stat -c '%u:%g:%a' "$helper_target")" == '0:0:555' ]] || fail helper_final_mode
[[ "$(stat -c '%u:%g:%a' "$sudoers_target")" == '0:0:440' ]] || fail sudoers_final_mode
visudo -cf "$sudoers_target" >/dev/null || fail installed_sudoers_syntax

set +e
second_output="$(run_provisioner --install 2>&1)"
second_rc=$?
set -e
[[ "$second_rc" -eq 64 ]] || fail existing_target_rejected
grep -Fq 'HELPER_ALREADY_INSTALLED' <<<"$second_output" || fail existing_target_reason

bad_remote="$(create_fixture_repo bad-remote)"
git -C "$bad_remote" remote set-url origin 'https://example.invalid/not-voxelpacs.git'
bad_provisioner="$bad_remote/scripts/provision-composer-metadata-profile-auditor.sh"
bad_sha="$(git -C "$bad_remote" rev-parse HEAD)"
set +e
bad_remote_output="$(run_as_root env -u SUDO_USER bash "$bad_provisioner" --expected-sha "$bad_sha" --dry-run 2>&1)"
bad_remote_rc=$?
set -e
[[ "$bad_remote_rc" -eq 64 ]] || fail bad_remote_rejected
grep -Fq 'CANONICAL_REMOTE_MISMATCH' <<<"$bad_remote_output" || fail bad_remote_reason

bad_main="$(create_fixture_repo bad-main)"
bad_main_provisioner="$bad_main/scripts/provision-composer-metadata-profile-auditor.sh"
git -C "$bad_main" commit --allow-empty -q -m 'test: unapproved main candidate'
bad_main_sha="$(git -C "$bad_main" rev-parse HEAD)"
git -C "$bad_main" update-ref refs/remotes/origin/main "$bad_main_sha^"
set +e
bad_main_output="$(run_as_root env -u SUDO_USER bash "$bad_main_provisioner" --expected-sha "$bad_main_sha" --dry-run 2>&1)"
bad_main_rc=$?
set -e
[[ "$bad_main_rc" -eq 64 ]] || fail bad_main_rejected
grep -Fq 'EXPECTED_SHA_NOT_ORIGIN_MAIN' <<<"$bad_main_output" || fail bad_main_reason

unsafe="$(create_fixture_repo unsafe)"
chmod 0775 "$fixture_root/targets/unsafe/usr/local/sbin"
unsafe_provisioner="$unsafe/scripts/provision-composer-metadata-profile-auditor.sh"
unsafe_sha="$(git -C "$unsafe" rev-parse HEAD)"
set +e
unsafe_output="$(run_as_root env -u SUDO_USER bash "$unsafe_provisioner" --expected-sha "$unsafe_sha" --dry-run 2>&1)"
unsafe_rc=$?
set -e
[[ "$unsafe_rc" -eq 64 ]] || fail unsafe_directory_rejected
grep -Fq 'ADMIN_DIRECTORY_GROUP_OTHER_WRITE' <<<"$unsafe_output" || fail unsafe_directory_reason

failure="$(create_fixture_repo failure)"
failure_provisioner="$failure/scripts/provision-composer-metadata-profile-auditor.sh"
python3 - "$failure_provisioner" <<'PY'
from pathlib import Path
p = Path(__import__('sys').argv[1])
s = p.read_text()
needle = "  ln -- \"$source\" \"$target\" || fail 'INSTALL_LINK_FAILED'"
replacement = "  if [[ \"$target\" == \"$INSTALLED_SUDOERS\" ]]; then fail 'TEST_INJECTED_SECOND_INSTALL_FAILURE'; fi\n  ln -- \"$source\" \"$target\" || fail 'INSTALL_LINK_FAILED'"
if needle not in s:
    raise SystemExit('injection point not found')
p.write_text(s.replace(needle, replacement, 1))
PY
git -C "$failure" add scripts/provision-composer-metadata-profile-auditor.sh
git -C "$failure" commit -q -m 'test: inject second install failure'
git -C "$failure" update-ref refs/remotes/origin/main HEAD
failure_sha="$(git -C "$failure" rev-parse HEAD)"
set +e
failure_output="$(run_as_root env -u SUDO_USER bash "$failure_provisioner" --expected-sha "$failure_sha" --install 2>&1)"
failure_rc=$?
set -e
[[ "$failure_rc" -eq 64 ]] || fail second_install_failure_expected
grep -Fq 'TEST_INJECTED_SECOND_INSTALL_FAILURE' <<<"$failure_output" || fail second_install_failure_reason
[[ ! -e "$fixture_root/targets/failure/usr/local/sbin/voxelpacs-composer-metadata-profile-auditor" ]] || fail partial_helper_left
[[ ! -e "$fixture_root/targets/failure/etc/sudoers.d/voxelpacs-composer-metadata-profile-auditor" ]] || fail partial_sudoers_left

printf 'TEST=PASS\nPROVISIONER_ISOLATED=PASS\nDRY_RUN=PASS\nINSTALL=PASS\nFINAL_OWNERSHIP_MODES=PASS\nNO_REPLACE=PASS\nREMOTE_REJECT=PASS\nMAIN_REF_REJECT=PASS\nUNSAFE_DIRECTORY_REJECT=PASS\nPARTIAL_INSTALL_CLEANUP=PASS\nPRODUCTION_CHANGED=NO\n'
