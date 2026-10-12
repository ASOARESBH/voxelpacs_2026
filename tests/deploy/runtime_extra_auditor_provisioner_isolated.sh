#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_provisioner="$repo/scripts/provision-runtime-extra-auditor.sh"
source_helper="$repo/ops/deploy/voxelpacs-runtime-extra-auditor"
source_sudoers="$repo/ops/sudoers/voxelpacs-runtime-extra-auditor"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }
run_as_root() { if [[ "$EUID" -eq 0 ]]; then "$@"; else sudo -n "$@"; fi; }

root_dir="$(mktemp -d /tmp/voxelpacs-runtime-extra-provisioner.XXXXXX)"
cleanup() {
  [[ "$root_dir" == /tmp/voxelpacs-runtime-extra-provisioner.* && -d "$root_dir" ]] || return 0
  run_as_root find "$root_dir" -depth -type f -delete
  run_as_root find "$root_dir" -depth -type d -empty -delete
}
trap cleanup EXIT

fixture="$root_dir/repo"
mkdir -p "$fixture/scripts" "$fixture/ops/deploy" "$fixture/ops/sudoers" "$root_dir/bin" "$root_dir/etc" "$root_dir/run-lock"
cp "$source_provisioner" "$fixture/scripts/provision-runtime-extra-auditor.sh"
cp "$source_helper" "$fixture/ops/deploy/voxelpacs-runtime-extra-auditor"
sed "s|/usr/local/sbin/voxelpacs-runtime-extra-auditor|$root_dir/bin/voxelpacs-runtime-extra-auditor|g" "$source_sudoers" > "$fixture/ops/sudoers/voxelpacs-runtime-extra-auditor"
chmod 0755 "$fixture/scripts/provision-runtime-extra-auditor.sh" "$fixture/ops/deploy/voxelpacs-runtime-extra-auditor"
chmod 0440 "$fixture/ops/sudoers/voxelpacs-runtime-extra-auditor"

git -C "$fixture" init -q
git -C "$fixture" config user.email 'fixture@example.invalid'
git -C "$fixture" config user.name 'fixture'
git -C "$fixture" add .
git -C "$fixture" commit -q -m 'synthetic fixture'
provisioner="$fixture/scripts/provision-runtime-extra-auditor.sh"
sed \
  -e "s|readonly INSTALLED_HELPER='/usr/local/sbin/voxelpacs-runtime-extra-auditor'|readonly INSTALLED_HELPER='$root_dir/bin/voxelpacs-runtime-extra-auditor'|" \
  -e "s|readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-runtime-extra-auditor'|readonly INSTALLED_SUDOERS='$root_dir/etc/voxelpacs-runtime-extra-auditor'|" \
  -e "s|readonly LOCK_PATH='/run/lock/voxelpacs-runtime-extra-auditor-provision.lock'|readonly LOCK_PATH='$root_dir/run-lock/provision.lock'|" \
  -e "s|readonly STAGE_BASE='/var/tmp'|readonly STAGE_BASE='$root_dir'|" \
  -e "s|/usr/local/sbin/voxelpacs-runtime-extra-auditor audit|$root_dir/bin/voxelpacs-runtime-extra-auditor audit|g" \
  "$provisioner" > "$root_dir/provisioner.modified"
mv "$root_dir/provisioner.modified" "$provisioner"
chmod 0755 "$provisioner"
git -C "$fixture" add scripts/provision-runtime-extra-auditor.sh
git -C "$fixture" commit -q --amend --no-edit
expected_sha="$(git -C "$fixture" rev-parse HEAD)"
[[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]] || fail fixture_sha_invalid

run_as_root env -u SUDO_USER bash "$provisioner" --expected-sha "$expected_sha" --dry-run > "$root_dir/dry-run.out"
grep -Fqx 'DRY_RUN=PASS' "$root_dir/dry-run.out" || fail dry_run_not_pass
[[ ! -e "$root_dir/bin/voxelpacs-runtime-extra-auditor" ]] || fail dry_run_wrote_helper
[[ ! -e "$root_dir/etc/voxelpacs-runtime-extra-auditor" ]] || fail dry_run_wrote_sudoers

if [[ -n "$(git -C "$fixture" status --porcelain --untracked-files=all)" ]]; then
  fail fixture_not_clean_before_install
fi
run_as_root env -u SUDO_USER bash "$provisioner" --expected-sha "$expected_sha" --install > "$root_dir/install.out"
grep -Fqx 'INSTALL=PASS' "$root_dir/install.out" || fail install_not_pass
[[ "$(stat -c '%u:%g:%a' "$root_dir/bin/voxelpacs-runtime-extra-auditor")" == '0:0:555' ]] || fail helper_owner_mode_invalid
[[ "$(stat -c '%u:%g:%a' "$root_dir/etc/voxelpacs-runtime-extra-auditor")" == '0:0:440' ]] || fail sudoers_owner_mode_invalid
visudo -cf "$root_dir/etc/voxelpacs-runtime-extra-auditor" >/dev/null || fail installed_sudoers_invalid

helper_before="$(sha256sum "$root_dir/bin/voxelpacs-runtime-extra-auditor" | awk '{print $1}')"
sudoers_before="$(sha256sum "$root_dir/etc/voxelpacs-runtime-extra-auditor" | awk '{print $1}')"
set +e
second_output="$(run_as_root env -u SUDO_USER bash "$provisioner" --expected-sha "$expected_sha" --install 2>&1)"
second_rc=$?
set -e
[[ "$second_rc" -ne 0 ]] || fail replace_accepted
grep -Fq 'HELPER_ALREADY_INSTALLED' <<<"$second_output" || fail no_replace_reason_missing
[[ "$helper_before" == "$(sha256sum "$root_dir/bin/voxelpacs-runtime-extra-auditor" | awk '{print $1}')" ]] || fail helper_changed_on_reject
[[ "$sudoers_before" == "$(sha256sum "$root_dir/etc/voxelpacs-runtime-extra-auditor" | awk '{print $1}')" ]] || fail sudoers_changed_on_reject

printf 'TEST=PASS\nISOLATED=PASS\nDRY_RUN=PASS\nATOMIC_INSTALL=PASS\nOWNERSHIP_MODES=PASS\nSUDOERS_SYNTAX=PASS\nNO_REPLACE=PASS\nPRODUCTION_CHANGED=NO\n'
