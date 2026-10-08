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
FAKE_BIN="$TMP_DIR/bin"
mkdir -p "$FAKE_BIN"
cat > "$FAKE_BIN/systemctl" <<'SYSTEMCTL'
#!/usr/bin/env bash
set -Eeuo pipefail
[[ "$1" == 'show' ]] || exit 64
case "$3" in
  FragmentPath) printf '/etc/systemd/system/voxelpacs-philips-folder-bridge.service\n' ;;
  ExecStart) printf '{ path=/usr/bin/python3 ; argv[]=/usr/bin/python3 /opt/voxelpacs/report-delivery-gateway/philips_folder_bridge.py ; }\n' ;;
  EnvironmentFiles) printf '%s (ignore_errors=no)\n' "$VOXEL_TEST_ENV_FILE" ;;
  *) exit 65 ;;
esac
SYSTEMCTL
chmod 700 "$FAKE_BIN/systemctl"
export PATH="$FAKE_BIN:$PATH"
export VOXEL_TEST_ENV_FILE="$ENV_FILE"
UNIT='voxelpacs-philips-folder-bridge.service'
COMMON=(--env-file "$ENV_FILE" --allowlist "$ALLOWLIST" --backup-root "$BACKUP_ROOT" --expected-host "$HOST_NAME" --tenant-id 2 --destination-id 7 --job-id 519 --transport philips_non_dicom --profile submission_document --mode single_test)

expect_not_success "$APPLIER" --backup-only "${COMMON[@]}"
pass 'backup-only without explicit unit rejected'

env_before="$(sha256sum "$ENV_FILE" | awk '{print $1}')"
allowlist_before="$(sha256sum "$ALLOWLIST" | awk '{print $1}')"
backup_only_output="$($APPLIER --backup-only "${COMMON[@]}" --unit "$UNIT")"
expect_contains "$backup_only_output" 'BACKUP_ONLY=PASS' 'backup-only should pass'
expect_contains "$backup_only_output" 'BACKUP_MANIFEST=PASS' 'backup-only manifest'
expect_contains "$backup_only_output" 'BACKUP_CHECKSUM=PASS' 'backup-only checksum'
expect_contains "$backup_only_output" 'POLICY_CHANGED=NO' 'backup-only policy isolation'
expect_contains "$backup_only_output" 'RELOAD=NOT_PERFORMED' 'backup-only must not reload'
backup_id="$(sed -n 's/^BACKUP_ID=//p' <<<"$backup_only_output")"
[[ "$backup_id" =~ ^policy-[0-9]{8}T[0-9]{6}Z-[A-Za-z0-9]+$ ]] || fail 'backup-only id format'
[[ -f "$BACKUP_ROOT/$backup_id/manifest" ]] || fail 'backup-only manifest missing'
[[ -f "$BACKUP_ROOT/$backup_id/policy.conf" ]] || fail 'backup-only policy missing'
[[ "$(stat -c '%U:%G:%a' "$BACKUP_ROOT/$backup_id")" == 'root:root:700' ]] || fail 'backup-only directory protection'
[[ "$(stat -c '%U:%G:%a' "$BACKUP_ROOT/$backup_id/manifest")" == 'root:root:600' ]] || fail 'backup-only manifest protection'
[[ "$(stat -c '%U:%G:%a' "$BACKUP_ROOT/$backup_id/policy.conf")" == 'root:root:600' ]] || fail 'backup-only policy protection'
grep -Eq '^SOURCE_POLICY_CHECKSUM=[a-f0-9]{64}$' "$BACKUP_ROOT/$backup_id/manifest" || fail 'source checksum missing'
grep -Eq '^POLICY_CHECKSUM=[a-f0-9]{64}$' "$BACKUP_ROOT/$backup_id/manifest" || fail 'policy checksum missing'
[[ "$(sha256sum "$ENV_FILE" | awk '{print $1}')" == "$env_before" ]] || fail 'backup-only changed EnvironmentFile fixture'
[[ "$(sha256sum "$ALLOWLIST" | awk '{print $1}')" == "$allowlist_before" ]] || fail 'backup-only changed allowlist fixture'
[[ "$(find "$BACKUP_ROOT/$backup_id" -maxdepth 1 -type f -printf '%f\n' | sort | tr '\n' ' ')" == 'manifest policy.conf ' ]] || fail 'backup-only not isolated'
pass 'backup-only success, manifest, checksum, root-only isolation and no reload'

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

