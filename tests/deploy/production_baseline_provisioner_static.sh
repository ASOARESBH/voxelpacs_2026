#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
capture="$repo/scripts/capture-production-baseline.sh"
provisioner="$repo/scripts/provision-production-baseline-capture.sh"
wrapper="$repo/ops/deploy/voxelpacs-production-baseline-capture"
sudoers="$repo/ops/sudoers/voxelpacs-production-baseline-capture"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

for file in "$capture" "$provisioner" "$wrapper" "$sudoers"; do
  [[ -f "$file" && ! -L "$file" ]] || fail "missing:$file"
done

bash -n "$capture" || fail capture_syntax
bash -n "$provisioner" || fail provisioner_syntax
bash -n "$wrapper" || fail wrapper_syntax
visudo -cf "$sudoers" >/dev/null || fail sudoers_syntax

grep -Fqx "readonly SOURCE_ROOT='/var/www/voxelpacs/app'" "$wrapper" || fail fixed_source_root
grep -Fqx "readonly OUTPUT_ROOT='/var/lib/voxelpacs/baselines'" "$wrapper" || fail fixed_output_root
grep -Fqx "readonly CAPTURE_SCRIPT='/usr/local/sbin/voxelpacs-capture-production-baseline'" "$wrapper" || fail fixed_capture_path
grep -Fq "[[ \"\$#\" -eq 1 && \"\$1\" == 'capture' ]]" "$wrapper" || fail fixed_arguments
grep -Fq '/usr/local/sbin/voxelpacs-production-baseline-capture capture' "$sudoers" || fail sudoers_command
grep -Eq '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:' "$sudoers" || fail sudoers_caller

for file in "$wrapper" "$sudoers"; do
  for forbidden in 'eval' 'systemctl' 'smbclient' 'curl ' 'mysql' 'psql' 'composer' 'git push' 'git checkout' 'NOPASSWD: ALL'; do
    ! grep -Fq -- "$forbidden" "$file" || fail "forbidden:$forbidden:$file"
  done
done

! grep -Eq '^[[:space:]]*eval([[:space:]]|$)' "$provisioner" || fail executable_eval
! grep -Eq '^[[:space:]]*(systemctl|smbclient|curl|mysql|psql|composer|git[[:space:]]+(push|checkout))([[:space:]]|$)' "$provisioner" || fail executable_generic_operation
! grep -Fq 'rm -rf' "$provisioner" || fail destructive_cleanup
! grep -Fq 'rm -rf' "$wrapper" || fail destructive_wrapper

printf 'TEST=PASS\nSTATIC=PASS\nSUDOERS_SYNTAX=PASS\nFIXED_SCOPE=PASS\nGENERIC_OPERATIONS=ABSENT\nPRODUCTION_CHANGED=NO\n'
