#!/usr/bin/env bash
# VOXEL PACS — provisionamento root-controlled do diagnóstico Philips Non-DICOM.
# O mecanismo é administrativo e independente do deploy da aplicação.
set -Eeuo pipefail
umask 077
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly SCRIPT_DIR
ROOT="$(git -C "$SCRIPT_DIR/.." rev-parse --show-toplevel 2>/dev/null || true)"
readonly ROOT
readonly SOURCE_RELATIVE_PATH='bin/philips_nondicom_production_diagnostic.php'
readonly PROVISIONER_RELATIVE_PATH='scripts/provision-philips-nondicom-production-diagnostic.sh'
readonly SOURCE_PATH="$ROOT/$SOURCE_RELATIVE_PATH"
readonly TARGET_PARENT='/opt/voxelpacs/diagnostics/philips-nondicom'
readonly TARGET_PATH="$TARGET_PARENT/philips_nondicom_production_diagnostic.php"
readonly BACKUP_ROOT='/var/backups/voxelpacs/diagnostics/philips-nondicom-production-diagnostic'
readonly LOCK_DIR='/run/voxelpacs-philips-nondicom-production-diagnostic-provision'
readonly LOCK_FILE="$LOCK_DIR/lock"
readonly MECHANISM_VERSION='1'

mode=''
expected_sha=''
backup_id=''
upgrade='NO'
target_state=''
target_hash=''
target_mode=''
target_uid=''
target_gid=''
target_mtime=''
source_file_sha256=''
backup_dir=''
stage_dir=''
lock_fd=''
published='NO'

fail() {
  printf 'DIAGNOSTIC_PROVISION=BLOCKED\nREASON=%s\nAPPLICATION_DEPLOY=NOT_EXECUTED\nDATABASE_CHANGED=NO\nTRANSMISSION=NO\n' "$1" >&2
  exit 64
}

usage() {
  printf '%s\n' \
    'uso: provision-philips-nondicom-production-diagnostic.sh --expected-sha <40-hex-sha> --dry-run [--upgrade]' \
    'uso: provision-philips-nondicom-production-diagnostic.sh --expected-sha <40-hex-sha> --install [--upgrade]' \
    'uso: provision-philips-nondicom-production-diagnostic.sh --rollback --backup-id <64-hex>' >&2
}

is_sha() {
  [[ "${1:-}" =~ ^[0-9a-f]{40}$ ]]
}

is_backup_id() {
  [[ "${1:-}" =~ ^[0-9a-f]{64}$ ]]
}

parse_args() {
  local expected_count=0 mode_count=0
  while [[ "$#" -gt 0 ]]; do
    case "$1" in
      --expected-sha)
        (( ++expected_count == 1 )) || fail 'ARGUMENTS_INVALID'
        [[ "$#" -ge 2 ]] || fail 'ARGUMENTS_INVALID'
        expected_sha="$2"
        shift 2
        ;;
      --dry-run|--install)
        (( ++mode_count == 1 )) || fail 'ARGUMENTS_INVALID'
        mode="${1#--}"
        shift
        ;;
      --upgrade)
        [[ "$upgrade" == 'NO' ]] || fail 'ARGUMENTS_INVALID'
        upgrade='YES'
        shift
        ;;
      --rollback)
        (( ++mode_count == 1 )) || fail 'ARGUMENTS_INVALID'
        mode='rollback'
        shift
        ;;
      --backup-id)
        [[ -z "$backup_id" && "$#" -ge 2 ]] || fail 'ARGUMENTS_INVALID'
        backup_id="$2"
        shift 2
        ;;
      *)
        usage
        fail 'ARGUMENTS_INVALID'
        ;;
    esac
  done

  if [[ "$mode" == 'rollback' ]]; then
    [[ "$expected_count" -eq 0 && "$mode_count" -eq 1 && "$upgrade" == 'NO' ]] || fail 'ARGUMENTS_INVALID'
    is_backup_id "$backup_id" || fail 'BACKUP_ID_INVALID'
    return 0
  fi

  [[ "$expected_count" -eq 1 && "$mode_count" -eq 1 && -z "$backup_id" ]] || fail 'ARGUMENTS_INVALID'
  is_sha "$expected_sha" || fail 'SHA_INVALID'
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

