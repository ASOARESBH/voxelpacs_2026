#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }
run_as_root() {
  if [[ "$EUID" -eq 0 ]]; then "$@"; else sudo -n "$@"; fi
}
run_helper_as_root() {
  if [[ "$EUID" -eq 0 ]]; then env -u SUDO_USER "$@"; else sudo -n env -u SUDO_USER "$@"; fi
}

fixture="$(mktemp -d /tmp/voxelpacs-runtime-drift-upgrade.XXXXXX)"
cleanup() {
  [[ "$fixture" == /tmp/voxelpacs-runtime-drift-upgrade.* && -d "$fixture" ]] || return 0
  run_as_root find "$fixture" -depth -type f -delete
  run_as_root find "$fixture" -depth -type l -delete
  run_as_root find "$fixture" -depth -type d -empty -delete
}
trap cleanup EXIT

mkdir -p "$fixture/repo/scripts" "$fixture/repo/ops/deploy" "$fixture/repo/ops/sudoers"
cp "$repo/ops/deploy/voxelpacs-runtime-drift-manifest-reader" "$fixture/repo/ops/deploy/"
cp "$repo/ops/deploy/voxelpacs-runtime-drift-gate" "$fixture/repo/ops/deploy/"
cp "$repo/scripts/validate-runtime-drift.sh" "$fixture/repo/scripts/"
cp "$repo/scripts/provision-runtime-drift-manifest-reader.sh" "$fixture/repo/scripts/"
cp "$repo/ops/sudoers/voxelpacs-runtime-drift-manifest-reader" "$fixture/repo/ops/sudoers/"
sed \
  -e "s|readonly INSTALLED_READER='/usr/local/sbin/voxelpacs-runtime-drift-manifest-reader'|readonly INSTALLED_READER='$fixture/targets/reader'|" \
  -e "s|readonly INSTALLED_GATE='/usr/local/sbin/voxelpacs-runtime-drift-gate'|readonly INSTALLED_GATE='$fixture/targets/gate'|" \
  -e "s|readonly INSTALLED_COMPARATOR='/usr/local/sbin/voxelpacs-validate-runtime-drift'|readonly INSTALLED_COMPARATOR='$fixture/targets/comparator'|" \
  -e "s|readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-runtime-drift-manifest-reader'|readonly INSTALLED_SUDOERS='$fixture/targets/sudoers'|" \
  -e "s|readonly BACKUP_ROOT='/var/backups/voxelpacs/runtime-drift-manifest-reader-upgrades'|readonly BACKUP_ROOT='$fixture/backups'|" \
  -e "s|readonly LOCK_PATH='/run/lock/voxelpacs-runtime-drift-manifest-reader-upgrade.lock'|readonly LOCK_PATH='$fixture/run/upgrade.lock'|" \
  -e "s|readonly STAGE_BASE='/var/tmp'|readonly STAGE_BASE='$fixture/stage'|" \
  "$repo/scripts/upgrade-runtime-drift-manifest-reader.sh" > "$fixture/repo/scripts/upgrade-runtime-drift-manifest-reader.sh"

mkdir -p "$fixture/targets" "$fixture/backups" "$fixture/run" "$fixture/stage"
run_as_root chmod 0700 "$fixture/backups" "$fixture/run" "$fixture/stage"
run_as_root chown root:root "$fixture/backups" "$fixture/run" "$fixture/stage"
for name in reader gate comparator; do
  printf 'OLD_%s_NOT_EXPOSED\n' "$name" > "$fixture/targets/$name"
  run_as_root chown root:root "$fixture/targets/$name"
  run_as_root chmod 0555 "$fixture/targets/$name"
done
printf 'old-sudoers-fixture\n' > "$fixture/targets/sudoers"
run_as_root chown root:root "$fixture/targets/sudoers"
run_as_root chmod 0440 "$fixture/targets/sudoers"

cd "$fixture/repo"
git init -q
git config user.email 'fixture@example.invalid'
git config user.name 'fixture'
git add .
git commit -qm 'fixture'
expected_sha="$(git rev-parse HEAD)"

