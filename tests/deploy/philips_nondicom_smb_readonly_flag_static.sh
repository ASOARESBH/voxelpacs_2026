#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
helper="$repo/ops/deploy/voxelpacs-philips-nondicom-smb-readonly-flag"
provisioner="$repo/scripts/provision-philips-nondicom-smb-readonly-flag.sh"
sudoers="$repo/ops/sudoers/voxelpacs-philips-nondicom-smb-readonly-flag"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

for file in "$helper" "$provisioner" "$sudoers"; do
  [[ -f "$file" && ! -L "$file" ]] || fail "missing:$file"
done

bash -n "$helper" || fail helper_syntax
bash -n "$provisioner" || fail provisioner_syntax
visudo -cf "$sudoers" >/dev/null || fail sudoers_syntax

grep -Fqx "readonly ENV_FILE='/var/www/voxelpacs/app/.env'" "$helper" || fail fixed_env_file
grep -Fqx "readonly BACKUP_ROOT='/var/backups/voxelpacs/config'" "$helper" || fail fixed_backup_root
grep -Fqx "readonly TARGET_KEY='PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED'" "$helper" || fail fixed_target_key
grep -Fq 'OTHER_FLAGS_PRESERVED=YES' "$helper" || fail integrity_contract
grep -Fq 'BACKUP=CREATED' "$helper" || fail backup_contract
grep -Fq -- '--rollback --backup-id' "$helper" || fail rollback_contract
grep -Fq "readonly INSTALLED_HELPER='/usr/local/sbin/voxelpacs-philips-nondicom-smb-readonly-flag'" "$provisioner" || fail installed_helper
grep -Fq "readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-philips-nondicom-smb-readonly-flag'" "$provisioner" || fail installed_sudoers
grep -Fq -- '--dry-run' "$provisioner" || fail provisioner_dry_run

grep -Fq -- '/usr/local/sbin/voxelpacs-philips-nondicom-smb-readonly-flag --status' "$sudoers" || fail sudoers_status
grep -Fq -- '/usr/local/sbin/voxelpacs-philips-nondicom-smb-readonly-flag --dry-run' "$sudoers" || fail sudoers_dry_run
grep -Fq -- '/usr/local/sbin/voxelpacs-philips-nondicom-smb-readonly-flag --apply' "$sudoers" || fail sudoers_apply
grep -Fq -- '/usr/local/sbin/voxelpacs-philips-nondicom-smb-readonly-flag --rollback --backup-id' "$sudoers" || fail sudoers_rollback
! grep -Eq 'NOPASSWD:[[:space:]]+ALL([[:space:]]|$)' "$sudoers" || fail sudoers_generic

for file in "$helper" "$sudoers"; do
  for forbidden in '--path' '--source' '--destination' '--command' 'eval ' 'rm -rf' 'systemctl' 'smbclient' 'curl ' 'mysql' 'psql' 'composer' 'git push' 'git checkout' 'NOPASSWD: ALL'; do
    ! grep -Fq -- "$forbidden" "$file" || fail "forbidden:$forbidden:$file"
  done
done

printf 'TEST=PASS\nSTATIC=PASS\nSUDOERS_SYNTAX=PASS\nFIXED_TARGET=PASS\nBACKUP_ROLLBACK_CONTRACT=PASS\nGENERIC_OPERATIONS=ABSENT\nPRODUCTION_CHANGED=NO\n'
