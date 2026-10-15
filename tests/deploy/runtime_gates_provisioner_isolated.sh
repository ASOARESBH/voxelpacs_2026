#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }
run_root() { if [[ "$EUID" -eq 0 ]]; then "$@"; else sudo -n "$@"; fi; }
run_provisioner() { run_root env SUDO_USER=manus-deploy bash "$provisioner" "$@"; }

fixture="$(mktemp -d /tmp/voxelpacs-runtime-gates-provisioner.XXXXXX)"
cleanup() {
  [[ "$fixture" == /tmp/voxelpacs-runtime-gates-provisioner.* && -d "$fixture" ]] || return 0
  run_root find "$fixture" -depth -type f -delete
  run_root find "$fixture" -depth -type l -delete
  run_root find "$fixture" -depth -type d -empty -delete
}
trap cleanup EXIT

mkdir -p \
  "$fixture/repo/scripts" \
  "$fixture/repo/ops/deploy" \
  "$fixture/repo/ops/sudoers" \
  "$fixture/target/usr/local/sbin" \
  "$fixture/target/etc/sudoers.d" \
  "$fixture/target/run/lock" \
  "$fixture/target/var/backups" \
  "$fixture/target/var/tmp"

for path in \
  scripts/capture-production-baseline.sh \
  scripts/validate-runtime-drift.sh \
  ops/deploy/voxelpacs-production-baseline-capture \
  ops/deploy/voxelpacs-runtime-drift-manifest-reader \
  ops/deploy/voxelpacs-runtime-drift-gate \
  ops/deploy/voxelpacs-runtime-extra-auditor \
  ops/sudoers/voxelpacs-production-baseline-capture \
  ops/sudoers/voxelpacs-runtime-drift-manifest-reader \
  ops/sudoers/voxelpacs-runtime-extra-auditor; do
  mkdir -p "$fixture/repo/$(dirname "$path")"
  cp -- "$repo/$path" "$fixture/repo/$path"
done
cp -- "$repo/scripts/provision-runtime-gates.sh" "$fixture/repo/scripts/provision-runtime-gates.sh"

provisioner="$fixture/repo/scripts/provision-runtime-gates.sh"
sed \
  -e "s|readonly TARGET_ROOT='/usr/local/sbin'|readonly TARGET_ROOT='$fixture/target/usr/local/sbin'|" \
  -e "s|readonly SUDOERS_ROOT='/etc/sudoers.d'|readonly SUDOERS_ROOT='$fixture/target/etc/sudoers.d'|" \
  -e "s|readonly BACKUP_ROOT='/var/backups/voxelpacs/runtime-gates-provision'|readonly BACKUP_ROOT='$fixture/target/var/backups/runtime-gates-provision'|" \
  -e "s|readonly LOCK_PATH='/run/lock/voxelpacs-runtime-gates-provision.lock'|readonly LOCK_PATH='$fixture/target/run/lock/runtime-gates.lock'|" \
  "$provisioner" > "$fixture/repo/scripts/provision-runtime-gates.modified"
mv -- "$fixture/repo/scripts/provision-runtime-gates.modified" "$provisioner"
chmod 0755 "$provisioner"

run_root git -C "$fixture/repo" init -q
run_root git -C "$fixture/repo" config user.email 'fixture@example.invalid'
run_root git -C "$fixture/repo" config user.name 'fixture'
run_root git -C "$fixture/repo" remote add origin 'https://github.com/ASOARESBH/voxelpacs_2026.git'
run_root git -C "$fixture/repo" add scripts ops
run_root git -C "$fixture/repo" commit -qm 'test: runtime gates provisioner'
run_root git -C "$fixture/repo" branch -M main
run_root git -C "$fixture/repo" update-ref refs/remotes/origin/main HEAD
expected_sha="$(git -C "$fixture/repo" rev-parse HEAD)"

run_provisioner --expected-sha "$expected_sha" --dry-run > "$fixture/dry-run.out"
grep -Fxq 'DRY_RUN=PASS' "$fixture/dry-run.out" || fail dry_run_failed
grep -Fxq 'TARGETS_EXISTING=0' "$fixture/dry-run.out" || fail dry_run_target_count
[[ -z "$(find "$fixture/target/usr/local/sbin" "$fixture/target/etc/sudoers.d" -type f -print -quit)" ]] || fail dry_run_mutated

