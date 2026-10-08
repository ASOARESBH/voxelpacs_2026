#!/usr/bin/env bash
# VOXEL PACS — provisionamento root-controlled do Philips Folder policy applier.
# Executar somente a partir de staging mínimo derivado de uma main aprovada.
set -Eeuo pipefail
umask 077
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'

readonly EXPECTED_HOST='gateway-dicom-01'
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"
readonly ROOT
readonly HELPER_SOURCE="$ROOT/ops/deploy/voxelpacs-philips-folder-policy-applier"
readonly SUDOERS_SOURCE="$ROOT/ops/sudoers/voxelpacs-philips-folder-policy-applier"
readonly INSTALLED_HELPER='/usr/local/sbin/voxelpacs-philips-folder-policy-applier'
readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-philips-folder-policy-applier'
readonly BACKUP_ROOT='/var/backups/voxelpacs/philips-folder-policy-applier'

fail() {
  printf 'PROVISION=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

[[ "${EUID}" -eq 0 ]] || fail 'ROOT_REQUIRED'
[[ "$(hostname -s)" == "$EXPECTED_HOST" ]] || fail 'HOST_IDENTITY_MISMATCH'
[[ "$#" -ge 2 && "$1" == '--expected-sha' ]] || fail 'ARGUMENTS_INVALID'
expected_sha="$2"
[[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'SHA_INVALID'
shift 2
expected_helper_sha=''
expected_sudoers_sha=''
expected_provisioner_sha=''
dry_run=NO
upgrade=NO
while [[ "$#" -gt 0 ]]; do
  case "$1" in
    --expected-helper-sha) [[ -z "$expected_helper_sha" && "${2:-}" =~ ^[0-9a-f]{64}$ ]] || fail 'HELPER_SHA_ARGUMENT_INVALID'; expected_helper_sha="$2"; shift 2 ;;
    --expected-sudoers-sha) [[ -z "$expected_sudoers_sha" && "${2:-}" =~ ^[0-9a-f]{64}$ ]] || fail 'SUDOERS_SHA_ARGUMENT_INVALID'; expected_sudoers_sha="$2"; shift 2 ;;
    --expected-provisioner-sha) [[ -z "$expected_provisioner_sha" && "${2:-}" =~ ^[0-9a-f]{64}$ ]] || fail 'PROVISIONER_SHA_ARGUMENT_INVALID'; expected_provisioner_sha="$2"; shift 2 ;;
    --dry-run) [[ "$dry_run" == NO ]] || fail 'DUPLICATE_DRY_RUN'; dry_run=YES; shift ;;
    --upgrade) [[ "$upgrade" == NO ]] || fail 'DUPLICATE_UPGRADE'; upgrade=YES; shift ;;
    *) fail 'ARGUMENTS_INVALID' ;;
  esac
done
[[ -n "$expected_helper_sha" && -n "$expected_sudoers_sha" && -n "$expected_provisioner_sha" ]] || fail 'SOURCE_HASHES_REQUIRED'

[[ -f "$HELPER_SOURCE" && ! -L "$HELPER_SOURCE" ]] || fail 'HELPER_SOURCE_INVALID'
[[ -f "$SUDOERS_SOURCE" && ! -L "$SUDOERS_SOURCE" ]] || fail 'SUDOERS_SOURCE_INVALID'
/usr/bin/bash -n "$HELPER_SOURCE" || fail 'HELPER_SYNTAX_INVALID'
/usr/bin/grep -Eq '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:.*philips-folder-policy-applier' "$SUDOERS_SOURCE" || fail 'SUDOERS_ADMIN_RULE_MISSING'
if /usr/bin/grep -Eq 'NOPASSWD:[[:space:]]+ALL|/bin/(ba|d)?sh|/usr/(bin|sbin)/(cp|mv|rm|rsync|chmod|chown|install|systemctl|tar)' "$SUDOERS_SOURCE"; then
  fail 'SUDOERS_RULE_TOO_BROAD'
