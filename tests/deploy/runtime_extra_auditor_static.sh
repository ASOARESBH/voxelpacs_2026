#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
helper="$repo/ops/deploy/voxelpacs-runtime-extra-auditor"
provisioner="$repo/scripts/provision-runtime-extra-auditor.sh"
sudoers="$repo/ops/sudoers/voxelpacs-runtime-extra-auditor"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

[[ -f "$helper" ]] || fail helper_missing
[[ -f "$provisioner" ]] || fail provisioner_missing
[[ -f "$sudoers" ]] || fail sudoers_missing
bash -n "$helper" || fail helper_syntax
bash -n "$provisioner" || fail provisioner_syntax
visudo -cf "$sudoers" >/dev/null || fail sudoers_syntax

for literal in \
  "readonly APP_ROOT='/var/www/voxelpacs/app'" \
  "readonly BASELINE_ROOT='/var/lib/voxelpacs/baselines'" \
  "readonly MAX_ARTIFACT_MANIFEST_BYTES=52428800" \
  'ARTIFACT_MANIFEST_FORMAT_INVALID' \
  'BASELINE_MANIFEST_FORMAT_INVALID' \
  'INDIVIDUAL_HASHES_EXPOSED=NO' \
  'INDIVIDUAL_PATHS_EXPOSED=NO' \
  'READY_FOR_DEPLOY=NO' \
  'PRODUCTION_CHANGED=NO' \
  'DRIFT_GATE_INTEGRATION=NOT_EXECUTED'; do
  grep -Fq -- "$literal" "$helper" || fail "helper_contract_missing:$literal"
done
grep -Fq 'manus-admin|manus-deploy' "$helper" || fail helper_caller_contract_missing

for forbidden in \
  '--path' '--source' '--destination' '--command' 'eval' 'rm -rf' 'smbclient' 'systemctl' 'composer' 'mysql' 'psql' 'php '; do
  ! grep -Eq -- "$forbidden" "$helper" || fail "generic_or_clinical_operation:$forbidden"
done

for literal in \
  'audit --baseline-id' \
  '--artifact-sha' \
  'voxelpacs-runtime-extra-auditor'; do
  grep -Fq -- "$literal" "$sudoers" || fail "sudoers_contract_missing:$literal"
done
! grep -Eq 'NOPASSWD:[[:space:]]+ALL([[:space:]]|$)' "$sudoers" || fail generic_sudoers
! grep -Eq '/bin/(ba|d)?sh|/usr/(bin|sbin)/(cp|mv|rm|rsync|install|tee|python|php|composer|systemctl)' "$sudoers" || fail generic_sudo_command
for caller in manus-admin manus-deploy; do
  [[ "$(grep -Ec "^${caller}[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:" "$sudoers")" -eq 1 ]] || fail "sudoers_caller:${caller}"
done

for literal in \
  'git -C "$ROOT" rev-parse HEAD' \
  'WORKTREE_NOT_CLEAN' \
  'HELPER_NOT_IN_SHA' \
  'PROVISIONER_NOT_IN_SHA' \
  'SUDOERS_NOT_IN_SHA' \
  'HELPER_ALREADY_INSTALLED' \
  'SUDOERS_ALREADY_INSTALLED' \
  'TARGETS_ABSENT=PASS' \
  'install -o root -g root -m 0555' \
  'install -o root -g root -m 0440'; do
  grep -Fq -- "$literal" "$provisioner" || fail "provisioner_contract_missing:$literal"
done

! grep -Eq '(^|[[:space:]])(cp|rsync|scp|git[[:space:]]+pull|git[[:space:]]+reset|git[[:space:]]+clean)([[:space:]]|$)' "$provisioner" || fail unsafe_copy_or_runtime_git_operation

printf 'TEST=PASS\nSTATIC=PASS\nFIXED_TARGET=PASS\nFAIL_CLOSED=PASS\nSANITIZED_OUTPUT=PASS\nMINIMAL_SUDOERS=PASS\nNO_GENERIC_OPERATIONS=PASS\nPRODUCTION_CHANGED=NO\n'
