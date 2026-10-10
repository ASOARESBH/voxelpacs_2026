#!/usr/bin/env bash
# VOXEL PACS — provisionamento root-controlled da captura de baseline.
# Exige checkout limpo e SHA exata; --dry-run não grava destinos.
set -Eeuo pipefail
umask 077
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly SCRIPT_DIR
ROOT="$(git -C "$SCRIPT_DIR/.." rev-parse --show-toplevel 2>/dev/null || true)"
readonly ROOT
readonly CAPTURE_SOURCE="$ROOT/scripts/capture-production-baseline.sh"
readonly WRAPPER_SOURCE="$ROOT/ops/deploy/voxelpacs-production-baseline-capture"
readonly SUDOERS_SOURCE="$ROOT/ops/sudoers/voxelpacs-production-baseline-capture"
readonly INSTALLED_CAPTURE='/usr/local/sbin/voxelpacs-capture-production-baseline'
readonly INSTALLED_WRAPPER='/usr/local/sbin/voxelpacs-production-baseline-capture'
readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-production-baseline-capture'
readonly LOCK_PATH='/run/lock/voxelpacs-production-baseline-capture-provision.lock'
readonly STAGE_BASE='/var/tmp'

mode=''
expected_sha=''
stage_dir=''
lock_fd=''
installed_capture=0
installed_wrapper=0
installed_sudoers=0

fail() {
  printf 'PROVISION=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

usage() {
  printf '%s\n' \
    'uso: provision-production-baseline-capture.sh --expected-sha <40-hex-sha> --dry-run' \
    'uso: provision-production-baseline-capture.sh --expected-sha <40-hex-sha> --install' >&2
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
  for source in "$CAPTURE_SOURCE" "$WRAPPER_SOURCE" "$SUDOERS_SOURCE"; do
    [[ -f "$source" && ! -L "$source" ]] || fail 'SOURCE_FILE_INVALID'
  done
  git -C "$ROOT" ls-files --error-unmatch \
    scripts/capture-production-baseline.sh \
    scripts/provision-production-baseline-capture.sh \
    ops/deploy/voxelpacs-production-baseline-capture \
    ops/sudoers/voxelpacs-production-baseline-capture >/dev/null 2>&1 || fail 'SOURCE_NOT_TRACKED'
  for path in \
    scripts/capture-production-baseline.sh \
    scripts/provision-production-baseline-capture.sh \
    ops/deploy/voxelpacs-production-baseline-capture \
    ops/sudoers/voxelpacs-production-baseline-capture; do
    git -C "$ROOT" cat-file -e "$expected_sha:$path" >/dev/null 2>&1 || fail 'SOURCE_NOT_IN_SHA'
  done
}

validate_capture_contract() {
  grep -Fqx "readonly DEFAULT_SOURCE_ROOT='/var/www/voxelpacs/app'" "$CAPTURE_SOURCE" || fail 'CAPTURE_SOURCE_ROOT_INVALID'
  grep -Fqx "readonly DEFAULT_OUTPUT_ROOT='/var/lib/voxelpacs/baselines'" "$CAPTURE_SOURCE" || fail 'CAPTURE_OUTPUT_ROOT_INVALID'
  grep -Fq 'find -P "$source_root" -xdev' "$CAPTURE_SOURCE" || fail 'CAPTURE_TRAVERSAL_CONTRACT_INVALID'
  grep -Fq 'require_complete' "$CAPTURE_SOURCE" || fail 'CAPTURE_COMPLETENESS_CONTRACT_INVALID'
  bash -n "$CAPTURE_SOURCE"
}

validate_wrapper_contract() {
  grep -Fqx "readonly SOURCE_ROOT='/var/www/voxelpacs/app'" "$WRAPPER_SOURCE" || fail 'WRAPPER_SOURCE_ROOT_INVALID'
  grep -Fqx "readonly OUTPUT_ROOT='/var/lib/voxelpacs/baselines'" "$WRAPPER_SOURCE" || fail 'WRAPPER_OUTPUT_ROOT_INVALID'
  grep -Fqx "readonly CAPTURE_SCRIPT='/usr/local/libexec/voxelpacs-capture-production-baseline'" "$WRAPPER_SOURCE" || fail 'WRAPPER_CAPTURE_PATH_INVALID'
  grep -Fq '[[ "$#" -eq 1 && "$1" == '\''capture'\'' ]]' "$WRAPPER_SOURCE" || fail 'WRAPPER_ARGUMENT_CONTRACT_INVALID'
  ! grep -Eq 'eval|systemctl|smbclient|curl[[:space:]]|mysql|psql|composer|NOPASSWD:[[:space:]]+ALL([[:space:]]|$)' "$WRAPPER_SOURCE" || fail 'WRAPPER_GENERIC_OPERATION_PRESENT'
  bash -n "$WRAPPER_SOURCE"
}

validate_sudoers() {
  [[ "$(grep -Ec '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:' "$SUDOERS_SOURCE")" -eq 1 ]] || fail 'SUDOERS_RULE_COUNT_INVALID'
  grep -Fq '/usr/local/sbin/voxelpacs-production-baseline-capture capture' "$SUDOERS_SOURCE" || fail 'SUDOERS_COMMAND_MISSING'
  ! grep -Eq 'NOPASSWD:[[:space:]]+ALL([[:space:]]|$)' "$SUDOERS_SOURCE" || fail 'GENERIC_SUDO_RULE_PRESENT'
  ! grep -Eq '/bin/(ba|d)?sh|/usr/(bin|sbin)/(cp|mv|rm|rsync|install|python|php|composer|systemctl)' "$SUDOERS_SOURCE" || fail 'GENERIC_COMMAND_PRESENT'
  visudo -cf "$SUDOERS_SOURCE" >/dev/null || fail 'SUDOERS_SYNTAX_INVALID'
}

