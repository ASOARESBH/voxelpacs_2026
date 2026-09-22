#!/usr/bin/env bash
# Diagnóstico fechado e somente leitura do control-plane Philips SMB.
# Não chama o endpoint, não cria job/outbox, não abre conexão de rede e não altera banco ou runtime.
set -euo pipefail

if [[ "${EUID}" -ne 0 || "$#" -ne 0 ]]; then
  printf 'Uso não permitido. Execute localmente como root, sem argumentos.\n' >&2
  exit 64
fi

readonly APP='/var/www/voxelpacs/app'
readonly ENV_FILE="$APP/.env"

env_value() {
  local key="$1"
  [[ -r "$ENV_FILE" ]] || return 0
  sed -nE "s/^[[:space:]]*${key}=(.*)$/\1/p" "$ENV_FILE" | tail -n 1 | sed -E "s/^['\"](.*)['\"]$/\1/"
}

set_state() {
  [[ -n "$1" ]] && printf 'set' || printf 'absent'
}

db_audit_state() {
  local db schema query result
  db="$(env_value DB_DATABASE)"
  [[ -n "$db" ]] || { printf 'not_available'; return; }
  schema="$(sudo -u postgres psql -At -d "$db" -c "SELECT table_schema FROM information_schema.tables WHERE table_name='bi_audit_logs' AND table_type='BASE TABLE' ORDER BY CASE WHEN table_schema='public' THEN 2 ELSE 1 END, table_schema LIMIT 1;" 2>/dev/null || true)"
  [[ "$schema" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || { printf 'not_available'; return; }
  query="WITH e AS (SELECT action, COALESCE(details::text,'') AS details FROM \"$schema\".bi_audit_logs WHERE action IN ('report_delivery.smb_connectivity_tested','report_delivery.smb_connectivity_failed') ORDER BY created_at DESC LIMIT 1) SELECT COALESCE((SELECT CASE WHEN action='report_delivery.smb_connectivity_tested' THEN 'success:none' WHEN details LIKE '%connectivity%' THEN 'failed:connectivity' WHEN details LIKE '%timeout%' THEN 'failed:timeout' WHEN details LIKE '%authentication%' THEN 'failed:authentication' WHEN details LIKE '%permission%' THEN 'failed:permission' WHEN details LIKE '%credentials_unavailable%' THEN 'failed:credentials_unavailable' WHEN details LIKE '%configuration%' THEN 'failed:configuration' ELSE 'failed:other_sanitized' END FROM e),'no_event');"
  result="$(sudo -u postgres psql -At -d "$db" -c "$query" 2>/dev/null || true)"
  case "$result" in success:none|failed:connectivity|failed:timeout|failed:authentication|failed:permission|failed:credentials_unavailable|failed:configuration|failed:other_sanitized|no_event) printf '%s' "$result" ;; *) printf 'not_available' ;; esac
}

db_destination_state() {
  local db schema query result
  db="$(env_value DB_DATABASE)"
  [[ -n "$db" ]] || { printf 'not_available'; return; }
  schema="$(sudo -u postgres psql -At -d "$db" -c "SELECT table_schema FROM information_schema.tables WHERE table_name='pacs_report_delivery_destinations' AND table_type='BASE TABLE' ORDER BY CASE WHEN table_schema='public' THEN 2 ELSE 1 END, table_schema LIMIT 1;" 2>/dev/null || true)"
  [[ "$schema" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || { printf 'not_available'; return; }
  query="SELECT COALESCE((SELECT CASE WHEN enabled AND ambiente='homologacao' AND NOT disparar_na_liberacao AND configuration_secret IS NOT NULL AND configuration_secret<>'' AND configuration_json::text LIKE '%\"host\"%' AND configuration_json::text LIKE '%\"share\"%' AND configuration_json::text LIKE '%\"username\"%' THEN 'ready' ELSE 'incomplete_or_policy_mismatch' END FROM \"$schema\".pacs_report_delivery_destinations WHERE transport='philips_non_dicom' ORDER BY id DESC LIMIT 1),'not_found');"
  result="$(sudo -u postgres psql -At -d "$db" -c "$query" 2>/dev/null || true)"
  case "$result" in ready|incomplete_or_policy_mismatch|not_found) printf '%s' "$result" ;; *) printf 'not_available' ;; esac
}

http_state() {
  local result
  result="$(grep -hE '/report-delivery/destinations/[0-9]+/test-smb' /var/log/nginx/*.log 2>/dev/null | awk '{status=$9} END {print status}' || true)"
  case "$result" in 200|400|401|403|404|405|419|422|500|502|503|504) printf '%s' "$result" ;; '') printf 'no_record' ;; *) printf 'other' ;; esac
}

printf '%s\n' '=== PHILIPS_SMB_PACS_READONLY ==='
printf 'DIAGNOSTIC_SCHEMA=%s\n' '1'
printf 'CONTROLLER_SERVICE_NAMESPACE=%s\n' "$(grep -Fqx 'namespace App\Services;' "$APP/app/Services/PhilipsFolderSmbConnectivityService.php" 2>/dev/null && printf 'ready' || printf 'not_confirmed')"
printf 'CONTROLLER_IMPORT=%s\n' "$(grep -Fq 'use App\Services\PhilipsFolderSmbConnectivityService;' "$APP/app/Controllers/Platform/ReportDeliveryController.php" 2>/dev/null && printf 'ready' || printf 'not_confirmed')"
printf 'BRIDGE_BASE_URL=%s\n' "$(set_state "$(env_value PHILIPS_FOLDER_BRIDGE_BASE_URL)")"
printf 'BRIDGE_HMAC=%s\n' "$(set_state "$(env_value PHILIPS_FOLDER_BRIDGE_HMAC)")"
printf 'BRIDGE_MTLS_CA=%s\n' "$(set_state "$(env_value PHILIPS_FOLDER_BRIDGE_CA_FILE)")"
printf 'BRIDGE_MTLS_CERT=%s\n' "$(set_state "$(env_value PHILIPS_FOLDER_BRIDGE_CERT_FILE)")"
printf 'BRIDGE_MTLS_KEY=%s\n' "$(set_state "$(env_value PHILIPS_FOLDER_BRIDGE_KEY_FILE)")"
printf 'ENVELOPE_PUBLIC_KEY=%s\n' "$(set_state "$(env_value PHILIPS_NON_DICOM_GATEWAY_ENVELOPE_PUBLIC_KEY_FILE)")"
printf 'DESTINATION_POLICY=%s\n' "$(db_destination_state)"
printf 'LAST_AUDIT=%s\n' "$(db_audit_state)"
printf 'LAST_HTTP_STATUS=%s\n' "$(http_state)"
printf '%s\n' 'PHILIPS_SMB_PACS_READONLY_OK'
