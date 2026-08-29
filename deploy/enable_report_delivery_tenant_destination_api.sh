#!/usr/bin/env bash
# Ativa automação somente para novas liberações do destino tenant-scoped informado.
# Executar como root no host da API, após a bridge já estar ativa no gateway.
set -Eeuo pipefail
umask 077

TENANT_ID=${1:?Informe o tenant_id positivo}
DESTINATION_ID=${2:?Informe o destination_id positivo}
APP_ROOT=/var/www/voxelpacs/app
ENV_FILE="$APP_ROOT/.env"
WORKER_SERVICE=voxelpacs-report-delivery-worker.service
DATABASE=voxelpacs_homolog
SCHEMA=voxelpacs_mysql_source
BACKUP_DIR="/var/backups/voxelpacs/report-delivery-automation-$(date -u +%Y%m%dT%H%M%SZ)"

[[ "$TENANT_ID" =~ ^[1-9][0-9]*$ ]] || { echo 'tenant_id inválido' >&2; exit 2; }
[[ "$DESTINATION_ID" =~ ^[1-9][0-9]*$ ]] || { echo 'destination_id inválido' >&2; exit 2; }
[[ $EUID -eq 0 ]] || { echo 'Execução requer root' >&2; exit 2; }
test -r "$ENV_FILE"
test -x /usr/bin/php

systemctl disable --now "$WORKER_SERVICE" 2>/dev/null || true
install -d -o postgres -g postgres -m 0700 "$BACKUP_DIR"
cp -a "$ENV_FILE" "$BACKUP_DIR/app.env.pre-automation"
sudo -u postgres pg_dump --dbname="$DATABASE" --schema="$SCHEMA" \
  --table="$SCHEMA.pacs_report_delivery_destinations" --format=custom \
  --file="$BACKUP_DIR/destinations.pre-automation.dump"

sudo -u postgres psql --set=ON_ERROR_STOP=1 --dbname="$DATABASE" \
  --set=tenant_id="$TENANT_ID" --set=destination_id="$DESTINATION_ID" <<'SQL'
BEGIN;
LOCK TABLE voxelpacs_mysql_source.pacs_report_delivery_destinations IN SHARE ROW EXCLUSIVE MODE;

DO $activate$
DECLARE
    changed_rows INTEGER;
BEGIN
    UPDATE voxelpacs_mysql_source.pacs_report_delivery_destinations d
       SET enabled = 1,
           disparar_na_liberacao = 1,
           max_attempts = 1,
           configuration_json = jsonb_set(
               jsonb_set(
                   ((d.configuration_json::jsonb) - 'bridge_job_id'),
                   '{gateway_bridge_mode}', to_jsonb('tenant_destination'::text), true),
               '{bridge_url}', to_jsonb(format('https://10.0.0.4:9443/v1/report-delivery/tenant/%s/destination/%s', d.tenant_id, d.id)), true),
           updated_at = NOW()
     WHERE d.id = :'destination_id'::bigint
       AND d.tenant_id = :'tenant_id'::bigint
       AND d.ambiente::text = 'producao'
       AND d.servidor_pacs_id IS NOT NULL
       AND EXISTS (
           SELECT 1
           FROM voxelpacs_mysql_source.bi_negocio_servidor_pacs n
           WHERE n.tenant_id = d.tenant_id
             AND n.servidor_id = d.servidor_pacs_id
             AND n.ativo = 1
       )
       AND EXISTS (
           SELECT 1
           FROM voxelpacs_mysql_source.pacs_report_delivery_destination_issuers di
           WHERE di.destination_id = d.id
             AND di.tenant_id = d.tenant_id
       );

    GET DIAGNOSTICS changed_rows = ROW_COUNT;
    IF changed_rows <> 1 THEN
        RAISE EXCEPTION 'expected_one_eligible_tenant_destination, got=%', changed_rows;
    END IF;
END
$activate$;

COMMIT;
SQL

TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT
awk '!/^VOXEL_REPORT_DELIVERY_HUB_ENABLED=/' "$ENV_FILE" > "$TMP"
printf 'VOXEL_REPORT_DELIVERY_HUB_ENABLED=true\n' >> "$TMP"
install -o root -g root -m 0600 "$TMP" "$ENV_FILE"

sudo -u voxel bash -lc "cd '$APP_ROOT' && /usr/bin/php -l app/Repositories/ReportDeliveryWorkerRepository.php && VOXEL_REPORT_DELIVERY_CONTRACT_SCOPE=api /usr/bin/php tests/report_delivery_production_routing_contract.php && /usr/bin/php bin/report_delivery_worker.php --check"
systemctl daemon-reload
systemctl enable --now "$WORKER_SERVICE"
sleep 1
systemctl is-active --quiet "$WORKER_SERVICE"
printf 'AUTOMATION_API_ENABLED tenant=%s destination=%s backup=%s\n' "$TENANT_ID" "$DESTINATION_ID" "$BACKUP_DIR"
