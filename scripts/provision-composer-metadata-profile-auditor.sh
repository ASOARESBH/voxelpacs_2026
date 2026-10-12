#!/usr/bin/env bash
# VOXEL PACS — provisionamento root-controlled do auditor de perfil Composer.
set -Eeuo pipefail
umask 077
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly SCRIPT_DIR
ROOT="$(git -C "$SCRIPT_DIR/.." rev-parse --show-toplevel 2>/dev/null || true)"
readonly ROOT
readonly CANONICAL_REMOTE='https://github.com/ASOARESBH/voxelpacs_2026.git'
readonly CANONICAL_MAIN_REF='refs/remotes/origin/main'
readonly HELPER_PATH='ops/deploy/voxelpacs-composer-metadata-profile-auditor'
readonly SUDOERS_PATH='ops/sudoers/voxelpacs-composer-metadata-profile-auditor'
readonly PROVISIONER_PATH='scripts/provision-composer-metadata-profile-auditor.sh'
readonly HELPER_SOURCE="$ROOT/$HELPER_PATH"
readonly SUDOERS_SOURCE="$ROOT/$SUDOERS_PATH"
readonly INSTALLED_HELPER='/usr/local/sbin/voxelpacs-composer-metadata-profile-auditor'
readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-composer-metadata-profile-auditor'
readonly LOCK_PATH='/run/lock/voxelpacs-composer-metadata-profile-auditor-provision.lock'
readonly HELPER_PARENT='/usr/local/sbin'
readonly SUDOERS_PARENT='/etc/sudoers.d'
readonly APPROVAL_FILE='/etc/voxelpacs-composer-profile/voxelpacs-composer-profile-approved-main-sha'

mode=''
expected_sha=''
helper_stage_dir=''
sudoers_stage_dir=''
lock_fd=''
installed_helper=0
installed_sudoers=0