validate_blob() {
  local relative_path="$1" label="$2" expected_blob actual_blob
  expected_blob="$(git -C "$ROOT" rev-parse "$expected_sha:$relative_path" 2>/dev/null || true)"
  actual_blob="$(git -C "$ROOT" hash-object -- "$relative_path" 2>/dev/null || true)"
  [[ "$expected_blob" =~ ^[0-9a-f]{40}$ ]] || fail "${label}_BLOB_NOT_IN_SHA"
  [[ "$actual_blob" =~ ^[0-9a-f]{40}$ ]] || fail "${label}_BLOB_UNREADABLE"
  [[ "$actual_blob" == "$expected_blob" ]] || fail "${label}_BLOB_MISMATCH"
}

validate_source() {
  [[ -f "$SOURCE_PATH" && ! -L "$SOURCE_PATH" ]] || fail 'SOURCE_FILE_INVALID'
  git -C "$ROOT" ls-files --error-unmatch "$SOURCE_RELATIVE_PATH" "$PROVISIONER_RELATIVE_PATH" >/dev/null 2>&1 || fail 'SOURCE_NOT_TRACKED'
  git -C "$ROOT" cat-file -e "$expected_sha:$SOURCE_RELATIVE_PATH" >/dev/null 2>&1 || fail 'SOURCE_NOT_IN_SHA'
  git -C "$ROOT" cat-file -e "$expected_sha:$PROVISIONER_RELATIVE_PATH" >/dev/null 2>&1 || fail 'PROVISIONER_NOT_IN_SHA'
  validate_blob "$SOURCE_RELATIVE_PATH" 'SOURCE'
  validate_blob "$PROVISIONER_RELATIVE_PATH" 'PROVISIONER'
  /usr/bin/php -l "$SOURCE_PATH" >/dev/null || fail 'SOURCE_PHP_SYNTAX_INVALID'
  source_file_sha256="$(sha256sum -- "$SOURCE_PATH" | awk '{print $1}')"
  [[ "$source_file_sha256" =~ ^[0-9a-f]{64}$ ]] || fail 'SOURCE_SHA256_INVALID'
}

