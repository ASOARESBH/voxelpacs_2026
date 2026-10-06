#!/usr/bin/env bash
# Local fixture tests only. Never contacts a host, service, Bridge, SMB or database.
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APPLIER="$ROOT_DIR/ops/deploy/voxelpacs-philips-folder-policy-applier"
TMP_DIR="$(mktemp -d -p "${TMPDIR:-/tmp}" voxelpacs-policy-applier.XXXXXX)"
trap 'rm -rf -- "$TMP_DIR"' EXIT

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }
pass() { printf 'PASS: %s\n' "$1"; }
expect_contains() { grep -Fqx "$2" <<<"$1" || fail "$3"; }
expect_not_success() { if "$@" >/dev/null 2>&1; then fail "unexpected success: $*"; fi; }

[[ -x "$APPLIER" ]] || fail 'applier is not executable'
bash -n "$APPLIER" || fail 'applier syntax'

ENV_FILE="$TMP_DIR/bridge.env"
ALLOWLIST="$TMP_DIR/allowlist"
BACKUP_ROOT="$TMP_DIR/backups"
mkdir -p "$BACKUP_ROOT"
chmod 700 "$BACKUP_ROOT"
cat > "$ENV_FILE" <<'ENV'
PHILIPS_FOLDER_BIND_IP=private
PHILIPS_FOLDER_BIND_PORT=9443
PHILIPS_FOLDER_ALLOW_TENANT_ID=2
PHILIPS_FOLDER_ALLOW_DESTINATION_ID=6
PHILIPS_FOLDER_DESTINATION_ID=6
PHILIPS_FOLDER_MODE=single_test
PHILIPS_FOLDER_ALLOW_JOB_ID=272
PHILIPS_FOLDER_TRANSPORT=sftp
PHILIPS_FOLDER_FALLBACK=
PHILIPS_FOLDER_STAGE_DIAGNOSTICS=0
PHILIPS_FOLDER_SMB_DIAGNOSTICS=0
PHILIPS_FOLDER_ENVELOPE_DIAGNOSTICS=0
PHILIPS_FOLDER_ALLOW_MISSING_PATIENT_NAME_COMPONENTS_FOR_HOMOLOGATION=0
PHILIPS_FOLDER_ALLOW_PATIENT_NAME_AS_FAMILY_FOR_HOMOLOGATION=0
ENV
chmod 600 "$ENV_FILE"
cat > "$ALLOWLIST" <<'ALLOW'
tenant_id=2
destination_id=7
jobs=519
transport=philips_non_dicom
profile=submission_document
mode=single_test
ALLOW
chmod 600 "$ALLOWLIST"

HOST_NAME="$(hostname -s)"
COMMON=(--env-file "$ENV_FILE" --allowlist "$ALLOWLIST" --backup-root "$BACKUP_ROOT" --expected-host "$HOST_NAME" --tenant-id 2 --destination-id 7 --job-id 519 --transport philips_non_dicom --profile submission_document --mode single_test)

env_before="$(sha256sum "$ENV_FILE" | awk '{print $1}')"
backup_dirs_before="$(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -print | wc -l | tr -d ' ')"
output="$($APPLIER --dry-run "${COMMON[@]}")"
expect_contains "$output" 'DRY_RUN=PASS' 'dry-run should pass'
expect_contains "$output" 'WOULD_APPLY=YES' 'dry-run should describe apply'
expect_contains "$output" 'WOULD_RELOAD=YES' 'dry-run should describe reload'
expect_contains "$output" 'WOULD_TRANSMIT=NO' 'dry-run must not transmit'
[[ "$(sha256sum "$ENV_FILE" | awk '{print $1}')" == "$env_before" ]] || fail 'dry-run changed EnvironmentFile fixture'
[[ "$(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -print | wc -l | tr -d ' ')" == "$backup_dirs_before" ]] || fail 'dry-run created backup fixture'
pass 'dry-run happy path'

output="$($APPLIER --apply "${COMMON[@]}")"
expect_contains "$output" 'APPLY=PASS' 'apply fixture should pass'
expect_contains "$output" 'BACKUP_VERIFIED=PASS' 'apply must verify backup'
backup_id="$(sed -n 's/^BACKUP_ID=//p' <<<"$output")"
[[ "$backup_id" =~ ^policy-[0-9]{8}T[0-9]{6}Z-[A-Za-z0-9]+$ ]] || fail 'backup id format'
[[ -f "$BACKUP_ROOT/$backup_id/manifest" ]] || fail 'backup manifest missing'
[[ -f "$BACKUP_ROOT/$backup_id/policy.conf" ]] || fail 'backup policy missing'
[[ "$(stat -c '%U:%G:%a' "$BACKUP_ROOT/$backup_id/manifest")" == 'root:root:600' ]] || fail 'backup manifest protection'
[[ "$(stat -c '%U:%G:%a' "$BACKUP_ROOT/$backup_id/policy.conf")" == 'root:root:600' ]] || fail 'backup policy protection'
if grep -Eqi 'hmac|private[_-]?key|certificate|password|token|secret' "$BACKUP_ROOT/$backup_id/policy.conf"; then
  fail 'backup policy contains secret reference'
