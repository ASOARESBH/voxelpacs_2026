#!/usr/bin/env bash
# VOXEL PACS — provisionamento root-controlled da sincronização restrita.
#
# Este script NÃO é allowlisted no sudoers. Deve ser revisado e executado
# somente por administrador root a partir de checkout limpo e SHA confirmado.
# A instalação exige --install explícito; --dry-run não grava os destinos.
set -Eeuo pipefail
umask 077
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly SCRIPT_DIR
ROOT="$(/usr/bin/git -C "$SCRIPT_DIR/.." rev-parse --show-toplevel 2>/dev/null || true)"
readonly ROOT
readonly EXPECTED_SYNC_SHA='617e7d67bf88ba325773b220b78052d416eddb2b'
readonly HELPER_SOURCE="$ROOT/ops/deploy/voxelpacs-sync-restricted-drift"
readonly MANIFEST_SOURCE="$ROOT/ops/deploy/voxelpacs-restricted-drift-sync.manifest.tsv"
readonly SUDOERS_SOURCE="$ROOT/ops/sudoers/voxelpacs-restricted-drift-sync"
readonly INSTALLED_HELPER='/usr/local/sbin/voxelpacs-sync-restricted-drift'
readonly SHARE_ROOT='/usr/local/share/voxelpacs'
readonly INSTALLED_MANIFEST="$SHARE_ROOT/voxelpacs-restricted-drift-sync.manifest.tsv"
readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-restricted-drift-sync'
readonly LOCK_PATH='/run/lock/voxelpacs-restricted-drift-provision.lock'
readonly STAGE_BASE='/var/tmp'
readonly ALLOWLIST_COUNT=11
readonly ALLOWED_PATHS=(
  'app/Repositories/ReportDeliveryRepository.php'
  'app/Repositories/ReportDeliveryWorkerRepository.php'
  'app/Services/PhilipsFolderDeliveryService.php'
  'app/Services/PhilipsFolderGatewayBridgeClient.php'
  'app/Services/PhilipsSubmissionPackageProducer.php'
  'bin/report_delivery_worker.php'
  '.htaccess'
  'app/Services/ActiveDestinationResolutionException.php'
  'app/Services/ActiveDestinationResolver.php'
  'composer.json'
  'composer.lock'
)

mode=''
lock_fd=''
stage_dir=''
installed_helper=0
installed_manifest=0
installed_sudoers=0
created_share_root=0