expect_not_success "$APPLIER" --apply "${COMMON[@]}"
pass 'apply without backup rejected'

output="$($APPLIER --apply "${COMMON[@]}" --backup-id "$backup_id")"
expect_contains "$output" 'APPLY=PASS' 'apply fixture should pass'
expect_contains "$output" 'BACKUP_VERIFIED=PASS' 'apply must verify backup'
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

for incompatible in incompatible-job incompatible-destination; do
  mkdir -p "$BACKUP_ROOT/$incompatible"
  cp -p "$BACKUP_ROOT/$backup_id/policy.conf" "$BACKUP_ROOT/$incompatible/policy.conf"
  cp -p "$BACKUP_ROOT/$backup_id/manifest" "$BACKUP_ROOT/$incompatible/manifest"
  sed -i "s/^BACKUP_ID=.*/BACKUP_ID=$incompatible/" "$BACKUP_ROOT/$incompatible/manifest"
  if [[ "$incompatible" == 'incompatible-job' ]]; then
    sed -i 's/^TARGET_JOB=519$/TARGET_JOB=518/' "$BACKUP_ROOT/$incompatible/manifest"
  else
    sed -i 's/^TARGET_DESTINATION=7$/TARGET_DESTINATION=6/' "$BACKUP_ROOT/$incompatible/manifest"
  fi
  chown root:root "$BACKUP_ROOT/$incompatible/manifest" "$BACKUP_ROOT/$incompatible/policy.conf"
  chmod 600 "$BACKUP_ROOT/$incompatible/manifest" "$BACKUP_ROOT/$incompatible/policy.conf"
done
expect_not_success "$APPLIER" --apply "${COMMON[@]}" --backup-id incompatible-job
expect_not_success "$APPLIER" --apply "${COMMON[@]}" --backup-id incompatible-destination
expect_not_success "$APPLIER" rollback --dry-run "${COMMON[@]}" --backup-id incompatible-job
expect_not_success "$APPLIER" rollback --dry-run "${COMMON[@]}" --backup-id incompatible-destination
pass 'incompatible Job/Destination rejected for apply and rollback'

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

# Transition contract: atomically move the Philips Folder single-test policy
# and its exact one-job allowlist from predecessor 519 to target 522.
output="$($APPLIER --apply "${COMMON[@]}" --backup-id "$backup_id")"
expect_contains "$output" 'APPLY=PASS' 're-arm predecessor policy for transition fixture'
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_ALLOW_JOB_ID=519' 'transition predecessor policy'

TRANSITION_COMMON=(
  --env-file "$ENV_FILE"
  --allowlist "$ALLOWLIST"
  --backup-root "$BACKUP_ROOT"
  --expected-host "$HOST_NAME"
  --tenant-id 2
  --destination-id 7
  --job-id 522
  --source-job-id 519
  --transport philips_non_dicom
  --profile submission_document
  --mode single_test
)

output="$($APPLIER --transition-dry-run "${TRANSITION_COMMON[@]}")"
expect_contains "$output" 'TRANSITION_DRY_RUN=PASS' 'transition dry-run'
expect_contains "$output" 'SOURCE_POLICY=PASS' 'transition source policy'
expect_contains "$output" 'SOURCE_ALLOWLIST=PASS' 'transition source allowlist'
expect_contains "$output" 'ALLOWLIST_EXACT_TARGET=YES' 'transition exact target allowlist'
expect_contains "$output" 'TRANSMISSION=NO' 'transition dry-run transport isolation'
pass 'transition dry-run and source guards'

