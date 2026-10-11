#!/usr/bin/env bash
set -Eeuo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
reader="$repo/ops/deploy/voxelpacs-runtime-drift-manifest-reader"
gate="$repo/ops/deploy/voxelpacs-runtime-drift-gate"
provisioner="$repo/scripts/provision-runtime-drift-manifest-reader.sh"
sudoers="$repo/ops/sudoers/voxelpacs-runtime-drift-manifest-reader"
fail() { printf 'TEST=FAIL\nREASON=%s\n' "$1" >&2; exit 1; }

for file in "$reader" "$gate" "$provisioner" "$sudoers"; do
  [[ -f "$file" && ! -L "$file" ]] || fail "missing:$file"
done

for file in "$reader" "$gate" "$provisioner"; do
  bash -n "$file" || fail "syntax:$file"
done
visudo -cf "$sudoers" >/dev/null || fail sudoers_syntax

grep -Fqx "readonly BASELINE_ROOT='/var/lib/voxelpacs/baselines'" "$reader" || fail reader_root_not_fixed
grep -Fq 'stdout is intentionally the validated manifest only' "$reader" || fail reader_output_contract_missing
grep -Fqx "readonly RUNTIME_ROOT='/var/www/voxelpacs/app'" "$gate" || fail gate_runtime_not_fixed
grep -Fqx "readonly READER='/usr/local/sbin/voxelpacs-runtime-drift-manifest-reader'" "$gate" || fail gate_reader_not_fixed
grep -Fqx "readonly COMPARATOR='/usr/local/sbin/voxelpacs-validate-runtime-drift'" "$gate" || fail gate_comparator_not_fixed
grep -Fq -- "--baseline-id" "$reader" || fail reader_argument_missing
grep -Fq -- "--baseline-id" "$gate" || fail gate_argument_missing
grep -Fq "validate-runtime-drift.sh" "$provisioner" || fail comparator_source_missing
grep -Fq '/usr/local/sbin/voxelpacs-runtime-drift-manifest-reader read --baseline-id' "$sudoers" || fail reader_sudoers_missing
grep -Fq '/usr/local/sbin/voxelpacs-runtime-drift-gate --baseline-id' "$sudoers" || fail gate_sudoers_missing
[[ "$(grep -Ec '^manus-admin[[:space:]]+ALL=\(root\)[[:space:]]+NOPASSWD:' "$sudoers")" -eq 2 ]] || fail sudoers_rule_count

for file in "$reader" "$gate"; do
  for forbidden in \
    '--path' '--source' '--destination' '--command' 'eval ' 'rm -rf' \
    'smbclient' 'systemctl' 'composer' 'curl ' 'mysql' 'psql' \
    'NOPASSWD: ALL'; do
    if grep -Fq -- "$forbidden" "$file"; then fail "forbidden:$file:$forbidden"; fi
  done
done

printf 'TEST=PASS\nSTATIC=PASS\nSUDOERS_SYNTAX=PASS\nFIXED_SCOPE=PASS\nGENERIC_OPERATIONS=ABSENT\nPRODUCTION_CHANGED=NO\n'
