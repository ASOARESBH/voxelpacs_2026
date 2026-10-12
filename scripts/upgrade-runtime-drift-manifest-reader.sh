#!/usr/bin/env bash
# VOXEL PACS — upgrade root-controlled dos helpers do drift.
# --dry-run não grava; --upgrade preserva os destinos atuais e instala a SHA exata.
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
readonly BACKUP_ROOT='/var/backups/voxelpacs/runtime-drift-manifest-reader-upgrades'
readonly LOCK_PATH='/run/lock/voxelpacs-runtime-drift-manifest-reader-upgrade.lock'
readonly STAGE_BASE='/var/tmp'

mode=''
expected_sha=''
stage_dir=''
backup_dir=''
lock_fd=''
upgrade_started=0

fail() {
  printf 'UPGRADE=BLOCKED\nREASON=%s\nAPPLICATION_RUNTIME_CHANGED=NO\n' "$1" >&2
  exit 64
}

usage() {
  printf '%s\n' \
    'uso: upgrade-runtime-drift-manifest-reader.sh --expected-sha <40-hex-sha> --dry-run' \
    'uso: upgrade-runtime-drift-manifest-reader.sh --expected-sha <40-hex-sha> --upgrade' >&2
}

parse_args() {
  [[ "$#" -eq 3 && "$1" == '--expected-sha' ]] || { usage; fail 'ARGUMENTS_INVALID'; }
  expected_sha="$2"
  case "$3" in
    --dry-run) mode='dry-run' ;;
    --upgrade) mode='upgrade' ;;
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
    scripts/upgrade-runtime-drift-manifest-reader.sh \
    ops/sudoers/voxelpacs-runtime-drift-manifest-reader; do
    git -C "$ROOT" ls-files --error-unmatch -- "$path" >/dev/null 2>&1 || fail 'SOURCE_NOT_TRACKED'
    git -C "$ROOT" cat-file -e "$expected_sha:$path" >/dev/null 2>&1 || fail 'SOURCE_NOT_IN_SHA'
  done
}

validate_contracts() {
  grep -Fqx "readonly BASELINE_ROOT='/var/lib/voxelpacs/baselines'" "$READER_SOURCE" || fail 'READER_BASELINE_ROOT_INVALID'
  grep -Fq 'stdout is intentionally the validated manifest only' "$READER_SOURCE" || fail 'READER_OUTPUT_CONTRACT_INVALID'
  grep -Fqx "readonly RUNTIME_ROOT='/var/www/voxelpacs/app'" "$GATE_SOURCE" || fail 'GATE_RUNTIME_ROOT_INVALID'
  grep -Fqx "readonly READER='/usr/local/sbin/voxelpacs-runtime-drift-manifest-reader'" "$GATE_SOURCE" || fail 'GATE_READER_PATH_INVALID'
  grep -Fqx "readonly COMPARATOR='/usr/local/sbin/voxelpacs-validate-runtime-drift'" "$GATE_SOURCE" || fail 'GATE_COMPARATOR_PATH_INVALID'
  grep -Fqx "readonly MECHANISM_VERSION='2'" "$COMPARATOR_SOURCE" || fail 'COMPARATOR_VERSION_INVALID'
  grep -Fqx "readonly APPROVED_CODE_DELTA_PATH_1='app/Services/PhilipsSubmissionMetadataResolver.php'" "$COMPARATOR_SOURCE" || fail 'COMPARATOR_ALLOWLIST_INVALID'
  grep -Fqx "readonly APPROVED_CODE_DELTA_PATH_2='app/Services/ReportVersionPatientNameService.php'" "$COMPARATOR_SOURCE" || fail 'COMPARATOR_ALLOWLIST_INVALID'
  bash -n "$READER_SOURCE"
  bash -n "$GATE_SOURCE"
  bash -n "$COMPARATOR_SOURCE"
  visudo -cf "$SUDOERS_SOURCE" >/dev/null || fail 'SUDOERS_SYNTAX_INVALID'
  [[ "$(grep -Ec '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:' "$SUDOERS_SOURCE")" -eq 2 ]] || fail 'SUDOERS_RULE_COUNT_INVALID'
}