fi
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_ALLOW_JOB_ID=519' 'apply target job'
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_DESTINATION_ID=7' 'apply destination'
pass 'apply and verified root-only backup'

output="$($APPLIER --validate "${COMMON[@]}")"
expect_contains "$output" 'VALIDATION=PASS' 'effective target validation'
expect_contains "$output" 'BRIDGE_MODE_SINGLE_TEST=PASS' 'single_test validation'
expect_contains "$output" 'EFFECTIVE_ALLOWLIST_JOB=519' 'effective job validation'
pass 'effective policy validation'

output="$($APPLIER rollback --dry-run "${COMMON[@]}" --backup-id "$backup_id")"
expect_contains "$output" 'ROLLBACK_DRY_RUN=PASS' 'rollback dry-run'
expect_contains "$output" 'BACKUP_INTEGRITY=PASS' 'rollback integrity'
expect_contains "$output" 'WOULD_RESTORE=YES' 'rollback preview'
pass 'rollback dry-run'

output="$($APPLIER rollback "${COMMON[@]}" --backup-id "$backup_id")"
expect_contains "$output" 'ROLLBACK=PASS' 'rollback apply'
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_ALLOW_JOB_ID=272' 'rollback restored previous job'
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_DESTINATION_ID=6' 'rollback restored previous destination'
pass 'rollback restore and validation'

# Negative contract matrix.
expect_not_success "$APPLIER" --dry-run "${COMMON[@]}" --tenant-id 3
expect_not_success "$APPLIER" --dry-run "${COMMON[@]}" --destination-id 6
expect_not_success "$APPLIER" --dry-run "${COMMON[@]}" --job-id 518
expect_not_success "$APPLIER" --dry-run "${COMMON[@]}" --transport dicom_pdf
expect_not_success "$APPLIER" --dry-run "${COMMON[@]}" --profile pdf_only
pass 'wrong tenant/destination/job/transport/profile rejected'

cp "$ALLOWLIST" "$TMP_DIR/empty-allowlist"
: > "$TMP_DIR/empty-allowlist"
expect_not_success "$APPLIER" --dry-run "${COMMON[@]}" --allowlist "$TMP_DIR/empty-allowlist"
printf 'jobs=519,520\n' > "$TMP_DIR/multiple-jobs"
cat > "$TMP_DIR/multiple-jobs" <<'ALLOW'
tenant_id=2
destination_id=7
jobs=519,520
transport=philips_non_dicom
profile=submission_document
mode=single_test
ALLOW
expect_not_success "$APPLIER" --dry-run "${COMMON[@]}" --allowlist "$TMP_DIR/multiple-jobs"
pass 'empty and ambiguous allowlists rejected'

expect_not_success "$APPLIER" rollback --dry-run "${COMMON[@]}" --backup-id missing-backup
expect_not_success "$APPLIER" rollback --dry-run "${COMMON[@]}"
printf 'FORMAT=1\n' > "$TMP_DIR/invalid-manifest"
mkdir -p "$BACKUP_ROOT/invalid"
chmod 700 "$BACKUP_ROOT/invalid"
install -o root -g root -m 600 "$TMP_DIR/invalid-manifest" "$BACKUP_ROOT/invalid/manifest"
install -o root -g root -m 600 /dev/null "$BACKUP_ROOT/invalid/policy.conf"
expect_not_success "$APPLIER" rollback --dry-run "${COMMON[@]}" --backup-id invalid
pass 'missing/invalid backup and missing rollback rejected'

cp "$ENV_FILE" "$TMP_DIR/unknown-policy"
sed -i 's/^PHILIPS_FOLDER_MODE=.*/PHILIPS_FOLDER_MODE=unknown/' "$TMP_DIR/unknown-policy"
expect_not_success "$APPLIER" --dry-run "${COMMON[@]}" --env-file "$TMP_DIR/unknown-policy"
pass 'unknown current policy rejected'

if grep -Eq 'systemctl (start|stop|enable|disable|restart|reload) .*dicom|bridge_server\.py|smbclient|curl |scp |rsync ' "$APPLIER"; then
  fail 'applier contains forbidden transport or DICOM control'
fi
if grep -Eq 'PHILIPS_FOLDER_(HMAC|.*KEY|.*CERT).*=' "$APPLIER"; then
  fail 'applier embeds secret material'
fi
pass 'static safety scan'

printf 'PHILIPS_FOLDER_POLICY_APPLIER_TEST=PASS\n'
printf 'PRODUCTION_ACCESS=NO\nHOST2_CHANGED=NO\nJOB_EXECUTED=NO\nTRANSMISSION=NO\n'