fail() {
  printf 'PROVISION=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

usage() {
  printf '%s\n' \
    'uso: provision-restricted-drift-sync.sh --expected-sha <40-hex-sha> --dry-run' \
    'uso: provision-restricted-drift-sync.sh --expected-sha <40-hex-sha> --install' >&2
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
  [[ "$(/usr/bin/git -C "$ROOT" rev-parse HEAD 2>/dev/null)" == "$expected_sha" ]] || fail 'GIT_SHA_MISMATCH'
  [[ -z "$(/usr/bin/git -C "$ROOT" status --porcelain --untracked-files=all)" ]] || fail 'WORKTREE_NOT_CLEAN'
}

is_allowed_path() {
  local candidate="$1" allowed
  for allowed in "${ALLOWED_PATHS[@]}"; do
    [[ "$candidate" == "$allowed" ]] && return 0
  done
  return 1
}

validate_manifest() {
  local count=0 path hash extra actual
  declare -A seen=()
  [[ -f "$MANIFEST_SOURCE" && ! -L "$MANIFEST_SOURCE" ]] || fail 'MANIFEST_SOURCE_INVALID'
  while IFS=$'\t' read -r path hash extra; do
    [[ -n "$path" && -z "${extra:-}" ]] || fail 'MANIFEST_FORMAT_INVALID'
    [[ "$hash" =~ ^[0-9a-f]{64}$ ]] || fail 'MANIFEST_HASH_FORMAT_INVALID'
    is_allowed_path "$path" || fail 'MANIFEST_PATH_NOT_ALLOWED'
    [[ -z "${seen[$path]+present}" ]] || fail 'MANIFEST_DUPLICATE_PATH'
    seen["$path"]=1
    [[ -f "$ROOT/$path" && ! -L "$ROOT/$path" ]] || fail "SOURCE_FILE_INVALID:$path"
    actual="$(sha256sum "$ROOT/$path" | awk '{print $1}')"
    [[ "$actual" == "$hash" ]] || fail "MANIFEST_SOURCE_HASH_MISMATCH:$path"
    count=$((count + 1))
  done < "$MANIFEST_SOURCE"
  [[ "$count" -eq "$ALLOWLIST_COUNT" ]] || fail 'MANIFEST_COUNT_INVALID'
  local allowed
  for allowed in "${ALLOWED_PATHS[@]}"; do
    [[ -n "${seen[$allowed]+present}" ]] || fail 'MANIFEST_PATH_MISSING'
    /usr/bin/git -C "$ROOT" ls-files --error-unmatch -- "$allowed" >/dev/null 2>&1 || fail "SOURCE_FILE_NOT_TRACKED:$allowed"
    /usr/bin/git -C "$ROOT" cat-file -e "$expected_sha:$allowed" >/dev/null 2>&1 || fail "SOURCE_FILE_NOT_IN_SHA:$allowed"
  done
}

validate_helper_contract() {
  [[ -f "$HELPER_SOURCE" && ! -L "$HELPER_SOURCE" ]] || fail 'HELPER_SOURCE_INVALID'
  /usr/bin/grep -Fqx "readonly TARGET_SHA='$EXPECTED_SYNC_SHA'" "$HELPER_SOURCE" || fail 'HELPER_TARGET_SHA_MISMATCH'
  /usr/bin/grep -Fqx "readonly MANIFEST_PATH='/usr/local/share/voxelpacs/voxelpacs-restricted-drift-sync.manifest.tsv'" "$HELPER_SOURCE" || fail 'HELPER_MANIFEST_PATH_MISMATCH'
  /usr/bin/grep -Fqx "readonly FIXED_APP_ROOT='/var/www/voxelpacs/app'" "$HELPER_SOURCE" || fail 'HELPER_DESTINATION_PATH_MISMATCH'
}

validate_sudoers() {
  local rule_count
  [[ -f "$SUDOERS_SOURCE" && ! -L "$SUDOERS_SOURCE" ]] || fail 'SUDOERS_SOURCE_INVALID'
  rule_count="$(/usr/bin/grep -Ec '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:' "$SUDOERS_SOURCE")"
  [[ "$rule_count" -eq 2 ]] || fail 'SUDOERS_RULE_COUNT_INVALID'
  /usr/bin/grep -Fq '/usr/local/sbin/voxelpacs-sync-restricted-drift --dry-run' "$SUDOERS_SOURCE" || fail 'SUDOERS_DRY_RUN_RULE_MISSING'
  /usr/bin/grep -Fq '/usr/local/sbin/voxelpacs-sync-restricted-drift --execute' "$SUDOERS_SOURCE" || fail 'SUDOERS_EXECUTE_RULE_MISSING'
  ! /usr/bin/grep -Eq 'NOPASSWD:[[:space:]]+ALL([[:space:]]|$)' "$SUDOERS_SOURCE" || fail 'GENERIC_SUDO_RULE_PRESENT'
  ! /usr/bin/grep -Eq '/bin/(ba|d)?sh|/usr/(bin|sbin)/(cp|mv|rm|rsync|install|tee|python|php|composer|systemctl)' "$SUDOERS_SOURCE" || fail 'GENERIC_COMMAND_PRESENT'
  /usr/sbin/visudo -cf "$SUDOERS_SOURCE" >/dev/null || fail 'SUDOERS_SYNTAX_INVALID'
}

validate_install_scope() {
  [[ -d '/usr/local/sbin' && ! -L '/usr/local/sbin' ]] || fail 'SUDO_BIN_DIRECTORY_INVALID'
  [[ -d '/etc/sudoers.d' && ! -L '/etc/sudoers.d' ]] || fail 'SUDOERS_DIRECTORY_INVALID'
  if [[ -e "$SHARE_ROOT" || -L "$SHARE_ROOT" ]]; then
    [[ -d "$SHARE_ROOT" && ! -L "$SHARE_ROOT" ]] || fail 'SHARE_ROOT_INVALID'
  fi
  [[ ! -e "$INSTALLED_HELPER" && ! -L "$INSTALLED_HELPER" ]] || fail 'HELPER_ALREADY_INSTALLED'
  [[ ! -e "$INSTALLED_MANIFEST" && ! -L "$INSTALLED_MANIFEST" ]] || fail 'MANIFEST_ALREADY_INSTALLED'
  [[ ! -e "$INSTALLED_SUDOERS" && ! -L "$INSTALLED_SUDOERS" ]] || fail 'SUDOERS_ALREADY_INSTALLED'
}

acquire_lock() {
  [[ -d "$(dirname "$LOCK_PATH")" && ! -L "$(dirname "$LOCK_PATH")" ]] || fail 'LOCK_DIRECTORY_INVALID'
  exec {lock_fd}>"$LOCK_PATH"
  /usr/bin/flock -n "$lock_fd" || fail 'PROVISION_LOCK_BUSY'
}

cleanup() {
  if (( installed_sudoers )); then /usr/bin/rm -f -- "$INSTALLED_SUDOERS"; fi
  if (( installed_manifest )); then /usr/bin/rm -f -- "$INSTALLED_MANIFEST"; fi
  if (( installed_helper )); then /usr/bin/rm -f -- "$INSTALLED_HELPER"; fi
  if (( created_share_root )); then /usr/bin/rmdir -- "$SHARE_ROOT" 2>/dev/null || true; fi
  cleanup_stage
}

cleanup_stage() {
  if [[ -n "$stage_dir" && -d "$stage_dir" ]]; then
    /usr/bin/rm -f -- "$stage_dir/helper" "$stage_dir/manifest" "$stage_dir/sudoers"
    /usr/bin/rmdir -- "$stage_dir" 2>/dev/null || true
  fi
}
trap cleanup EXIT

stage_files() {
  stage_dir="$(/usr/bin/mktemp -d "$STAGE_BASE/voxelpacs-restricted-drift-provision.XXXXXX")"
  /usr/bin/chmod 0700 "$stage_dir"
  /usr/bin/install -o root -g root -m 0555 -- "$HELPER_SOURCE" "$stage_dir/helper"
  /usr/bin/install -o root -g root -m 0444 -- "$MANIFEST_SOURCE" "$stage_dir/manifest"
  /usr/bin/install -o root -g root -m 0440 -- "$SUDOERS_SOURCE" "$stage_dir/sudoers"
  /usr/sbin/visudo -cf "$stage_dir/sudoers" >/dev/null || fail 'STAGED_SUDOERS_SYNTAX_INVALID'
  [[ "$(sha256sum "$stage_dir/helper" | awk '{print $1}')" == "$(sha256sum "$HELPER_SOURCE" | awk '{print $1}')" ]] || fail 'STAGED_HELPER_HASH_MISMATCH'
  [[ "$(sha256sum "$stage_dir/manifest" | awk '{print $1}')" == "$(sha256sum "$MANIFEST_SOURCE" | awk '{print $1}')" ]] || fail 'STAGED_MANIFEST_HASH_MISMATCH'
  [[ "$(sha256sum "$stage_dir/sudoers" | awk '{print $1}')" == "$(sha256sum "$SUDOERS_SOURCE" | awk '{print $1}')" ]] || fail 'STAGED_SUDOERS_HASH_MISMATCH'
}

install_staged_files() {
  if [[ ! -e "$SHARE_ROOT" ]]; then
    /usr/bin/install -d -o root -g root -m 0755 -- "$SHARE_ROOT"
    created_share_root=1
  fi
  /usr/bin/mv -- "$stage_dir/helper" "$INSTALLED_HELPER"
  installed_helper=1
  /usr/bin/mv -- "$stage_dir/manifest" "$INSTALLED_MANIFEST"
  installed_manifest=1
  /usr/bin/mv -- "$stage_dir/sudoers" "$INSTALLED_SUDOERS"
  installed_sudoers=1
  cleanup_stage
  trap - EXIT
}

print_common() {
  printf 'PROVISION=START\nSOURCE_SHA=%s\nSYNC_TARGET_SHA=%s\nDESTINATION=%s\n' "$expected_sha" "$EXPECTED_SYNC_SHA" "$INSTALLED_HELPER"
  printf 'ALLOWLIST_COUNT=%s\n' "$ALLOWLIST_COUNT"
}

main() {
  parse_args "$@"
  require_root
  print_common
  validate_repo
  validate_manifest
  validate_helper_contract
  validate_sudoers
  validate_install_scope
  acquire_lock
  if [[ "$mode" == 'dry-run' ]]; then
    printf 'SOURCE_SHA_CHECK=PASS\nMANIFEST_CHECK=PASS\nHELPER_CHECK=PASS\nSUDOERS_CHECK=PASS\nTARGETS_ABSENT=PASS\nDRY_RUN=PASS\nINSTALL=NOT_EXECUTED\nPRODUCTION_CHANGED=NO\nWORKER=NO\nBRIDGE=NO\nSMB=NO\nTRANSMISSION=NO\nPROVISION=END\n'
    return 0
  fi
  stage_files
  install_staged_files
  printf 'SOURCE_SHA_CHECK=PASS\nMANIFEST_CHECK=PASS\nHELPER_INSTALLED=YES\nMANIFEST_INSTALLED=YES\nSUDOERS_INSTALLED=YES\nSUDOERS_SYNTAX=PASS\nHELPER_OWNER_MODE=root:root:0555\nMANIFEST_OWNER_MODE=root:root:0444\nSUDOERS_OWNER_MODE=root:root:0440\nINSTALL=PASS\nPRODUCTION_CHANGED=YES\nWORKER=NO\nBRIDGE=NO\nSMB=NO\nTRANSMISSION=NO\nPROVISION=END\n'
}

main "$@"
