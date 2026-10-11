#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
helper="$repo/ops/deploy/voxelpacs-composer-drift-auditor"
provisioner="$repo/scripts/provision-composer-drift-auditor.sh"
sudoers="$repo/ops/sudoers/voxelpacs-composer-drift-auditor"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

[[ -f "$helper" && ! -L "$helper" ]] || fail helper_missing
[[ -f "$provisioner" && ! -L "$provisioner" ]] || fail provisioner_missing
[[ -f "$sudoers" && ! -L "$sudoers" ]] || fail sudoers_missing

grep -Fqx "readonly APP_ROOT='/var/www/voxelpacs/app'" "$helper" || fail app_root_not_fixed
grep -Fqx "readonly BASELINE_ROOT='/var/lib/voxelpacs/baselines'" "$helper" || fail baseline_root_not_fixed
grep -Fqx "readonly TARGET_RELATIVE_PATH='vendor/composer/installed.php'" "$helper" || fail target_not_fixed
grep -Fq 'ARTIFACT_FILE_SHA256=NOT_EXPOSED' "$helper" || fail hash_redaction_missing
grep -Fq 'RUNTIME_BASELINE_HASH_EQUAL' "$helper" || fail equality_output_missing
grep -Fq 'baseline_classification" == '\''COMPOSER_DEPENDENCY'\''' "$helper" || fail classification_contract_missing
grep -Fq 'READY_FOR_DEPLOY=NO' "$helper" || fail deploy_guard_missing

grep -Fq "readonly INSTALLED_HELPER='/usr/local/sbin/voxelpacs-composer-drift-auditor'" "$provisioner" || fail installed_helper_missing
grep -Fq "readonly INSTALLED_SUDOERS='/etc/sudoers.d/voxelpacs-composer-drift-auditor'" "$provisioner" || fail installed_sudoers_missing
grep -Fq -- '--dry-run' "$provisioner" || fail dry_run_missing

grep -Fq '/usr/local/sbin/voxelpacs-composer-drift-auditor --baseline-id' "$sudoers" || fail sudoers_command_missing
grep -Eq '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:' "$sudoers" || fail sudoers_caller_missing
! grep -Eq 'NOPASSWD:[[:space:]]+ALL([[:space:]]|$)' "$sudoers" || fail sudoers_generic

for file in "$helper" "$provisioner"; do
  bash -n "$file" || fail "syntax:$file"
done
visudo -cf "$sudoers" >/dev/null || fail sudoers_syntax

for forbidden in \
  '--path' '--source' '--destination' '--command' 'eval ' 'rm -rf' \
  'smbclient' 'systemctl' 'composer ' 'curl ' 'mysql' 'psql' \
  'NOPASSWD: ALL'; do
  if grep -Fq -- "$forbidden" "$helper"; then fail "helper_forbidden:$forbidden"; fi
done

printf 'TEST=PASS\nSTATIC=PASS\nSUDOERS_SYNTAX=PASS\nFIXED_TARGET=PASS\nHASH_CONTENT=NOT_EXPOSED\nGENERIC_OPERATIONS=ABSENT\nPRODUCTION_CHANGED=NO\n'