fi
[[ "$(sha256sum "${BASH_SOURCE[0]}" | awk '{print $1}')" == "$expected_provisioner_sha" ]] || fail 'PROVISIONER_HASH_MISMATCH'
[[ "$(sha256sum "$HELPER_SOURCE" | awk '{print $1}')" == "$expected_helper_sha" ]] || fail 'HELPER_HASH_MISMATCH'
[[ "$(sha256sum "$SUDOERS_SOURCE" | awk '{print $1}')" == "$expected_sudoers_sha" ]] || fail 'SUDOERS_HASH_MISMATCH'
/usr/sbin/visudo -cf "$SUDOERS_SOURCE" >/dev/null 2>&1 || fail 'SUDOERS_SYNTAX_INVALID'

helper_state=ABSENT
sudoers_state=ABSENT
if [[ -e "$INSTALLED_HELPER" ]]; then
  [[ -f "$INSTALLED_HELPER" && ! -L "$INSTALLED_HELPER" ]] || fail 'INSTALLED_HELPER_INVALID'
  [[ "$(stat -c '%U:%G:%a' "$INSTALLED_HELPER")" == 'root:root:750' ]] || fail 'INSTALLED_HELPER_METADATA_INVALID'
  helper_state=PRESENT
fi
if [[ -e "$INSTALLED_SUDOERS" ]]; then
  [[ -f "$INSTALLED_SUDOERS" && ! -L "$INSTALLED_SUDOERS" ]] || fail 'INSTALLED_SUDOERS_INVALID'
  [[ "$(stat -c '%U:%G:%a' "$INSTALLED_SUDOERS")" == 'root:root:440' ]] || fail 'INSTALLED_SUDOERS_METADATA_INVALID'
  /usr/sbin/visudo -cf "$INSTALLED_SUDOERS" >/dev/null 2>&1 || fail 'INSTALLED_SUDOERS_SYNTAX_INVALID'
  sudoers_state=PRESENT
fi
[[ "$upgrade" == YES || ("$helper_state" == ABSENT && "$sudoers_state" == ABSENT) ]] || fail 'INSTALLATION_ALREADY_PRESENT'

printf 'PROVISION=START\nSOURCE_SHA=%s\nHOST=%s\nUPGRADE=%s\nDRY_RUN=%s\nHELPER_SOURCE_HASH=PASS\nSUDOERS_SOURCE_HASH=PASS\nPROVISIONER_SOURCE_HASH=PASS\nHELPER_SYNTAX=PASS\nSUDOERS_SYNTAX=PASS\nCURRENT_HELPER=%s\nCURRENT_SUDOERS=%s\n' "$expected_sha" "$EXPECTED_HOST" "$upgrade" "$dry_run" "$helper_state" "$sudoers_state"
if [[ "$dry_run" == YES ]]; then
  printf 'INSTALL=NOT_EXECUTED\nPOLICY_CHANGED=NO\nBRIDGE_RELOAD=NOT_EXECUTED\nWORKER=NOT_EXECUTED\nSMB=NOT_EXECUTED\nTRANSMISSION=NO\nDICOM_CSTORE=NOT_CHANGED\nPROVISION=END\n'
  exit 0
fi

