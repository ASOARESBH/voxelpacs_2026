#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
provisioner="$repo/scripts/provision-runtime-gates.sh"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

[[ -f "$provisioner" && ! -L "$provisioner" ]] || fail provisioner_missing
bash -n "$provisioner" || fail provisioner_syntax

for literal in \
  "readonly TARGET_ROOT='/usr/local/sbin'" \
  "readonly SUDOERS_ROOT='/etc/sudoers.d'" \
  "readonly BACKUP_ROOT='/var/backups/voxelpacs/runtime-gates-provision'" \
  "readonly LOCK_PATH='/run/lock/voxelpacs-runtime-gates-provision.lock'" \
  'CANONICAL_REMOTE_MISMATCH' \
  'WORKTREE_NOT_CLEAN' \
  'SOURCE_NOT_IN_SHA' \
  'TARGET_PROVENANCE_UNPROVEN' \
  'TARGET_ALREADY_INSTALLED' \
  'TARGET_SECURITY_INVALID' \
  'BACKUP_CHECKSUM_INVALID' \
  'ROLLBACK=PASS' \
  'ORIGINAL_PRESERVED=YES' \
  'visudo -cf' \
  'PRODUCTION_CHANGED=YES'; do
  grep -Fq -- "$literal" "$provisioner" || fail "contract_missing:$literal"
done

for caller in manus-admin manus-deploy; do
  grep -Fq -- "$caller" "$provisioner" || fail "caller_missing:$caller"
done

! grep -Eq '^[[:space:]]*(eval|rm[[:space:]]+-rf|smbclient|systemctl|composer|mysql|psql|curl)([[:space:]]|$)' "$provisioner" || fail generic_operation_execution
! grep -Eq '^[[:space:]]*(cp|rsync|scp|git[[:space:]]+(pull|reset|clean))([[:space:]]|$)' "$provisioner" || fail unsafe_source_operation

printf 'TEST=PASS\nSTATIC=PASS\nFIXED_TARGETS=PASS\nPROVENANCE_GATE=PASS\nROLLBACK_CONTRACT=PASS\nNO_GENERIC_OPERATIONS=PASS\nPRODUCTION_CHANGED=NO\n'
