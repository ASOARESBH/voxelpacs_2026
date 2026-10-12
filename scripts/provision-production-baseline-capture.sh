#!/usr/bin/env bash
# VOXEL PACS — provisionamento root-controlled da captura de baseline.
# Exige checkout limpo e SHA exata; --dry-run não altera destinos.
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
readonly CAPTURE_PARENT='/usr/local/sbin'
readonly SUDOERS_PARENT='/etc/sudoers.d'
readonly INSTALLED_CAPTURE="$CAPTURE_PARENT/voxelpacs-capture-production-baseline"
readonly INSTALLED_WRAPPER="$CAPTURE_PARENT/voxelpacs-production-baseline-capture"
readonly INSTALLED_SUDOERS="$SUDOERS_PARENT/voxelpacs-production-baseline-capture"
readonly LOCK_PATH='/run/lock/voxelpacs-production-baseline-capture-provision.lock'
readonly OPERATION_PARENT='/var/tmp'

mode=''
expected_sha=''
upgrade='NO'
operation_dir=''
stage_capture_dir=''
stage_sudoers_dir=''
stage_capture=''
stage_wrapper=''
stage_sudoers=''
backup_dir=''
lock_fd=''
write_started='NO'
replaced_capture='NO'
replaced_wrapper='NO'
replaced_sudoers='NO'

fail() {
  printf 'PROVISION=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

usage() {
  printf '%s\n' \
    'uso: provision-production-baseline-capture.sh --expected-sha <40-hex-sha> --dry-run' \
    'uso: provision-production-baseline-capture.sh --expected-sha <40-hex-sha> --install' \
    'uso: provision-production-baseline-capture.sh --expected-sha <40-hex-sha> --upgrade --dry-run' \
    'uso: provision-production-baseline-capture.sh --expected-sha <40-hex-sha> --upgrade --install' >&2
}

parse_args() {
  [[ "$#" -ge 3 && "$1" == '--expected-sha' ]] || { usage; fail 'ARGUMENTS_INVALID'; }
  expected_sha="$2"
  shift 2
  while [[ "$#" -gt 0 ]]; do
    case "$1" in
      --dry-run)
        [[ -z "$mode" ]] || fail 'ARGUMENTS_INVALID'
        mode='dry-run'
        ;;
      --install)
        [[ -z "$mode" ]] || fail 'ARGUMENTS_INVALID'
        mode='install'
        ;;
      --upgrade)
        [[ "$upgrade" == 'NO' ]] || fail 'ARGUMENTS_INVALID'
        upgrade='YES'
        ;;
      *)
        usage
        fail 'ARGUMENTS_INVALID'
        ;;
    esac
    shift
  done
  [[ -n "$mode" ]] || { usage; fail 'ARGUMENTS_INVALID'; }
  [[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'SHA_INVALID'
}

require_root() {
  [[ "$EUID" -eq 0 ]] || fail 'ROOT_REQUIRED'
}

validate_repo() {
  local remote shallow
  [[ -n "$ROOT" && ( -d "$ROOT/.git" || -f "$ROOT/.git" ) ]] || fail 'GIT_ROOT_NOT_FOUND'
  [[ "$SCRIPT_DIR" == "$ROOT/scripts" ]] || fail 'SCRIPT_LOCATION_INVALID'
  [[ "$(git -C "$ROOT" rev-parse HEAD 2>/dev/null)" == "$expected_sha" ]] || fail 'GIT_SHA_MISMATCH'
  [[ -z "$(git -C "$ROOT" status --porcelain --untracked-files=all)" ]] || fail 'WORKTREE_NOT_CLEAN'
  remote="$(git -C "$ROOT" remote get-url origin 2>/dev/null || true)"
  [[ "$remote" == 'https://github.com/ASOARESBH/voxelpacs_2026.git' ]] || fail 'CANONICAL_REMOTE_MISMATCH'
  [[ "$(git -C "$ROOT" rev-parse refs/remotes/origin/main 2>/dev/null || true)" == "$expected_sha" ]] || fail 'EXPECTED_SHA_NOT_ORIGIN_MAIN'
  shallow="$(git -C "$ROOT" rev-parse --is-shallow-repository 2>/dev/null || true)"
  [[ "$shallow" == 'false' ]] || fail 'GIT_HISTORY_INCOMPLETE'
}

validate_sources() {
  local source path
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
  grep -Fq -- '--composer-profile-sha' "$CAPTURE_SOURCE" || fail 'CAPTURE_COMPOSER_PROFILE_ARGUMENT_INVALID'
  grep -Fq 'official_root_controlled_baseline_profile_v1' "$CAPTURE_SOURCE" || fail 'CAPTURE_COMPOSER_PROFILE_CONTRACT_INVALID'
  grep -Fq 'composer-profile.tsv' "$CAPTURE_SOURCE" || fail 'CAPTURE_COMPOSER_PROFILE_OUTPUT_INVALID'
  bash -n "$CAPTURE_SOURCE"
}

validate_wrapper_contract() {
  grep -Fqx "readonly SOURCE_ROOT='/var/www/voxelpacs/app'" "$WRAPPER_SOURCE" || fail 'WRAPPER_SOURCE_ROOT_INVALID'
  grep -Fqx "readonly OUTPUT_ROOT='/var/lib/voxelpacs/baselines'" "$WRAPPER_SOURCE" || fail 'WRAPPER_OUTPUT_ROOT_INVALID'
  grep -Fqx "readonly CAPTURE_SCRIPT='/usr/local/sbin/voxelpacs-capture-production-baseline'" "$WRAPPER_SOURCE" || fail 'WRAPPER_CAPTURE_PATH_INVALID'
  grep -Fqx "readonly COMPOSER_PROFILE_SHA_FILE='/etc/voxelpacs/voxelpacs-composer-profile-approved-main-sha'" "$WRAPPER_SOURCE" || fail 'WRAPPER_COMPOSER_PROFILE_APPROVAL_PATH_INVALID'
  grep -Fq '[[ "$#" -eq 1 && "$1" == '\''capture'\'' ]]' "$WRAPPER_SOURCE" || fail 'WRAPPER_ARGUMENT_CONTRACT_INVALID'
  grep -Fq -- '--composer-profile-sha' "$WRAPPER_SOURCE" || fail 'WRAPPER_COMPOSER_PROFILE_ARGUMENT_INVALID'
  grep -Fq 'read_approved_composer_profile_sha' "$WRAPPER_SOURCE" || fail 'WRAPPER_COMPOSER_PROFILE_APPROVAL_INVALID'
  grep -Fq 'COMPOSER_PROFILE=PASS' "$WRAPPER_SOURCE" || fail 'WRAPPER_COMPOSER_PROFILE_RESULT_INVALID'
  ! grep -Eq 'eval|systemctl|smbclient|curl[[:space:]]|mysql|psql|composer[[:space:]]|NOPASSWD:[[:space:]]+ALL([[:space:]]|$)' "$WRAPPER_SOURCE" || fail 'WRAPPER_GENERIC_OPERATION_PRESENT'
  bash -n "$WRAPPER_SOURCE"
}

