#!/usr/bin/env bash
# VOXEL PACS — aprovação root-controlled do perfil Composer da baseline.
# Opera somente sobre os caminhos fixos da âncora e exige a main canônica.
set -Eeuo pipefail
umask 077
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly SCRIPT_DIR
ROOT="$(git -C "$SCRIPT_DIR/.." rev-parse --show-toplevel 2>/dev/null || true)"
readonly ROOT
readonly APPROVAL_PARENT='/etc/voxelpacs'
readonly APPROVAL_FILE='/etc/voxelpacs/voxelpacs-composer-profile-approved-main-sha'
readonly APPROVAL_PARENT_PARENT='/etc'
readonly CANONICAL_REMOTE='https://github.com/ASOARESBH/voxelpacs_2026.git'
readonly MAIN_REF='refs/heads/main'
readonly LOCK_PATH='/run/lock/voxelpacs-composer-profile-approval.lock'
readonly SOURCE_PATH='scripts/provision-composer-profile-approval.sh'

mode=''
expected_sha=''
parent_created='NO'
parent_changed='NO'
anchor_created='NO'
write_started='NO'
changed='NO'
old_parent_uid=''
old_parent_gid=''
old_parent_mode=''
lock_fd=''
temporary_anchor=''

fail() {
  printf 'APPROVAL=BLOCKED\nREASON=%s\nCONFIG_CHANGED=NO\n' "$1" >&2
  exit 64
}

usage() {
  printf '%s\n' \
    'uso: provision-composer-profile-approval.sh --expected-sha <40-hex-sha> --inspect' \
    'uso: provision-composer-profile-approval.sh --expected-sha <40-hex-sha> --dry-run' \
    'uso: provision-composer-profile-approval.sh --expected-sha <40-hex-sha> --apply' >&2
}

parse_args() {
  [[ "$#" -ge 3 && "$1" == '--expected-sha' ]] || { usage; fail 'ARGUMENTS_INVALID'; }
  expected_sha="$2"
  shift 2
  while [[ "$#" -gt 0 ]]; do
    case "$1" in
      --inspect|--dry-run|--apply)
        [[ -z "$mode" ]] || fail 'ARGUMENTS_INVALID'
        mode="${1#--}"
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

require_root_and_caller() {
  [[ "$EUID" -eq 0 ]] || fail 'ROOT_REQUIRED'
  case "${SUDO_USER:-}" in
    ''|manus-admin|manus-deploy) ;;
    *) fail 'CALLER_NOT_ALLOWLISTED' ;;
  esac
}

validate_repo() {
  local remote shallow remote_main
  [[ -n "$ROOT" && ( -d "$ROOT/.git" || -f "$ROOT/.git" ) ]] || fail 'GIT_ROOT_NOT_FOUND'
  [[ "$SCRIPT_DIR" == "$ROOT/scripts" ]] || fail 'SCRIPT_LOCATION_INVALID'
  [[ "$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || true)" == "$expected_sha" ]] || fail 'GIT_SHA_MISMATCH'
  [[ -z "$(git -C "$ROOT" status --porcelain --untracked-files=all)" ]] || fail 'WORKTREE_NOT_CLEAN'
  remote="$(git -C "$ROOT" remote get-url origin 2>/dev/null || true)"
  [[ "$remote" == "$CANONICAL_REMOTE" ]] || fail 'CANONICAL_REMOTE_MISMATCH'
  [[ "$(git -C "$ROOT" rev-parse refs/remotes/origin/main 2>/dev/null || true)" == "$expected_sha" ]] || fail 'EXPECTED_SHA_NOT_ORIGIN_MAIN'
  shallow="$(git -C "$ROOT" rev-parse --is-shallow-repository 2>/dev/null || true)"
  [[ "$shallow" == 'false' ]] || fail 'GIT_HISTORY_INCOMPLETE'
  git -C "$ROOT" cat-file -e "$expected_sha:$SOURCE_PATH" >/dev/null 2>&1 || fail 'SOURCE_NOT_IN_SHA'
  remote_main="$(timeout 20 git ls-remote --refs "$CANONICAL_REMOTE" "$MAIN_REF" | awk 'NF == 2 { print $1; count++ } END { if (count != 1) exit 1 }')" || fail 'CANONICAL_MAIN_UNAVAILABLE'
  [[ "$remote_main" == "$expected_sha" ]] || fail 'CANONICAL_MAIN_MISMATCH'
}

