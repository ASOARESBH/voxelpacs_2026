#!/usr/bin/env bash
# VOXEL PACS — mecanismo root-only de backup de release.
#
# Este arquivo é instalado como root:root em /usr/local/sbin e só deve ser
# chamado pelos três comandos explicitamente allowlisted no sudoers.
# Não aceita paths, comandos, shell ou ambiente de backup fornecidos pelo caller.
set -Eeuo pipefail
umask 077

export PATH='/usr/sbin:/usr/bin:/sbin:/bin'
readonly APP_ROOT='/var/www/voxelpacs/app'
readonly BACKUP_ROOT='/var/backups/voxelpacs/releases'
readonly RESTORE_TEST_ROOT='/var/backups/voxelpacs/restore-tests'
readonly LOCK_PATH='/run/lock/voxelpacs-release-backup.lock'
readonly MECHANISM_VERSION='1'
readonly RELEASE_ARCHIVE='release.tar.gz'
readonly RELEASE_MANIFEST='manifest.tsv'
readonly RELEASE_CHECKSUM='release.sha256'
readonly RELEASE_SOURCE_SHA='source-sha'
readonly RELEASE_METADATA='metadata'
readonly RELEASE_STATUS='status'
readonly RELEASE_PATHS=(
  '.htaccess'
  'VERSAO.txt'
  'composer.json'
  'composer.lock'
  'app'
  'public'
  'bin'
  'routes'
  'lang'
  'cron'
  'config'
  'vendor'
  'Config'
  'Controllers'
  'Core'
  'Helpers'
  'Middlewares'
  'Models'
  'Repositories'
  'Services'
  'Views'
)

operation=''
sha=''
lock_fd=''
cleanup_dir=''

cleanup_on_exit() {
  if [[ -n "$cleanup_dir" && -d "$cleanup_dir" ]]; then
    /usr/bin/rm -rf -- "$cleanup_dir"
  fi
}
trap cleanup_on_exit EXIT

