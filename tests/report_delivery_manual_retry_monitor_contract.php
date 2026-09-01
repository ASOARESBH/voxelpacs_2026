<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$contracts = [
    'app/Repositories/ReportDeliveryRepository.php' => [
        'manual_retry_requested_at',
        'manual_retry_requested_by',
        'manual_retry_count',
        "COALESCE(j.manual_retry_count, 0) < 3",
        "'Reenvio manual autorizado; aguardando worker.'",
        "'gateway_bridge_mode' = 'tenant_destination'",
        'e.servidor_id = d.servidor_pacs_id',
        'report_pdf_snapshots',
    ],
    'app/Repositories/ReportDeliveryWorkerRepository.php' => [
        'j.manual_retry_requested_at IS NOT NULL',
        'boundProductionBridgeWhere',
        "'gateway_bridge_mode' = 'tenant_destination'",
    ],
    'app/Services/ReportDeliveryGatewayBridgeClient.php' => [
        'X-VOXEL-Attempt-Number',
        'c_echo',
        'c_store',
        'ReportDeliveryGatewayBridgeFailure',
    ],
    'app/Services/ReportDeliveryGatewayBridgeFailure.php' => [
        'final class ReportDeliveryGatewayBridgeFailure',
        'public readonly array $metadata',
    ],
    'bin/report_delivery_worker.php' => [
        "'c_echo' => \$result['c_echo']",
        "'c_store' => \$result['c_store']",
        "['bridge' => 'not_reached', 'c_echo' => 'not_attempted', 'c_store' => 'not_attempted']",
    ],
    'bin/report_delivery_monitor.php' => [
        'Monitor local do Delivery Hub. Não executa claim, requeue, C-ECHO ou C-STORE.',
        'automatic_current_eligible',
        'manual_retry_queued',
        'active_routes_noncompliant',
    ],
    'deploy/report-delivery-gateway-bridge/bridge_server.py' => [
        'X-VOXEL-Attempt-Number',
        'attempt_number_int',
        'attempted-job-{job_id}-attempt-{attempt_number}.json',
        '"c_echo": c_echo',
        '"c_store": c_store',
    ],
    'deploy/voxelpacs-report-delivery-monitor.service' => [
        'Type=oneshot',
        'report_delivery_monitor.php',
        'NoNewPrivileges=true',
    ],
    'deploy/voxelpacs-report-delivery-monitor.timer' => [
        'OnCalendar=*-*-* *:*:00',
        'Persistent=true',
    ],
];

foreach ($contracts as $relative => $needles) {
    $content = file_get_contents($root . '/' . $relative);
    if (!is_string($content)) {
        throw new RuntimeException('missing_contract_file:' . $relative);
    }
    foreach ($needles as $needle) {
        if (!str_contains($content, $needle)) {
            throw new RuntimeException('missing_contract_marker:' . $relative);
        }
    }
}

fwrite(STDOUT, "report_delivery_manual_retry_monitor_contract_ok\n");