transition_env_before="$(sha256sum "$ENV_FILE" | awk '{print $1}')"
transition_allowlist_before="$(sha256sum "$ALLOWLIST" | awk '{print $1}')"
transition_backup_output="$($APPLIER --transition-backup-only "${TRANSITION_COMMON[@]}" --unit "$UNIT")"
expect_contains "$transition_backup_output" 'TRANSITION_BACKUP_ONLY=PASS' 'transition backup-only'
expect_contains "$transition_backup_output" 'BACKUP_POLICY_CHECKSUM=PASS' 'transition policy checksum'
expect_contains "$transition_backup_output" 'BACKUP_ALLOWLIST_CHECKSUM=PASS' 'transition allowlist checksum'
expect_contains "$transition_backup_output" 'POLICY_CHANGED=NO' 'transition backup policy isolation'
expect_contains "$transition_backup_output" 'ALLOWLIST_CHANGED=NO' 'transition backup allowlist isolation'
transition_backup_id="$(sed -n 's/^BACKUP_ID=//p' <<<"$transition_backup_output")"
[[ "$transition_backup_id" =~ ^transition-[0-9]{8}T[0-9]{6}Z-[A-Za-z0-9]+$ ]] || fail 'transition backup id format'
[[ -f "$BACKUP_ROOT/$transition_backup_id/manifest" ]] || fail 'transition manifest missing'
[[ -f "$BACKUP_ROOT/$transition_backup_id/policy.conf" ]] || fail 'transition policy backup missing'
[[ -f "$BACKUP_ROOT/$transition_backup_id/allowlist" ]] || fail 'transition allowlist backup missing'
[[ "$(sha256sum "$ENV_FILE" | awk '{print $1}')" == "$transition_env_before" ]] || fail 'transition backup changed EnvironmentFile fixture'
[[ "$(sha256sum "$ALLOWLIST" | awk '{print $1}')" == "$transition_allowlist_before" ]] || fail 'transition backup changed allowlist fixture'
grep -Eq '^SOURCE_ALLOWLIST_CHECKSUM=[a-f0-9]{64}$' "$BACKUP_ROOT/$transition_backup_id/manifest" || fail 'transition source allowlist checksum missing'
pass 'transition backup-only and dual-state manifest'

FAIL_MV_BIN="$TMP_DIR/fail-mv-bin"
mkdir -p "$FAIL_MV_BIN"
cat > "$FAIL_MV_BIN/mv" <<'MV'
#!/usr/bin/env bash
set -Eeuo pipefail
if [[ "${!#}" == "${VOXEL_TEST_FAIL_MV_DEST:-}" ]]; then
  exit 77
fi
exec /usr/bin/mv "$@"
MV
chmod 700 "$FAIL_MV_BIN/mv"
original_path="$PATH"
export VOXEL_TEST_FAIL_MV_DEST="$ALLOWLIST"
export PATH="$FAIL_MV_BIN:$PATH"
expect_not_success "$APPLIER" --transition-apply "${TRANSITION_COMMON[@]}" --backup-id "$transition_backup_id"
export PATH="$original_path"
unset VOXEL_TEST_FAIL_MV_DEST
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_ALLOW_JOB_ID=519' 'transition failure restored predecessor policy'
expect_contains "$(cat "$ALLOWLIST")" 'jobs=519' 'transition failure restored predecessor allowlist'
pass 'transition second-file failure triggers automatic rollback'

output="$($APPLIER --transition-apply "${TRANSITION_COMMON[@]}" --backup-id "$transition_backup_id")"
expect_contains "$output" 'TRANSITION_APPLY=PASS' 'transition apply'
expect_contains "$output" 'BACKUP_VERIFIED=PASS' 'transition backup verification'
expect_contains "$output" 'POLICY_CHANGED=YES' 'transition policy changed'
expect_contains "$output" 'ALLOWLIST_CHANGED=YES' 'transition allowlist changed'
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_ALLOW_JOB_ID=522' 'transition target policy'
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_DESTINATION_ID=7' 'transition target destination'
expect_contains "$(cat "$ALLOWLIST")" 'jobs=522' 'transition target allowlist'
pass 'transition apply changes both files without reload'

output="$($APPLIER --validate "${TRANSITION_COMMON[@]}")"
expect_contains "$output" 'VALIDATION=PASS' 'transition target validation'
expect_contains "$output" 'EFFECTIVE_ALLOWLIST_JOB=522' 'transition target job validation'
pass 'transition target effective validation'

