#!/usr/bin/env bash
# Diagnóstico fechado/read-only da última entrega manual Philips Non-DICOM.
# Não cria job, não chama bridge, não acessa artefato, não realiza SMB e não altera runtime.
set -euo pipefail

if [ "$#" -ne 0 ]; then
  echo 'USAGE=NO_ARGUMENTS'
  exit 64
fi

app=/var/www/voxelpacs/app
env_file="$app/.env"

echo '=== PHILIPS_MANUAL_DELIVERY_READONLY ==='

if [ ! -r "$env_file" ]; then
  echo 'DELIVERY_DB=unavailable'
  echo 'PHILIPS_MANUAL_DELIVERY_READONLY_OK'
  exit 0
fi

db_name="$(sed -n 's/^DB_DATABASE=//p' "$env_file" | head -n1 | tr -d "'\"")"
if [ -z "$db_name" ]; then
  echo 'DELIVERY_DB=unavailable'
  echo 'PHILIPS_MANUAL_DELIVERY_READONLY_OK'
  exit 0
fi

schema="$(sudo -u postgres psql -At -d "$db_name" -c "
SELECT table_schema
  FROM information_schema.tables
 WHERE table_name = 'pacs_report_delivery_outbox'
   AND table_type = 'BASE TABLE'
 ORDER BY CASE WHEN table_schema = 'public' THEN 2 ELSE 1 END, table_schema
 LIMIT 1;" 2>/dev/null || true)"

case "$schema" in
  [A-Za-z_][A-Za-z0-9_]*) ;;
  *)
    echo 'DELIVERY_SCHEMA=unavailable'
    echo 'PHILIPS_MANUAL_DELIVERY_READONLY_OK'
    exit 0
    ;;
esac

echo 'DELIVERY_DB=ready'
echo 'DELIVERY_SCHEMA=ready'

sudo -u postgres psql -At -d "$db_name" -c "
WITH latest_outbox AS (
    SELECT id, status
      FROM \"$schema\".pacs_report_delivery_outbox
     WHERE COALESCE(payload_json, '') LIKE '%\"dispatch_mode\":\"manual_homologation\"%'
     ORDER BY id DESC
     LIMIT 1
), latest_job AS (
    SELECT j.id, j.status, j.transport, j.remote_reference, j.last_error
      FROM \"$schema\".pacs_report_delivery_jobs j
      INNER JOIN latest_outbox o ON o.id = j.outbox_id
     WHERE j.transport = 'philips_non_dicom'
     ORDER BY j.id DESC
     LIMIT 1
)
SELECT CASE
    WHEN NOT EXISTS (SELECT 1 FROM latest_outbox) THEN 'MANUAL_OUTBOX=absent'
    ELSE 'MANUAL_OUTBOX=present;MANUAL_OUTBOX_STATUS=' ||
         CASE (SELECT status FROM latest_outbox)
           WHEN 'queued' THEN 'queued'
           WHEN 'processed' THEN 'processed'
           WHEN 'no_destination' THEN 'no_destination'
           ELSE 'other'
         END
  END
UNION ALL
SELECT CASE
    WHEN NOT EXISTS (SELECT 1 FROM latest_job) THEN 'PHILIPS_MANUAL_JOB=absent'
    ELSE 'PHILIPS_MANUAL_JOB=present;PHILIPS_MANUAL_JOB_ID=' || (SELECT id::text FROM latest_job) ||
         ';PHILIPS_MANUAL_JOB_STATUS=' || CASE (SELECT status FROM latest_job)
           WHEN 'queued' THEN 'queued'
           WHEN 'processing' THEN 'processing'
           WHEN 'delivered' THEN 'delivered'
           WHEN 'failed' THEN 'failed'
           WHEN 'dead_letter' THEN 'dead_letter'
           ELSE 'other'
         END ||
         ';PHILIPS_MANUAL_TRANSPORT=philips_non_dicom' ||
         ';AUTOMATIC_DISPATCH=absent' ||
         ';REMOTE_DELIVERY_EVIDENCE=' || CASE WHEN COALESCE((SELECT remote_reference FROM latest_job), '') = '' THEN 'absent' ELSE 'recorded' END ||
         ';DELIVERY_ERROR_CATEGORY=' || CASE
           WHEN lower(COALESCE((SELECT last_error FROM latest_job), '')) LIKE '%gateway_policy_rejected%' THEN 'gateway_policy_rejected'
           WHEN lower(COALESCE((SELECT last_error FROM latest_job), '')) LIKE '%gateway_unavailable%' THEN 'gateway_unavailable'
           WHEN lower(COALESCE((SELECT last_error FROM latest_job), '')) LIKE '%gateway_delivery_failed%' THEN 'gateway_delivery_failed'
           WHEN lower(COALESCE((SELECT last_error FROM latest_job), '')) LIKE '%credentials_unavailable%' THEN 'credentials_unavailable'
           WHEN lower(COALESCE((SELECT last_error FROM latest_job), '')) LIKE '%invalid_artifact%' THEN 'invalid_artifact'
           WHEN lower(COALESCE((SELECT last_error FROM latest_job), '')) LIKE '%remote_integrity_unconfirmed%' THEN 'remote_integrity_unconfirmed'
           WHEN COALESCE((SELECT last_error FROM latest_job), '') = '' THEN 'none'
           ELSE 'other_sanitized'
         END
  END;" 2>/dev/null || {
  echo 'MANUAL_DELIVERY_QUERY=unavailable'
  echo 'PHILIPS_MANUAL_DELIVERY_READONLY_OK'
  exit 0
}

echo 'PHILIPS_MANUAL_DELIVERY_READONLY_OK'
