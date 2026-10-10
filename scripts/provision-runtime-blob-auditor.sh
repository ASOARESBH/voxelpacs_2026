#!/usr/bin/env bash
# VOXEL PACS — provisionamento root-controlled do auditor de blob.
#
# O provisioner exige checkout limpo e SHA exata. --dry-run não grava destinos.
set -Eeuo pipefail
umask 077
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly SCRIPT_DIR
ROOT="$(git -C "$SCRIPT_DIR/.." rev-parse --show-toplevel 2>/dev/null || true)"
readonly ROOT
readonly HELPER_SOURCE="$ROOT/ops/deploy/voxelpacs-runtime-blob-auditor"
readonly SUDOERS_SOURCE="$ROOT/ops/sudoers/voxelpacs-runtime-blob-auditor"
readonly INSTALLED_HELPER='/usr/local/sbin/voxelpacs-runtime-blob-auditor'
readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-runtime-blob-auditor'
readonly LOCK_PATH='/run/lock/voxelpacs-runtime-blob-auditor-provision.lock'
readonly STAGE_BASE='/var/tmp'

mode=''
expected_sha=''
stage_dir=''
lock_fd=''
installed_helper=0
installed_sudoers=0

fail() {
  printf 'PROVISION=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

usage() {
  printf '%s\n' \
    'uso: provision-runtime-blob-auditor.sh --expected-sha <40-hex-sha> --dry-run' \
    'uso: provision-runtime-blob-auditor.sh --expected-sha <40-hex-sha> --install' >&2
}

parse_args() {
  [[ "$#" -eq 3 && "$1" == '--expected-sha' ]] || { usage; fail 'ARGUMENTS_INVALID'; }
  expected_sha="$2"
  case "$3" in
    --dry-run) mode='dry-run' ;;
    --install) mode='install' ;;
    *) usage; fail 'ARGUMENTS_INVALID' ;;
  esac
  [[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'SHA_INVALID'
}

require_root() {
  [[ "$EUID" -eq 0 ]] || fail 'ROOT_REQUIRED'
}

validate_repo() {
  [[ -n "$ROOT" && ( -d "$ROOT/.git" || -f "$ROOT/.git" ) ]] || fail 'GIT_ROOT_NOT_FOUND'
  [[ "$SCRIPT_DIR" == "$ROOT/scripts" ]] || fail 'SCRIPT_LOCATION_INVALID'
  [[ "$(git -C "$ROOT" rev-parse HEAD 2>/dev/null)" == "$expected_sha" ]] || fail 'GIT_SHA_MISMATCH'
  [[ -z "$(git -C "$ROOT" status --porcelain --untracked-files=all)" ]] || fail 'WORKTREE_NOT_CLEAN'
}

validate_sources() {
  [[ -f "$HELPER_SOURCE" && ! -L "$HELPER_SOURCE" ]] || fail 'HELPER_SOURCE_INVALID'
  [[ -f "$SUDOERS_SOURCE" && ! -L "$SUDOERS_SOURCE" ]] || fail 'SUDOERS_SOURCE_INVALID'
  git -C "$ROOT" ls-files --error-unmatch -- ops/deploy/voxelpacs-runtime-blob-auditor scripts/provision-runtime-blob-auditor.sh ops/sudoers/voxelpacs-runtime-blob-auditor >/dev/null 2>&1 || fail 'SOURCE_NOT_TRACKED'
  git -C "$ROOT" cat-file -e "$expected_sha:ops/deploy/voxelpacs-runtime-blob-auditor" >/dev/null 2>&1 || fail 'HELPER_NOT_IN_SHA'
  git -C "$ROOT" cat-file -e "$expected_sha:scripts/provision-runtime-blob-auditor.sh" >/dev/null 2>&1 || fail 'PROVISIONER_NOT_IN_SHA'
  git -C "$ROOT" cat-file -e "$expected_sha:ops/sudoers/voxelpacs-runtime-blob-auditor" >/dev/null 2>&1 || fail 'SUDOERS_NOT_IN_SHA'
}

validate_helper_contract() {
  grep -Fqx "readonly APP_ROOT='/var/www/voxelpacs/app'" "$HELPER_SOURCE" || fail 'APP_ROOT_CONTRACT_INVALID'
  grep -Fqx "readonly BACKUP_ROOT='/var/backups/voxelpacs/releases'" "$HELPER_SOURCE" || fail 'BACKUP_ROOT_CONTRACT_INVALID'
  grep -Fqx "readonly TARGET_RELATIVE_PATH='bin/philips_nondicom_production_diagnostic.php'" "$HELPER_SOURCE" || fail 'TARGET_PATH_CONTRACT_INVALID'
  ! grep -Eq -- '--path|--source|--destination|--command|eval|NOPASSWD:[[:space:]]+ALL|rm -rf|smbclient|systemctl|composer|php ' "$HELPER_SOURCE" || fail 'HELPER_GENERIC_OPERATION_PRESENT'
  bash -n "$HELPER_SOURCE"
}

validate_sudoers() {
  [[ "$(grep -Ec '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:' "$SUDOERS_SOURCE")" -eq 1 ]] || fail 'SUDOERS_RULE_COUNT_INVALID'
  grep -Fq '/usr/local/sbin/voxelpacs-runtime-blob-auditor audit --backup-sha' "$SUDOERS_SOURCE" || fail 'SUDOERS_COMMAND_MISSING'
  ! grep -Eq 'NOPASSWD:[[:space:]]+ALL([[:space:]]|$)' "$SUDOERS_SOURCE" || fail 'GENERIC_SUDO_RULE_PRESENT'
  ! grep -Eq '/bin/(ba|d)?sh|/usr/(bin|sbin)/(cp|mv|rm|rsync|install|tee|python|php|composer|systemctl)' "$SUDOERS_SOURCE" || fail 'GENERIC_COMMAND_PRESENT'
  visudo -cf "$SUDOERS_SOURCE" >/dev/null || fail 'SUDOERS_SYNTAX_INVALID'
}

validate_install_scope() {
  [[ -d /usr/local/sbin && ! -L /usr/local/sbin ]] || fail 'SUDO_BIN_DIRECTORY_INVALID'
  [[ -d /etc/sudoers.d && ! -L /etc/sudoers.d ]] || fail 'SUDOERS_DIRECTORY_INVALID'
  [[ ! -e "$INSTALLED_HELPER" && ! -L "$INSTALLED_HELPER" ]] || fail 'HELPER_ALREADY_INSTALLED'
  [[ ! -e "$INSTALLED_SUDOERS" && ! -L "$INSTALLED_SUDOERS" ]] || fail 'SUDOERS_ALREADY_INSTALLED'
}

acquire_lock() {
  [[ -d "$(dirname "$LOCK_PATH")" && ! -L "$(dirname "$LOCK_PATH")" ]] || fail 'LOCK_DIRECTORY_INVALID'
  exec {lock_fd}>"$LOCK_PATH"
  flock -n "$lock_fd" || fail 'PROVISION_LOCK_BUSY'
}

cleanup_stage() {
  if [[ -n "$stage_dir" && -d "$stage_dir" ]]; then
    rm -f -- "$stage_dir/helper" "$stage_dir/sudoers"
    rmdir -- "$stage_dir" 2>/dev/null || true
  fi
}

cleanup() {
  if (( installed_sudoers )); then rm -f -- "$INSTALLED_SUDOERS"; fi
  if (( installed_helper )); then rm -f -- "$INSTALLED_HELPER"; fi
  cleanup_stage
}
trap cleanup EXIT

stage_files() {
  stage_dir="$(mktemp -d "$STAGE_BASE/voxelpacs-runtime-blob-auditor.XXXXXX")"
  chmod 0700 "$stage_dir"
  install -o root -g root -m 0555 -- "$HELPER_SOURCE" "$stage_dir/helper"
  install -o root -g root -m 0440 -- "$SUDOERS_SOURCE" "$stage_dir/sudoers"
  visudo -cf "$stage_dir/sudoers" >/dev/null || fail 'STAGED_SUDOERS_SYNTAX_INVALID'
  [[ "$(sha256sum "$stage_dir/helper" | awk '{print $1}')" == "$(sha256sum "$HELPER_SOURCE" | awk '{print $1}')" ]] || fail 'STAGED_HELPER_HASH_MISMATCH'
  [[ "$(sha256sum "$stage_dir/sudoers" | awk '{print $1}')" == "$(sha256sum "$SUDOERS_SOURCE" | awk '{print $1}')" ]] || fail 'STAGED_SUDOERS_HASH_MISMATCH'
}

parse_args "$@"
require_root
validate_repo
validate_sources
validate_helper_contract
validate_sudoers
validate_install_scope
acquire_lock
printf 'SOURCE_SHA=%s\nTARGET_PATH=bin/philips_nondicom_production_diagnostic.php\n' "$expected_sha"
if [[ "$mode" == 'dry-run' ]]; then
  printf 'SOURCE_SHA_CHECK=PASS\nHELPER_CHECK=PASS\nSUDOERS_SYNTAX=PASS\nTARGETS_ABSENT=PASS\nDRY_RUN=PASS\nINSTALL=NOT_EXECUTED\nPRODUCTION_CHANGED=NO\n'
  exit 0
fi
stage_files
mv -- "$stage_dir/helper" "$INSTALLED_HELPER"
installed_helper=1
mv -- "$stage_dir/sudoers" "$INSTALLED_SUDOERS"
installed_sudoers=1
cleanup_stage
trap - EXIT
printf 'SOURCE_SHA_CHECK=PASS\nHELPER_INSTALLED=YES\nSUDOERS_INSTALLED=YES\nHELPER_OWNER_MODE=root:root:0555\nSUDOERS_OWNER_MODE=root:root:0440\nSUDOERS_SYNTAX=PASS\nINSTALL=PASS\nPRODUCTION_CHANGED=YES\n'
