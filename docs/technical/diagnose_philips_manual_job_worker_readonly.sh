#!/usr/bin/env bash
# Diagnóstico fechado e somente leitura da elegibilidade do job Philips manual.
# Não cria, altera, reclama ou reenvia jobs; não chama bridge, SMB, PDF ou XML.
set -euo pipefail

readonly APP_DIR=/var/www/voxelpacs/app
readonly WORKER_UNIT=voxelpacs-report-delivery-worker.service
readonly TARGET_JOB_ID=272

emit() {
  printf '%s\n' "$1"
}

classify_unit() {
  if systemctl is-active --quiet "$WORKER_UNIT"; then
    emit 'WORKER_UNIT=active'
  elif systemctl is-enabled --quiet "$WORKER_UNIT" 2>/dev/null; then
    emit 'WORKER_UNIT=inactive'
  else
    emit 'WORKER_UNIT=unavailable'
  fi
}

classify_worker_configuration() {
  local pid started_at config_mtime now
  pid="$(systemctl show -p MainPID --value "$WORKER_UNIT" 2>/dev/null || true)"
  if [[ ! "$pid" =~ ^[1-9][0-9]*$ ]] || [[ ! -r "/proc/$pid/environ" ]]; then
    emit 'WORKER_PROCESS=unavailable'
    emit 'WORKER_NON_DICOM_FLAG=unavailable'
    emit 'WORKER_CONFIG_AGE=unavailable'
    return
  fi

  emit 'WORKER_PROCESS=present'
  if tr '\0' '\n' < "/proc/$pid/environ" | grep -Fxq 'PHILIPS_NON_DICOM_DELIVERY_ENABLED=true'; then
    emit 'WORKER_NON_DICOM_FLAG=loaded'
  else
    emit 'WORKER_NON_DICOM_FLAG=not_loaded'
  fi

  if [[ ! -r "$APP_DIR/.env" ]]; then
    emit 'WORKER_CONFIG_AGE=unavailable'
    return
  fi

  started_at="$(ps -o lstart= -p "$pid" 2>/dev/null | xargs -r -I{} date -d '{}' +%s 2>/dev/null || true)"
  config_mtime="$(stat -c %Y "$APP_DIR/.env" 2>/dev/null || true)"
  now="$(date +%s)"
  if [[ "$started_at" =~ ^[0-9]+$ ]] && [[ "$config_mtime" =~ ^[0-9]+$ ]] && (( started_at <= now )); then
    if (( config_mtime > started_at )); then
      emit 'WORKER_CONFIG_AGE=older_than_env'
    else
      emit 'WORKER_CONFIG_AGE=current_or_newer'
    fi
  else
    emit 'WORKER_CONFIG_AGE=unavailable'
  fi
}

classify_job() {
  local db_name schema result
  db_name="$(grep -m1 '^DB_DATABASE=' "$APP_DIR/.env" 2>/dev/null | cut -d= -f2- | tr -d '\"' || true)"
  if [[ -z "$db_name" ]]; then
    emit 'JOB_DIAGNOSTIC=database_unavailable'
    return
  fi

  schema="$(sudo -u postgres psql -At -d "$db_name" -c "SELECT table_schema FROM information_schema.tables WHERE table_name='pacs_report_delivery_jobs' AND table_type='BASE TABLE' ORDER BY CASE WHEN table_schema='public' THEN 2 ELSE 1 END, table_schema LIMIT 1;" 2>/dev/null || true)"
  if [[ ! "$schema" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
    emit 'JOB_DIAGNOSTIC=schema_unavailable'
    return
  fi

  result="$(sudo -u postgres psql -At -d "$db_name" -c "SELECT COALESCE((SELECT 'JOB_PRESENT=ready;JOB_STATUS=' || CASE j.status WHEN 'queued' THEN 'queued' WHEN 'processing' THEN 'processing' WHEN 'delivered' THEN 'delivered' WHEN 'retrying' THEN 'retrying' WHEN 'failed' THEN 'failed' WHEN 'dead_letter' THEN 'dead_letter' ELSE 'other' END || ';JOB_TRANSPORT=' || CASE WHEN j.transport='philips_non_dicom' THEN 'philips_non_dicom' ELSE 'other' END || ';WORKER_ELIGIBILITY=' || CASE WHEN j.worker_eligible_at IS NULL THEN 'absent' WHEN j.worker_eligible_at <= NOW() THEN 'ready' ELSE 'not_yet' END || ';RETRY_WINDOW=' || CASE WHEN j.next_attempt_at IS NULL OR j.next_attempt_at <= NOW() THEN 'ready' ELSE 'not_yet' END || ';JOB_LOCK=' || CASE WHEN j.locked_at IS NULL THEN 'clear' ELSE 'present' END || ';DISPATCH_WINDOW=' || CASE WHEN j.automatic_dispatch_date IS NULL THEN 'manual' WHEN j.automatic_dispatch_date=CURRENT_DATE THEN 'today' ELSE 'other' END || ';DESTINATION=' || CASE WHEN d.enabled=1 AND d.ambiente='homologacao' AND d.transport='philips_non_dicom' THEN 'eligible' ELSE 'ineligible' END FROM \"$schema\".pacs_report_delivery_jobs j INNER JOIN \"$schema\".pacs_report_delivery_destinations d ON d.id=j.destination_id WHERE j.id=$TARGET_JOB_ID LIMIT 1),'JOB_PRESENT=absent');" 2>/dev/null || true)"
  case "$result" in
    JOB_PRESENT=*) emit "$result" ;;
    *) emit 'JOB_DIAGNOSTIC=unavailable' ;;
  esac
}

emit '=== PHILIPS_MANUAL_JOB_WORKER_READONLY ==='
emit 'DIAGNOSTIC_SCHEMA=1'
classify_unit
classify_worker_configuration
classify_job
emit 'PHILIPS_MANUAL_JOB_WORKER_READONLY_OK'
