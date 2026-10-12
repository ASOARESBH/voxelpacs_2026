#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
script="$repo/scripts/upgrade-runtime-drift-manifest-reader.sh"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

[[ -f "$script" && ! -L "$script" ]] || fail missing_script
bash -n "$script" || fail syntax
for required in \
  "readonly BACKUP_ROOT='/var/backups/voxelpacs/runtime-drift-manifest-reader-upgrades'" \
  "readonly INSTALLED_READER='/usr/local/sbin/voxelpacs-runtime-drift-manifest-reader'" \
  "readonly INSTALLED_GATE='/usr/local/sbin/voxelpacs-runtime-drift-gate'" \
  "readonly INSTALLED_COMPARATOR='/usr/local/sbin/voxelpacs-validate-runtime-drift'" \
  "readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-runtime-drift-manifest-reader'"; do
  grep -Fqx "$required" "$script" || fail "fixed_scope:$required"
done
for required in 'TARGETS_EXISTING=YES' 'create_backup' 'restore_backup' 'ROLLBACK=PASS' 'TARGET_NOT_ROOT_OWNED_REGULAR_FILE' '--dry-run' '--upgrade'; do
  grep -Fq -- "$required" "$script" || fail "contract_missing:$required"
done
for forbidden in 'rm -rf' 'rsync --delete' 'systemctl' 'smbclient' 'curl ' 'wget ' 'mysql ' 'psql ' 'composer ' 'git reset' 'git clean' 'eval ' 'NOPASSWD: ALL'; do
  ! grep -Fq -- "$forbidden" "$script" || fail "forbidden_operation:$forbidden"
done
printf 'TEST=PASS\nSTATIC=PASS\nFIXED_TARGETS=PASS\nBACKUP_REQUIRED=YES\nROLLBACK_GUARD=YES\nDRY_RUN_CONTRACT=YES\nGENERIC_OPERATIONS=ABSENT\nPRODUCTION_CHANGED=NO\n'
