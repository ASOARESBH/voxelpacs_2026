#!/usr/bin/env bash
# VOXEL PACS — provisionamento root-controlled do seletor B6.1.
# Executar somente a partir de checkout limpo e SHA aprovada.
set -Eeuo pipefail
umask 077
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"
readonly ROOT
readonly DIAGNOSTIC_SOURCE="$ROOT/bin/philips_nondicom_b6_selector.php"
readonly HELPER_SOURCE="$ROOT/ops/deploy/voxelpacs-philips-nondicom-b6-selector"
readonly SUDOERS_SOURCE="$ROOT/ops/sudoers/voxelpacs-philips-nondicom-b6-selector"
readonly INSTALLED_DIR='/usr/local/libexec/voxelpacs'
readonly INSTALLED_DIAGNOSTIC="$INSTALLED_DIR/philips_nondicom_b6_selector.php"
readonly INSTALLED_HELPER='/usr/local/sbin/voxelpacs-philips-nondicom-b6-selector'
readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-philips-nondicom-b6-selector'

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
[[ -f "$DIAGNOSTIC_SOURCE" && -f "$HELPER_SOURCE" && -f "$SUDOERS_SOURCE" ]] || fail 'SOURCE_FILE_MISSING'
/usr/bin/php -l "$DIAGNOSTIC_SOURCE" >/dev/null || fail 'DIAGNOSTIC_SYNTAX_INVALID'
/usr/bin/bash -n "$HELPER_SOURCE" || fail 'HELPER_SYNTAX_INVALID'
/usr/bin/grep -Eq '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:[[:space:]]+/usr/local/sbin/voxelpacs-philips-nondicom-b6-selector$' "$SUDOERS_SOURCE" || fail 'SUDOERS_ADMIN_RULE_MISSING'
if /usr/bin/grep -Eq 'NOPASSWD:[[:space:]]+ALL' "$SUDOERS_SOURCE"; then
  fail 'GENERIC_SUDO_RULE_PRESENT'
fi
if /usr/bin/grep -Eq '(/bin/(ba|d)?sh|/usr/(bin|sbin)/(cp|rm|rsync|chmod|chown|systemctl|tar))' "$SUDOERS_SOURCE"; then
  fail 'GENERIC_COMMAND_PRESENT'
fi

printf 'PROVISION=START\nSOURCE_SHA=%s\nDRY_RUN=%s\n' "$expected_sha" "$dry_run"
if [[ "$dry_run" == YES ]]; then
  printf 'SOURCE_SHA_CHECK=PASS\nDIAGNOSTIC_SYNTAX=PASS\nHELPER_SYNTAX=PASS\nSUDOERS_SYNTAX=PASS\nINSTALL=NOT_EXECUTED\nPRODUCTION_CHANGED=NO\nDATABASE_CHANGED=NO\nWORKER=NOT_EXECUTED\nBRIDGE=NOT_EXECUTED\nSMB=NOT_EXECUTED\nTRANSMISSION=NO\nPROVISION=END\n'
  exit 0
fi

[[ ! -e "$INSTALLED_DIAGNOSTIC" && ! -e "$INSTALLED_HELPER" && ! -e "$INSTALLED_SUDOERS" ]] || fail 'INSTALLATION_ALREADY_PRESENT'
readonly staging_dir="/usr/local/libexec/.voxelpacs-b6-selector.$$"
readonly diagnostic_tmp="$staging_dir/philips_nondicom_b6_selector.php"
readonly helper_tmp="/usr/local/sbin/.voxelpacs-philips-nondicom-b6-selector.$$"
readonly sudoers_tmp="/etc/sudoers.d/.voxelpacs-philips-nondicom-b6-selector.$$"
cleanup() {
  /usr/bin/rm -f -- "$diagnostic_tmp" "$helper_tmp" "$sudoers_tmp"
  /usr/bin/rmdir -- "$staging_dir" 2>/dev/null || true
}
trap cleanup EXIT

/usr/bin/install -d -o root -g root -m 0750 -- "$staging_dir"
/usr/bin/install -o root -g root -m 0750 -- "$DIAGNOSTIC_SOURCE" "$diagnostic_tmp"
/usr/bin/install -o root -g root -m 0750 -- "$HELPER_SOURCE" "$helper_tmp"
/usr/bin/install -o root -g root -m 0440 -- "$SUDOERS_SOURCE" "$sudoers_tmp"
/usr/sbin/visudo -cf "$sudoers_tmp" >/dev/null || fail 'SUDOERS_SYNTAX_INVALID'
/usr/bin/mv -- "$diagnostic_tmp" "$INSTALLED_DIAGNOSTIC"
/usr/bin/mv -- "$helper_tmp" "$INSTALLED_HELPER"
/usr/bin/mv -- "$sudoers_tmp" "$INSTALLED_SUDOERS"
trap - EXIT

printf 'PROVISION=PASS\nSOURCE_SHA_PREFIX=%s…\nDIAGNOSTIC_INSTALLED=YES\nDIAGNOSTIC_OWNER_MODE=root:root:0750\nHELPER_INSTALLED=YES\nHELPER_OWNER_MODE=root:root:0750\nSUDOERS_INSTALLED=YES\nSUDOERS_OWNER_MODE=root:root:0440\nSUDOERS_SYNTAX=PASS\nEXECUTION=NOT_PERFORMED\nDATABASE_CHANGED=NO\nWORKER=NOT_EXECUTED\nBRIDGE=NOT_EXECUTED\nSMB=NOT_EXECUTED\nTRANSMISSION=NO\n' "${expected_sha:0:12}"