expect_not_success "$APPLIER" --transition-dry-run "${TRANSITION_COMMON[@]}" --source-job-id 518
pass 'transition rejects wrong predecessor job'
expect_not_success "$APPLIER" --transition-dry-run "${TRANSITION_COMMON[@]}" --unit "$UNIT"
pass 'transition dry-run rejects implicit unit control'

output="$($APPLIER transition-rollback --dry-run "${TRANSITION_COMMON[@]}" --backup-id "$transition_backup_id")"
expect_contains "$output" 'TRANSITION_ROLLBACK_DRY_RUN=PASS' 'transition rollback dry-run'
expect_contains "$output" 'ROLLBACK_COMPATIBILITY=PASS' 'transition rollback compatibility'
expect_contains "$output" 'WOULD_RESTORE_SOURCE_JOB=519' 'transition rollback source job'
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_ALLOW_JOB_ID=522' 'transition rollback dry-run policy isolation'
expect_contains "$(cat "$ALLOWLIST")" 'jobs=522' 'transition rollback dry-run allowlist isolation'
pass 'transition rollback dry-run'

output="$($APPLIER transition-rollback "${TRANSITION_COMMON[@]}" --backup-id "$transition_backup_id")"
expect_contains "$output" 'TRANSITION_ROLLBACK=PASS' 'transition rollback apply'
expect_contains "$output" 'RESTORED_SOURCE_JOB=519' 'transition rollback source'
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_ALLOW_JOB_ID=519' 'transition rollback restored policy'
expect_contains "$(cat "$ALLOWLIST")" 'jobs=519' 'transition rollback restored allowlist'
pass 'transition rollback restores policy and allowlist'

# Destination transition contract: move the existing single-test policy for
# source job 519 to tenant/Destination mode with the explicit job=0 sentinel.
DESTINATION_TRANSITION_COMMON=(
  --env-file "$ENV_FILE"
  --allowlist "$ALLOWLIST"
  --backup-root "$BACKUP_ROOT"
  --expected-host "$HOST_NAME"
  --tenant-id 2
  --destination-id 7
  --job-id 0
  --source-job-id 519
  --transport philips_non_dicom
  --profile submission_document
  --mode destination
)

output="$($APPLIER --transition-dry-run "${DESTINATION_TRANSITION_COMMON[@]}")"
expect_contains "$output" 'TRANSITION_DRY_RUN=PASS' 'destination transition dry-run'
expect_contains "$output" 'TARGET_JOB=0' 'destination transition job sentinel'
expect_contains "$output" 'MODE=destination' 'destination transition mode'
pass 'single-test to destination transition dry-run'

destination_transition_backup_output="$($APPLIER --transition-backup-only "${DESTINATION_TRANSITION_COMMON[@]}" --unit "$UNIT")"
expect_contains "$destination_transition_backup_output" 'TRANSITION_BACKUP_ONLY=PASS' 'destination transition backup-only'
destination_transition_backup_id="$(sed -n 's/^BACKUP_ID=//p' <<<"$destination_transition_backup_output")"
output="$($APPLIER --transition-apply "${DESTINATION_TRANSITION_COMMON[@]}" --backup-id "$destination_transition_backup_id")"
expect_contains "$output" 'TRANSITION_APPLY=PASS' 'destination transition apply'
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_MODE=destination' 'destination transition policy mode'
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_ALLOW_JOB_ID=0' 'destination transition policy sentinel'
expect_contains "$(cat "$ALLOWLIST")" 'jobs=0' 'destination transition allowlist sentinel'
expect_contains "$(cat "$ALLOWLIST")" 'mode=destination' 'destination transition allowlist mode'

output="$($APPLIER --validate "${DESTINATION_TRANSITION_COMMON[@]}")"
expect_contains "$output" 'VALIDATION=PASS' 'destination transition validation'
expect_contains "$output" 'BRIDGE_MODE_DESTINATION=PASS' 'destination transition validation mode'
pass 'single-test to destination transition apply and validate'

output="$($APPLIER transition-rollback "${DESTINATION_TRANSITION_COMMON[@]}" --backup-id "$destination_transition_backup_id")"
expect_contains "$output" 'TRANSITION_ROLLBACK=PASS' 'destination transition rollback'
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_MODE=single_test' 'destination transition rollback mode'
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_ALLOW_JOB_ID=519' 'destination transition rollback job'
expect_contains "$(cat "$ALLOWLIST")" 'jobs=519' 'destination transition rollback allowlist'
expect_contains "$(cat "$ALLOWLIST")" 'mode=single_test' 'destination transition rollback allowlist mode'
pass 'single-test to destination transition rollback'

