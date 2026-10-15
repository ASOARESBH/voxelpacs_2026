#!/usr/bin/env bash
set -Eeuo pipefail

RUNTIME_DIR=${VOXEL_REPORT_DELIVERY_RUNTIME_DIR:-/var/www/voxelpacs/app}
SERVICE_NAME=${VOXEL_REPORT_DELIVERY_WORKER_SERVICE:-voxelpacs-report-delivery-worker.service}
BACKUP_DIR=${VOXEL_REPORT_DELIVERY_DIAGNOSTICS_DIR:-/var/backups/voxelpacs/diagnostics}
WORKER_ID=${VOXEL_REPORT_DELIVERY_WORKER_ID:-local-dicom-worker}
IDLE_SECONDS=${VOXEL_REPORT_DELIVERY_WORKER_IDLE_SECONDS:-3}
JOB_ID=''

usage() {
  printf '%s\n' 'Uso: report-delivery-one-shot.sh --job-id=<id>' >&2
  exit 64
}

for argument in "$@"; do
  case "$argument" in
    --job-id=[1-9][0-9]*) JOB_ID=${argument#--job-id=} ;;
    *) usage ;;
  esac
done
[ -n "$JOB_ID" ] || usage

if ! [[ "$WORKER_ID" =~ ^[a-zA-Z0-9._:-]{3,120}$ ]] || ! [[ "$IDLE_SECONDS" =~ ^[0-9]+$ ]] || [ "$IDLE_SECONDS" -lt 1 ] || [ "$IDLE_SECONDS" -gt 30 ]; then
  printf '%s\n' 'ONE_SHOT_RESULT=BLOCKED' 'REASON=invalid_worker_environment' >&2
  exit 4
fi

if [ "$(id -u)" -ne 0 ]; then
  printf '%s\n' 'ONE_SHOT_RESULT=BLOCKED' 'REASON=root_required' >&2
  exit 4
fi

if [ ! -d "$RUNTIME_DIR" ] || [ ! -f "$RUNTIME_DIR/bin/report_delivery_worker.php" ] || [ ! -f "$RUNTIME_DIR/bin/report_delivery_one_shot_preflight.php" ] || [ ! -x /usr/bin/php ]; then
  printf '%s\n' 'ONE_SHOT_RESULT=BLOCKED' 'REASON=runtime_unavailable' >&2
  exit 4
fi

if systemctl is-active --quiet "$SERVICE_NAME" 2>/dev/null; then
  printf '%s\n' 'ONE_SHOT_RESULT=BLOCKED' 'REASON=worker_active' >&2
  exit 4
fi
if pgrep -u voxel -f '/bin/report_delivery_worker\.php([[:space:]]|$)' >/dev/null 2>&1; then
  printf '%s\n' 'ONE_SHOT_RESULT=BLOCKED' 'REASON=worker_process_active' >&2
  exit 4
fi

umask 077
install -d -m 700 "$BACKUP_DIR"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
LOG_PATH="$BACKUP_DIR/runone-${JOB_ID}-${STAMP}.log"
install -m 600 /dev/null "$LOG_PATH"

cd "$RUNTIME_DIR"
printf '%s\n' "HARNESS_USER=voxel" "HARNESS_CWD=$RUNTIME_DIR" "HARNESS_SCRIPT_PATH=$RUNTIME_DIR/bin/report_delivery_worker.php" >"$LOG_PATH"
printf '%s\n' "HARNESS_WORKER_ID=$WORKER_ID" "HARNESS_IDLE_SECONDS=$IDLE_SECONDS" "HARNESS_LOG_PRESERVED=YES" >>"$LOG_PATH"

set +e
runuser -u voxel -- /usr/bin/php "$RUNTIME_DIR/bin/report_delivery_one_shot_preflight.php" \
  "--job-id=$JOB_ID" --expect=ELIGIBLE >>"$LOG_PATH" 2>&1
PREFLIGHT_EXIT=$?
set -e
if [ "$PREFLIGHT_EXIT" -ne 0 ]; then
  printf '%s\n' 'ONE_SHOT_RESULT=BLOCKED' 'REASON=preflight_failed' "HARNESS_PHP_EXIT=$PREFLIGHT_EXIT"
  awk -F= '/^(PREFLIGHT_RESULT|PREFLIGHT_REASON|TARGET_GATE|ELIGIBLE_COUNT|OTHER_ELIGIBLE_COUNT|WORKER_KILL_SWITCH|DB_READ_ONLY)=/{print}' "$LOG_PATH"
  exit 4
fi

set +e
runuser -u voxel -- env \
  "VOXEL_REPORT_DELIVERY_WORKER_ID=$WORKER_ID" \
  "VOXEL_REPORT_DELIVERY_WORKER_IDLE_SECONDS=$IDLE_SECONDS" \
  /usr/bin/php "$RUNTIME_DIR/bin/report_delivery_worker.php" \
  "--job-id=$JOB_ID" >>"$LOG_PATH" 2>&1
PHP_EXIT=$?
set -e

printf '%s\n' "HARNESS_USER=voxel" "HARNESS_CWD=$RUNTIME_DIR" \
  "HARNESS_SCRIPT_PATH=$RUNTIME_DIR/bin/report_delivery_worker.php" \
  "HARNESS_PHP_EXIT=$PHP_EXIT" "HARNESS_LOG_PRESERVED=YES" \
  "HARNESS_LOG_CATEGORY=ROOT_ONLY_PRESERVED"
awk -F= '/^(RUNONE_RESULT|REASON|JOB_ID|ATTEMPT_ID|PREFLIGHT_RESULT|PREFLIGHT_EXPECTED|TARGET_GATE|ELIGIBLE_COUNT|OTHER_ELIGIBLE_COUNT|WORKER_KILL_SWITCH|CLINICAL_DATE_SOURCE|DB_READ_ONLY)=/{print}' "$LOG_PATH"
exit "$PHP_EXIT"