validate_install_scope() {
  [[ -d /usr/local/sbin && ! -L /usr/local/sbin ]] || fail 'SUDO_BIN_DIRECTORY_INVALID'
  [[ -d /etc/sudoers.d && ! -L /etc/sudoers.d ]] || fail 'SUDOERS_DIRECTORY_INVALID'
  [[ ! -e "$INSTALLED_CAPTURE" && ! -L "$INSTALLED_CAPTURE" ]] || fail 'CAPTURE_ALREADY_INSTALLED'
  [[ ! -e "$INSTALLED_WRAPPER" && ! -L "$INSTALLED_WRAPPER" ]] || fail 'WRAPPER_ALREADY_INSTALLED'
  [[ ! -e "$INSTALLED_SUDOERS" && ! -L "$INSTALLED_SUDOERS" ]] || fail 'SUDOERS_ALREADY_INSTALLED'
}

acquire_lock() {
  [[ -d "$(dirname "$LOCK_PATH")" && ! -L "$(dirname "$LOCK_PATH")" ]] || fail 'LOCK_DIRECTORY_INVALID'
  exec {lock_fd}>"$LOCK_PATH"
  flock -n "$lock_fd" || fail 'PROVISION_LOCK_BUSY'
}

cleanup_stage() {
  if [[ -n "$stage_dir" && -d "$stage_dir" ]]; then
    rm -f -- "$stage_dir/capture" "$stage_dir/wrapper" "$stage_dir/sudoers"
    rmdir -- "$stage_dir" 2>/dev/null || true
  fi
}

cleanup() {
  if (( installed_sudoers )); then rm -f -- "$INSTALLED_SUDOERS"; fi
  if (( installed_wrapper )); then rm -f -- "$INSTALLED_WRAPPER"; fi
  if (( installed_capture )); then rm -f -- "$INSTALLED_CAPTURE"; fi
  cleanup_stage
}
trap cleanup EXIT

stage_files() {
  stage_dir="$(mktemp -d "$STAGE_BASE/voxelpacs-production-baseline-capture.XXXXXX")"
  chmod 0700 "$stage_dir"
  install -o root -g root -m 0555 -- "$CAPTURE_SOURCE" "$stage_dir/capture"
  install -o root -g root -m 0555 -- "$WRAPPER_SOURCE" "$stage_dir/wrapper"
  install -o root -g root -m 0440 -- "$SUDOERS_SOURCE" "$stage_dir/sudoers"
  visudo -cf "$stage_dir/sudoers" >/dev/null || fail 'STAGED_SUDOERS_SYNTAX_INVALID'
  [[ "$(sha256sum "$stage_dir/capture" | awk '{print $1}')" == "$(sha256sum "$CAPTURE_SOURCE" | awk '{print $1}')" ]] || fail 'STAGED_CAPTURE_HASH_MISMATCH'
  [[ "$(sha256sum "$stage_dir/wrapper" | awk '{print $1}')" == "$(sha256sum "$WRAPPER_SOURCE" | awk '{print $1}')" ]] || fail 'STAGED_WRAPPER_HASH_MISMATCH'
  [[ "$(sha256sum "$stage_dir/sudoers" | awk '{print $1}')" == "$(sha256sum "$SUDOERS_SOURCE" | awk '{print $1}')" ]] || fail 'STAGED_SUDOERS_HASH_MISMATCH'
}

parse_args "$@"
require_root
validate_repo
validate_sources
validate_capture_contract
validate_wrapper_contract
validate_sudoers
validate_install_scope
acquire_lock
printf 'SOURCE_SHA=%s\nCAPTURE_SOURCE=scripts/capture-production-baseline.sh\nWRAPPER_SOURCE=ops/deploy/voxelpacs-production-baseline-capture\n' "$expected_sha"
if [[ "$mode" == 'dry-run' ]]; then
  printf 'SOURCE_SHA_CHECK=PASS\nCAPTURE_CHECK=PASS\nWRAPPER_CHECK=PASS\nSUDOERS_SYNTAX=PASS\nTARGETS_ABSENT=PASS\nDRY_RUN=PASS\nINSTALL=NOT_EXECUTED\nPRODUCTION_CHANGED=NO\n'
  exit 0
fi
stage_files
mv -- "$stage_dir/capture" "$INSTALLED_CAPTURE"
installed_capture=1
mv -- "$stage_dir/wrapper" "$INSTALLED_WRAPPER"
installed_wrapper=1
mv -- "$stage_dir/sudoers" "$INSTALLED_SUDOERS"
installed_sudoers=1
cleanup_stage
trap - EXIT
printf 'SOURCE_SHA_CHECK=PASS\nCAPTURE_INSTALLED=YES\nWRAPPER_INSTALLED=YES\nSUDOERS_INSTALLED=YES\nCAPTURE_OWNER_MODE=root:root:0555\nWRAPPER_OWNER_MODE=root:root:0555\nSUDOERS_OWNER_MODE=root:root:0440\nSUDOERS_SYNTAX=PASS\nINSTALL=PASS\nPRODUCTION_CHANGED=YES\n'
