#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
helper="$repo/ops/deploy/voxelpacs-runtime-blob-auditor"
provisioner="$repo/scripts/provision-runtime-blob-auditor.sh"
sudoers="$repo/ops/sudoers/voxelpacs-runtime-blob-auditor"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

[[ -f "$helper" && ! -L "$helper" ]] || fail helper_missing
[[ -f "$provisioner" && ! -L "$provisioner" ]] || fail provisioner_missing
[[ -f "$sudoers" && ! -L "$sudoers" ]] || fail sudoers_missing

grep -Fqx "readonly TARGET_RELATIVE_PATH='bin/philips_nondicom_production_diagnostic.php'" "$helper" || fail target_not_fixed
grep -Fqx "readonly APP_ROOT='/var/www/voxelpacs/app'" "$helper" || fail app_root_not_fixed
grep -Fqx "readonly BACKUP_ROOT='/var/backups/voxelpacs/releases'" "$helper" || fail backup_root_not_fixed
grep -Fq "audit --backup-sha" "$helper" || fail audit_interface_missing
grep -Fq "MAIN_COMPARISON=NOT_AVAILABLE" "$helper" || fail main_not_marked_unavailable

grep -Fq "readonly INSTALLED_HELPER='/usr/local/sbin/voxelpacs-runtime-blob-auditor'" "$provisioner" || fail installed_helper_missing
grep -Fq "readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-runtime-blob-auditor'" "$provisioner" || fail installed_sudoers_missing
grep -Fq -- '--dry-run' "$provisioner" || fail dry_run_missing

grep -Fq '/usr/local/sbin/voxelpacs-runtime-blob-auditor audit --backup-sha' "$sudoers" || fail sudoers_command_missing
grep -Eq '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:' "$sudoers" || fail sudoers_caller_missing
! grep -Eq 'NOPASSWD:[[:space:]]+ALL([[:space:]]|$)' "$sudoers" || fail sudoers_generic

for file in "$helper" "$provisioner"; do
  bash -n "$file" || fail "syntax:$file"
done
visudo -cf "$sudoers" >/dev/null || fail sudoers_syntax

for forbidden in \
  '--path' '--source' '--destination' '--command' 'eval ' 'rm -rf' \
  'smbclient' 'systemctl' 'composer' 'curl ' 'mysql' 'psql' \
  'NOPASSWD: ALL'; do
  if grep -Fq -- "$forbidden" "$helper"; then fail "helper_forbidden:$forbidden"; fi
done

printf 'TEST=PASS\nSTATIC=PASS\nSUDOERS_SYNTAX=PASS\nFIXED_TARGET=PASS\nGENERIC_OPERATIONS=ABSENT\nPRODUCTION_CHANGED=NO\n'
