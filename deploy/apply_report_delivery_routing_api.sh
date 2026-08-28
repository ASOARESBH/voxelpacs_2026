#!/usr/bin/env bash
# Implantação cirúrgica do vínculo tenant/PACS para o Delivery Hub.
# Uso: sudo bash apply_report_delivery_routing_api.sh /caminho/release-api.tar.gz /caminho/release-api.sha256
set -Eeuo pipefail
umask 077

APP_ROOT=/var/www/voxelpacs/app
DB_NAME=voxelpacs_homolog
SCHEMA=voxelpacs_mysql_source
WORKER_UNIT=voxelpacs-report-delivery-worker.service
RELEASE_TAR=${1:?Informe o arquivo release-api.tar.gz}
RELEASE_SHA=${2:?Informe o arquivo release-api.sha256}
RELEASE_ID="report-delivery-routing-$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP_ROOT="/var/backups/voxelpacs/${RELEASE_ID}"
STAGE="/var/tmp/${RELEASE_ID}"

required_files=(
  app/Controllers/Platform/ReportDeliveryController.php
  app/Repositories/ReportDeliveryRepository.php
  app/Repositories/ReportDeliveryWorkerRepository.php
  app/Services/ReportDeliveryGatewayBridgeClient.php
  app/Services/ReportDeliveryOutboxService.php
  app/Views/platform/negocios/report_delivery.php
  bin/report_delivery_worker.php
  database/migrations/2026-08-28_report_delivery_destination_server_binding_postgresql.sql
  deploy/report-delivery-gateway-bridge/README.md
  lang/en.php
  lang/es.php
  lang/pt_BR.php
  tests/report_delivery_production_routing_contract.php
)

require_root() {
  if [ "$(id -u)" -ne 0 ]; then
    echo 'Este procedimento deve ser executado como root.' >&2
    exit 1
  fi
}

cleanup() {
  rm -rf "$STAGE"
}
trap cleanup EXIT

require_root
[ -d "$APP_ROOT" ] || { echo "Runtime não encontrado: $APP_ROOT" >&2; exit 1; }
[ -f "$RELEASE_TAR" ] || { echo 'Arquivo de release ausente.' >&2; exit 1; }
[ -f "$RELEASE_SHA" ] || { echo 'Manifesto SHA-256 ausente.' >&2; exit 1; }

# A segurança operacional prevalece mesmo em caso de reboot durante a implantação.
systemctl disable --now "$WORKER_UNIT"

install -d -m 0750 "$BACKUP_ROOT/files" "$STAGE"
install -d -o postgres -g postgres -m 0700 "$BACKUP_ROOT/database"

# A soma é verificada antes de extrair e nenhum git é executado no runtime.
(
  cd "$(dirname "$RELEASE_TAR")"
  sha256sum -c "$RELEASE_SHA"
)
tar -xzf "$RELEASE_TAR" -C "$STAGE"

for relative_path in "${required_files[@]}"; do
  [ -f "$STAGE/$relative_path" ] || { echo "Release incompleto: $relative_path" >&2; exit 1; }
  if [ -e "$APP_ROOT/$relative_path" ]; then
    (
      cd "$APP_ROOT"
      cp -a --parents "$relative_path" "$BACKUP_ROOT/files"
    )
  fi
done

# O backup de configuração do destino fica root/postgres-only e não é copiado para a estação de trabalho.
sudo -u postgres pg_dump --dbname="$DB_NAME" --schema="$SCHEMA" \
  --table="$SCHEMA.pacs_report_delivery_destinations" \
  --format=custom --file="$BACKUP_ROOT/database/pacs_report_delivery_destinations.pre-migration.dump"

# Migration aditiva e transacionada. O código antigo é compatível com a coluna nova.
sudo -u postgres psql --set=ON_ERROR_STOP=1 --dbname="$DB_NAME" \
  --file="$STAGE/database/migrations/2026-08-28_report_delivery_destination_server_binding_postgresql.sql"

has_binding=$(sudo -u postgres psql --tuples-only --no-align --dbname="$DB_NAME" -c \
  "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='$SCHEMA' AND table_name='pacs_report_delivery_destinations' AND column_name='servidor_pacs_id');")
[ "$has_binding" = 't' ] || { echo 'A migration não criou o vínculo de servidor PACS.' >&2; exit 1; }

for relative_path in "${required_files[@]}"; do
  target="$APP_ROOT/$relative_path"
  source="$STAGE/$relative_path"
  if [ -e "$target" ]; then
    owner=$(stat -c '%u:%g' "$target")
    mode=$(stat -c '%a' "$target")
  elif [[ "$relative_path" == bin/* ]]; then
    owner='root:root'
    mode='0644'
  else
    owner='voxel:voxel'
    mode='0640'
  fi
  install -D -m "$mode" "$source" "$target.new"
  chown "$owner" "$target.new"
  mv -f "$target.new" "$target"
done

# Validações não transmitem DICOM, não leem snapshots e não acionam filas.
for php_file in \
  app/Controllers/Platform/ReportDeliveryController.php \
  app/Repositories/ReportDeliveryRepository.php \
  app/Repositories/ReportDeliveryWorkerRepository.php \
  app/Services/ReportDeliveryGatewayBridgeClient.php \
  app/Services/ReportDeliveryOutboxService.php \
  app/Views/platform/negocios/report_delivery.php \
  bin/report_delivery_worker.php \
  lang/pt_BR.php lang/en.php lang/es.php; do
  sudo -u voxel /usr/bin/php -l "$APP_ROOT/$php_file" >/dev/null
done
sudo -u voxel /usr/bin/php "$APP_ROOT/tests/report_delivery_production_routing_contract.php" >/dev/null

# Código PHP pode estar em OPcache; reload gracioso não reinicia Nginx nem habilita o worker.
if systemctl is-active --quiet php8.3-fpm.service; then
  systemctl reload php8.3-fpm.service
fi
systemctl disable --now "$WORKER_UNIT"

printf 'API_RELEASE_OK backup=%s worker=disabled migration=servidor_pacs_id\n' "$BACKUP_ROOT"
