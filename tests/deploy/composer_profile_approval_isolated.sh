#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_helper="$repo/scripts/provision-composer-profile-approval.sh"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }
run_as_root() {
  if [[ "$EUID" -eq 0 ]]; then "$@"; else sudo -n "$@"; fi
}

fixture_root="$(mktemp -d /tmp/voxelpacs-composer-profile-approval.XXXXXX)"
cleanup() {
  run_as_root find "$fixture_root" -depth -type f -delete 2>/dev/null || true
  run_as_root find "$fixture_root" -depth -type l -delete 2>/dev/null || true
  run_as_root find "$fixture_root" -depth -type d -empty -delete 2>/dev/null || true
}
trap cleanup EXIT

fixture_repo="$fixture_root/repo"
bare_repo="$fixture_root/remote.git"
config_parent="$fixture_root/config"
approval_parent="$config_parent/voxelpacs-composer-profile"
approval_file="$approval_parent/voxelpacs-composer-profile-approved-main-sha"
backup_parent="$fixture_root/backups/composer-profile-approval"
shared_parent="$config_parent/voxelpacs"
shared_file="$shared_parent/existing-admin-config"
lock_parent="$fixture_root/lock"
lock_path="$lock_parent/approval.lock"
helper="$fixture_repo/scripts/provision-composer-profile-approval.sh"
mkdir -p "$fixture_repo/scripts" "$config_parent" "$shared_parent" "$lock_parent"
printf 'preexisting-admin-config\n' > "$shared_file"
cp -- "$source_helper" "$helper"
python3 - "$helper" "$config_parent" "$approval_parent" "$approval_file" "$lock_path" "$bare_repo" "$backup_parent" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text()
replacements = {
    "readonly APPROVAL_PARENT='/etc/voxelpacs-composer-profile'": f"readonly APPROVAL_PARENT={sys.argv[3]!r}",
    "readonly APPROVAL_FILE='/etc/voxelpacs-composer-profile/voxelpacs-composer-profile-approved-main-sha'": f"readonly APPROVAL_FILE={sys.argv[4]!r}",
    "readonly APPROVAL_PARENT_PARENT='/etc'": f"readonly APPROVAL_PARENT_PARENT={sys.argv[2]!r}",
    "readonly LOCK_PATH='/run/lock/voxelpacs-composer-profile-approval.lock'": f"readonly LOCK_PATH={sys.argv[5]!r}",
    "readonly BACKUP_PARENT='/var/lib/voxelpacs/admin-backups/composer-profile-approval'": f"readonly BACKUP_PARENT={sys.argv[7]!r}",
    "readonly CANONICAL_REMOTE='https://github.com/ASOARESBH/voxelpacs_2026.git'": f"readonly CANONICAL_REMOTE={'file://' + sys.argv[6]!r}",
}
for old, new in replacements.items():
    if old not in text:
        raise SystemExit(f'missing marker: {old}')
    text = text.replace(old, new, 1)
path.write_text(text)
PY
chmod 0755 "$helper"
git init -q "$fixture_repo"
git -C "$fixture_repo" config user.email 'test@example.invalid'
git -C "$fixture_repo" config user.name 'VOXEL PACS test'
git -C "$fixture_repo" add scripts/provision-composer-profile-approval.sh
git -C "$fixture_repo" commit -q -m 'test: approval helper fixture'
git init -q --bare "$bare_repo"
git -C "$fixture_repo" remote add origin "file://$bare_repo"
git -C "$fixture_repo" branch -M main
git -C "$fixture_repo" push -q -u origin main
fixture_sha="$(git -C "$fixture_repo" rev-parse HEAD)"
run_helper() {
  run_as_root env -u SUDO_USER bash "$helper" --expected-sha "$fixture_sha" "$@"
}
assert_blocked() {
  local expected="$1"; shift
  local output rc
  set +e
  output="$(run_helper "$@" 2>&1)"
  rc=$?
  set -e
  [[ "$rc" -eq 64 ]] || fail "not_blocked:$expected"
  grep -Fq "REASON=$expected" <<<"$output" || fail "reason:$expected"
}

run_as_root chown root:root "$config_parent" "$lock_parent"
run_as_root chmod 0755 "$config_parent" "$lock_parent"
run_as_root chown root:root "$shared_parent" "$shared_file"
run_as_root chmod 0700 "$shared_parent"
shared_before="$(run_as_root sha256sum "$shared_file")"

