#!/usr/bin/env bash
# VOXEL PACS — provisionamento root-controlled do diagnóstico Job 522.
# Deve ser executado por administrador root a partir de checkout limpo e SHA
# explicitamente confirmada. Não executa o diagnóstico durante a instalação.
set -Eeuo pipefail
umask 077
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"
readonly ROOT
readonly DIAGNOSTIC_SOURCE="$ROOT/bin/philips_job_522_readonly_audit.php"
readonly HELPER_SOURCE="$ROOT/ops/deploy/voxelpacs-philips-job-522-readonly-audit"
readonly SUDOERS_SOURCE="$ROOT/ops/sudoers/voxelpacs-philips-job-522-readonly-audit"
readonly INSTALLED_DIR='/usr/local/libexec/voxelpacs'
readonly INSTALLED_DIAGNOSTIC="$INSTALLED_DIR/philips_job_522_readonly_audit.php"
readonly INSTALLED_HELPER='/usr/local/sbin/voxelpacs-philips-job-522-readonly-audit'
readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-philips-job-522-readonly-audit'

fail() {
  printf 'PROVISION=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

[[ "$EUID" -eq 0 ]] || fail 'ROOT_REQUIRED'
[[ "$#" -eq 2 && "$1" == '--expected-sha' ]] || fail 'ARGUMENTS_INVALID'
expected_sha="$2"
[[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'SHA_INVALID'
[[ -d "$ROOT/.git" || -f "$ROOT/.git" ]] || fail 'GIT_ROOT_NOT_FOUND'
[[ "$(/usr/bin/git -C "$ROOT" rev-parse HEAD)" == "$expected_sha" ]] || fail 'GIT_SHA_MISMATCH'
[[ -z "$(/usr/bin/git -C "$ROOT" status --porcelain --untracked-files=all)" ]] || fail 'WORKTREE_NOT_CLEAN'
[[ -f "$DIAGNOSTIC_SOURCE" && -f "$HELPER_SOURCE" && -f "$SUDOERS_SOURCE" ]] || fail 'SOURCE_FILE_MISSING'
[[ ! -e "$INSTALLED_DIAGNOSTIC" && ! -e "$INSTALLED_HELPER" && ! -e "$INSTALLED_SUDOERS" ]] || fail 'INSTALLATION_ALREADY_PRESENT'
/usr/bin/php -l "$DIAGNOSTIC_SOURCE" >/dev/null || fail 'DIAGNOSTIC_SYNTAX_INVALID'
/usr/bin/bash -n "$HELPER_SOURCE" || fail 'HELPER_SYNTAX_INVALID'
/usr/bin/grep -Eq '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:[[:space:]]+/usr/local/sbin/voxelpacs-philips-job-522-readonly-audit$' "$SUDOERS_SOURCE" || fail 'SUDOERS_ADMIN_RULE_MISSING'
if /usr/bin/grep -Eq 'NOPASSWD:[[:space:]]+ALL' "$SUDOERS_SOURCE"; then
  fail 'GENERIC_SUDO_RULE_PRESENT'
fi
if /usr/bin/grep -Eq '(/bin/(ba|d)?sh|/usr/(bin|sbin)/(cp|rm|rsync|chmod|chown|systemctl|tar))' "$SUDOERS_SOURCE"; then
  fail 'GENERIC_COMMAND_PRESENT'
fi

readonly staging_root="/usr/local/libexec/.voxelpacs-job-522.$$"
readonly diagnostic_tmp="$staging_root/philips_job_522_readonly_audit.php"
readonly helper_tmp="/usr/local/sbin/.voxelpacs-job-522.$$"
readonly sudoers_tmp="/etc/sudoers.d/.voxelpacs-job-522.$$"
cleanup() {
  /usr/bin/rm -rf -- "$staging_root"
  /usr/bin/rm -f -- "$helper_tmp" "$sudoers_tmp"
}
trap cleanup EXIT

/usr/bin/install -d -o root -g root -m 0750 -- "$staging_root"
/usr/bin/install -o root -g root -m 0750 -- "$DIAGNOSTIC_SOURCE" "$diagnostic_tmp"
/usr/bin/install -o root -g root -m 0750 -- "$HELPER_SOURCE" "$helper_tmp"
/usr/bin/install -o root -g root -m 0440 -- "$SUDOERS_SOURCE" "$sudoers_tmp"
/usr/sbin/visudo -cf "$sudoers_tmp" >/dev/null || fail 'SUDOERS_SYNTAX_INVALID'

/usr/bin/install -d -o root -g root -m 0750 -- "$INSTALLED_DIR"
/usr/bin/mv -- "$diagnostic_tmp" "$INSTALLED_DIAGNOSTIC"
/usr/bin/mv -- "$helper_tmp" "$INSTALLED_HELPER"
/usr/bin/mv -- "$sudoers_tmp" "$INSTALLED_SUDOERS"
trap - EXIT

printf 'PROVISION=PASS\n'
printf 'SOURCE_SHA_PREFIX=%s…\n' "${expected_sha:0:12}"
printf 'DIAGNOSTIC_INSTALLED=YES\nDIAGNOSTIC_OWNER_MODE=root:root:0750\n'
printf 'HELPER_INSTALLED=YES\nHELPER_OWNER_MODE=root:root:0750\n'
printf 'SUDOERS_INSTALLED=YES\nSUDOERS_OWNER_MODE=root:root:0440\nSUDOERS_SYNTAX=PASS\n'
printf 'EXECUTION=NOT_PERFORMED\nDATABASE_CHANGED=NO\nWORKER=NOT_EXECUTED\nBRIDGE=NOT_EXECUTED\nSMB=NOT_EXECUTED\nTRANSMISSION=NO\n'
