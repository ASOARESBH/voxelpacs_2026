#!/usr/bin/env bash
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
assert_marker() {
  local file=$1
  local marker=$2
  grep -Fq -- "$marker" "$root/$file" || {
    printf 'missing_contract_marker:%s\n' "$file" >&2
    exit 1
  }
}

assert_marker app/Repositories/ReportDeliveryRepository.php 'manual_retry_requested_at'
assert_marker app/Repositories/ReportDeliveryRepository.php 'manual_retry_requested_by'
assert_marker app/Repositories/ReportDeliveryRepository.php 'manual_retry_count'
assert_marker app/Repositories/ReportDeliveryRepository.php "COALESCE(j.manual_retry_count, 0) < 3"
assert_marker app/Repositories/ReportDeliveryRepository.php "'gateway_bridge_mode' = 'tenant_destination'"
assert_marker app/Repositories/ReportDeliveryWorkerRepository.php 'j.manual_retry_requested_at IS NOT NULL'
assert_marker app/Repositories/ReportDeliveryWorkerRepository.php 'boundProductionBridgeWhere'
assert_marker app/Services/ReportDeliveryGatewayBridgeClient.php 'X-VOXEL-Attempt-Number'
assert_marker app/Services/ReportDeliveryGatewayBridgeClient.php 'c_echo'
assert_marker app/Services/ReportDeliveryGatewayBridgeFailure.php 'final class ReportDeliveryGatewayBridgeFailure'
assert_marker bin/report_delivery_worker.php "'c_echo' => \$result['c_echo']"
assert_marker bin/report_delivery_worker.php "'c_store' => \$result['c_store']"
assert_marker bin/report_delivery_monitor.php 'Não executa claim, requeue, C-ECHO ou C-STORE.'
assert_marker deploy/report-delivery-gateway-bridge/bridge_server.py 'X-VOXEL-Attempt-Number'
assert_marker deploy/report-delivery-gateway-bridge/bridge_server.py 'attempted-job-{job_id}-attempt-{attempt_number}.json'
assert_marker deploy/voxelpacs-report-delivery-monitor.timer 'OnCalendar=*-*-* *:*:00'

printf 'report_delivery_manual_retry_monitor_contract_ok\n'
