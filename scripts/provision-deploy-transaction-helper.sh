#!/usr/bin/env bash
# VOXEL PACS — provisionamento root-controlled do helper de transações.
set -Eeuo pipefail
umask 077
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"
readonly ROOT
readonly HELPER_SOURCE="$ROOT/ops/deploy/voxelpacs-deploy-transaction"
readonly SUDOERS_SOURCE="$ROOT/ops/sudoers/voxelpacs-deploy-transaction"
readonly INSTALLED_HELPER='/usr/local/sbin/voxelpacs-deploy-transaction'
readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-deploy-transaction'

fail() {
  printf 'PROVISION=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

[[ "$EUID" -eq 0 ]] || fail 'ROOT_REQUIRED'
[[ "$#" -ge 2 && "$1" == '--expected-sha' ]] || fail 'ARGUMENTS_INVALID'
expected_sha="$2"
[[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'SHA_INVALID'
dry_run=NO
upgrade=NO
shift 2
while [[ "$#" -gt 0 ]]; do
  case "$1" in
    --dry-run)
      [[ "$dry_run" == NO ]] || fail 'ARGUMENTS_INVALID'
      dry_run=YES
      ;;
    --upgrade)
      [[ "$upgrade" == NO ]] || fail 'ARGUMENTS_INVALID'
      upgrade=YES
      ;;
    *)
      fail 'ARGUMENTS_INVALID'
      ;;
  esac
  shift
done

[[ -d "$ROOT/.git" || -f "$ROOT/.git" ]] || fail 'GIT_ROOT_NOT_FOUND'
[[ "$(/usr/bin/git -C "$ROOT" rev-parse HEAD)" == "$expected_sha" ]] || fail 'GIT_SHA_MISMATCH'
[[ -z "$(/usr/bin/git -C "$ROOT" status --porcelain --untracked-files=all)" ]] || fail 'WORKTREE_NOT_CLEAN'
[[ -f "$HELPER_SOURCE" && -f "$SUDOERS_SOURCE" ]] || fail 'SOURCE_FILE_MISSING'
/usr/bin/bash -n "$HELPER_SOURCE" || fail 'HELPER_SYNTAX_INVALID'
/usr/bin/grep -Eq '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:[[:space:]]+/usr/local/sbin/voxelpacs-deploy-transaction (inspect|reconcile) --sha \[a-f0-9\]\*' "$SUDOERS_SOURCE" || fail 'SUDOERS_ADMIN_RULE_MISSING'
if /usr/bin/grep -Eq 'NOPASSWD:[[:space:]]+ALL' "$SUDOERS_SOURCE"; then
  fail 'GENERIC_SUDO_RULE_PRESENT'
fi
if /usr/bin/grep -Eq '(/bin/(ba|d)?sh|/usr/(bin|sbin)/(cp|rm|rsync|chmod|chown|systemctl|tar))' "$SUDOERS_SOURCE"; then
  fail 'GENERIC_COMMAND_PRESENT'
fi

if [[ "$upgrade" == YES ]]; then
  [[ -f "$INSTALLED_HELPER" && ! -L "$INSTALLED_HELPER" ]] || fail 'EXISTING_HELPER_MISSING_FOR_UPGRADE'
  [[ -f "$INSTALLED_SUDOERS" && ! -L "$INSTALLED_SUDOERS" ]] || fail 'EXISTING_SUDOERS_MISSING_FOR_UPGRADE'
  [[ "$(/usr/bin/stat -c '%U:%a' -- "$INSTALLED_HELPER")" == 'root:750' ]] || fail 'EXISTING_HELPER_PERMISSIONS_INVALID'
  [[ "$(/usr/bin/stat -c '%U:%a' -- "$INSTALLED_SUDOERS")" == 'root:440' ]] || fail 'EXISTING_SUDOERS_PERMISSIONS_INVALID'
fi

printf 'PROVISION=START\nSOURCE_SHA=%s\nDRY_RUN=%s\nUPGRADE=%s\n' "$expected_sha" "$dry_run" "$upgrade"
if [[ "$dry_run" == YES ]]; then
  printf 'SOURCE_SHA_CHECK=PASS\nHELPER_SYNTAX=PASS\nSUDOERS_SYNTAX=PASS\nINSTALL=NOT_EXECUTED\nPRODUCTION_CHANGED=NO\nTRANSACTION_CHANGED=NO\nPROVISION=END\n'
  exit 0
fi

if [[ "$upgrade" != YES ]]; then
  [[ ! -e "$INSTALLED_HELPER" && ! -e "$INSTALLED_SUDOERS" ]] || fail 'INSTALLATION_ALREADY_PRESENT'
fi
helper_tmp="/usr/local/sbin/.voxelpacs-deploy-transaction.$$"
sudoers_tmp="/etc/sudoers.d/.voxelpacs-deploy-transaction.$$"
helper_backup="/run/.voxelpacs-deploy-transaction-helper-backup.$$"
sudoers_backup="/run/.voxelpacs-deploy-transaction-sudoers-backup.$$"
cleanup() {
  /usr/bin/rm -f -- "$helper_tmp" "$sudoers_tmp"
  /usr/bin/rm -f -- "$helper_backup" "$sudoers_backup"
}
rollback_on_error() {
  local status="$?"
  if [[ "$status" -ne 0 && "$upgrade" == YES ]]; then
    if [[ -f "$helper_backup" && ! -L "$helper_backup" ]]; then
      /usr/bin/install -o root -g root -m 0750 -- "$helper_backup" "$INSTALLED_HELPER" || true
    fi
    if [[ -f "$sudoers_backup" && ! -L "$sudoers_backup" ]]; then
      /usr/bin/install -o root -g root -m 0440 -- "$sudoers_backup" "$INSTALLED_SUDOERS" || true
    fi
  fi
  cleanup
  exit "$status"
}
trap rollback_on_error EXIT
if [[ "$upgrade" == YES ]]; then
  /usr/bin/install -o root -g root -m 0750 -- "$INSTALLED_HELPER" "$helper_backup"
  /usr/bin/install -o root -g root -m 0440 -- "$INSTALLED_SUDOERS" "$sudoers_backup"
fi
/usr/bin/install -o root -g root -m 0750 -- "$HELPER_SOURCE" "$helper_tmp"
/usr/bin/install -o root -g root -m 0440 -- "$SUDOERS_SOURCE" "$sudoers_tmp"
/usr/sbin/visudo -cf "$sudoers_tmp" >/dev/null || fail 'SUDOERS_SYNTAX_INVALID'
/usr/bin/mv -f -- "$helper_tmp" "$INSTALLED_HELPER"
/usr/bin/mv -f -- "$sudoers_tmp" "$INSTALLED_SUDOERS"
cleanup
trap - EXIT

printf 'PROVISION=PASS\nSOURCE_SHA_PREFIX=%s…\nHELPER_INSTALLED=YES\nHELPER_OWNER_MODE=root:root:0750\nSUDOERS_INSTALLED=YES\nSUDOERS_OWNER_MODE=root:root:0440\nSUDOERS_SYNTAX=PASS\nUPGRADE_APPLIED=%s\nEXECUTION=NOT_PERFORMED\nTRANSACTION_CHANGED=NO\nPRODUCTION_CHANGED=NO\n' "${expected_sha:0:12}" "$upgrade"
