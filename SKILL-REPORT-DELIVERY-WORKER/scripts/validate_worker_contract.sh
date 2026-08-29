#!/usr/bin/env sh
set -eu

REPOSITORY=${1:?Uso: validate_worker_contract.sh /caminho/para/clone}
cd "$REPOSITORY"

php -l app/bootstrap.php
php -l app/Repositories/ReportDeliveryWorkerRepository.php
php -l app/Services/ReportDeliveryOutboxService.php
php -l app/Services/ReportDeliveryGatewayBridgeClient.php
php -l bin/report_delivery_worker.php
python3 -m py_compile deploy/report-delivery-gateway-bridge/bridge_server.py
bash -n deploy/enable_report_delivery_tenant_destination_api.sh
bash -n deploy/disable_report_delivery_automation_api.sh
bash -n deploy/report-delivery-gateway-bridge/enable_tenant_destination_automation.sh
bash -n deploy/report-delivery-gateway-bridge/disable_automation.sh
php tests/report_delivery_production_routing_contract.php

echo "report_delivery_worker_contract=ok"
