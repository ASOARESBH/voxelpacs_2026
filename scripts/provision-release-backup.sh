#!/usr/bin/env bash
# VOXEL PACS — provisionamento administrativo do backup root-only.
# Este script NÃO é allowlisted no sudoers. Deve ser revisado e executado
# somente por um administrador root a partir de checkout limpo e SHA confirmado.
set -Eeuo pipefail
umask 077
export PATH='/usr/sbin:/usr/bin:/sbin:/bin'

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"
readonly HELPER_SOURCE="$ROOT/scripts/release_backup.sh"
readonly SUDOERS_SOURCE="$ROOT/ops/sudoers/voxelpacs-release-backup"
readonly INSTALLED_HELPER='/usr/local/sbin/voxelpacs-release-backup'
readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-release-backup'
readonly BACKUP_BASE='/var/backups/voxelpacs'
readonly BACKUP_ROOT='/var/backups/voxelpacs/releases'
readonly RESTORE_TEST_ROOT='/var/backups/voxelpacs/restore-tests'

fail() {
  printf 'PROVISION=BLOCKED\nREASON=%s\n' "$1" >&2
  exit 64
}

[[ "$EUID" -eq 0 ]] || fail 'ROOT_REQUIRED'
[[ "$#" -eq 2 && "$1" == '--expected-sha' ]] || fail 'ARGUMENTS_INVALID'
expected_sha="$2"
[[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'SHA_INVALID'
[[ -d "$ROOT/.git" || -f "$ROOT/.git" ]] || fail 'GIT_ROOT_NOT_FOUND'
[[ "$(/usr/bin/git -C "$ROOT" rev-parse HEAD)" == "$expected_sha" ]] || fail 'GIT_SHA_MISMATCH'
[[ -z "$(/usr/bin/git -C "$ROOT" status --porcelain --untracked-files=all)" ]] || fail 'WORKTREE_NOT_CLEAN'
[[ -f "$HELPER_SOURCE" && -f "$SUDOERS_SOURCE" ]] || fail 'SOURCE_FILE_MISSING'
[[ ! -e "$INSTALLED_HELPER" ]] || fail 'HELPER_ALREADY_INSTALLED'
[[ ! -e "$INSTALLED_SUDOERS" ]] || fail 'SUDOERS_ALREADY_INSTALLED'
/usr/bin/grep -Eq '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:' "$SUDOERS_SOURCE" || fail 'SUDOERS_CALLER_MISSING'
/usr/bin/grep -Eq '^manus-admin[[:space:]]+ALL=\(ALL\)[[:space:]]+NOPASSWD:[[:space:]]+ALL' "$SUDOERS_SOURCE" && fail 'GENERIC_SUDO_RULE_PRESENT' || true
/usr/bin/grep -Eq '(/bin/(ba|d)?sh|/usr/(bin|sbin)/(cp|rm|rsync|chmod|chown|systemctl|tar))' "$SUDOERS_SOURCE" && fail 'GENERIC_COMMAND_PRESENT' || true

helper_tmp="/usr/local/sbin/.voxelpacs-release-backup.$$"
sudoers_tmp="/etc/sudoers.d/.voxelpacs-release-backup.$$"
cleanup() {
  /usr/bin/rm -f -- "$helper_tmp" "$sudoers_tmp"
}
trap cleanup EXIT

/usr/bin/install -d -o root -g root -m 0700 -- "$BACKUP_BASE" "$BACKUP_ROOT" "$RESTORE_TEST_ROOT"
/usr/bin/install -o root -g root -m 0755 -- "$HELPER_SOURCE" "$helper_tmp"
/usr/bin/install -o root -g root -m 0440 -- "$SUDOERS_SOURCE" "$sudoers_tmp"
/usr/sbin/visudo -cf "$sudoers_tmp" >/dev/null || fail 'SUDOERS_SYNTAX_INVALID'
/usr/bin/mv -- "$helper_tmp" "$INSTALLED_HELPER"
/usr/bin/mv -- "$sudoers_tmp" "$INSTALLED_SUDOERS"
trap - EXIT

printf 'PROVISION=PASS\n'
printf 'SOURCE_SHA_PREFIX=%s…\n' "${expected_sha:0:12}"
printf 'HELPER_INSTALLED=YES\nHELPER_OWNER_MODE=root:root:0755\n'
printf 'SUDOERS_INSTALLED=YES\nSUDOERS_OWNER_MODE=root:root:0440\nSUDOERS_SYNTAX=PASS\n'
printf 'BACKUP_ROOTS_CREATED=YES\nGENERIC_SUDO=NO\nPRODUCTION_DEPLOYED=NO\n'
