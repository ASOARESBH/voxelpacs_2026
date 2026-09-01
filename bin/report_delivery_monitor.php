<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Logger;

require dirname(__DIR__) . '/app/bootstrap.php';

/**
 * Monitor local do Delivery Hub. Não executa claim, requeue, C-ECHO ou C-STORE.
 * A unidade/timer apenas produz observabilidade sanitizada para a operação.
 */
function scalar(PDO $pdo, string $sql): int
{
    $value = $pdo->query($sql)->fetchColumn();
    return (int) $value;
}

try {
    $pdo = Database::getInstance();
    $metrics = [
        'automatic_current_eligible' => scalar($pdo,
            "SELECT COUNT(*)
               FROM pacs_report_delivery_jobs j
               INNER JOIN pacs_report_delivery_destinations d ON d.id = j.destination_id AND d.tenant_id = j.tenant_id
              WHERE j.status IN ('queued', 'retrying')
                AND j.automatic_dispatch_date = CURRENT_DATE
                AND j.worker_eligible_at <= NOW()
                AND (j.next_attempt_at IS NULL OR j.next_attempt_at <= NOW())
                AND d.enabled = 1 AND d.disparar_na_liberacao = 1 AND d.ambiente = 'producao'"
        ),
        'manual_retry_queued' => scalar($pdo,
            "SELECT COUNT(*)
               FROM pacs_report_delivery_jobs
              WHERE status IN ('queued', 'retrying')
                AND automatic_dispatch_date IS NULL
                AND manual_retry_requested_at IS NOT NULL"
        ),
        'stale_processing' => scalar($pdo,
            "SELECT COUNT(*)
               FROM pacs_report_delivery_jobs
              WHERE status = 'processing'
                AND locked_at IS NOT NULL
                AND locked_at <= NOW() - INTERVAL '10 minutes'"
        ),
        'active_routes_noncompliant' => scalar($pdo,
            "SELECT COUNT(*)
               FROM pacs_report_delivery_destinations d
              WHERE d.enabled = 1 AND d.disparar_na_liberacao = 1
                AND d.ambiente = 'producao' AND d.transport = 'dicom_pdf'
                AND NOT (
                    (d.configuration_json::jsonb)->>'gateway_bridge_mode' = 'tenant_destination'
                    AND (d.configuration_json::jsonb)->>'bridge_tenant_id' ~ '^[0-9]+$'
                    AND (d.configuration_json::jsonb)->>'bridge_destination_id' ~ '^[0-9]+$'
                    AND ((d.configuration_json::jsonb)->>'bridge_tenant_id')::bigint = d.tenant_id
                    AND ((d.configuration_json::jsonb)->>'bridge_destination_id')::bigint = d.id
                )"
        ),
    ];

    Logger::info('[ReportDeliveryMonitor] Estado sanitizado da fila', $metrics);
    fwrite(STDOUT, 'report_delivery_monitor_ok' . PHP_EOL);
    exit($metrics['active_routes_noncompliant'] > 0 ? 2 : 0);
} catch (Throwable $error) {
    Logger::error('[ReportDeliveryMonitor] Falha na observabilidade', ['error' => $error->getMessage()]);
    fwrite(STDERR, "report_delivery_monitor_failed\n");
    exit(3);
}