fail() {
  printf 'BACKUP=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

usage() {
  printf '%s\n' \
    'uso: voxelpacs-release-backup create --sha <40-hex-sha>' \
    'uso: voxelpacs-release-backup validate --sha <40-hex-sha>' \
    'uso: voxelpacs-release-backup restore-test --sha <40-hex-sha>' >&2
}

is_sha() {
  [[ "${1:-}" =~ ^[0-9a-f]{40}$ ]]
}

safe_relative_path() {
  local path="$1"
  [[ -n "$path" && "$path" != /* ]] || return 1
  [[ "$path" != '.' && "$path" != *$'\n'* && "$path" != *$'\r'* && "$path" != *$'\t'* ]] || return 1
  [[ "$path" != '../'* && "$path" != */../* && "$path" != */.. && "$path" != '..' ]] || return 1
  case "$path" in
    .env|.env.*|*/.env|*/.env.*|storage|storage/*|*/storage|*/storage/*|logs|logs/*|*/logs|*/logs/*|backups|backups/*|*/backups|*/backups/*|public/uploads|public/uploads/*|*/public/uploads|*/public/uploads/*)
      return 1
      ;;
  esac
  return 0
}

require_root_and_caller() {
  [[ "$EUID" -eq 0 ]] || fail 'ROOT_REQUIRED'
  case "${SUDO_USER:-}" in
    ''|manus-admin|manus-deploy) ;;
    *) fail 'CALLER_NOT_ALLOWLISTED' ;;
  esac
}

require_fixed_directory() {
  local path="$1"
  if [[ ! -e "$path" ]]; then
    /usr/bin/mkdir -p -- "$path"
    /usr/bin/chown root:root -- "$path"
    /usr/bin/chmod 700 -- "$path"
  fi
  [[ -d "$path" && ! -L "$path" ]] || fail 'BACKUP_ROOT_INVALID'
  [[ "$(/usr/bin/realpath -e -- "$path")" == "$path" ]] || fail 'BACKUP_ROOT_PATH_NOT_CANONICAL'
  [[ "$(/usr/bin/stat -c '%u:%g' "$path")" == '0:0' ]] || fail 'BACKUP_ROOT_OWNER_INVALID'
  local mode
  mode=$((8#$(/usr/bin/stat -c '%a' "$path")))
  (( (mode & 077) == 0 )) || fail 'BACKUP_ROOT_MODE_INVALID'
}

state_fingerprint() {
  local env_state storage_state storage_target uploads_state report_delivery_state logs_state backups_state legacy_state
  if [[ -e "$APP_ROOT/.env" || -L "$APP_ROOT/.env" ]]; then
    env_state="$(/usr/bin/stat -c '%d:%i:%u:%g:%a:%s:%Y:%F' "$APP_ROOT/.env")"
  else
    env_state='ABSENT'
  fi
  if [[ -e "$APP_ROOT/storage" || -L "$APP_ROOT/storage" ]]; then
    storage_state="$(/usr/bin/stat -c '%d:%i:%u:%g:%a:%s:%Y:%F' "$APP_ROOT/storage")"
    storage_target='NOT_A_SYMLINK'
    [[ -L "$APP_ROOT/storage" ]] && storage_target="$(/usr/bin/readlink -- "$APP_ROOT/storage")"
  else
    storage_state='ABSENT'
    storage_target='ABSENT'
  fi
  if [[ -e "$APP_ROOT/public/uploads" || -L "$APP_ROOT/public/uploads" ]]; then
    uploads_state="$(/usr/bin/stat -c '%d:%i:%u:%g:%a:%s:%Y:%F' "$APP_ROOT/public/uploads")"
  else
    uploads_state='ABSENT'
  fi
  if [[ -e "$APP_ROOT/storage/report_delivery" || -L "$APP_ROOT/storage/report_delivery" ]]; then
    report_delivery_state="$(/usr/bin/stat -c '%d:%i:%u:%g:%a:%s:%Y:%F' "$APP_ROOT/storage/report_delivery")"
  else
    report_delivery_state='ABSENT'
  fi
  if [[ -e "$APP_ROOT/storage/logs" || -L "$APP_ROOT/storage/logs" ]]; then
    logs_state="$(/usr/bin/stat -c '%d:%i:%u:%g:%a:%s:%Y:%F' "$APP_ROOT/storage/logs")"
  else
    logs_state='ABSENT'
  fi
  if [[ -e "$APP_ROOT/storage/backups" || -L "$APP_ROOT/storage/backups" ]]; then
    backups_state="$(/usr/bin/stat -c '%d:%i:%u:%g:%a:%s:%Y:%F' "$APP_ROOT/storage/backups")"
  else
    backups_state='ABSENT'
  fi
  legacy_state='ABSENT'
  if [[ -f "$APP_ROOT/Config/ReportDeliveryRuntimeConfig.php" ]]; then
    legacy_state="$(/usr/bin/sha256sum -- "$APP_ROOT/Config/ReportDeliveryRuntimeConfig.php" | /usr/bin/awk '{print $1}')"
  fi
  printf '%s|%s|%s|%s|%s|%s|%s|%s\n' "$env_state" "$storage_state" "$storage_target" "$uploads_state" "$report_delivery_state" "$logs_state" "$backups_state" "$legacy_state"
}

assert_state_unchanged() {
  local before="$1" after
  after="$(state_fingerprint)" || fail 'PERSISTENT_STATE_UNREADABLE'
  [[ "$before" == "$after" ]] || fail 'PERSISTENT_STATE_CHANGED'
}

assert_release_root() {
  [[ -d "$APP_ROOT" && ! -L "$APP_ROOT" ]] || fail 'RUNTIME_ROOT_NOT_FOUND'
  [[ -r "$APP_ROOT/.env" || ! -e "$APP_ROOT/.env" ]] || fail 'ENV_STATE_UNREADABLE'
  [[ -e "$APP_ROOT/storage" || -L "$APP_ROOT/storage" ]] || fail 'STORAGE_STATE_NOT_FOUND'
  [[ "$APP_ROOT" != '/var/www/voxelpacs' ]] || fail 'PARENT_ROOT_IS_NOT_RUNTIME_ROOT'
}

append_tree_paths() {
  local relative="$1" root="$APP_ROOT/$relative" path rel
  [[ -e "$root" || -L "$root" ]] || return 0
  [[ ! -L "$root" ]] || fail 'RELEASE_ROOT_SYMLINK_NOT_ALLOWED'
  if [[ -d "$root" ]]; then
    while IFS= read -r -d '' path; do
      rel="${path#"$APP_ROOT/"}"
      safe_relative_path "$rel" || fail 'RELEASE_PATH_NOT_ALLOWED'
      [[ ! -L "$path" ]] || fail 'RELEASE_SYMLINK_NOT_ALLOWED'
      printf '%s\0' "$rel"
    done < <(/usr/bin/find -P "$root" -path "$root/uploads" -prune -o \( -type d -o -type f \) -print0)
  else
    safe_relative_path "$relative" || fail 'RELEASE_PATH_NOT_ALLOWED'
    printf '%s\0' "$relative"
  fi
}

collect_release_paths() {
  local relative
  for relative in "${RELEASE_PATHS[@]}"; do
    append_tree_paths "$relative"
  done
}

write_manifest() {
  local paths="$1" manifest="$2" relative target mode uid gid size mtime hash
  {
    printf 'META\tmechanism_version\t%s\n' "$MECHANISM_VERSION"
    printf 'META\tsource_sha\t%s\n' "$sha"
    printf 'META\tmtime_encoding\tPOSIX_EPOCH_UTC\n'
    printf 'META\tarchive\t%s\n' "$RELEASE_ARCHIVE"
    printf 'EXCLUDED\t.env\tcontent_not_backed_up\n'
    printf 'EXCLUDED\tstorage\tpersistent_symlink_or_directory_not_followed\n'
    printf 'EXCLUDED\tpublic/uploads\tpublic_non_versioned_path_not_backed_up\n'
    printf 'EXCLUDED\tlogs\tpersistent_path_not_backed_up\n'
    printf 'EXCLUDED\tbackups\tpersistent_path_not_backed_up\n'
    while IFS= read -r -d '' relative; do
      target="$APP_ROOT/$relative"
      mode="$(/usr/bin/stat -c '%a' -- "$target")"
      uid="$(/usr/bin/stat -c '%u' -- "$target")"
      gid="$(/usr/bin/stat -c '%g' -- "$target")"
      mtime="$(/usr/bin/stat -c '%Y' -- "$target")"
      if [[ -d "$target" ]]; then
        printf 'DIR\t%s\t%s\t%s\t%s\t%s\t%s\n' "$relative" "$mode" "$uid" "$gid" "$mtime" '-'
      else
        size="$(/usr/bin/stat -c '%s' -- "$target")"
        hash="$(/usr/bin/sha256sum -- "$target" | /usr/bin/awk '{print $1}')"
        printf 'FILE\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$relative" "$size" "$hash" "$mode" "$uid" "$gid" "$mtime"
      fi
    done < "$paths"
  } > "$manifest"
  /usr/bin/chown root:root -- "$manifest"
  /usr/bin/chmod 600 -- "$manifest"
}

manifest_paths() {
  local manifest="$1"
  /usr/bin/awk -F '\t' '$1 == "FILE" || $1 == "DIR" { print $2 }' "$manifest" | /usr/bin/sort
}

archive_paths() {
  local archive="$1"
  /usr/bin/tar -tzf "$archive" | /usr/bin/sed 's:/$::' | /usr/bin/sort
}

validate_archive_entries() {
  local archive="$1" entry top
  while IFS= read -r entry; do
    [[ -n "$entry" ]] || continue
    entry="${entry%/}"
    safe_relative_path "$entry" || fail 'ARCHIVE_PATH_NOT_ALLOWED'
    top="${entry%%/*}"
    case "$top" in
      .htaccess|VERSAO.txt|composer.json|composer.lock|app|public|bin|routes|lang|cron|config|vendor|Config|Controllers|Core|Helpers|Middlewares|Models|Repositories|Services|Views) ;;
      *) fail 'ARCHIVE_TOP_LEVEL_NOT_ALLOWED' ;;
    esac
  done < <(/usr/bin/tar -tzf "$archive")
}

validate_manifest_shape() {
  local manifest="$1"
  [[ -s "$manifest" ]] || fail 'MANIFEST_MISSING'
  /usr/bin/awk -F '\t' '
    $1 == "META" && NF == 3 { next }
    $1 == "EXCLUDED" && NF == 3 { next }
    $1 == "DIR" && NF == 7 && $2 != "" && $3 ~ /^[0-9]+$/ && $4 ~ /^[0-9]+$/ && $5 ~ /^[0-9]+$/ && $6 ~ /^[0-9]+$/ && $7 == "-" { next }
    $1 == "FILE" && NF == 8 && $2 != "" && $3 ~ /^[0-9]+$/ && $4 ~ /^[0-9a-f]{64}$/ && $5 ~ /^[0-9]+$/ && $6 ~ /^[0-9]+$/ && $7 ~ /^[0-9]+$/ && $8 ~ /^[0-9]+$/ { next }
    { bad=1 }
    END { exit bad ? 1 : 0 }
  ' "$manifest" || fail 'MANIFEST_FORMAT_INVALID'
  /usr/bin/awk -F '\t' '$1 == "META" && $2 == "source_sha" { print $3 }' "$manifest" | /usr/bin/grep -Fxq "$sha" || fail 'MANIFEST_SHA_MISMATCH'
}

validate_manifest_paths() {
  local manifest="$1" rel
  while IFS= read -r rel; do
    safe_relative_path "$rel" || fail 'MANIFEST_PATH_NOT_ALLOWED'
  done < <(manifest_paths "$manifest")
}

validate_archive_and_manifest() {
  local backup_dir="$1" archive manifest checksum source_sha expected actual
  archive="$backup_dir/$RELEASE_ARCHIVE"
  manifest="$backup_dir/$RELEASE_MANIFEST"
  checksum="$backup_dir/$RELEASE_CHECKSUM"
  source_sha="$backup_dir/$RELEASE_SOURCE_SHA"
  [[ -d "$backup_dir" && ! -L "$backup_dir" ]] || fail 'BACKUP_NOT_FOUND'
  [[ "$(/usr/bin/stat -c '%u:%g:%a' "$backup_dir")" == '0:0:700' ]] || fail 'BACKUP_DIRECTORY_SECURITY_INVALID'
  for file in "$archive" "$manifest" "$checksum" "$source_sha" "$backup_dir/$RELEASE_METADATA" "$backup_dir/$RELEASE_STATUS"; do
    [[ -f "$file" && ! -L "$file" ]] || fail 'BACKUP_FILE_MISSING'
    local mode
    mode=$((8#$(/usr/bin/stat -c '%a' "$file")))
    (( (mode & 077) == 0 )) || fail 'BACKUP_FILE_MODE_INVALID'
  done
  expected="$(/usr/bin/awk 'NR==1 {print $1}' "$source_sha")"
  [[ "$expected" == "$sha" && "$(/usr/bin/wc -l < "$source_sha")" -eq 1 ]] || fail 'SOURCE_SHA_INVALID'
  (cd "$backup_dir" && /usr/bin/sha256sum -c "$RELEASE_CHECKSUM" >/dev/null 2>&1) || fail 'BACKUP_CHECKSUM_INVALID'
  /usr/bin/tar -tzf "$archive" >/dev/null 2>&1 || fail 'BACKUP_ARCHIVE_INVALID'
  validate_archive_entries "$archive"
  validate_manifest_shape "$manifest"
  validate_manifest_paths "$manifest"
  /usr/bin/cmp -s <(manifest_paths "$manifest") <(archive_paths "$archive") || fail 'BACKUP_MANIFEST_ARCHIVE_MISMATCH'
  printf 'BACKUP=PASS\nBACKUP_SHA_PREFIX=%s…\nCHECKSUM_VALIDATION=PASS\nMANIFEST_VALIDATION=PASS\nPERSISTENT_STORAGE_PROTECTION=PASS\nSECRET_PROTECTION=PASS\n' "${sha:0:12}"
}

compare_extracted_tree() {
  local root="$1" manifest="$2" rel size hash mode uid gid mtime target
  /usr/bin/cmp -s <(manifest_paths "$manifest") <(/usr/bin/find -P "$root" -mindepth 1 \( -type d -o -type f \) -printf '%P\n' | /usr/bin/sort) || fail 'RESTORE_TREE_MISMATCH'
  while IFS=$'\t' read -r kind rel size hash mode uid gid mtime; do
    target="$root/$rel"
    case "$kind" in
      FILE)
        [[ -f "$target" && ! -L "$target" ]] || fail 'RESTORE_FILE_MISSING'
        [[ "$(/usr/bin/stat -c '%s' -- "$target")" == "$size" ]] || fail 'RESTORE_SIZE_MISMATCH'
        [[ "$(/usr/bin/sha256sum -- "$target" | /usr/bin/awk '{print $1}')" == "$hash" ]] || fail 'RESTORE_HASH_MISMATCH'
        [[ "$(/usr/bin/stat -c '%a:%u:%g:%Y' -- "$target")" == "$mode:$uid:$gid:$mtime" ]] || fail 'RESTORE_METADATA_MISMATCH'
        ;;
      DIR)
        [[ -d "$target" && ! -L "$target" ]] || fail 'RESTORE_DIRECTORY_MISSING'
        [[ "$(/usr/bin/stat -c '%a:%u:%g:%Y' -- "$target")" == "$size:$hash:$mode:$uid" ]] || fail 'RESTORE_DIRECTORY_METADATA_MISMATCH'
        ;;
    esac
  done < "$manifest"
}

create_backup() {
  local backup_dir="$BACKUP_ROOT/$sha" temp_dir paths before_state after_state archive tar_path manifest checksum source_sha metadata status
  require_fixed_directory "$BACKUP_ROOT"
  require_fixed_directory "$RESTORE_TEST_ROOT"
  assert_release_root
  backup_dir="$BACKUP_ROOT/$sha"
  if [[ -e "$backup_dir" ]]; then
    [[ -d "$backup_dir" && ! -L "$backup_dir" ]] || fail 'BACKUP_EXISTING_INVALID'
    validate_archive_and_manifest "$backup_dir" >/dev/null
    printf 'BACKUP=ALREADY_VALID\nBACKUP_SHA_PREFIX=%s…\nPRODUCTION_CHANGED=NO\n' "${sha:0:12}"
    return 0
  fi
  temp_dir="$BACKUP_ROOT/.tmp.${sha}.$$.${RANDOM}"
  [[ ! -e "$temp_dir" ]] || fail 'BACKUP_TEMP_COLLISION'
  /usr/bin/mkdir -- "$temp_dir"
  /usr/bin/chown root:root -- "$temp_dir"
  /usr/bin/chmod 700 -- "$temp_dir"
  cleanup_dir="$temp_dir"
  paths="$temp_dir/paths.list"
  archive="$temp_dir/$RELEASE_ARCHIVE"
  tar_path="$temp_dir/.release.tar"
  manifest="$temp_dir/$RELEASE_MANIFEST"
  checksum="$temp_dir/$RELEASE_CHECKSUM"
  source_sha="$temp_dir/$RELEASE_SOURCE_SHA"
  metadata="$temp_dir/$RELEASE_METADATA"
  status="$temp_dir/$RELEASE_STATUS"
  before_state="$(state_fingerprint)"
  collect_release_paths | /usr/bin/sort -z -u > "$paths"
  [[ -s "$paths" ]] || fail 'RELEASE_PATHS_EMPTY'
  (cd "$APP_ROOT" && /usr/bin/tar --create --no-recursion --null --verbatim-files-from --files-from="$paths" --numeric-owner --preserve-permissions --file="$tar_path")
  /usr/bin/gzip -n -9 -- "$tar_path"
  /usr/bin/mv -- "$tar_path.gz" "$archive"
  printf '%s\n' "$sha" > "$source_sha"
  write_manifest "$paths" "$manifest"
  (cd "$temp_dir" && /usr/bin/sha256sum -- "$RELEASE_ARCHIVE") > "$checksum"
  /usr/bin/chown root:root -- "$archive" "$checksum" "$source_sha"
  /usr/bin/chmod 600 -- "$archive" "$checksum" "$source_sha"
  printf 'mechanism_version=%s\nsource_sha=%s\narchive=%s\nmanifest=%s\napp_root_storage=excluded_and_not_followed\nsecret_content=excluded\ncreated_at_utc=%s\n' "$MECHANISM_VERSION" "$sha" "$RELEASE_ARCHIVE" "$RELEASE_MANIFEST" "$(/bin/date -u +%Y-%m-%dT%H:%M:%SZ)" > "$metadata"
  printf 'created\n' > "$status"
  /usr/bin/chown root:root -- "$metadata" "$status"
  /usr/bin/chmod 600 -- "$metadata" "$status"
  validate_archive_and_manifest "$temp_dir" >/dev/null
  after_state="$(state_fingerprint)"
  [[ "$before_state" == "$after_state" ]] || fail 'PERSISTENT_STATE_CHANGED'
  /usr/bin/rm -f -- "$paths"
  /usr/bin/mv -- "$temp_dir" "$backup_dir"
  cleanup_dir=''
  printf 'BACKUP=PASS\nBACKUP_SHA_PREFIX=%s…\nBACKUP_ARCHIVE=PASS\nCHECKSUM_VALIDATION=PASS\nMANIFEST_VALIDATION=PASS\nBACKUP_ATOMIC_PUBLISH=PASS\nPERSISTENT_STORAGE_PROTECTION=PASS\nSECRET_PROTECTION=PASS\nPRODUCTION_CHANGED=NO\n' "${sha:0:12}"
}

validate_backup() {
  require_fixed_directory "$BACKUP_ROOT"
  require_fixed_directory "$RESTORE_TEST_ROOT"
  validate_archive_and_manifest "$BACKUP_ROOT/$sha"
  printf 'PRODUCTION_CHANGED=NO\nDATABASE_CHANGED=NO\n'
}

restore_test() {
  local backup_dir="$BACKUP_ROOT/$sha" final_dir="$RESTORE_TEST_ROOT/$sha" temp_dir archive manifest before_state
  require_fixed_directory "$BACKUP_ROOT"
  require_fixed_directory "$RESTORE_TEST_ROOT"
  [[ ! -e "$final_dir" ]] || fail 'RESTORE_TEST_ALREADY_EXISTS'
  validate_archive_and_manifest "$backup_dir" >/dev/null
  temp_dir="$RESTORE_TEST_ROOT/.tmp.${sha}.$$.${RANDOM}"
  /usr/bin/mkdir -- "$temp_dir"
  /usr/bin/chown root:root -- "$temp_dir"
  /usr/bin/chmod 700 -- "$temp_dir"
  cleanup_dir="$temp_dir"
  archive="$backup_dir/$RELEASE_ARCHIVE"
  manifest="$backup_dir/$RELEASE_MANIFEST"
  /usr/bin/tar --extract --gzip --file="$archive" --directory="$temp_dir" --numeric-owner --same-owner --same-permissions
  compare_extracted_tree "$temp_dir" "$manifest"
  printf 'restore-test-pass\n' > "$temp_dir/$RELEASE_STATUS"
  /usr/bin/chown root:root -- "$temp_dir/$RELEASE_STATUS"
  /usr/bin/chmod 600 -- "$temp_dir/$RELEASE_STATUS"
  /usr/bin/mv -- "$temp_dir" "$final_dir"
  cleanup_dir=''
  printf 'RESTORE_TEST=PASS\nRESTORE_TEST_ROOT=FIXED\nPRODUCTION_CHANGED=NO\nDATABASE_CHANGED=NO\n'
}

require_root_and_caller
[[ "$#" -eq 3 && ( "$1" == 'create' || "$1" == 'validate' || "$1" == 'restore-test' ) && "$2" == '--sha' ]] || { usage; fail 'ARGUMENTS_INVALID'; }
operation="$1"
sha="$3"
is_sha "$sha" || fail 'SHA_INVALID'

/usr/bin/mkdir -p -- "$(/usr/bin/dirname "$LOCK_PATH")"
exec {lock_fd}>"$LOCK_PATH"
/usr/bin/flock -n "$lock_fd" || fail 'BACKUP_LOCK_BUSY'

case "$operation" in
  create) create_backup ;;
  validate) validate_backup ;;
  restore-test) restore_test ;;
  *) fail 'ARGUMENTS_INVALID' ;;
esac
