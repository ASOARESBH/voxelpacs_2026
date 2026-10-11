#!/usr/bin/env bash
# VOXEL PACS — provisionamento root-controlled do leitor/gate de drift.
# --dry-run não grava destinos; --install instala somente os helpers fixos.
set -Eeuo pipefail
umask 077
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly SCRIPT_DIR
ROOT="$(git -C "$SCRIPT_DIR/.." rev-parse --show-toplevel 2>/dev/null || true)"
readonly ROOT
readonly READER_SOURCE="$ROOT/ops/deploy/voxelpacs-runtime-drift-manifest-reader"
readonly GATE_SOURCE="$ROOT/ops/deploy/voxelpacs-runtime-drift-gate"
readonly COMPARATOR_SOURCE="$ROOT/scripts/validate-runtime-drift.sh"
readonly SUDOERS_SOURCE="$ROOT/ops/sudoers/voxelpacs-runtime-drift-manifest-reader"
readonly INSTALLED_READER='/usr/local/sbin/voxelpacs-runtime-drift-manifest-reader'
readonly INSTALLED_GATE='/usr/local/sbin/voxelpacs-runtime-drift-gate'
readonly INSTALLED_COMPARATOR='/usr/local/sbin/voxelpacs-validate-runtime-drift'
readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-runtime-drift-manifest-reader'
readonly LOCK_PATH='/run/lock/voxelpacs-runtime-drift-manifest-reader-provision.lock'
readonly STAGE_BASE='/var/tmp'

mode=''
expected_sha=''
stage_dir=''
lock_fd=''
installed_reader=0
installed_gate=0
installed_comparator=0
installed_sudoers=0