inspect_output="$(run_helper --inspect)"
grep -Fxq 'DIRECTORY_STATE=ABSENT' <<<"$inspect_output" || fail inspect_absent_directory
grep -Fxq 'ANCHOR_STATE=ABSENT' <<<"$inspect_output" || fail inspect_absent_anchor
grep -Fxq 'CONFIG_CHANGED=NO' <<<"$inspect_output" || fail inspect_mutated

dry_output="$(run_helper --dry-run)"
grep -Fxq 'DIRECTORY_ACTION=CREATE' <<<"$dry_output" || fail dry_directory_action
grep -Fxq 'ANCHOR_ACTION=CREATE' <<<"$dry_output" || fail dry_anchor_action
[[ ! -e "$approval_parent" && ! -e "$approval_file" ]] || fail dry_mutated

apply_output="$(run_helper --apply)"
grep -Fxq 'APPLY=PASS' <<<"$apply_output" || fail apply_result
grep -Fxq 'CONFIG_CHANGED=YES' <<<"$apply_output" || fail apply_changed
[[ "$(run_as_root stat -c '%u:%g:%a' "$approval_parent")" == '0:0:700' ]] || fail apply_parent_mode
[[ "$(run_as_root stat -c '%u:%g:%a' "$approval_file")" == '0:0:600' ]] || fail apply_anchor_mode
run_as_root grep -Fxq "$fixture_sha" "$approval_file" || fail apply_anchor_value

before_anchor="$(run_as_root sha256sum "$approval_file")"
idempotent_output="$(run_helper --apply)"
grep -Fxq 'APPLY=PASS' <<<"$idempotent_output" || fail idempotent_apply
! grep -Fxq 'CONFIG_CHANGED=YES' <<<"$idempotent_output" || fail idempotent_changed
[[ "$(run_as_root sha256sum "$approval_file")" == "$before_anchor" ]] || fail idempotent_anchor_replaced

run_as_root chown root:1 "$approval_parent"
group_output="$(run_helper --dry-run)"
grep -Fxq 'DIRECTORY_ACTION=RECONCILE' <<<"$group_output" || fail group_reconcile_not_reported
run_helper --apply >/dev/null
[[ "$(run_as_root stat -c '%u:%g:%a' "$approval_parent")" == '0:0:700' ]] || fail group_reconcile_not_applied

run_as_root chown 1:0 "$approval_parent"
assert_blocked APPROVAL_PARENT_OWNER_INVALID --inspect
run_as_root chown root:root "$approval_parent"

run_as_root chmod 0770 "$approval_parent"
assert_blocked APPROVAL_PARENT_MODE_WRITEABLE --inspect
run_as_root chmod 0700 "$approval_parent"

run_as_root unlink "$approval_file"
run_as_root rmdir "$approval_parent"
printf 'not-a-directory\n' | run_as_root tee "$approval_parent" >/dev/null
assert_blocked APPROVAL_PARENT_SYMLINK_OR_TYPE_INVALID --apply
run_as_root unlink "$approval_parent"
run_helper --apply >/dev/null

run_as_root unlink "$approval_file"
run_as_root rmdir "$approval_parent"
run_as_root mkdir "$approval_parent-target"
run_as_root ln -s "$approval_parent-target" "$approval_parent"
assert_blocked APPROVAL_PARENT_SYMLINK_OR_TYPE_INVALID --inspect
run_as_root unlink "$approval_parent"
run_as_root rmdir "$approval_parent-target"
run_helper --apply >/dev/null