/usr/bin/install -d -o root -g root -m 0700 -- "$BACKUP_ROOT"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_dir="$(mktemp -d "$BACKUP_ROOT/provision-${timestamp}-XXXXXX")"
chmod 0700 "$backup_dir"
chown root:root "$backup_dir"
restore_needed=YES
restore_previous() {
  if [[ "$restore_needed" != YES ]]; then return; fi
  if [[ -f "$backup_dir/helper" ]]; then /usr/bin/install -o root -g root -m 0750 -- "$backup_dir/helper" "$INSTALLED_HELPER"; else /usr/bin/rm -f -- "$INSTALLED_HELPER"; fi
  if [[ -f "$backup_dir/sudoers" ]]; then /usr/bin/install -o root -g root -m 0440 -- "$backup_dir/sudoers" "$INSTALLED_SUDOERS"; else /usr/bin/rm -f -- "$INSTALLED_SUDOERS"; fi
}
trap restore_previous ERR
if [[ "$helper_state" == PRESENT ]]; then /usr/bin/install -o root -g root -m 0600 -- "$INSTALLED_HELPER" "$backup_dir/helper"; fi
if [[ "$sudoers_state" == PRESENT ]]; then /usr/bin/install -o root -g root -m 0600 -- "$INSTALLED_SUDOERS" "$backup_dir/sudoers"; fi
{
  printf 'FORMAT=1\nSOURCE_COMMIT=%s\nSOURCE_PROVISIONER_SHA256=%s\nSOURCE_HELPER_SHA256=%s\nSOURCE_SUDOERS_SHA256=%s\n' "$expected_sha" "$expected_provisioner_sha" "$expected_helper_sha" "$expected_sudoers_sha"
  printf 'PREVIOUS_HELPER=%s\nPREVIOUS_SUDOERS=%s\n' "$helper_state" "$sudoers_state"
} > "$backup_dir/manifest"
chown root:root "$backup_dir/manifest"
chmod 0600 "$backup_dir/manifest"

helper_tmp="/usr/local/sbin/.voxelpacs-philips-folder-policy-applier.$$"
sudoers_tmp="/etc/sudoers.d/.voxelpacs-philips-folder-policy-applier.$$"
cleanup() { /usr/bin/rm -f -- "$helper_tmp" "$sudoers_tmp"; }
trap 'cleanup; restore_previous' EXIT
/usr/bin/install -o root -g root -m 0750 -- "$HELPER_SOURCE" "$helper_tmp"
/usr/bin/install -o root -g root -m 0440 -- "$SUDOERS_SOURCE" "$sudoers_tmp"
/usr/sbin/visudo -cf "$sudoers_tmp" >/dev/null 2>&1 || fail 'STAGED_SUDOERS_SYNTAX_INVALID'
/usr/bin/mv -f -- "$helper_tmp" "$INSTALLED_HELPER"
/usr/bin/mv -f -- "$sudoers_tmp" "$INSTALLED_SUDOERS"
[[ "$(sha256sum "$INSTALLED_HELPER" | awk '{print $1}')" == "$expected_helper_sha" ]] || fail 'INSTALLED_HELPER_HASH_MISMATCH'
/usr/sbin/visudo -cf "$INSTALLED_SUDOERS" >/dev/null 2>&1 || fail 'INSTALLED_SUDOERS_SYNTAX_INVALID'
[[ "$(stat -c '%U:%G:%a' "$INSTALLED_HELPER")" == 'root:root:750' ]] || fail 'INSTALLED_HELPER_METADATA_INVALID'
[[ "$(stat -c '%U:%G:%a' "$INSTALLED_SUDOERS")" == 'root:root:440' ]] || fail 'INSTALLED_SUDOERS_METADATA_INVALID'
restore_needed=NO
trap - EXIT
cleanup
printf 'PROVISION=PASS\nSOURCE_SHA=%s\nHELPER_INSTALLED=YES\nHELPER_OWNER_MODE=root:root:750\nSUDOERS_INSTALLED=YES\nSUDOERS_OWNER_MODE=root:root:440\nSUDOERS_SYNTAX=PASS\nBACKUP_CREATED=YES\nBACKUP_PATH_RECORDED=YES\nPOLICY_CHANGED=NO\nBRIDGE_RELOAD=NOT_EXECUTED\nWORKER=NOT_EXECUTED\nSMB=NOT_EXECUTED\nTRANSMISSION=NO\nDICOM_CSTORE=NOT_CHANGED\nPROVISION=END\n' "$expected_sha"
