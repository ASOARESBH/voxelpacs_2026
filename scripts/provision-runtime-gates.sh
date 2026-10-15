#!/usr/bin/env bash
# VOXEL PACS — provisionamento root-controlled dos gates de baseline e drift.
# --dry-run não grava; --install exige destinos ausentes; --repair aceita o
# estado parcial comprovadamente versionado e restaura em falha.
set -Eeuo pipefail
umask 077
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly SCRIPT_DIR
ROOT="$(git -C "$SCRIPT_DIR/.." rev-parse --show-toplevel 2>/dev/null || true)"
readonly ROOT
readonly TARGET_ROOT='/usr/local/sbin'
readonly SUDOERS_ROOT='/etc/sudoers.d'
readonly BACKUP_ROOT='/var/backups/voxelpacs/runtime-gates-provision'
readonly LOCK_PATH='/run/lock/voxelpacs-runtime-gates-provision.lock'
readonly STAGE_BASE='/var/tmp'

mode=''
expected_sha=''
lock_fd=''
backup_dir=''
stage_runtime_dir=''
stage_sudoers_dir=''
write_started='NO'

source_paths=()
source_relative_paths=()
target_paths=()
target_labels=()
target_modes=()
existing_targets=()
written_targets=()

fail() {
  printf 'PROVISION=BLOCKED\nREASON=%s\nAPPLICATION_RUNTIME_CHANGED=NO\nDATABASE_CHANGED=NO\nTRANSMISSION=NO\n' "$1" >&2
  exit 64
}

usage() {
  printf '%s\n' \
    'uso: provision-runtime-gates.sh --expected-sha <40-hex-sha> --dry-run' \
    'uso: provision-runtime-gates.sh --expected-sha <40-hex-sha> --install' \
    'uso: provision-runtime-gates.sh --expected-sha <40-hex-sha> --repair' >&2
}

parse_args() {
  [[ "$#" -eq 3 && "$1" == '--expected-sha' ]] || { usage; fail 'ARGUMENTS_INVALID'; }
  expected_sha="$2"
  case "$3" in
    --dry-run) mode='dry-run' ;;
    --install) mode='install' ;;
    --repair) mode='repair' ;;
    *) usage; fail 'ARGUMENTS_INVALID' ;;
  esac
  [[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'SHA_INVALID'
}

require_root_and_caller() {
  [[ "$EUID" -eq 0 ]] || fail 'ROOT_REQUIRED'
  case "${SUDO_USER:-}" in
    ''|manus-admin|manus-deploy) ;;
    *) fail 'CALLER_NOT_ALLOWLISTED' ;;
  esac
}