fail() {
  printf 'PROVISION=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

usage() {
  printf '%s\n' \
    'uso: provision-composer-metadata-profile-auditor.sh --expected-sha <40-hex-sha> --dry-run' \
    'uso: provision-composer-metadata-profile-auditor.sh --expected-sha <40-hex-sha> --install' >&2
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

require_root() { [[ "$EUID" -eq 0 ]] || fail 'ROOT_REQUIRED'; }

validate_repo() {
  local remote_url main_sha
  [[ -n "$ROOT" && ( -d "$ROOT/.git" || -f "$ROOT/.git" ) ]] || fail 'GIT_ROOT_NOT_FOUND'
  [[ "$SCRIPT_DIR" == "$ROOT/scripts" ]] || fail 'SCRIPT_LOCATION_INVALID'
  remote_url="$(git -C "$ROOT" remote get-url origin 2>/dev/null || true)"
  [[ "$remote_url" == "$CANONICAL_REMOTE" ]] || fail 'CANONICAL_REMOTE_MISMATCH'
  git -C "$ROOT" show-ref --verify --quiet "$CANONICAL_MAIN_REF" || fail 'ORIGIN_MAIN_REF_MISSING'
  main_sha="$(git -C "$ROOT" rev-parse "$CANONICAL_MAIN_REF" 2>/dev/null || true)"
  [[ "$main_sha" == "$expected_sha" ]] || fail 'EXPECTED_SHA_NOT_ORIGIN_MAIN'
  [[ "$(git -C "$ROOT" rev-parse HEAD 2>/dev/null)" == "$expected_sha" ]] || fail 'GIT_SHA_MISMATCH'
  [[ -z "$(git -C "$ROOT" status --porcelain --untracked-files=all)" ]] || fail 'WORKTREE_NOT_CLEAN'
}

validate_approval() {
  local approval_parent approval_metadata approval_file_metadata approval_mode
  approval_parent="$(dirname -- "$APPROVAL_FILE")"
  [[ -d "$approval_parent" && ! -L "$approval_parent" ]] || fail 'APPROVAL_DIRECTORY_INVALID'
  approval_metadata="$(stat -c '%u:%g:%a' -- "$approval_parent" 2>/dev/null || true)"
  [[ "$approval_metadata" == 0:0:* ]] || fail 'APPROVAL_DIRECTORY_OWNERSHIP_INVALID'
  approval_mode="${approval_metadata##*:}"
  [[ "$approval_mode" =~ ^[0-7]{3,4}$ ]] || fail 'APPROVAL_DIRECTORY_MODE_INVALID'
  (( (8#$approval_mode & 18) == 0 )) || fail 'APPROVAL_DIRECTORY_GROUP_OTHER_WRITE'
  [[ -f "$APPROVAL_FILE" && ! -L "$APPROVAL_FILE" ]] || fail 'APPROVAL_FILE_UNAVAILABLE'
  approval_file_metadata="$(stat -c '%u:%g:%a' -- "$APPROVAL_FILE" 2>/dev/null || true)"
  [[ "$approval_file_metadata" == '0:0:600' ]] || fail 'APPROVAL_FILE_SECURITY_INVALID'
  awk 'NF != 1 || $1 !~ /^[0-9a-f]{40}$/ { bad=1 } END { exit bad ? 1 : 0 }' "$APPROVAL_FILE" || fail 'APPROVAL_FILE_FORMAT_INVALID'
  [[ "$(grep -Fxc -- "$expected_sha" "$APPROVAL_FILE")" -eq 1 ]] || fail 'EXPECTED_SHA_NOT_APPROVED'
}

validate_sources() {
  [[ -f "$HELPER_SOURCE" && ! -L "$HELPER_SOURCE" ]] || fail 'HELPER_SOURCE_INVALID'
  [[ -f "$SUDOERS_SOURCE" && ! -L "$SUDOERS_SOURCE" ]] || fail 'SUDOERS_SOURCE_INVALID'
  git -C "$ROOT" ls-files --error-unmatch -- "$HELPER_PATH" "$PROVISIONER_PATH" "$SUDOERS_PATH" >/dev/null 2>&1 || fail 'SOURCE_NOT_TRACKED'
  git -C "$ROOT" cat-file -e "$expected_sha:$HELPER_PATH" >/dev/null 2>&1 || fail 'HELPER_NOT_IN_SHA'
  git -C "$ROOT" cat-file -e "$expected_sha:$PROVISIONER_PATH" >/dev/null 2>&1 || fail 'PROVISIONER_NOT_IN_SHA'
  git -C "$ROOT" cat-file -e "$expected_sha:$SUDOERS_PATH" >/dev/null 2>&1 || fail 'SUDOERS_NOT_IN_SHA'
  git -C "$ROOT" diff --quiet "$expected_sha" -- "$HELPER_PATH" "$PROVISIONER_PATH" "$SUDOERS_PATH" || fail 'SOURCE_DIFFERS_FROM_SHA'
  git -C "$ROOT" diff --cached --quiet "$expected_sha" -- "$HELPER_PATH" "$PROVISIONER_PATH" "$SUDOERS_PATH" || fail 'INDEX_DIFFERS_FROM_SHA'
}

validate_helper_contract() {
  local helper_contract
  helper_contract="$(git -C "$ROOT" show "$expected_sha:$HELPER_PATH" 2>/dev/null)" || fail 'HELPER_BLOB_READ_FAILED'
  grep -Fqx "readonly APP_ROOT='/var/www/voxelpacs/app'" <<<"$helper_contract" || fail 'APP_ROOT_CONTRACT_INVALID'
  grep -Fqx "readonly BASELINE_ROOT='/var/lib/voxelpacs/baselines'" <<<"$helper_contract" || fail 'BASELINE_ROOT_CONTRACT_INVALID'
  grep -Fqx "readonly TARGET_RELATIVE_PATH='vendor/composer/installed.php'" <<<"$helper_contract" || fail 'TARGET_PATH_CONTRACT_INVALID'
  grep -Fqx "readonly BASELINE_PROFILE_NAME='composer-profile.tsv'" <<<"$helper_contract" || fail 'BASELINE_PROFILE_CONTRACT_INVALID'
  ! grep -Eq -- '--path|--source|--destination|--command|eval|NOPASSWD:[[:space:]]+ALL|rm -rf|smbclient|systemctl|composer[[:space:]]|php ' <<<"$helper_contract" || fail 'HELPER_GENERIC_OPERATION_PRESENT'
  grep -Fq 'RAW_VALUES_EXPOSED=NO' <<<"$helper_contract" || fail 'RAW_VALUE_CONTRACT_MISSING'
  grep -Fq 'READY_FOR_DEPLOY=NO' <<<"$helper_contract" || fail 'DEPLOY_GUARD_MISSING'
  grep -Fq "audit_status='INCOMPLETE'" <<<"$helper_contract" || fail 'INCOMPLETE_AUDIT_CONTRACT_MISSING'
  bash -n <(printf '%s\n' "$helper_contract")
}

validate_sudoers() {
  [[ "$(grep -Ec '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:' "$SUDOERS_SOURCE")" -eq 1 ]] || fail 'SUDOERS_RULE_COUNT_INVALID'
  grep -Fq '/usr/local/sbin/voxelpacs-composer-metadata-profile-auditor --baseline-id' "$SUDOERS_SOURCE" || fail 'SUDOERS_COMMAND_MISSING'
  ! grep -Eq 'NOPASSWD:[[:space:]]+ALL([[:space:]]|$)' "$SUDOERS_SOURCE" || fail 'GENERIC_SUDO_RULE_PRESENT'
  ! grep -Eq '/bin/(ba|d)?sh|/usr/(bin|sbin)/(cp|mv|rm|rsync|install|tee|python|php|composer|systemctl)' "$SUDOERS_SOURCE" || fail 'GENERIC_COMMAND_PRESENT'
  visudo -cf "$SUDOERS_SOURCE" >/dev/null || fail 'SUDOERS_SYNTAX_INVALID'
}

validate_admin_directory() {
  local path="$1" metadata mode_bits
  [[ -d "$path" && ! -L "$path" ]] || fail 'ADMIN_DIRECTORY_INVALID'
  metadata="$(stat -c '%u:%g:%a' -- "$path" 2>/dev/null || true)"
  [[ "$metadata" == 0:0:* ]] || fail 'ADMIN_DIRECTORY_OWNERSHIP_INVALID'
  mode_bits="${metadata##*:}"
  [[ "$mode_bits" =~ ^[0-7]{3,4}$ ]] || fail 'ADMIN_DIRECTORY_MODE_INVALID'
  (( (8#$mode_bits & 18) == 0 )) || fail 'ADMIN_DIRECTORY_GROUP_OTHER_WRITE'
}

validate_install_scope() {
  validate_admin_directory "$HELPER_PARENT"
  validate_admin_directory "$SUDOERS_PARENT"
  [[ ! -e "$INSTALLED_HELPER" && ! -L "$INSTALLED_HELPER" ]] || fail 'HELPER_ALREADY_INSTALLED'
  [[ ! -e "$INSTALLED_SUDOERS" && ! -L "$INSTALLED_SUDOERS" ]] || fail 'SUDOERS_ALREADY_INSTALLED'
}

acquire_lock() {
  [[ -d "$(dirname "$LOCK_PATH")" && ! -L "$(dirname "$LOCK_PATH")" ]] || fail 'LOCK_DIRECTORY_INVALID'
  [[ ! -L "$LOCK_PATH" ]] || fail 'LOCK_PATH_SYMLINK'
  if [[ -e "$LOCK_PATH" ]]; then
    local lock_mode
    [[ -f "$LOCK_PATH" ]] || fail 'LOCK_PATH_NOT_REGULAR'
    [[ "$(stat -c '%u:%g:%a' -- "$LOCK_PATH" 2>/dev/null || true)" == 0:0:* ]] || fail 'LOCK_PATH_OWNERSHIP_INVALID'
    lock_mode="$(stat -c '%a' -- "$LOCK_PATH" 2>/dev/null || true)"
    [[ "$lock_mode" =~ ^[0-7]{3,4}$ ]] || fail 'LOCK_PATH_MODE_INVALID'
    (( (8#$lock_mode & 18) == 0 )) || fail 'LOCK_PATH_GROUP_OTHER_WRITE'
  fi
  exec {lock_fd}<>"$LOCK_PATH"
  flock -n "$lock_fd" || fail 'PROVISION_LOCK_BUSY'
}

cleanup_stage() {
  if [[ -n "$helper_stage_dir" && -d "$helper_stage_dir" ]]; then
    rm -f -- "$helper_stage_dir/helper"
    rmdir -- "$helper_stage_dir" 2>/dev/null || true
  fi
  if [[ -n "$sudoers_stage_dir" && -d "$sudoers_stage_dir" ]]; then
    rm -f -- "$sudoers_stage_dir/sudoers"
    rmdir -- "$sudoers_stage_dir" 2>/dev/null || true
  fi
}

cleanup() {
  if (( installed_sudoers )); then rm -f -- "$INSTALLED_SUDOERS"; fi
  if (( installed_helper )); then rm -f -- "$INSTALLED_HELPER"; fi
  cleanup_stage
}
trap cleanup EXIT

stage_files_from_git() {
  local helper_blob sudoers_blob
  validate_admin_directory "$HELPER_PARENT"
  validate_admin_directory "$SUDOERS_PARENT"
  helper_stage_dir="$(mktemp -d "$HELPER_PARENT/.voxelpacs-composer-metadata-profile-auditor.XXXXXX")"
  sudoers_stage_dir="$(mktemp -d "$SUDOERS_PARENT/.voxelpacs-composer-metadata-profile-auditor.XXXXXX")"
  chmod 0700 "$helper_stage_dir" "$sudoers_stage_dir"

  git -C "$ROOT" show "$expected_sha:$HELPER_PATH" > "$helper_stage_dir/helper" || fail 'HELPER_BLOB_READ_FAILED'
  git -C "$ROOT" show "$expected_sha:$SUDOERS_PATH" > "$sudoers_stage_dir/sudoers" || fail 'SUDOERS_BLOB_READ_FAILED'
  chown root:root "$helper_stage_dir/helper" "$sudoers_stage_dir/sudoers"
  chmod 0555 "$helper_stage_dir/helper"
  chmod 0440 "$sudoers_stage_dir/sudoers"
  visudo -cf "$sudoers_stage_dir/sudoers" >/dev/null || fail 'STAGED_SUDOERS_SYNTAX_INVALID'

  helper_blob="$(git -C "$ROOT" rev-parse "$expected_sha:$HELPER_PATH")"
  sudoers_blob="$(git -C "$ROOT" rev-parse "$expected_sha:$SUDOERS_PATH")"
  [[ "$(git -C "$ROOT" hash-object -- "$helper_stage_dir/helper")" == "$helper_blob" ]] || fail 'STAGED_HELPER_BLOB_MISMATCH'
  [[ "$(git -C "$ROOT" hash-object -- "$sudoers_stage_dir/sudoers")" == "$sudoers_blob" ]] || fail 'STAGED_SUDOERS_BLOB_MISMATCH'
}

link_no_replace() {
  local source="$1" target="$2"
  [[ ! -e "$target" && ! -L "$target" ]] || fail 'INSTALL_TARGET_APPEARED'
  ln -- "$source" "$target" || fail 'INSTALL_LINK_FAILED'
}

parse_args "$@"
require_root
validate_repo
validate_approval
validate_sources
validate_helper_contract
validate_sudoers
validate_install_scope
printf 'SOURCE_SHA=%s\nCANONICAL_REMOTE=PASS\nORIGIN_MAIN_MATCH=PASS\nADMIN_APPROVAL=PASS\nTARGET_PATH=vendor/composer/installed.php\nPROFILE=STATE_ONLY_V1\n' "$expected_sha"
if [[ "$mode" == 'dry-run' ]]; then
  printf 'SOURCE_SHA_CHECK=PASS\nHELPER_CHECK=PASS\nSUDOERS_SYNTAX=PASS\nADMIN_DIRECTORIES=PASS\nTARGETS_ABSENT=PASS\nDRY_RUN=PASS\nINSTALL=NOT_EXECUTED\nPRODUCTION_CHANGED=NO\n'
  exit 0
fi

acquire_lock
validate_install_scope
stage_files_from_git
validate_admin_directory "$HELPER_PARENT"
validate_admin_directory "$SUDOERS_PARENT"
validate_install_scope
link_no_replace "$helper_stage_dir/helper" "$INSTALLED_HELPER"
installed_helper=1
link_no_replace "$sudoers_stage_dir/sudoers" "$INSTALLED_SUDOERS"
installed_sudoers=1
[[ "$(stat -c '%u:%g:%a' -- "$INSTALLED_HELPER")" == '0:0:555' ]] || fail 'HELPER_FINAL_OWNERSHIP_MODE_INVALID'
[[ "$(stat -c '%u:%g:%a' -- "$INSTALLED_SUDOERS")" == '0:0:440' ]] || fail 'SUDOERS_FINAL_OWNERSHIP_MODE_INVALID'
visudo -cf "$INSTALLED_SUDOERS" >/dev/null || fail 'FINAL_SUDOERS_SYNTAX_INVALID'
cleanup_stage
trap - EXIT
printf 'SOURCE_SHA_CHECK=PASS\nHELPER_INSTALLED=YES\nSUDOERS_INSTALLED=YES\nHELPER_OWNER_MODE=root:root:0555\nSUDOERS_OWNER_MODE=root:root:0440\nSUDOERS_SYNTAX=PASS\nINSTALL=PASS\nPRODUCTION_CHANGED=YES\n'
