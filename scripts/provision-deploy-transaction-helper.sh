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
if [[ "$#" -eq 3 && "$3" == '--dry-run' ]]; then
  dry_run=YES
elif [[ "$#" -ne 2 ]]; then
  fail 'ARGUMENTS_INVALID'
fi

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

printf 'PROVISION=START\nSOURCE_SHA=%s\nDRY_RUN=%s\n' "$expected_sha" "$dry_run"
if [[ "$dry_run" == YES ]]; then
  printf 'SOURCE_SHA_CHECK=PASS\nHELPER_SYNTAX=PASS\nSUDOERS_SYNTAX=PASS\nINSTALL=NOT_EXECUTED\nPRODUCTION_CHANGED=NO\nTRANSACTION_CHANGED=NO\nPROVISION=END\n'
  exit 0
fi

[[ ! -e "$INSTALLED_HELPER" && ! -e "$INSTALLED_SUDOERS" ]] || fail 'INSTALLATION_ALREADY_PRESENT'
helper_tmp="/usr/local/sbin/.voxelpacs-deploy-transaction.$$"
sudoers_tmp="/etc/sudoers.d/.voxelpacs-deploy-transaction.$$"
cleanup() {
  /usr/bin/rm -f -- "$helper_tmp" "$sudoers_tmp"
}
trap cleanup EXIT
/usr/bin/install -o root -g root -m 0750 -- "$HELPER_SOURCE" "$helper_tmp"
/usr/bin/install -o root -g root -m 0440 -- "$SUDOERS_SOURCE" "$sudoers_tmp"
/usr/sbin/visudo -cf "$sudoers_tmp" >/dev/null || fail 'SUDOERS_SYNTAX_INVALID'
/usr/bin/mv -- "$helper_tmp" "$INSTALLED_HELPER"
/usr/bin/mv -- "$sudoers_tmp" "$INSTALLED_SUDOERS"
trap - EXIT

printf 'PROVISION=PASS\nSOURCE_SHA_PREFIX=%s…\nHELPER_INSTALLED=YES\nHELPER_OWNER_MODE=root:root:0750\nSUDOERS_INSTALLED=YES\nSUDOERS_OWNER_MODE=root:root:0440\nSUDOERS_SYNTAX=PASS\nEXECUTION=NOT_PERFORMED\nTRANSACTION_CHANGED=NO\nPRODUCTION_CHANGED=NO\n' "${expected_sha:0:12}"