# Destination-mode contract: allow the tenant/Destination policy with no job
# wildcard; job=0 is the only representation accepted for this mode.
single_allowlist_saved="$TMP_DIR/single-allowlist-saved"
cp "$ALLOWLIST" "$single_allowlist_saved"
DEST_ALLOWLIST="$TMP_DIR/destination-allowlist"
cat > "$DEST_ALLOWLIST" <<'ALLOW'
tenant_id=2
destination_id=7
jobs=0
transport=philips_non_dicom
profile=submission_document
mode=destination
ALLOW
chmod 600 "$DEST_ALLOWLIST"
DEST_COMMON=(--env-file "$ENV_FILE" --allowlist "$DEST_ALLOWLIST" --backup-root "$BACKUP_ROOT" --expected-host "$HOST_NAME" --tenant-id 2 --destination-id 7 --job-id 0 --transport philips_non_dicom --profile submission_document --mode destination)

output="$($APPLIER --dry-run "${DEST_COMMON[@]}")"
expect_contains "$output" 'DRY_RUN=PASS' 'destination dry-run'
expect_contains "$output" 'TARGET_JOB=0' 'destination job sentinel'
expect_contains "$output" 'MODE=destination' 'destination mode output'
pass 'destination dry-run with job zero'

expect_not_success "$APPLIER" --dry-run "${DEST_COMMON[@]}" --job-id 519
bad_destination_allowlist="$TMP_DIR/bad-destination-allowlist"
sed 's/^jobs=0$/jobs=519/' "$DEST_ALLOWLIST" > "$bad_destination_allowlist"
chmod 600 "$bad_destination_allowlist"
expect_not_success "$APPLIER" --dry-run "${DEST_COMMON[@]}" --allowlist "$bad_destination_allowlist"
bad_single_allowlist="$TMP_DIR/bad-single-allowlist"
sed 's/^jobs=519$/jobs=0/' "$single_allowlist_saved" > "$bad_single_allowlist"
chmod 600 "$bad_single_allowlist"
expect_not_success "$APPLIER" --dry-run "${COMMON[@]}" --allowlist "$bad_single_allowlist"
expect_not_success "$APPLIER" --dry-run "${COMMON[@]}" --job-id 0
pass 'destination rejects positive job and nonzero allowlist sentinel'

destination_backup_output="$($APPLIER --backup-only "${DEST_COMMON[@]}" --unit "$UNIT")"
expect_contains "$destination_backup_output" 'BACKUP_ONLY=PASS' 'destination backup-only'
destination_backup_id="$(sed -n 's/^BACKUP_ID=//p' <<<"$destination_backup_output")"
output="$($APPLIER --apply "${DEST_COMMON[@]}" --backup-id "$destination_backup_id")"
expect_contains "$output" 'APPLY=PASS' 'destination apply'
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_MODE=destination' 'destination mode applied'
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_ALLOW_JOB_ID=0' 'destination job sentinel applied'

output="$($APPLIER --validate "${DEST_COMMON[@]}")"
expect_contains "$output" 'VALIDATION=PASS' 'destination validation'
expect_contains "$output" 'BRIDGE_MODE_DESTINATION=PASS' 'destination mode validation'
expect_contains "$output" 'EFFECTIVE_ALLOWLIST_JOB=0' 'destination allowlist validation'
pass 'destination apply and effective validation'

output="$($APPLIER rollback "${DEST_COMMON[@]}" --backup-id "$destination_backup_id")"
expect_contains "$output" 'ROLLBACK=PASS' 'destination rollback'
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_MODE=single_test' 'destination rollback mode'
expect_contains "$(cat "$ENV_FILE")" 'PHILIPS_FOLDER_ALLOW_JOB_ID=519' 'destination rollback source job'
cp "$single_allowlist_saved" "$ALLOWLIST"
pass 'destination rollback restores previous single-test policy'

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
