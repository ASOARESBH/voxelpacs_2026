#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
source_helper="$repo/ops/deploy/voxelpacs-runtime-blob-auditor"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

run_as_root() {
  if [[ "$EUID" -eq 0 ]]; then
    "$@"
  else
    sudo -n "$@"
  fi
}

fixture_root="$(mktemp -d /tmp/voxelpacs-runtime-blob-auditor.XXXXXX)"
cleanup() {
  [[ "$fixture_root" == /tmp/voxelpacs-runtime-blob-auditor.* && -d "$fixture_root" ]] || return 0
  run_as_root find "$fixture_root" -depth -type f -delete
  run_as_root find "$fixture_root" -depth -type l -delete
  run_as_root find "$fixture_root" -depth -type d -empty -delete
}
trap cleanup EXIT

fixture_helper="$fixture_root/helper"
app="$fixture_root/app"
backup_root="$fixture_root/backups/releases"
backup_sha='0123456789abcdef0123456789abcdef01234567'
backup_dir="$backup_root/$backup_sha"
target='bin/philips_nondicom_production_diagnostic.php'

sed \
  -e "s|readonly APP_ROOT='/var/www/voxelpacs/app'|readonly APP_ROOT='$app'|" \
  -e "s|readonly BACKUP_ROOT='/var/backups/voxelpacs/releases'|readonly BACKUP_ROOT='$backup_root'|" \
  "$source_helper" > "$fixture_helper"
chmod 0755 "$fixture_helper"
mkdir -p "$app/bin" "$backup_dir"
printf '%s\n' 'SYNTHETIC_BLOB_CONTENT_NOT_EXPOSED' > "$app/$target"
chmod 0644 "$app/$target"
touch -d '@1700000000' "$app/$target"

size="$(stat -c '%s' "$app/$target")"
hash="$(sha256sum "$app/$target" | awk '{print $1}')"
mode="$(stat -c '%a' "$app/$target")"
uid="$(stat -c '%u' "$app/$target")"
gid="$(stat -c '%g' "$app/$target")"
mtime="$(stat -c '%Y' "$app/$target")"
(cd "$app" && tar -czf "$backup_dir/release.tar.gz" -- "$target")
printf '%s\n' "$backup_sha" > "$backup_dir/source-sha"
printf 'META\tmechanism_version\t1\nMETA\tsource_sha\t%s\nFILE\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$backup_sha" "$target" "$size" "$hash" "$mode" "$uid" "$gid" "$mtime" > "$backup_dir/manifest.tsv"
sha256sum "$backup_dir/release.tar.gz" > "$backup_dir/release.sha256"
printf 'fixture\n' > "$backup_dir/metadata"
printf 'created\n' > "$backup_dir/status"
chmod 0700 "$backup_root" "$backup_dir"
chmod 0600 "$backup_dir"/*
run_as_root chown -R root:root "$backup_root"

run_helper_as_root() {
  if [[ "$EUID" -eq 0 ]]; then
    env -u SUDO_USER bash "$@"
  else
    sudo -n env -u SUDO_USER bash "$@"
  fi
}

output="$(run_helper_as_root "$fixture_helper" audit --backup-sha "$backup_sha")"
grep -Fxq 'PROVENANCE=BACKUP_MATCH' <<<"$output" || fail provenance_match
 grep -Fxq 'AUDIT=PASS' <<<"$output" || fail audit_pass
 grep -Fxq 'RUNTIME_BACKUP_CONTENT_MATCH=YES' <<<"$output" || fail content_match
 grep -Fxq 'RUNTIME_BACKUP_METADATA_MATCH=YES' <<<"$output" || fail metadata_match
! grep -Fq 'SYNTHETIC_BLOB_CONTENT_NOT_EXPOSED' <<<"$output" || fail raw_content_exposed

before_runtime="$(sha256sum "$app/$target")"
before_backup="$(run_as_root sha256sum "$backup_dir/manifest.tsv")"
printf '%s\n' 'MUTATED_RUNTIME_ONLY' > "$app/$target"
set +e
blocked_output="$(run_helper_as_root "$fixture_helper" audit --backup-sha "$backup_sha" 2>&1)"
blocked_rc=$?
set -e
[[ "$blocked_rc" -ne 0 ]] || fail mismatch_accepted
grep -Fxq 'PROVENANCE=UNKNOWN_RUNTIME_BLOB' <<<"$blocked_output" || fail mismatch_classification
grep -Fxq 'AUDIT=BLOCKED' <<<"$blocked_output" || fail mismatch_blocked
! grep -Fq 'MUTATED_RUNTIME_ONLY' <<<"$blocked_output" || fail mutated_content_exposed
[[ "$(run_as_root sha256sum "$backup_dir/manifest.tsv")" == "$before_backup" ]] || fail backup_modified
[[ "$(sha256sum "$app/$target")" != "$before_runtime" ]] || fail fixture_mutation_missing

set +e
invalid_output="$(run_helper_as_root "$fixture_helper" audit --backup-sha '../escape' 2>&1)"
invalid_rc=$?
set -e
[[ "$invalid_rc" -ne 0 ]] || fail traversal_accepted
grep -Fq 'BACKUP_SHA_INVALID' <<<"$invalid_output" || fail traversal_reason

printf 'TEST=PASS\nISOLATED=PASS\nMATCH=PASS\nMISMATCH_FAIL_CLOSED=PASS\nTRAVERSAL_REJECTED=PASS\nRAW_CONTENT=NOT_EXPOSED\nPRODUCTION_CHANGED=NO\n'