fail() {
  printf 'PROVISION=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

usage() {
  printf '%s\n' \
    'uso: provision-runtime-drift-manifest-reader.sh --expected-sha <40-hex-sha> --dry-run' \
    'uso: provision-runtime-drift-manifest-reader.sh --expected-sha <40-hex-sha> --install' >&2
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
  for source in "$READER_SOURCE" "$GATE_SOURCE" "$COMPARATOR_SOURCE" "$SUDOERS_SOURCE"; do
    [[ -f "$source" && ! -L "$source" ]] || fail 'SOURCE_FILE_INVALID'
  done
  for path in \
    ops/deploy/voxelpacs-runtime-drift-manifest-reader \
    ops/deploy/voxelpacs-runtime-drift-gate \
    scripts/validate-runtime-drift.sh \
    scripts/provision-runtime-drift-manifest-reader.sh \
    ops/sudoers/voxelpacs-runtime-drift-manifest-reader; do
    git -C "$ROOT" ls-files --error-unmatch -- "$path" >/dev/null 2>&1 || fail 'SOURCE_NOT_TRACKED'
    git -C "$ROOT" cat-file -e "$expected_sha:$path" >/dev/null 2>&1 || fail 'SOURCE_NOT_IN_SHA'
  done
}

validate_reader_contract() {
  grep -Fqx "readonly BASELINE_ROOT='/var/lib/voxelpacs/baselines'" "$READER_SOURCE" || fail 'READER_BASELINE_ROOT_INVALID'
  grep -Fq 'stdout is intentionally the validated manifest only' "$READER_SOURCE" || fail 'READER_OUTPUT_CONTRACT_INVALID'
  ! grep -Eq -- '--path|--source|--destination|--command|eval|rm -rf|smbclient|systemctl|composer|curl[[:space:]]|mysql|psql' "$READER_SOURCE" || fail 'READER_GENERIC_OPERATION_PRESENT'
  bash -n "$READER_SOURCE"
}

validate_gate_contract() {
  grep -Fqx "readonly RUNTIME_ROOT='/var/www/voxelpacs/app'" "$GATE_SOURCE" || fail 'GATE_RUNTIME_ROOT_INVALID'
  grep -Fqx "readonly READER='/usr/local/sbin/voxelpacs-runtime-drift-manifest-reader'" "$GATE_SOURCE" || fail 'GATE_READER_PATH_INVALID'
  grep -Fqx "readonly COMPARATOR='/usr/local/sbin/voxelpacs-validate-runtime-drift'" "$GATE_SOURCE" || fail 'GATE_COMPARATOR_PATH_INVALID'
  ! grep -Eq -- '--source|--destination|--command|eval|rm -rf|smbclient|systemctl|composer|curl[[:space:]]|mysql|psql' "$GATE_SOURCE" || fail 'GATE_GENERIC_OPERATION_PRESENT'
  bash -n "$GATE_SOURCE"
  bash -n "$COMPARATOR_SOURCE"
}

validate_sudoers() {
  [[ "$(grep -Ec '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:' "$SUDOERS_SOURCE")" -eq 2 ]] || fail 'SUDOERS_RULE_COUNT_INVALID'
  grep -Fq '/usr/local/sbin/voxelpacs-runtime-drift-manifest-reader read --baseline-id' "$SUDOERS_SOURCE" || fail 'SUDOERS_READER_COMMAND_MISSING'
  grep -Fq '/usr/local/sbin/voxelpacs-runtime-drift-gate --baseline-id' "$SUDOERS_SOURCE" || fail 'SUDOERS_GATE_COMMAND_MISSING'
  ! grep -Eq 'NOPASSWD:[[:space:]]+ALL([[:space:]]|$)' "$SUDOERS_SOURCE" || fail 'GENERIC_SUDO_RULE_PRESENT'
  ! grep -Eq '/bin/(ba|d)?sh|/usr/(bin|sbin)/(cp|mv|rm|rsync|install|tee|python|php|composer|systemctl)' "$SUDOERS_SOURCE" || fail 'GENERIC_COMMAND_PRESENT'
  visudo -cf "$SUDOERS_SOURCE" >/dev/null || fail 'SUDOERS_SYNTAX_INVALID'
}

validate_install_scope() {
  [[ -d /usr/local/sbin && ! -L /usr/local/sbin ]] || fail 'SUDO_BIN_DIRECTORY_INVALID'
  [[ -d /etc/sudoers.d && ! -L /etc/sudoers.d ]] || fail 'SUDOERS_DIRECTORY_INVALID'
  for path in "$INSTALLED_READER" "$INSTALLED_GATE" "$INSTALLED_COMPARATOR" "$INSTALLED_SUDOERS"; do
    [[ ! -e "$path" && ! -L "$path" ]] || fail 'TARGET_ALREADY_INSTALLED'
  done
}

acquire_lock() {
  [[ -d "$(dirname "$LOCK_PATH")" && ! -L "$(dirname "$LOCK_PATH")" ]] || fail 'LOCK_DIRECTORY_INVALID'
  exec {lock_fd}>"$LOCK_PATH"
  flock -n "$lock_fd" || fail 'PROVISION_LOCK_BUSY'
}

cleanup_stage() {
  if [[ -n "$stage_dir" && -d "$stage_dir" ]]; then
    rm -f -- "$stage_dir/reader" "$stage_dir/gate" "$stage_dir/comparator" "$stage_dir/sudoers"
    rmdir -- "$stage_dir" 2>/dev/null || true
  fi
}

cleanup() {
  if (( installed_sudoers )); then rm -f -- "$INSTALLED_SUDOERS"; fi
  if (( installed_comparator )); then rm -f -- "$INSTALLED_COMPARATOR"; fi
  if (( installed_gate )); then rm -f -- "$INSTALLED_GATE"; fi
  if (( installed_reader )); then rm -f -- "$INSTALLED_READER"; fi
  cleanup_stage
}
trap cleanup EXIT

stage_files() {
  stage_dir="$(mktemp -d "$STAGE_BASE/voxelpacs-runtime-drift-manifest-reader.XXXXXX")"
  chmod 0700 "$stage_dir"
  install -o root -g root -m 0555 -- "$READER_SOURCE" "$stage_dir/reader"
  install -o root -g root -m 0555 -- "$GATE_SOURCE" "$stage_dir/gate"
  install -o root -g root -m 0555 -- "$COMPARATOR_SOURCE" "$stage_dir/comparator"
  install -o root -g root -m 0440 -- "$SUDOERS_SOURCE" "$stage_dir/sudoers"
  visudo -cf "$stage_dir/sudoers" >/dev/null || fail 'STAGED_SUDOERS_SYNTAX_INVALID'
  [[ "$(sha256sum "$stage_dir/reader" | awk '{print $1}')" == "$(sha256sum "$READER_SOURCE" | awk '{print $1}')" ]] || fail 'STAGED_READER_HASH_MISMATCH'
  [[ "$(sha256sum "$stage_dir/gate" | awk '{print $1}')" == "$(sha256sum "$GATE_SOURCE" | awk '{print $1}')" ]] || fail 'STAGED_GATE_HASH_MISMATCH'
  [[ "$(sha256sum "$stage_dir/comparator" | awk '{print $1}')" == "$(sha256sum "$COMPARATOR_SOURCE" | awk '{print $1}')" ]] || fail 'STAGED_COMPARATOR_HASH_MISMATCH'
  [[ "$(sha256sum "$stage_dir/sudoers" | awk '{print $1}')" == "$(sha256sum "$SUDOERS_SOURCE" | awk '{print $1}')" ]] || fail 'STAGED_SUDOERS_HASH_MISMATCH'
}

parse_args "$@"
require_root
validate_repo
validate_sources
validate_reader_contract
validate_gate_contract
validate_sudoers
validate_install_scope
acquire_lock
printf 'SOURCE_SHA=%s\nREADER_SOURCE=ops/deploy/voxelpacs-runtime-drift-manifest-reader\nGATE_SOURCE=ops/deploy/voxelpacs-runtime-drift-gate\nCOMPARATOR_SOURCE=scripts/validate-runtime-drift.sh\n' "$expected_sha"
if [[ "$mode" == 'dry-run' ]]; then
  printf 'SOURCE_SHA_CHECK=PASS\nREADER_CHECK=PASS\nGATE_CHECK=PASS\nCOMPARATOR_CHECK=PASS\nSUDOERS_SYNTAX=PASS\nTARGETS_ABSENT=PASS\nDRY_RUN=PASS\nINSTALL=NOT_EXECUTED\nAPPLICATION_RUNTIME_CHANGED=NO\n'
  exit 0
fi
stage_files
mv -- "$stage_dir/reader" "$INSTALLED_READER"
installed_reader=1
mv -- "$stage_dir/gate" "$INSTALLED_GATE"
installed_gate=1
mv -- "$stage_dir/comparator" "$INSTALLED_COMPARATOR"
installed_comparator=1
mv -- "$stage_dir/sudoers" "$INSTALLED_SUDOERS"
installed_sudoers=1
cleanup_stage
trap - EXIT
printf 'SOURCE_SHA_CHECK=PASS\nREADER_INSTALLED=YES\nGATE_INSTALLED=YES\nCOMPARATOR_INSTALLED=YES\nSUDOERS_INSTALLED=YES\nREADER_OWNER_MODE=root:root:0555\nGATE_OWNER_MODE=root:root:0555\nCOMPARATOR_OWNER_MODE=root:root:0555\nSUDOERS_OWNER_MODE=root:root:0440\nSUDOERS_SYNTAX=PASS\nINSTALL=PASS\nAPPLICATION_RUNTIME_CHANGED=NO\n'