is_safe_mode() {
  [[ "${1:-}" =~ ^[0-7]{3,4}$ ]] || return 1
  (( (8#${1#0} & 022) == 0 ))
}

validate_secure_chain() {
  local path="$1" label="$2" current='' segment metadata uid gid mode
  [[ "$path" == /* && "$path" != *'//'* && "$path" != *'/./'* && "$path" != *'/../'* ]] || fail "${label}_PATH_INVALID"
  IFS='/' read -r -a segments <<< "${path#/}"
  for segment in "${segments[@]}"; do
    [[ -n "$segment" && "$segment" != '.' && "$segment" != '..' ]] || continue
    current="$current/$segment"
    [[ -d "$current" && ! -L "$current" ]] || fail "${label}_PATH_INVALID"
    metadata="$(stat -c '%u:%g:%a' -- "$current" 2>/dev/null || true)"
    IFS=: read -r uid gid mode <<< "$metadata"
    [[ "$uid" == '0' && "$gid" == '0' ]] || fail "${label}_ANCESTOR_SECURITY_INVALID"
    is_safe_mode "$mode" || fail "${label}_ANCESTOR_MODE_INVALID"
  done
}

validate_target_parent() {
  validate_secure_chain "$TARGET_PARENT" 'TARGET_PARENT'
}

read_target_state() {
  [[ ! -L "$TARGET_PATH" ]] || fail 'TARGET_SYMLINK_INVALID'
  if [[ ! -e "$TARGET_PATH" ]]; then
    target_state='ABSENT'
    target_hash=''
    target_mode=''
    target_uid=''
    target_gid=''
    target_mtime=''
    return 0
  fi
  [[ -f "$TARGET_PATH" && ! -L "$TARGET_PATH" ]] || fail 'TARGET_NOT_REGULAR'
  target_uid="$(stat -c '%u' -- "$TARGET_PATH")"
  target_gid="$(stat -c '%g' -- "$TARGET_PATH")"
  target_mode="$(stat -c '%a' -- "$TARGET_PATH")"
  target_mtime="$(stat -c '%Y' -- "$TARGET_PATH")"
  [[ "$target_uid" == '0' && "$target_gid" == '0' ]] || fail 'TARGET_SECURITY_INVALID'
  is_safe_mode "$target_mode" || fail 'TARGET_WRITABLE_BY_NON_ROOT'
  [[ "$target_mtime" =~ ^[0-9]+$ ]] || fail 'TARGET_MTIME_INVALID'
  target_hash="$(sha256sum -- "$TARGET_PATH" | awk '{print $1}')"
  [[ "$target_hash" =~ ^[0-9a-f]{64}$ ]] || fail 'TARGET_HASH_INVALID'
  target_state='EXISTS'
}

validate_backup_root() {
  local parent
  parent="$(dirname -- "$BACKUP_ROOT")"
  validate_secure_chain "$parent" 'BACKUP_PARENT'
  if [[ "$mode" == 'rollback' ]]; then
    validate_secure_chain "$BACKUP_ROOT" 'BACKUP_ROOT'
  elif [[ "$mode" == 'dry-run' ]]; then
    if [[ -e "$BACKUP_ROOT" ]]; then
      validate_secure_chain "$BACKUP_ROOT" 'BACKUP_ROOT'
    else
      return 0
    fi
  else
    if [[ -e "$BACKUP_ROOT" || -L "$BACKUP_ROOT" ]]; then
      validate_secure_chain "$BACKUP_ROOT" 'BACKUP_ROOT'
    else
      install -d -o root -g root -m 0700 -- "$BACKUP_ROOT"
    fi
    validate_secure_chain "$BACKUP_ROOT" 'BACKUP_ROOT'
  fi
}

acquire_lock() {
  local lock_parent
  lock_parent="$(dirname -- "$LOCK_DIR")"
  validate_secure_chain "$lock_parent" 'LOCK_PARENT'
  if [[ -e "$LOCK_DIR" || -L "$LOCK_DIR" ]]; then
    validate_secure_chain "$LOCK_DIR" 'LOCK_DIR'
  else
    mkdir -- "$LOCK_DIR" || fail 'LOCK_DIRECTORY_CREATE_FAILED'
    chown root:root -- "$LOCK_DIR"
    chmod 0700 -- "$LOCK_DIR"
    validate_secure_chain "$LOCK_DIR" 'LOCK_DIR'
  fi
  if [[ -e "$LOCK_FILE" || -L "$LOCK_FILE" ]]; then
    [[ -f "$LOCK_FILE" && ! -L "$LOCK_FILE" ]] || fail 'LOCK_FILE_INVALID'
  else
    install -o root -g root -m 0600 /dev/null "$LOCK_FILE"
  fi
  [[ "$(stat -c '%u:%g:%a' -- "$LOCK_FILE" 2>/dev/null || true)" == '0:0:600' ]] || fail 'LOCK_FILE_SECURITY_INVALID'
  exec {lock_fd}<"$LOCK_FILE"
  flock -n "$lock_fd" || fail 'PROVISION_LOCK_BUSY'
}

new_backup_id() {
  local seed
  seed="$(date -u +%Y%m%dT%H%M%SZ):$$:$(od -An -N16 -tx1 /dev/urandom | tr -d '[:space:]')"
  backup_id="$(printf '%s' "$seed" | sha256sum | awk '{print $1}')"
  is_backup_id "$backup_id" || fail 'BACKUP_ID_GENERATION_FAILED'
}

create_backup() {
  local target_sha='ABSENT' target_time='0' published_sha
  new_backup_id
  published_sha="$source_file_sha256"
  [[ "$published_sha" =~ ^[0-9a-f]{64}$ ]] || fail 'SOURCE_HASH_INVALID'
  backup_dir="$BACKUP_ROOT/$backup_id"
  mkdir -m 0700 -- "$backup_dir"
  chown root:root -- "$backup_dir"
  if [[ "$target_state" == 'EXISTS' ]]; then
    target_sha="$target_hash"
    target_time="$target_mtime"
    cp -p -- "$TARGET_PATH" "$backup_dir/target.php"
    chown root:root -- "$backup_dir/target.php"
    chmod 0600 -- "$backup_dir/target.php"
    sha256sum -- "$backup_dir/target.php" > "$backup_dir/target.php.sha256"
    chown root:root -- "$backup_dir/target.php.sha256"
    chmod 0600 -- "$backup_dir/target.php.sha256"
  fi
  {
    printf 'MECHANISM_VERSION=%s\n' "$MECHANISM_VERSION"
    printf 'TARGET_PATH=%s\n' "$SOURCE_RELATIVE_PATH"
    printf 'TARGET_STATE=%s\n' "$target_state"
    printf 'TARGET_SHA256=%s\n' "$target_sha"
    printf 'TARGET_UID=%s\nTARGET_GID=%s\nTARGET_MODE=%s\n' "${target_uid:-0}" "${target_gid:-0}" "${target_mode:-0}"
    printf 'TARGET_MTIME=%s\n' "$target_time"
    printf 'PUBLISHED_SHA256=%s\n' "$published_sha"
  } > "$backup_dir/metadata"
  chown root:root -- "$backup_dir/metadata"
  chmod 0600 -- "$backup_dir/metadata"
  printf '%s\n' "$backup_id" > "$backup_dir/backup-id"
  chown root:root -- "$backup_dir/backup-id"
  chmod 0600 -- "$backup_dir/backup-id"
  validate_backup "$backup_id"
}

validate_backup() {
  local id="$1" dir metadata_state metadata_sha metadata_path metadata_mtime published_sha
  is_backup_id "$id" || fail 'BACKUP_ID_INVALID'
  dir="$BACKUP_ROOT/$id"
  [[ -d "$dir" && ! -L "$dir" ]] || fail 'BACKUP_NOT_FOUND'
  [[ "$(stat -c '%u:%g:%a' -- "$dir" 2>/dev/null || true)" == '0:0:700' ]] || fail 'BACKUP_DIRECTORY_SECURITY_INVALID'
  for file in metadata backup-id; do
    [[ -f "$dir/$file" && ! -L "$dir/$file" ]] || fail 'BACKUP_FILE_MISSING'
    [[ "$(stat -c '%u:%g:%a' -- "$dir/$file")" == '0:0:600' ]] || fail 'BACKUP_FILE_SECURITY_INVALID'
  done
  [[ "$(cat -- "$dir/backup-id")" == "$id" ]] || fail 'BACKUP_ID_CONTENT_INVALID'
  metadata_path="$(awk -F= '$1 == "TARGET_PATH" {print $2; exit}' "$dir/metadata")"
  metadata_state="$(awk -F= '$1 == "TARGET_STATE" {print $2; exit}' "$dir/metadata")"
  metadata_sha="$(awk -F= '$1 == "TARGET_SHA256" {print $2; exit}' "$dir/metadata")"
  metadata_mtime="$(awk -F= '$1 == "TARGET_MTIME" {print $2; exit}' "$dir/metadata")"
  published_sha="$(awk -F= '$1 == "PUBLISHED_SHA256" {print $2; exit}' "$dir/metadata")"
  [[ "$metadata_path" == "$SOURCE_RELATIVE_PATH" ]] || fail 'BACKUP_TARGET_PATH_INVALID'
  [[ "$metadata_state" =~ ^(ABSENT|EXISTS)$ ]] || fail 'BACKUP_TARGET_STATE_INVALID'
  [[ "$metadata_mtime" =~ ^[0-9]+$ ]] || fail 'BACKUP_TARGET_MTIME_INVALID'
  [[ "$published_sha" =~ ^[0-9a-f]{64}$ ]] || fail 'BACKUP_PUBLISHED_SHA_INVALID'
  if [[ "$metadata_state" == 'EXISTS' ]]; then
    [[ "$metadata_sha" =~ ^[0-9a-f]{64}$ ]] || fail 'BACKUP_TARGET_SHA_INVALID'
    [[ -f "$dir/target.php" && ! -L "$dir/target.php" ]] || fail 'BACKUP_TARGET_FILE_MISSING'
    [[ -f "$dir/target.php.sha256" && ! -L "$dir/target.php.sha256" ]] || fail 'BACKUP_TARGET_CHECKSUM_MISSING'
    [[ "$(stat -c '%u:%g:%a' -- "$dir/target.php")" == '0:0:600' ]] || fail 'BACKUP_TARGET_SECURITY_INVALID'
    [[ "$(stat -c '%u:%g:%a' -- "$dir/target.php.sha256")" == '0:0:600' ]] || fail 'BACKUP_TARGET_CHECKSUM_SECURITY_INVALID'
    (cd "$dir" && sha256sum -c target.php.sha256 >/dev/null 2>&1) || fail 'BACKUP_TARGET_CHECKSUM_INVALID'
    [[ "$(sha256sum -- "$dir/target.php" | awk '{print $1}')" == "$metadata_sha" ]] || fail 'BACKUP_TARGET_SHA_MISMATCH'
  else
    [[ ! -e "$dir/target.php" && ! -e "$dir/target.php.sha256" ]] || fail 'ABSENT_BACKUP_HAS_TARGET'
  fi
}

restore_backup() {
  local dir="$BACKUP_ROOT/$backup_id" state temp expected_uid expected_gid expected_mode expected_mtime published_sha
  validate_backup "$backup_id"
  state="$(awk -F= '$1 == "TARGET_STATE" {print $2; exit}' "$dir/metadata")"
  published_sha="$(awk -F= '$1 == "PUBLISHED_SHA256" {print $2; exit}' "$dir/metadata")"
  read_target_state
  [[ "$target_state" == 'EXISTS' && "$target_hash" == "$published_sha" ]] || fail 'ROLLBACK_TARGET_CHANGED'
  if [[ "$state" == 'EXISTS' ]]; then
    expected_uid="$(awk -F= '$1 == "TARGET_UID" {print $2; exit}' "$dir/metadata")"
    expected_gid="$(awk -F= '$1 == "TARGET_GID" {print $2; exit}' "$dir/metadata")"
    expected_mode="$(awk -F= '$1 == "TARGET_MODE" {print $2; exit}' "$dir/metadata")"
    expected_mtime="$(awk -F= '$1 == "TARGET_MTIME" {print $2; exit}' "$dir/metadata")"
    [[ "$expected_uid" == '0' && "$expected_gid" == '0' ]] || fail 'ROLLBACK_METADATA_INVALID'
    is_safe_mode "$expected_mode" || fail 'ROLLBACK_METADATA_MODE_INVALID'
    [[ "$expected_mtime" =~ ^[0-9]+$ ]] || fail 'ROLLBACK_METADATA_MTIME_INVALID'
    temp="$(mktemp "$TARGET_PARENT/.philips-nondicom-rollback.XXXXXX")"
    cp -p -- "$dir/target.php" "$temp"
    chown "$expected_uid:$expected_gid" -- "$temp"
    chmod "$expected_mode" -- "$temp"
    touch -d "@$expected_mtime" -- "$temp"
    mv -f -- "$temp" "$TARGET_PATH"
  else
    rm -f -- "$TARGET_PATH"
  fi
  read_target_state
  if [[ "$state" == 'EXISTS' ]]; then
    [[ "$target_hash" == "$(awk -F= '$1 == "TARGET_SHA256" {print $2; exit}' "$dir/metadata")" ]] || fail 'ROLLBACK_TARGET_SHA_MISMATCH'
    [[ "$target_mtime" == "$(awk -F= '$1 == "TARGET_MTIME" {print $2; exit}' "$dir/metadata")" ]] || fail 'ROLLBACK_TARGET_MTIME_MISMATCH'
  else
    [[ "$target_state" == 'ABSENT' ]] || fail 'ROLLBACK_TARGET_STILL_PRESENT'
  fi
}

cleanup() {
  [[ -z "$stage_dir" ]] || rm -f -- "$stage_dir/source.php" "$stage_dir/philips_nondicom_production_diagnostic.php" "$stage_dir/rollback"
  [[ -z "$stage_dir" ]] || rmdir -- "$stage_dir" 2>/dev/null || true
}

rollback_published_change() {
  local status="$?"
  if [[ "$status" -ne 0 && "$published" == 'YES' && -n "$backup_id" ]]; then
    local rollback_status
    set +e
    restore_backup "$backup_id" >/dev/null 2>&1
    rollback_status=$?
    set -e
    if [[ "$rollback_status" -ne 0 ]]; then
      printf 'ROLLBACK=FAILED\nROLLBACK_REASON=TARGET_NOT_RESTORED\n' >&2
    else
      printf 'ROLLBACK=PASS\n' >&2
    fi
  fi
  cleanup
  exit "$status"
}

parse_args "$@"
require_root
if [[ "$mode" != 'rollback' ]]; then
  validate_repo
  validate_source
fi
validate_target_parent
read_target_state
if [[ "$mode" != 'rollback' && "$target_state" == 'EXISTS' && "$upgrade" != 'YES' ]]; then
  fail 'TARGET_ALREADY_PRESENT_USE_UPGRADE'
fi
if [[ "$mode" == 'rollback' ]]; then
  validate_backup_root
  acquire_lock
  restore_backup "$backup_id"
  printf 'DIAGNOSTIC_PROVISION=PASS\nMECHANISM_VERSION=%s\nROLLBACK=PASS\nBACKUP_ID=%s\nAPPLICATION_DEPLOY=NOT_EXECUTED\nDATABASE_CHANGED=NO\nTRANSMISSION=NO\n' "$MECHANISM_VERSION" "$backup_id"
  exit 0
fi

printf 'DIAGNOSTIC_PROVISION=START\nSOURCE_SHA=%s\nSOURCE_PATH=%s\nTARGET_STATE=%s\nUPGRADE=%s\n' "$expected_sha" "$SOURCE_RELATIVE_PATH" "$target_state" "$upgrade"
validate_backup_root
if [[ "$mode" == 'dry-run' ]]; then
  printf 'SOURCE_SHA_CHECK=PASS\nSOURCE_PHP_SYNTAX=PASS\nTARGET_PARENT=PASS\nBACKUP_ROOT=NOT_CREATED\nDRY_RUN=PASS\nINSTALL=NOT_EXECUTED\nDIAGNOSTIC_COMPONENT_CHANGED=NO\nAPPLICATION_DEPLOY=NOT_EXECUTED\nDRIFT_GATE=NOT_MODIFIED\nDATABASE_CHANGED=NO\nTRANSMISSION=NO\n'
  exit 0
fi
acquire_lock

trap rollback_published_change EXIT
create_backup
stage_dir="$(mktemp -d "$TARGET_PARENT/.philips-nondicom-diagnostic.XXXXXX")"
chmod 0700 "$stage_dir"
/usr/bin/git -C "$ROOT" show "$expected_sha:$SOURCE_RELATIVE_PATH" > "$stage_dir/source.php"
/usr/bin/install -o root -g root -m 0644 -- "$stage_dir/source.php" "$stage_dir/philips_nondicom_production_diagnostic.php"
/usr/bin/php -l "$stage_dir/philips_nondicom_production_diagnostic.php" >/dev/null || fail 'STAGED_SOURCE_PHP_SYNTAX_INVALID'
[[ "$(sha256sum "$stage_dir/philips_nondicom_production_diagnostic.php" | awk '{print $1}')" == "$source_file_sha256" ]] || fail 'STAGED_SOURCE_HASH_MISMATCH'
/usr/bin/mv -f -- "$stage_dir/philips_nondicom_production_diagnostic.php" "$TARGET_PATH"
published='YES'
read_target_state
[[ "$target_hash" == "$(sha256sum "$stage_dir/source.php" | awk '{print $1}')" ]] || fail 'PUBLISHED_SOURCE_HASH_MISMATCH'
[[ "$target_uid" == '0' && "$target_gid" == '0' && "$target_mode" == '644' ]] || fail 'PUBLISHED_SOURCE_SECURITY_INVALID'
trap - EXIT
cleanup
printf 'DIAGNOSTIC_PROVISION=PASS\nMECHANISM_VERSION=%s\nSOURCE_SHA_PREFIX=%s…\nBACKUP=CREATED\nBACKUP_ID=%s\nSOURCE_SHA_CHECK=PASS\nPUBLISHED_TARGET=PASS\nTARGET_OWNER_MODE=root:root:0644\nAPPLICATION_DEPLOY=NOT_EXECUTED\nDIAGNOSTIC_COMPONENT_CHANGED=YES\nDRIFT_GATE=NOT_MODIFIED\nDATABASE_CHANGED=NO\nTRANSMISSION=NO\n' "$MECHANISM_VERSION" "${expected_sha:0:12}" "$backup_id"