validate_sudoers() {
  [[ "$(grep -Ec '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:' "$SUDOERS_SOURCE")" -eq 1 ]] || fail 'SUDOERS_RULE_COUNT_INVALID'
  grep -Fq '/usr/local/sbin/voxelpacs-production-baseline-capture capture' "$SUDOERS_SOURCE" || fail 'SUDOERS_COMMAND_MISSING'
  ! grep -Eq 'NOPASSWD:[[:space:]]+ALL([[:space:]]|$)' "$SUDOERS_SOURCE" || fail 'GENERIC_SUDO_RULE_PRESENT'
  ! grep -Eq '/bin/(ba|d)?sh|/usr/(bin|sbin)/(cp|mv|rm|rsync|install|python|php|composer|systemctl)' "$SUDOERS_SOURCE" || fail 'GENERIC_COMMAND_PRESENT'
  visudo -cf "$SUDOERS_SOURCE" >/dev/null || fail 'SUDOERS_SYNTAX_INVALID'
}

validate_admin_directory() {
  local directory metadata mode_bits
  directory="$1"
  [[ -d "$directory" && ! -L "$directory" ]] || fail 'ADMIN_DIRECTORY_INVALID'
  metadata="$(stat -c '%u:%g:%a' -- "$directory" 2>/dev/null || true)"
  [[ "$metadata" =~ ^0:0:[0-7]{3,4}$ ]] || fail 'ADMIN_DIRECTORY_OWNERSHIP_INVALID'
  mode_bits="${metadata##*:}"
  (( (8#$mode_bits & 18) == 0 )) || fail 'ADMIN_DIRECTORY_GROUP_OTHER_WRITE'
}

validate_install_scope() {
  validate_admin_directory "$CAPTURE_PARENT"
  validate_admin_directory "$SUDOERS_PARENT"
  if [[ "$upgrade" == 'YES' ]]; then
    [[ -f "$INSTALLED_CAPTURE" && ! -L "$INSTALLED_CAPTURE" ]] || fail 'CAPTURE_MISSING_FOR_UPGRADE'
    [[ -f "$INSTALLED_WRAPPER" && ! -L "$INSTALLED_WRAPPER" ]] || fail 'WRAPPER_MISSING_FOR_UPGRADE'
    [[ -f "$INSTALLED_SUDOERS" && ! -L "$INSTALLED_SUDOERS" ]] || fail 'SUDOERS_MISSING_FOR_UPGRADE'
    [[ "$(stat -c '%u:%g:%a' -- "$INSTALLED_CAPTURE")" == '0:0:555' ]] || fail 'CAPTURE_PERMISSIONS_INVALID_FOR_UPGRADE'
    [[ "$(stat -c '%u:%g:%a' -- "$INSTALLED_WRAPPER")" == '0:0:555' ]] || fail 'WRAPPER_PERMISSIONS_INVALID_FOR_UPGRADE'
    [[ "$(stat -c '%u:%g:%a' -- "$INSTALLED_SUDOERS")" == '0:0:440' ]] || fail 'SUDOERS_PERMISSIONS_INVALID_FOR_UPGRADE'
  else
    [[ ! -e "$INSTALLED_CAPTURE" && ! -L "$INSTALLED_CAPTURE" ]] || fail 'CAPTURE_ALREADY_INSTALLED'
    [[ ! -e "$INSTALLED_WRAPPER" && ! -L "$INSTALLED_WRAPPER" ]] || fail 'WRAPPER_ALREADY_INSTALLED'
    [[ ! -e "$INSTALLED_SUDOERS" && ! -L "$INSTALLED_SUDOERS" ]] || fail 'SUDOERS_ALREADY_INSTALLED'
  fi
}

source_history_contains_blob() {
  local installed_path="$1" source_path="$2" installed_sha commit candidate
  installed_sha="$(sha256sum -- "$installed_path" | awk '{print $1}')"
  while IFS= read -r commit; do
    [[ -n "$commit" ]] || continue
    git -C "$ROOT" cat-file -e "$commit:$source_path" 2>/dev/null || continue
    candidate="$(git -C "$ROOT" show "$commit:$source_path" 2>/dev/null | sha256sum | awk '{print $1}')"
    if [[ "$candidate" == "$installed_sha" ]]; then
      return 0
    fi
  done < <(git -C "$ROOT" log refs/remotes/origin/main --format='%H' -- "$source_path")
  return 1
}

validate_existing_provenance() {
  [[ "$upgrade" == 'YES' ]] || return 0
  source_history_contains_blob "$INSTALLED_CAPTURE" scripts/capture-production-baseline.sh || fail 'CAPTURE_PROVENANCE_UNPROVEN'
  source_history_contains_blob "$INSTALLED_WRAPPER" ops/deploy/voxelpacs-production-baseline-capture || fail 'WRAPPER_PROVENANCE_UNPROVEN'
  source_history_contains_blob "$INSTALLED_SUDOERS" ops/sudoers/voxelpacs-production-baseline-capture || fail 'SUDOERS_PROVENANCE_UNPROVEN'
}

acquire_lock() {
  [[ -d "$(dirname "$LOCK_PATH")" && ! -L "$(dirname "$LOCK_PATH")" ]] || fail 'LOCK_DIRECTORY_INVALID'
  exec {lock_fd}>"$LOCK_PATH"
  flock -n "$lock_fd" || fail 'PROVISION_LOCK_BUSY'
}

cleanup_known_files() {
  if [[ -n "$stage_capture" ]]; then rm -f -- "$stage_capture"; fi
  if [[ -n "$stage_wrapper" ]]; then rm -f -- "$stage_wrapper"; fi
  if [[ -n "$stage_sudoers" ]]; then rm -f -- "$stage_sudoers"; fi
  if [[ -n "$stage_capture_dir" && -d "$stage_capture_dir" ]]; then
    rmdir -- "$stage_capture_dir" 2>/dev/null || true
  fi
  if [[ -n "$stage_sudoers_dir" && -d "$stage_sudoers_dir" ]]; then
    rmdir -- "$stage_sudoers_dir" 2>/dev/null || true
  fi
  if [[ -n "$backup_dir" && -d "$backup_dir" ]]; then
    rm -f -- "$backup_dir/capture" "$backup_dir/wrapper" "$backup_dir/sudoers"
    rmdir -- "$backup_dir" 2>/dev/null || true
  fi
  if [[ -n "$operation_dir" && -d "$operation_dir" ]]; then
    rmdir -- "$operation_dir" 2>/dev/null || true
  fi
}

restore_target() {
  local backup="$1" target="$2" name restore_tmp
  name="$(basename -- "$target")"
  restore_tmp="$(dirname -- "$target")/.voxelpacs-restore.$$.${name}"
  rm -f -- "$restore_tmp"
  cp -a -- "$backup" "$restore_tmp"
  mv -f -- "$restore_tmp" "$target"
}

rollback_on_error() {
  local status="$?" restore_status=0
  trap - EXIT
  if [[ "$status" -ne 0 && "$write_started" == 'YES' && "$upgrade" == 'YES' ]]; then
    if [[ "$replaced_capture" == 'YES' ]]; then restore_target "$backup_dir/capture" "$INSTALLED_CAPTURE" || restore_status=1; fi
    if [[ "$replaced_wrapper" == 'YES' ]]; then restore_target "$backup_dir/wrapper" "$INSTALLED_WRAPPER" || restore_status=1; fi
    if [[ "$replaced_sudoers" == 'YES' ]]; then restore_target "$backup_dir/sudoers" "$INSTALLED_SUDOERS" || restore_status=1; fi
    if [[ "$restore_status" -eq 0 ]]; then
      printf 'ROLLBACK=PASS\nORIGINAL_PRESERVED=YES\n' >&2
    else
      printf 'ROLLBACK=FAIL\nORIGINAL_PRESERVED=UNKNOWN\n' >&2
    fi
  elif [[ "$status" -ne 0 && "$write_started" == 'YES' && "$upgrade" == 'NO' ]]; then
    if [[ "$replaced_sudoers" == 'YES' ]]; then rm -f -- "$INSTALLED_SUDOERS" || restore_status=1; fi
    if [[ "$replaced_wrapper" == 'YES' ]]; then rm -f -- "$INSTALLED_WRAPPER" || restore_status=1; fi
    if [[ "$replaced_capture" == 'YES' ]]; then rm -f -- "$INSTALLED_CAPTURE" || restore_status=1; fi
    if [[ "$restore_status" -eq 0 ]]; then
      printf 'ROLLBACK=PASS\nPARTIAL_INSTALL_CLEANED=YES\n' >&2
    else
      printf 'ROLLBACK=FAIL\nPARTIAL_INSTALL_CLEANED=UNKNOWN\n' >&2
    fi
  fi
  cleanup_known_files
  exit "$status"
}

stage_files() {
  backup_dir="$operation_dir/backup"
  mkdir -- "$backup_dir"
  chmod 0700 "$backup_dir"
  stage_capture_dir="$(mktemp -d "$CAPTURE_PARENT/.voxelpacs-production-baseline-capture.XXXXXX")"
  stage_sudoers_dir="$(mktemp -d "$SUDOERS_PARENT/.voxelpacs-production-baseline-capture.XXXXXX")"
  chmod 0700 "$stage_capture_dir" "$stage_sudoers_dir"
  stage_capture="$stage_capture_dir/capture"
  stage_wrapper="$stage_capture_dir/wrapper"
  stage_sudoers="$stage_sudoers_dir/sudoers"
  install -o root -g root -m 0555 -- "$CAPTURE_SOURCE" "$stage_capture"
  install -o root -g root -m 0555 -- "$WRAPPER_SOURCE" "$stage_wrapper"
  install -o root -g root -m 0440 -- "$SUDOERS_SOURCE" "$stage_sudoers"
  visudo -cf "$stage_sudoers" >/dev/null || fail 'STAGED_SUDOERS_SYNTAX_INVALID'
  [[ "$(sha256sum "$stage_capture" | awk '{print $1}')" == "$(sha256sum "$CAPTURE_SOURCE" | awk '{print $1}')" ]] || fail 'STAGED_CAPTURE_HASH_MISMATCH'
  [[ "$(sha256sum "$stage_wrapper" | awk '{print $1}')" == "$(sha256sum "$WRAPPER_SOURCE" | awk '{print $1}')" ]] || fail 'STAGED_WRAPPER_HASH_MISMATCH'
  [[ "$(sha256sum "$stage_sudoers" | awk '{print $1}')" == "$(sha256sum "$SUDOERS_SOURCE" | awk '{print $1}')" ]] || fail 'STAGED_SUDOERS_HASH_MISMATCH'
}

backup_existing() {
  [[ "$upgrade" == 'YES' ]] || return 0
  cp -a -- "$INSTALLED_CAPTURE" "$backup_dir/capture"
  cp -a -- "$INSTALLED_WRAPPER" "$backup_dir/wrapper"
  cp -a -- "$INSTALLED_SUDOERS" "$backup_dir/sudoers"
  [[ "$(sha256sum "$backup_dir/capture" | awk '{print $1}')" == "$(sha256sum "$INSTALLED_CAPTURE" | awk '{print $1}')" ]] || fail 'CAPTURE_BACKUP_VERIFY_FAILED'
  [[ "$(sha256sum "$backup_dir/wrapper" | awk '{print $1}')" == "$(sha256sum "$INSTALLED_WRAPPER" | awk '{print $1}')" ]] || fail 'WRAPPER_BACKUP_VERIFY_FAILED'
  [[ "$(sha256sum "$backup_dir/sudoers" | awk '{print $1}')" == "$(sha256sum "$INSTALLED_SUDOERS" | awk '{print $1}')" ]] || fail 'SUDOERS_BACKUP_VERIFY_FAILED'
}

parse_args "$@"
require_root
validate_repo
validate_sources
validate_capture_contract
validate_wrapper_contract
validate_sudoers
validate_install_scope
validate_existing_provenance

printf 'SOURCE_SHA=%s\nUPGRADE=%s\nCAPTURE_SOURCE=scripts/capture-production-baseline.sh\nWRAPPER_SOURCE=ops/deploy/voxelpacs-production-baseline-capture\n' "$expected_sha" "$upgrade"
if [[ "$mode" == 'dry-run' ]]; then
  printf 'SOURCE_SHA_CHECK=PASS\nCAPTURE_CHECK=PASS\nWRAPPER_CHECK=PASS\nSUDOERS_SYNTAX=PASS\n'
  if [[ "$upgrade" == 'YES' ]]; then
    printf 'EXISTING_PROVENANCE=PASS\nTARGETS_EXIST=PASS\n'
  else
    printf 'TARGETS_ABSENT=PASS\n'
  fi
  printf 'DRY_RUN=PASS\nINSTALL=NOT_EXECUTED\nPRODUCTION_CHANGED=NO\n'
  exit 0
fi

acquire_lock
operation_dir="$(mktemp -d "$OPERATION_PARENT/voxelpacs-production-baseline-capture-upgrade.XXXXXX")"
chmod 0700 "$operation_dir"
trap rollback_on_error EXIT
stage_files
backup_existing
if [[ "$upgrade" == 'YES' ]]; then
  write_started='YES'
  mv -f -- "$stage_capture" "$INSTALLED_CAPTURE"
  stage_capture=''
  replaced_capture='YES'
  mv -f -- "$stage_wrapper" "$INSTALLED_WRAPPER"
  stage_wrapper=''
  replaced_wrapper='YES'
  mv -f -- "$stage_sudoers" "$INSTALLED_SUDOERS"
  stage_sudoers=''
  replaced_sudoers='YES'
else
  write_started='YES'
  ln -- "$stage_capture" "$INSTALLED_CAPTURE" || fail 'CAPTURE_INSTALL_LINK_FAILED'
  replaced_capture='YES'
  rm -f -- "$stage_capture"
  stage_capture=''
  ln -- "$stage_wrapper" "$INSTALLED_WRAPPER" || fail 'WRAPPER_INSTALL_LINK_FAILED'
  replaced_wrapper='YES'
  rm -f -- "$stage_wrapper"
  stage_wrapper=''
  ln -- "$stage_sudoers" "$INSTALLED_SUDOERS" || fail 'SUDOERS_INSTALL_LINK_FAILED'
  replaced_sudoers='YES'
  rm -f -- "$stage_sudoers"
  stage_sudoers=''
fi
cleanup_known_files
trap - EXIT
if [[ "$upgrade" == 'YES' ]]; then
  printf 'SOURCE_SHA_CHECK=PASS\nEXISTING_PROVENANCE=PASS\nCAPTURE_INSTALLED=YES\nWRAPPER_INSTALLED=YES\nSUDOERS_INSTALLED=YES\nCAPTURE_OWNER_MODE=root:root:0555\nWRAPPER_OWNER_MODE=root:root:0555\nSUDOERS_OWNER_MODE=root:root:0440\nSUDOERS_SYNTAX=PASS\nUPGRADE_APPLIED=YES\nROLLBACK=NOT_NEEDED\nINSTALL=PASS\nPRODUCTION_CHANGED=YES\n'
else
  printf 'SOURCE_SHA_CHECK=PASS\nCAPTURE_INSTALLED=YES\nWRAPPER_INSTALLED=YES\nSUDOERS_INSTALLED=YES\nCAPTURE_OWNER_MODE=root:root:0555\nWRAPPER_OWNER_MODE=root:root:0555\nSUDOERS_OWNER_MODE=root:root:0440\nSUDOERS_SYNTAX=PASS\nUPGRADE_APPLIED=NO\nINSTALL=PASS\nPRODUCTION_CHANGED=YES\n'
fi