safe_existing_file() {
  local path="$1"
  [[ -f "$path" && ! -L "$path" ]] || return 1
  [[ "$(stat -c '%u:%g' -- "$path")" == '0:0' ]] || return 1
}

validate_targets() {
  [[ -d /usr/local/sbin && ! -L /usr/local/sbin ]] || fail 'SUDO_BIN_DIRECTORY_INVALID'
  [[ -d /etc/sudoers.d && ! -L /etc/sudoers.d ]] || fail 'SUDOERS_DIRECTORY_INVALID'
  for path in "$INSTALLED_READER" "$INSTALLED_GATE" "$INSTALLED_COMPARATOR" "$INSTALLED_SUDOERS"; do
    safe_existing_file "$path" || fail 'TARGET_NOT_ROOT_OWNED_REGULAR_FILE'
  done
  [[ "$(stat -c '%a' "$INSTALLED_READER")" == '555' ]] || fail 'READER_MODE_INVALID'
  [[ "$(stat -c '%a' "$INSTALLED_GATE")" == '555' ]] || fail 'GATE_MODE_INVALID'
  [[ "$(stat -c '%a' "$INSTALLED_COMPARATOR")" == '555' ]] || fail 'COMPARATOR_MODE_INVALID'
  [[ "$(stat -c '%a' "$INSTALLED_SUDOERS")" == '440' ]] || fail 'SUDOERS_MODE_INVALID'
  [[ ! -L "$BACKUP_ROOT" ]] || fail 'BACKUP_ROOT_SYMLINK'
}

prepare_backup_root() {
  if [[ -e "$BACKUP_ROOT" ]]; then
    [[ -d "$BACKUP_ROOT" && ! -L "$BACKUP_ROOT" ]] || fail 'BACKUP_ROOT_INVALID'
    [[ "$(stat -c '%u:%g:%a' "$BACKUP_ROOT")" == '0:0:700' ]] || fail 'BACKUP_ROOT_PERMISSIONS_INVALID'
  else
    install -d -o root -g root -m 0700 "$BACKUP_ROOT"
  fi
}

backup_file() {
  local label="$1"
  local source="$2"
  local target="$backup_dir/$label"
  install -o root -g root -m "$(stat -c '%a' "$source")" -- "$source" "$target"
  printf '%s\t%s\t%s\t%s\t%s\n' "$label" "$(sha256sum "$source" | awk '{print $1}')" "$(stat -c '%a' "$source")" "$(stat -c '%u' "$source")" "$(stat -c '%g' "$source")" >> "$backup_dir/metadata.tsv"
}

create_backup() {
  prepare_backup_root
  backup_dir="$BACKUP_ROOT/${expected_sha}_$(date -u +%Y%m%dT%H%M%SZ)"
  [[ ! -e "$backup_dir" && ! -L "$backup_dir" ]] || fail 'BACKUP_TARGET_ALREADY_EXISTS'
  install -d -o root -g root -m 0700 "$backup_dir"
  : > "$backup_dir/metadata.tsv"
  chmod 0600 "$backup_dir/metadata.tsv"
  backup_file reader "$INSTALLED_READER"
  backup_file gate "$INSTALLED_GATE"
  backup_file comparator "$INSTALLED_COMPARATOR"
  backup_file sudoers "$INSTALLED_SUDOERS"
  sha256sum "$backup_dir/reader" "$backup_dir/gate" "$backup_dir/comparator" "$backup_dir/sudoers" > "$backup_dir/checksums.sha256"
  chmod 0600 "$backup_dir/checksums.sha256"
  sha256sum -c "$backup_dir/checksums.sha256" >/dev/null || fail 'BACKUP_CHECKSUM_INVALID'
  chmod 0600 "$backup_dir/reader" "$backup_dir/gate" "$backup_dir/comparator" "$backup_dir/sudoers"
}