run_as_root unlink "$approval_file"
printf '%s\n' '0000000000000000000000000000000000000000' | run_as_root tee "$approval_file" >/dev/null
run_as_root chown root:root "$approval_file"
run_as_root chmod 0600 "$approval_file"
before_divergent="$(run_as_root sha256sum "$approval_file" | awk '{print $1}')"
divergent_inspect_output="$(run_helper --inspect)"
grep -Fxq 'ANCHOR_STATE=DIVERGENT' <<<"$divergent_inspect_output" || fail divergent_inspect_state
[[ "$(run_as_root sha256sum "$approval_file" | awk '{print $1}')" == "$before_divergent" ]] || fail divergent_anchor_overwritten
assert_blocked APPROVAL_ANCHOR_DIVERGENT --apply
reconcile_dry_output="$(run_helper --dry-run)"
grep -Fxq 'ANCHOR_ACTION=RECONCILE' <<<"$reconcile_dry_output" || fail reconcile_dry_action
reconcile_output="$(run_helper --reconcile)"
grep -Fxq 'RECONCILE=PASS' <<<"$reconcile_output" || fail reconcile_result
grep -Fxq 'BACKUP=PASS' <<<"$reconcile_output" || fail reconcile_backup_result
backup_dir="$(sed -n 's/^BACKUP_PATH=//p' <<<"$reconcile_output")"
[[ -n "$backup_dir" && -d "$backup_dir" ]] || fail reconcile_backup_path
backup_file="$backup_dir/voxelpacs-composer-profile-approved-main-sha"
[[ "$(run_as_root stat -c '%u:%g:%a' "$backup_file")" == '0:0:600' ]] || fail reconcile_backup_mode
[[ "$(run_as_root sha256sum "$backup_file" | awk '{print $1}')" == "$before_divergent" ]] || fail reconcile_backup_contents
run_as_root grep -Fxq "$fixture_sha" "$approval_file" || fail reconcile_anchor_value
[[ "$(run_as_root sha256sum "$approval_file" | awk '{print $1}')" != "$before_divergent" ]] || fail reconcile_anchor_changed
run_as_root unlink "$approval_file"
run_helper --apply >/dev/null

anchor_target="$config_parent/anchor-target"
run_as_root unlink "$approval_file"
printf '%s\n' "$fixture_sha" | run_as_root tee "$anchor_target" >/dev/null
run_as_root chown root:root "$anchor_target"
run_as_root chmod 0600 "$anchor_target"
run_as_root ln -s "$anchor_target" "$approval_file"
assert_blocked APPROVAL_ANCHOR_SYMLINK_OR_TYPE_INVALID --inspect
run_as_root unlink "$approval_file"
run_as_root unlink "$anchor_target"
run_helper --apply >/dev/null

run_as_root chmod 0644 "$approval_file"
assert_blocked APPROVAL_ANCHOR_SECURITY_INVALID --inspect
run_as_root chmod 0600 "$approval_file"

set +e
wrong_sha_output="$(run_as_root env -u SUDO_USER bash "$helper" --expected-sha 0000000000000000000000000000000000000000 --inspect 2>&1)"
wrong_sha_rc=$?
set -e
[[ "$wrong_sha_rc" -eq 64 ]] || fail wrong_sha_not_blocked
grep -Fq 'REASON=GIT_SHA_MISMATCH' <<<"$wrong_sha_output" || fail wrong_sha_reason

git -C "$fixture_repo" remote set-url origin 'https://wrong.example.invalid/voxelpacs.git'
assert_blocked CANONICAL_REMOTE_MISMATCH --inspect
git -C "$fixture_repo" remote set-url origin "file://$bare_repo"
printf 'dirty\n' > "$fixture_repo/dirty"
assert_blocked WORKTREE_NOT_CLEAN --inspect
unlink "$fixture_repo/dirty"
assert_blocked ARGUMENTS_INVALID --dry-run /tmp

[[ "$(run_as_root stat -c '%u:%g:%a' "$approval_parent")" == '0:0:700' ]] || fail final_parent_state
[[ "$(run_as_root stat -c '%u:%g:%a' "$approval_file")" == '0:0:600' ]] || fail final_anchor_state
run_as_root grep -Fxq "$fixture_sha" "$approval_file" || fail final_anchor_value
[[ "$(run_as_root sha256sum "$shared_file")" == "$shared_before" ]] || fail shared_parent_touched
printf 'TEST=PASS\nCOMPOSER_PROFILE_APPROVAL_ISOLATED=PASS\nDRY_RUN=PASS\nAPPLY=PASS\nIDEMPOTENT_NO_REPLACE=PASS\nGROUP_RECONCILIATION=PASS\nOWNER_REJECTION=PASS\nMODE_REJECTION=PASS\nSYMLINK_REJECTION=PASS\nDIVERGENT_ANCHOR_REJECTION=PASS\nRECONCILE_BACKUP=PASS\nRECONCILE_ATOMIC=PASS\nORIGIN_REJECTION=PASS\nDIRTY_CHECKOUT_REJECTION=PASS\nARGUMENT_REJECTION=PASS\nSHARED_PARENT_PRESERVED=PASS\nFINAL_VALIDATION=PASS\nHOST1_CHANGED=NO\n'