old_reader_sha="$(sha256sum "$fixture/targets/reader" | awk '{print $1}')"
old_gate_sha="$(sha256sum "$fixture/targets/gate" | awk '{print $1}')"
old_comparator_sha="$(sha256sum "$fixture/targets/comparator" | awk '{print $1}')"
old_sudoers_sha="$(run_as_root sha256sum "$fixture/targets/sudoers" | awk '{print $1}')"

set +e
run_helper_as_root bash scripts/upgrade-runtime-drift-manifest-reader.sh --expected-sha "$expected_sha" --dry-run > "$fixture/dry-run.out" 2>&1
rc=$?
set -e
[[ "$rc" -eq 0 ]] || { cat "$fixture/dry-run.out" >&2; fail dry_run_rejected; }
grep -Fq 'DRY_RUN=PASS' "$fixture/dry-run.out" || fail dry_run_marker
[[ ! -e "$fixture/run/upgrade.lock" ]] || fail dry_run_created_lock
[[ -z "$(run_as_root find "$fixture/backups" -mindepth 1 -print -quit)" ]] || fail dry_run_created_backup
[[ "$old_reader_sha" == "$(sha256sum "$fixture/targets/reader" | awk '{print $1}')" ]] || fail dry_run_mutated_reader
[[ "$old_gate_sha" == "$(sha256sum "$fixture/targets/gate" | awk '{print $1}')" ]] || fail dry_run_mutated_gate
[[ "$old_comparator_sha" == "$(sha256sum "$fixture/targets/comparator" | awk '{print $1}')" ]] || fail dry_run_mutated_comparator
[[ "$old_sudoers_sha" == "$(run_as_root sha256sum "$fixture/targets/sudoers" | awk '{print $1}')" ]] || fail dry_run_mutated_sudoers

run_helper_as_root bash scripts/upgrade-runtime-drift-manifest-reader.sh --expected-sha "$expected_sha" --upgrade > "$fixture/upgrade.out" 2>&1 || { cat "$fixture/upgrade.out" >&2; fail upgrade_rejected; }
grep -Fq 'UPGRADE=PASS' "$fixture/upgrade.out" || fail upgrade_marker
grep -Fq 'BACKUP=PASS' "$fixture/upgrade.out" || fail backup_marker
[[ "$(sha256sum "$fixture/targets/reader" | awk '{print $1}')" == "$(sha256sum ops/deploy/voxelpacs-runtime-drift-manifest-reader | awk '{print $1}')" ]] || fail reader_not_upgraded
[[ "$(sha256sum "$fixture/targets/gate" | awk '{print $1}')" == "$(sha256sum ops/deploy/voxelpacs-runtime-drift-gate | awk '{print $1}')" ]] || fail gate_not_upgraded
[[ "$(sha256sum "$fixture/targets/comparator" | awk '{print $1}')" == "$(sha256sum scripts/validate-runtime-drift.sh | awk '{print $1}')" ]] || fail comparator_not_upgraded
[[ "$(run_as_root sha256sum "$fixture/targets/sudoers" | awk '{print $1}')" == "$(sha256sum ops/sudoers/voxelpacs-runtime-drift-manifest-reader | awk '{print $1}')" ]] || fail sudoers_not_upgraded
backup_count="$(run_as_root find "$fixture/backups" -mindepth 1 -maxdepth 1 -type d | wc -l)"
[[ "$backup_count" -eq 1 ]] || fail backup_count
backup_dir="$(run_as_root find "$fixture/backups" -mindepth 1 -maxdepth 1 -type d -print -quit)"
for name in reader gate comparator sudoers metadata.tsv checksums.sha256; do
  run_as_root test -f "$backup_dir/$name" || fail "backup_missing:$name"
done
[[ "$(stat -c '%u:%g:%a' "$fixture/targets/reader")" == '0:0:555' ]] || fail reader_metadata
[[ "$(stat -c '%u:%g:%a' "$fixture/targets/sudoers")" == '0:0:440' ]] || fail sudoers_metadata

printf 'TEST=PASS\nISOLATED=PASS\nDRY_RUN_NO_MUTATION=PASS\nBACKUP=PASS\nUPGRADE=PASS\nTARGET_METADATA=PASS\nPRODUCTION_CHANGED=NO\nDATABASE_CHANGED=NO\nTRANSMISSION=NO\n'