validate_fixed_parent_parent() {
  [[ -d "$APPROVAL_PARENT_PARENT" && ! -L "$APPROVAL_PARENT_PARENT" ]] || fail 'APPROVAL_PARENT_PARENT_INVALID'
  [[ "$(stat -c '%u:%g' "$APPROVAL_PARENT_PARENT")" == '0:0' ]] || fail 'APPROVAL_PARENT_PARENT_OWNER_INVALID'
  local mode_bits
  mode_bits="$(stat -c '%a' "$APPROVAL_PARENT_PARENT")"
  (( (8#$mode_bits & 18) == 0 )) || fail 'APPROVAL_PARENT_PARENT_WRITEABLE'
}

validate_parent() {
  local metadata mode_bits entry
  if [[ ! -e "$APPROVAL_PARENT" ]]; then
    return 0
  fi
  [[ -d "$APPROVAL_PARENT" && ! -L "$APPROVAL_PARENT" ]] || fail 'APPROVAL_PARENT_SYMLINK_OR_TYPE_INVALID'
  metadata="$(stat -c '%u:%g:%a' "$APPROVAL_PARENT")"
  old_parent_uid="${metadata%%:*}"
  old_parent_gid="${metadata#*:}"
  old_parent_gid="${old_parent_gid%%:*}"
  old_parent_mode="${metadata##*:}"
  [[ "$old_parent_uid" == '0' ]] || fail 'APPROVAL_PARENT_OWNER_INVALID'
  mode_bits=$((8#$old_parent_mode))
  (( (mode_bits & 18) == 0 )) || fail 'APPROVAL_PARENT_MODE_WRITEABLE'
  while IFS= read -r entry; do
    [[ -z "$entry" || "$entry" == "$(basename -- "$APPROVAL_FILE")" ]] || fail 'APPROVAL_PARENT_UNEXPECTED_ENTRY'
  done < <(find -P "$APPROVAL_PARENT" -mindepth 1 -maxdepth 1 -printf '%f\n')
}

validate_anchor() {
  local metadata value line_count
  if [[ ! -e "$APPROVAL_FILE" ]]; then
    return 0
  fi
  [[ -f "$APPROVAL_FILE" && ! -L "$APPROVAL_FILE" ]] || fail 'APPROVAL_ANCHOR_SYMLINK_OR_TYPE_INVALID'
  metadata="$(stat -c '%u:%g:%a' "$APPROVAL_FILE")"
  [[ "$metadata" == '0:0:600' ]] || fail 'APPROVAL_ANCHOR_SECURITY_INVALID'
  line_count="$(wc -l < "$APPROVAL_FILE")"
  [[ "$line_count" -eq 1 ]] || fail 'APPROVAL_ANCHOR_FORMAT_INVALID'
  value="$(cat -- "$APPROVAL_FILE")"
  [[ "$value" =~ ^[0-9a-f]{40}$ ]] || fail 'APPROVAL_ANCHOR_FORMAT_INVALID'
  [[ "$value" == "$expected_sha" ]] || fail 'APPROVAL_ANCHOR_DIVERGENT'
}

anchor_state() {
  if [[ ! -e "$APPROVAL_FILE" ]]; then
    printf 'ABSENT'
  else
    printf 'CORRESPONDING'
  fi
}

parent_state() {
  if [[ ! -e "$APPROVAL_PARENT" ]]; then
    printf 'ABSENT'
  else
    local metadata
    metadata="$(stat -c '%u:%g:%a' "$APPROVAL_PARENT")"
    printf '%s' "$metadata"
  fi
}

rollback_on_error() {
  local status="$?" restore_status=0
  trap - EXIT
  if [[ "$status" -ne 0 && "$write_started" == 'YES' ]]; then
    if [[ "$anchor_created" == 'YES' && -e "$APPROVAL_FILE" && ! -L "$APPROVAL_FILE" ]]; then
      rm -f -- "$APPROVAL_FILE" || restore_status=1
    fi
    if [[ "$parent_created" == 'YES' && -d "$APPROVAL_PARENT" && ! -L "$APPROVAL_PARENT" ]]; then
      rmdir -- "$APPROVAL_PARENT" 2>/dev/null || restore_status=1
    elif [[ "$parent_changed" == 'YES' && -d "$APPROVAL_PARENT" && ! -L "$APPROVAL_PARENT" ]]; then
      chmod "$old_parent_mode" -- "$APPROVAL_PARENT" || restore_status=1
      chown "$old_parent_uid:$old_parent_gid" -- "$APPROVAL_PARENT" || restore_status=1
    fi
    if [[ "$restore_status" -eq 0 ]]; then
      printf 'ROLLBACK=PASS\nORIGINAL_PRESERVED=YES\n' >&2
    else
      printf 'ROLLBACK=FAIL\nORIGINAL_PRESERVED=UNKNOWN\n' >&2
    fi
  fi
  [[ -n "$temporary_anchor" && -e "$temporary_anchor" ]] && rm -f -- "$temporary_anchor" || true
  exit "$status"
}

acquire_lock() {
  [[ -d "$(dirname -- "$LOCK_PATH")" && ! -L "$(dirname -- "$LOCK_PATH")" ]] || fail 'LOCK_DIRECTORY_INVALID'
  exec {lock_fd}>"$LOCK_PATH"
  flock -n "$lock_fd" || fail 'APPROVAL_LOCK_BUSY'
}

report_state() {
  local directory_state anchor
  directory_state="$(parent_state)"
  anchor="$(anchor_state)"
  printf 'SOURCE_SHA_PREFIX=%s…\nDIRECTORY_STATE=%s\nANCHOR_STATE=%s\n' "${expected_sha:0:12}" "$directory_state" "$anchor"
}

apply_changes() {
  if [[ ! -e "$APPROVAL_PARENT" ]]; then
    write_started='YES'
    parent_created='YES'
    install -d -o root -g root -m 0700 -- "$APPROVAL_PARENT"
  else
    if [[ "$old_parent_gid" != '0' || "$old_parent_mode" != '700' ]]; then
      write_started='YES'
      parent_changed='YES'
      changed='YES'
      chown root:root -- "$APPROVAL_PARENT"
      chmod 0700 -- "$APPROVAL_PARENT"
    fi
  fi
  if [[ ! -e "$APPROVAL_FILE" ]]; then
    write_started='YES'
    temporary_anchor="$(mktemp "$APPROVAL_PARENT/.voxelpacs-composer-profile-approved-main-sha.XXXXXX")"
    chmod 0600 -- "$temporary_anchor"
    chown root:root -- "$temporary_anchor"
    printf '%s\n' "$expected_sha" > "$temporary_anchor"
    ln -- "$temporary_anchor" "$APPROVAL_FILE" || fail 'APPROVAL_ANCHOR_CREATE_RACE'
    rm -f -- "$temporary_anchor"
    temporary_anchor=''
    anchor_created='YES'
    changed='YES'
  fi
  [[ "$(stat -c '%u:%g:%a' "$APPROVAL_PARENT")" == '0:0:700' ]] || fail 'FINAL_PARENT_VALIDATION_FAILED'
  validate_anchor
}

parse_args "$@"
require_root_and_caller
validate_repo
validate_fixed_parent_parent
validate_parent
validate_anchor

if [[ "$mode" == 'inspect' ]]; then
  report_state
  printf 'INSPECT=PASS\nCONFIG_CHANGED=NO\n'
  exit 0
fi

if [[ "$mode" == 'dry-run' ]]; then
  report_state
  if [[ ! -e "$APPROVAL_PARENT" ]]; then
    printf 'DIRECTORY_ACTION=CREATE\n'
  elif [[ "$old_parent_gid" != '0' || "$old_parent_mode" != '700' ]]; then
    printf 'DIRECTORY_ACTION=RECONCILE\n'
  else
    printf 'DIRECTORY_ACTION=UNCHANGED\n'
  fi
  if [[ ! -e "$APPROVAL_FILE" ]]; then
    printf 'ANCHOR_ACTION=CREATE\n'
  else
    printf 'ANCHOR_ACTION=UNCHANGED\n'
  fi
  printf 'DRY_RUN=PASS\nAPPLY=NOT_EXECUTED\nCONFIG_CHANGED=NO\n'
  exit 0
fi

acquire_lock
trap rollback_on_error EXIT
apply_changes
  printf 'APPLY=PASS\nSOURCE_SHA_PREFIX=%s…\nDIRECTORY_OWNER_MODE=root:root:0700\nANCHOR_OWNER_MODE=root:root:0600\nANCHOR_MATCH=YES\nROLLBACK=NOT_NEEDED\nCONFIG_CHANGED=%s\nAPPLICATION_CHANGED=NO\nDATABASE_CHANGED=NO\n' "${expected_sha:0:12}" "$changed"
trap - EXIT