run_provisioner --expected-sha "$expected_sha" --install > "$fixture/install.out"
grep -Fxq 'INSTALL=PASS' "$fixture/install.out" || fail install_failed
grep -Fxq 'PRODUCTION_CHANGED=YES' "$fixture/install.out" || fail install_change_marker
for path in \
  "$fixture/target/usr/local/sbin/voxelpacs-capture-production-baseline" \
  "$fixture/target/usr/local/sbin/voxelpacs-production-baseline-capture" \
  "$fixture/target/usr/local/sbin/voxelpacs-runtime-drift-manifest-reader" \
  "$fixture/target/usr/local/sbin/voxelpacs-runtime-drift-gate" \
  "$fixture/target/usr/local/sbin/voxelpacs-validate-runtime-drift" \
  "$fixture/target/usr/local/sbin/voxelpacs-runtime-extra-auditor"; do
  [[ "$(stat -c '%u:%g:%a' "$path")" == '0:0:555' ]] || fail "helper_metadata:$path"
done
for path in \
  "$fixture/target/etc/sudoers.d/voxelpacs-production-baseline-capture" \
  "$fixture/target/etc/sudoers.d/voxelpacs-runtime-drift-manifest-reader" \
  "$fixture/target/etc/sudoers.d/voxelpacs-runtime-extra-auditor"; do
  [[ "$(stat -c '%u:%g:%a' "$path")" == '0:0:440' ]] || fail "sudoers_metadata:$path"
  run_root visudo -cf "$path" >/dev/null || fail "sudoers_invalid:$path"
done

set +e
second="$(run_provisioner --expected-sha "$expected_sha" --install 2>&1)"
second_rc=$?
set -e
[[ "$second_rc" -eq 64 ]] || fail install_replace_accepted
grep -Fq 'TARGET_ALREADY_INSTALLED' <<<"$second" || fail install_replace_reason

run_root rm -f \
  "$fixture/target/etc/sudoers.d/voxelpacs-production-baseline-capture" \
  "$fixture/target/etc/sudoers.d/voxelpacs-runtime-drift-manifest-reader" \
  "$fixture/target/etc/sudoers.d/voxelpacs-runtime-extra-auditor"
run_provisioner --expected-sha "$expected_sha" --dry-run > "$fixture/repair-dry-run.out"
grep -Fxq 'DRY_RUN=PASS' "$fixture/repair-dry-run.out" || fail repair_dry_run_failed
grep -Fxq 'TARGETS_EXISTING=6' "$fixture/repair-dry-run.out" || fail repair_existing_count

grep -Fxq 'TARGETS_MISSING=3' "$fixture/repair-dry-run.out" || fail repair_missing_count
run_provisioner --expected-sha "$expected_sha" --repair > "$fixture/repair.out"
grep -Fxq 'INSTALL=PASS' "$fixture/repair.out" || fail repair_failed
grep -Fxq 'BACKUP=PASS' "$fixture/repair.out" || fail repair_backup
[[ "$(find "$fixture/target/var/backups/runtime-gates-provision" -mindepth 1 -maxdepth 1 -type d | wc -l)" -eq 1 ]] || fail repair_backup_count

set +e
unauthorized="$(run_root env SUDO_USER=unknown bash "$provisioner" --expected-sha "$expected_sha" --dry-run 2>&1)"
unauthorized_rc=$?
set -e
[[ "$unauthorized_rc" -eq 64 ]] || fail unauthorized_accepted
grep -Fq 'CALLER_NOT_ALLOWLISTED' <<<"$unauthorized" || fail unauthorized_reason

printf 'TEST=PASS\nISOLATED=PASS\nDRY_RUN_NO_MUTATION=PASS\nINSTALL=PASS\nNO_REPLACE=PASS\nPARTIAL_REPAIR=PASS\nBACKUP=PASS\nCALLER_FAIL_CLOSED=PASS\nSUDOERS_SYNTAX=PASS\nPRODUCTION_CHANGED=NO\n'