initialize_targets() {
  source_paths=(
    "$ROOT/scripts/capture-production-baseline.sh"
    "$ROOT/ops/deploy/voxelpacs-production-baseline-capture"
    "$ROOT/ops/deploy/voxelpacs-runtime-drift-manifest-reader"
    "$ROOT/ops/deploy/voxelpacs-runtime-drift-gate"
    "$ROOT/scripts/validate-runtime-drift.sh"
    "$ROOT/ops/deploy/voxelpacs-runtime-extra-auditor"
    "$ROOT/ops/sudoers/voxelpacs-production-baseline-capture"
    "$ROOT/ops/sudoers/voxelpacs-runtime-drift-manifest-reader"
    "$ROOT/ops/sudoers/voxelpacs-runtime-extra-auditor"
  )
  source_relative_paths=(
    scripts/capture-production-baseline.sh
    ops/deploy/voxelpacs-production-baseline-capture
    ops/deploy/voxelpacs-runtime-drift-manifest-reader
    ops/deploy/voxelpacs-runtime-drift-gate
    scripts/validate-runtime-drift.sh
    ops/deploy/voxelpacs-runtime-extra-auditor
    ops/sudoers/voxelpacs-production-baseline-capture
    ops/sudoers/voxelpacs-runtime-drift-manifest-reader
    ops/sudoers/voxelpacs-runtime-extra-auditor
  )
  target_paths=(
    "$TARGET_ROOT/voxelpacs-capture-production-baseline"
    "$TARGET_ROOT/voxelpacs-production-baseline-capture"
    "$TARGET_ROOT/voxelpacs-runtime-drift-manifest-reader"
    "$TARGET_ROOT/voxelpacs-runtime-drift-gate"
    "$TARGET_ROOT/voxelpacs-validate-runtime-drift"
    "$TARGET_ROOT/voxelpacs-runtime-extra-auditor"
    "$SUDOERS_ROOT/voxelpacs-production-baseline-capture"
    "$SUDOERS_ROOT/voxelpacs-runtime-drift-manifest-reader"
    "$SUDOERS_ROOT/voxelpacs-runtime-extra-auditor"
  )
  target_labels=(
    capture
    baseline_wrapper
    drift_reader
    drift_gate
    drift_comparator
    extra_auditor
    baseline_sudoers
    drift_sudoers
    extra_sudoers
  )
  target_modes=(555 555 555 555 555 555 440 440 440)
  existing_targets=()
  written_targets=()
  for ((i=0; i<${#target_paths[@]}; i++)); do
    existing_targets[i]=0
    written_targets[i]=0
  done
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
  local i path
  for ((i=0; i<${#source_paths[@]}; i++)); do
    path="${source_paths[i]}"
    [[ -f "$path" && ! -L "$path" ]] || fail "SOURCE_FILE_INVALID_${target_labels[i]}"
    git -C "$ROOT" ls-files --error-unmatch -- "${source_relative_paths[i]}" >/dev/null 2>&1 || fail "SOURCE_NOT_TRACKED_${target_labels[i]}"
    git -C "$ROOT" cat-file -e "$expected_sha:${source_relative_paths[i]}" >/dev/null 2>&1 || fail "SOURCE_NOT_IN_SHA_${target_labels[i]}"
  done
  git -C "$ROOT" ls-files --error-unmatch -- scripts/provision-runtime-gates.sh >/dev/null 2>&1 || fail 'PROVISIONER_NOT_TRACKED'
  git -C "$ROOT" cat-file -e "$expected_sha:scripts/provision-runtime-gates.sh" >/dev/null 2>&1 || fail 'PROVISIONER_NOT_IN_SHA'
}

validate_contracts() {
  local i file
  for ((i=0; i<6; i++)); do
    bash -n "${source_paths[i]}" || fail "HELPER_SYNTAX_INVALID_${target_labels[i]}"
  done
  for i in 1 2 3 5; do
    grep -Fq 'manus-admin|manus-deploy' "${source_paths[i]}" || fail "CALLER_ALLOWLIST_MISSING_${target_labels[i]}"
  done
  for ((i=6; i<9; i++)); do
    file="${source_paths[i]}"
    visudo -cf "$file" >/dev/null || fail "SUDOERS_SYNTAX_INVALID_${target_labels[i]}"
    ! grep -Eq 'NOPASSWD:[[:space:]]+ALL([[:space:]]|$)' "$file" || fail "GENERIC_SUDOERS_${target_labels[i]}"
    ! grep -Eq '/bin/(ba|d)?sh|/usr/(bin|sbin)/(cp|mv|rm|rsync|install|python|php|composer|systemctl)' "$file" || fail "GENERIC_SUDO_COMMAND_${target_labels[i]}"
    grep -Eq '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:' "$file" || fail "ADMIN_RULE_MISSING_${target_labels[i]}"
    grep -Eq '^manus-deploy[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:' "$file" || fail "DEPLOY_RULE_MISSING_${target_labels[i]}"
  done
}

validate_parent() {
  local directory metadata mode_bits
  for directory in "$TARGET_ROOT" "$SUDOERS_ROOT"; do
    [[ -d "$directory" && ! -L "$directory" ]] || fail 'TARGET_PARENT_INVALID'
    metadata="$(stat -c '%u:%g:%a' -- "$directory" 2>/dev/null || true)"
    [[ "$metadata" =~ ^0:0:[0-7]{3,4}$ ]] || fail 'TARGET_PARENT_OWNERSHIP_INVALID'
    mode_bits="${metadata##*:}"
    (( (8#$mode_bits & 18) == 0 )) || fail 'TARGET_PARENT_WRITABLE'
  done
}

safe_existing_target() {
  local index="$1" path="${target_paths[$1]}" expected_mode="${target_modes[$1]}"
  [[ -f "$path" && ! -L "$path" ]] || return 1
  [[ "$(stat -c '%u:%g:%a' -- "$path" 2>/dev/null || true)" == "0:0:$expected_mode" ]]
}

validate_target_state() {
  local i path
  validate_parent
  for ((i=0; i<${#target_paths[@]}; i++)); do
    path="${target_paths[i]}"
    if [[ -e "$path" || -L "$path" ]]; then
      if [[ "$mode" == 'install' ]]; then
        fail "TARGET_ALREADY_INSTALLED_${target_labels[i]}"
      fi
      safe_existing_target "$i" || fail "TARGET_SECURITY_INVALID_${target_labels[i]}"
      existing_targets[i]=1
    else
      if [[ "$mode" == 'repair' ]]; then
        existing_targets[i]=0
      fi
    fi
  done
  if [[ "$mode" == 'install' ]]; then
    return 0
  fi
  if [[ "$mode" == 'repair' ]]; then
    return 0
  fi
}

source_history_contains_blob() {
  local installed_path="$1" source_path="$2" installed_sha commit candidate
  installed_sha="$(sha256sum -- "$installed_path" | awk '{print $1}')"
  while IFS= read -r commit; do
    [[ -n "$commit" ]] || continue
    git -C "$ROOT" cat-file -e "$commit:$source_path" 2>/dev/null || continue
    candidate="$(git -C "$ROOT" show "$commit:$source_path" 2>/dev/null | sha256sum | awk '{print $1}')"
    [[ "$candidate" == "$installed_sha" ]] && return 0
  done < <(git -C "$ROOT" log refs/remotes/origin/main --format='%H' -- "$source_path")
  return 1
}

validate_existing_provenance() {
  local i
  [[ "$mode" == 'repair' ]] || return 0
  for ((i=0; i<${#target_paths[@]}; i++)); do
    [[ "${existing_targets[i]}" -eq 1 ]] || continue
    source_history_contains_blob "${target_paths[i]}" "${source_relative_paths[i]}" || fail "TARGET_PROVENANCE_UNPROVEN_${target_labels[i]}"
  done
}

acquire_lock() {
  [[ -d "$(dirname "$LOCK_PATH")" && ! -L "$(dirname "$LOCK_PATH")" ]] || fail 'LOCK_DIRECTORY_INVALID'
  exec {lock_fd}>"$LOCK_PATH"
  chmod 0600 "$LOCK_PATH"
  flock -n "$lock_fd" || fail 'PROVISION_LOCK_BUSY'
}

prepare_backup() {
  local i
  if [[ "$mode" != 'repair' ]]; then
    return 0
  fi
  if [[ -e "$BACKUP_ROOT" ]]; then
    [[ -d "$BACKUP_ROOT" && ! -L "$BACKUP_ROOT" ]] || fail 'BACKUP_ROOT_INVALID'
    [[ "$(stat -c '%u:%g:%a' "$BACKUP_ROOT")" == '0:0:700' ]] || fail 'BACKUP_ROOT_SECURITY_INVALID'
  else
    install -d -o root -g root -m 0700 "$BACKUP_ROOT"
  fi
  backup_dir="$BACKUP_ROOT/${expected_sha}_$(date -u +%Y%m%dT%H%M%SZ)_$$"
  install -d -o root -g root -m 0700 "$backup_dir"
  : > "$backup_dir/metadata.tsv"
  chmod 0600 "$backup_dir/metadata.tsv"
  for ((i=0; i<${#target_paths[@]}; i++)); do
    [[ "${existing_targets[i]}" -eq 1 ]] || continue
    install -o root -g root -m 0600 -- "${target_paths[i]}" "$backup_dir/${target_labels[i]}"
    printf '%s\t%s\t%s\n' "${target_labels[i]}" "$(sha256sum "${target_paths[i]}" | awk '{print $1}')" "${target_modes[i]}" >> "$backup_dir/metadata.tsv"
  done
  sha256sum "$backup_dir"/* > "$backup_dir/checksums.sha256"
  chmod 0600 "$backup_dir/checksums.sha256"
  sha256sum -c "$backup_dir/checksums.sha256" >/dev/null || fail 'BACKUP_CHECKSUM_INVALID'
}

stage_sources() {
  local i stage_dir source
  stage_runtime_dir="$(mktemp -d "$TARGET_ROOT/.voxelpacs-runtime-gates.XXXXXX")"
  stage_sudoers_dir="$(mktemp -d "$SUDOERS_ROOT/.voxelpacs-runtime-gates.XXXXXX")"
  chmod 0700 "$stage_runtime_dir" "$stage_sudoers_dir"
  chown root:root "$stage_runtime_dir" "$stage_sudoers_dir"
  for ((i=0; i<${#source_paths[@]}; i++)); do
    source="${source_paths[i]}"
    if (( i < 6 )); then stage_dir="$stage_runtime_dir"; else stage_dir="$stage_sudoers_dir"; fi
    install -o root -g root -m "${target_modes[i]}" -- "$source" "$stage_dir/${target_labels[i]}"
    [[ "$(sha256sum "$source" | awk '{print $1}')" == "$(sha256sum "$stage_dir/${target_labels[i]}" | awk '{print $1}')" ]] || fail "STAGED_HASH_MISMATCH_${target_labels[i]}"
  done
  for ((i=6; i<9; i++)); do
    visudo -cf "$stage_sudoers_dir/${target_labels[i]}" >/dev/null || fail "STAGED_SUDOERS_INVALID_${target_labels[i]}"
  done
}

cleanup_stage() {
  if [[ -n "$stage_runtime_dir" && -d "$stage_runtime_dir" ]]; then
    rm -f -- "$stage_runtime_dir"/*
    rmdir -- "$stage_runtime_dir" 2>/dev/null || true
  fi
  if [[ -n "$stage_sudoers_dir" && -d "$stage_sudoers_dir" ]]; then
    rm -f -- "$stage_sudoers_dir"/*
    rmdir -- "$stage_sudoers_dir" 2>/dev/null || true
  fi
}

restore_backup() {
  local i path backup
  [[ -n "$backup_dir" && -d "$backup_dir" ]] || return 1
  for ((i=0; i<${#target_paths[@]}; i++)); do
    path="${target_paths[i]}"
    if [[ "${existing_targets[i]}" -eq 1 ]]; then
      backup="$backup_dir/${target_labels[i]}"
      install -o root -g root -m "${target_modes[i]}" -- "$backup" "$path"
    elif [[ "${written_targets[i]}" -eq 1 && -e "$path" && ! -L "$path" ]]; then
      rm -f -- "$path"
    fi
  done
  for ((i=0; i<${#target_paths[@]}; i++)); do
    [[ "${existing_targets[i]}" -eq 1 ]] || continue
    sha256sum -- "${target_paths[i]}" | awk '{print $1}' | grep -Fqx "$(awk -v label="${target_labels[i]}" '$1 == label {print $2; exit}' "$backup_dir/metadata.tsv")" || return 1
  done
}

rollback_on_error() {
  local status=$? restore_status=0
  trap - EXIT
  if [[ "$status" -ne 0 && "$write_started" == 'YES' ]]; then
    restore_backup || restore_status=1
    if [[ "$restore_status" -eq 0 ]]; then
      printf 'ROLLBACK=PASS\nORIGINAL_PRESERVED=YES\n' >&2
    else
      printf 'ROLLBACK=FAIL\nORIGINAL_PRESERVED=UNKNOWN\n' >&2
      status=70
    fi
  fi
  cleanup_stage
  [[ -z "$lock_fd" ]] || exec {lock_fd}>&-
  exit "$status"
}

install_staged() {
  local i stage_dir
  for ((i=0; i<${#target_paths[@]}; i++)); do
    if (( i < 6 )); then stage_dir="$stage_runtime_dir"; else stage_dir="$stage_sudoers_dir"; fi
    mv -T -- "$stage_dir/${target_labels[i]}" "${target_paths[i]}"
    written_targets[i]=1
  done
}

validate_final_targets() {
  local i stage_dir
  for ((i=0; i<${#target_paths[@]}; i++)); do
    safe_existing_target "$i" || fail "FINAL_TARGET_SECURITY_INVALID_${target_labels[i]}"
    [[ "$(sha256sum "${target_paths[i]}" | awk '{print $1}')" == "$(sha256sum "${source_paths[i]}" | awk '{print $1}')" ]] || fail "FINAL_HASH_MISMATCH_${target_labels[i]}"
  done
  for ((i=6; i<9; i++)); do
    visudo -cf "${target_paths[i]}" >/dev/null || fail "FINAL_SUDOERS_INVALID_${target_labels[i]}"
  done
}

parse_args "$@"
require_root_and_caller
initialize_targets
validate_repo
validate_sources
validate_contracts

if [[ "$mode" == 'dry-run' ]]; then
  mode='repair'
  validate_target_state
  validate_existing_provenance
  existing_count=0
  for value in "${existing_targets[@]}"; do existing_count=$((existing_count + value)); done
  printf 'SOURCE_SHA_PREFIX=%s…\nTARGETS_EXISTING=%s\nTARGETS_MISSING=%s\nTARGETS_VALIDATED=PASS\nDRY_RUN=PASS\nINSTALL=NOT_EXECUTED\nAPPLICATION_RUNTIME_CHANGED=NO\nDATABASE_CHANGED=NO\nPRODUCTION_CHANGED=NO\n' \
    "${expected_sha:0:12}" "$existing_count" "$(( ${#target_paths[@]} - existing_count ))"
  exit 0
fi

acquire_lock
validate_target_state
validate_existing_provenance
trap rollback_on_error EXIT
prepare_backup
stage_sources
write_started='YES'
install_staged
validate_final_targets
write_started='NO'
cleanup_stage
trap - EXIT
[[ -z "$lock_fd" ]] || exec {lock_fd}>&-
existing_count=0
for value in "${existing_targets[@]}"; do existing_count=$((existing_count + value)); done
printf 'SOURCE_SHA_PREFIX=%s…\nTARGETS_EXISTING=%s\nTARGETS_REPAIRED=%s\nBACKUP=%s\nBACKUP_PATH_REDACTED=YES\nINSTALL=PASS\nSUDOERS_SYNTAX=PASS\nROLLBACK=READY\nAPPLICATION_RUNTIME_CHANGED=NO\nDATABASE_CHANGED=NO\nPRODUCTION_CHANGED=YES\nTRANSMISSION=NO\n' \
  "${expected_sha:0:12}" "$existing_count" "${#target_paths[@]}" "$([[ "$mode" == 'repair' ]] && echo PASS || echo NOT_REQUIRED)"