stage_sources() {
  stage_dir="$(mktemp -d "$STAGE_BASE/voxelpacs-runtime-drift-upgrade.XXXXXX")"
  chmod 0700 "$stage_dir"
  install -o root -g root -m 0555 -- "$READER_SOURCE" "$stage_dir/reader"
  install -o root -g root -m 0555 -- "$GATE_SOURCE" "$stage_dir/gate"
  install -o root -g root -m 0555 -- "$COMPARATOR_SOURCE" "$stage_dir/comparator"
  install -o root -g root -m 0440 -- "$SUDOERS_SOURCE" "$stage_dir/sudoers"
  visudo -cf "$stage_dir/sudoers" >/dev/null || fail 'STAGED_SUDOERS_SYNTAX_INVALID'
}

restore_backup() {
  [[ -n "$backup_dir" && -d "$backup_dir" ]] || return 1
  install -o root -g root -m 0555 -- "$backup_dir/reader" "$INSTALLED_READER"
  install -o root -g root -m 0555 -- "$backup_dir/gate" "$INSTALLED_GATE"
  install -o root -g root -m 0555 -- "$backup_dir/comparator" "$INSTALLED_COMPARATOR"
  install -o root -g root -m 0440 -- "$backup_dir/sudoers" "$INSTALLED_SUDOERS"
  sha256sum -c "$backup_dir/checksums.sha256" >/dev/null
}

cleanup_stage() {
  if [[ -n "$stage_dir" && -d "$stage_dir" ]]; then
    rm -f -- "$stage_dir/reader" "$stage_dir/gate" "$stage_dir/comparator" "$stage_dir/sudoers"
    rmdir -- "$stage_dir" 2>/dev/null || true
  fi
}

cleanup() {
  local rc=$?
  set +e
  if [[ "$rc" -ne 0 && "$upgrade_started" -eq 1 ]]; then
    if restore_backup; then
      printf 'ROLLBACK=PASS\n' >&2
    else
      printf 'ROLLBACK=FAIL\n' >&2
      rc=70
    fi
  fi
  cleanup_stage
  if [[ -n "$lock_fd" ]]; then exec {lock_fd}>&-; fi
  exit "$rc"
}
trap cleanup EXIT

parse_args "$@"
require_root
validate_repo
validate_sources
validate_contracts
validate_targets

printf 'SOURCE_SHA_PREFIX=%s…\n' "${expected_sha:0:12}"
printf 'TARGETS_EXISTING=YES\nTARGETS_VALIDATED=PASS\n'
if [[ "$mode" == 'dry-run' ]]; then
  printf 'BACKUP=NOT_EXECUTED\nUPGRADE=NOT_EXECUTED\nDRY_RUN=PASS\nAPPLICATION_RUNTIME_CHANGED=NO\nDATABASE_CHANGED=NO\nTRANSMISSION=NO\n'
  exit 0
fi

[[ -d "$(dirname "$LOCK_PATH")" && ! -L "$(dirname "$LOCK_PATH")" ]] || fail 'LOCK_DIRECTORY_INVALID'
exec {lock_fd}>"$LOCK_PATH"
flock -n "$lock_fd" || fail 'UPGRADE_LOCK_BUSY'
create_backup
stage_sources
upgrade_started=1
mv -- "$stage_dir/reader" "$INSTALLED_READER"
mv -- "$stage_dir/gate" "$INSTALLED_GATE"
mv -- "$stage_dir/comparator" "$INSTALLED_COMPARATOR"
mv -- "$stage_dir/sudoers" "$INSTALLED_SUDOERS"
upgrade_started=0
printf 'BACKUP=PASS\nBACKUP_ID=%s\nUPGRADE=PASS\nREADER_INSTALLED=YES\nGATE_INSTALLED=YES\nCOMPARATOR_INSTALLED=YES\nSUDOERS_INSTALLED=YES\nSUDOERS_SYNTAX=PASS\nOWNER_MODE=root:root:0555/0555/0555/0440\nROLLBACK=READY\nAPPLICATION_RUNTIME_CHANGED=NO\nDATABASE_CHANGED=NO\nWORKER_STARTED=NO\nTRANSMISSION=NO\n' "$(basename "$backup_dir")"
