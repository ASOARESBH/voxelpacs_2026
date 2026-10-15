<?php

declare(strict_types=1);

use App\Config\ReportDeliveryRuntimeConfig;
use App\Core\Database;
use App\Services\ReportDeliveryClock;
use App\Services\ReportDeliveryOneShotEligibility;

require dirname(__DIR__) . '/app/bootstrap.php';

$jobId = 0;
$expected = 'ELIGIBLE';
$allowOtherEligible = false;
$verifyOtherEligible = false;
$otherEligibleIds = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/\A--job-id=([1-9][0-9]*)\z/', $argument, $match) === 1) {
        $jobId = (int) $match[1];
    }
    if (preg_match('/\A--expect=(ELIGIBLE|INELIGIBLE)\z/', $argument, $match) === 1) {
        $expected = $match[1];
    }
    if (preg_match('/\A--allow-other-eligible=([^\s]+)\z/', $argument, $match) === 1) {
        $allowOtherEligible = true;
        try {
            $otherEligibleIds = ReportDeliveryOneShotEligibility::parseExplicitIds($match[1]);
        } catch (\Throwable) {
            fwrite(STDERR, "PREFLIGHT_RESULT=FAIL\nPREFLIGHT_REASON=invalid_allow_other_eligible\n");
            exit(4);
        }
    }
    if (preg_match('/\A--verify-other-eligible=([^\s]+)\z/', $argument, $match) === 1) {
        $verifyOtherEligible = true;
        try {
            $otherEligibleIds = ReportDeliveryOneShotEligibility::parseExplicitIds($match[1]);
        } catch (\Throwable) {
            fwrite(STDERR, "PREFLIGHT_RESULT=FAIL\nPREFLIGHT_REASON=invalid_verify_other_eligible\n");
            exit(4);
        }
    }
}

if ($jobId <= 0) {
    fwrite(STDERR, "PREFLIGHT_RESULT=FAIL\nPREFLIGHT_REASON=invalid_job_id\n");
    exit(4);
}

try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();
    $pdo->exec('SET TRANSACTION READ ONLY');

    $clinicalDate = ReportDeliveryClock::currentClinicalDate($pdo);
    $targetStmt = $pdo->prepare(
        "SELECT j.id, j.tenant_id, j.destination_id, j.transport,
                j.status, j.automatic_dispatch_date, j.worker_eligible_at,
                j.next_attempt_at, j.locked_at, j.attempt_count,
                o.delivery_request_id
           FROM pacs_report_delivery_jobs j
           INNER JOIN pacs_report_delivery_outbox o
                   ON o.id = j.outbox_id AND o.tenant_id = j.tenant_id
          WHERE j.id = :job_id
          LIMIT 1"
    );
    $targetStmt->execute([':job_id' => $jobId]);
    $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($target)) {
        $pdo->rollBack();
        fwrite(STDERR, "PREFLIGHT_RESULT=FAIL\nPREFLIGHT_REASON=job_not_found\nJOB_ID={$jobId}\n");
        exit(4);
    }

    if ($verifyOtherEligible) {
        $placeholders = [];
        $parameters = [
            ':tenant_id' => (int) $target['tenant_id'],
            ':destination_id' => (int) $target['destination_id'],
            ':transport' => (string) $target['transport'],
        ];
        foreach ($otherEligibleIds as $index => $otherId) {
            $placeholder = ':other_id_' . $index;
            $placeholders[] = $placeholder;
            $parameters[$placeholder] = $otherId;
        }
        $verifiedIds = [];
        $unchanged = $otherEligibleIds !== [];
        if ($placeholders !== []) {
            $verifyStmt = $pdo->prepare(
                "SELECT id, status, attempt_count, locked_at
                   FROM pacs_report_delivery_jobs
                  WHERE tenant_id = :tenant_id
                    AND destination_id = :destination_id
                    AND transport = :transport
                    AND id IN (" . implode(', ', $placeholders) . ")"
            );
            $verifyStmt->execute($parameters);
            while ($row = $verifyStmt->fetch(PDO::FETCH_ASSOC)) {
                $verifiedIds[] = (int) $row['id'];
                if ((string) $row['status'] !== 'queued'
                    || (int) $row['attempt_count'] !== 0
                    || $row['locked_at'] !== null) {
                    $unchanged = false;
                }
            }
        }
        sort($verifiedIds, SORT_NUMERIC);
        $unchanged = $unchanged
            && ReportDeliveryOneShotEligibility::exactMatch($verifiedIds, $otherEligibleIds);
        $pdo->rollBack();
        fwrite(STDOUT, 'POSTCHECK_RESULT=' . ($unchanged ? 'PASS' : 'FAIL') . "\n");
        fwrite(STDOUT, 'OTHER_JOBS_UNCHANGED=' . ($unchanged ? 'YES' : 'NO') . "\n");
        fwrite(STDOUT, 'EXPECTED_OTHER_ELIGIBLE_IDS=[' . implode(',', $otherEligibleIds) . "]\n");
        fwrite(STDOUT, 'VERIFIED_OTHER_IDS=[' . implode(',', $verifiedIds) . "]\n");
        fwrite(STDOUT, "DB_READ_ONLY=PASS\n");
        exit($unchanged ? 0 : 4);
    }

    $requestsEnabled = ReportDeliveryRuntimeConfig::requestsEnabled();
    $requestJoin = $requestsEnabled
        ? "LEFT JOIN pacs_report_delivery_requests dr
                    ON dr.id = o.delivery_request_id AND dr.tenant_id = j.tenant_id"
        : '';
    $requestWhere = $requestsEnabled
        ? " AND (o.delivery_request_id IS NULL OR dr.status = 'armed')"
        : '';
    $baseWhere = "j.status IN ('queued', 'retrying')
        AND (j.next_attempt_at IS NULL OR j.next_attempt_at <= NOW())
        AND j.worker_eligible_at IS NOT NULL
        AND j.worker_eligible_at <= NOW()
        AND (j.automatic_dispatch_date IS NULL OR j.automatic_dispatch_date = :automatic_today)
        AND (o.delivery_request_id IS NULL OR (d.enabled = 1 AND d.ambiente IN ('homologacao', 'producao')))
        AND j.tenant_id = :tenant_id
        AND j.destination_id = :destination_id";

    $targetCount = $pdo->prepare(
        "SELECT COUNT(*)
           FROM pacs_report_delivery_jobs j
           INNER JOIN pacs_report_delivery_outbox o
                   ON o.id = j.outbox_id AND o.tenant_id = j.tenant_id
           LEFT JOIN pacs_report_delivery_destinations d
                   ON d.id = j.destination_id AND d.tenant_id = j.tenant_id
           {$requestJoin}
          WHERE {$baseWhere}
            AND j.id = :target_job_id{$requestWhere}"
    );
    $targetCount->execute([
        ':automatic_today' => $clinicalDate,
        ':tenant_id' => (int) $target['tenant_id'],
        ':destination_id' => (int) $target['destination_id'],
        ':target_job_id' => $jobId,
    ]);
    $targetEligible = (int) $targetCount->fetchColumn() === 1;

    $otherStmt = $pdo->prepare(
        "SELECT COUNT(*)
           FROM pacs_report_delivery_jobs j
           INNER JOIN pacs_report_delivery_outbox o
                   ON o.id = j.outbox_id AND o.tenant_id = j.tenant_id
           LEFT JOIN pacs_report_delivery_destinations d
                   ON d.id = j.destination_id AND d.tenant_id = j.tenant_id
           {$requestJoin}
          WHERE {$baseWhere}
            AND j.id <> :target_job_id{$requestWhere}"
    );
    $otherStmt->execute([
        ':automatic_today' => $clinicalDate,
        ':tenant_id' => (int) $target['tenant_id'],
        ':destination_id' => (int) $target['destination_id'],
        ':target_job_id' => $jobId,
    ]);
    $otherEligible = (int) $otherStmt->fetchColumn();
    $otherIdsStmt = $pdo->prepare(
        "SELECT j.id
           FROM pacs_report_delivery_jobs j
           INNER JOIN pacs_report_delivery_outbox o
                   ON o.id = j.outbox_id AND o.tenant_id = j.tenant_id
           LEFT JOIN pacs_report_delivery_destinations d
                   ON d.id = j.destination_id AND d.tenant_id = j.tenant_id
           {$requestJoin}
          WHERE {$baseWhere}
            AND j.id <> :target_job_id{$requestWhere}
          ORDER BY j.id"
    );
    $otherIdsStmt->execute([
        ':automatic_today' => $clinicalDate,
        ':tenant_id' => (int) $target['tenant_id'],
        ':destination_id' => (int) $target['destination_id'],
        ':target_job_id' => $jobId,
    ]);
    $actualOtherIds = [];
    while ($row = $otherIdsStmt->fetch(PDO::FETCH_ASSOC)) {
        $actualOtherIds[] = (int) $row['id'];
    }
    $eligibleCount = ($targetEligible ? 1 : 0) + $otherEligible;
    $killSwitchEnabled = ReportDeliveryRuntimeConfig::workerKillSwitchEnabled();
    $expectedPass = $expected === 'ELIGIBLE'
        ? $targetEligible
            && ($allowOtherEligible
                ? ReportDeliveryOneShotEligibility::exactMatch($actualOtherIds, $otherEligibleIds)
                : $otherEligible === 0)
            && !$killSwitchEnabled
        : !$targetEligible && $otherEligible === 0 && !$killSwitchEnabled;

    $pdo->rollBack();
    $killSwitch = $killSwitchEnabled ? 'ON' : 'OFF';
    fwrite(STDOUT, 'PREFLIGHT_RESULT=' . ($expectedPass ? 'PASS' : 'FAIL') . "\n");
    fwrite(STDOUT, "PREFLIGHT_EXPECTED={$expected}\n");
    fwrite(STDOUT, "JOB_ID={$jobId}\n");
    fwrite(STDOUT, 'TARGET_GATE=' . ($targetEligible ? 'TRUE' : 'FALSE') . "\n");
    fwrite(STDOUT, 'ALLOW_OTHER_ELIGIBLE=' . ($allowOtherEligible ? 'YES' : 'NO') . "\n");
    fwrite(STDOUT, 'EXPECTED_OTHER_ELIGIBLE_IDS=[' . implode(',', $otherEligibleIds) . "]\n");
    fwrite(STDOUT, 'OTHER_ELIGIBLE_IDS=[' . implode(',', $actualOtherIds) . "]\n");
    fwrite(STDOUT, "ELIGIBLE_COUNT={$eligibleCount}\n");
    fwrite(STDOUT, "OTHER_ELIGIBLE_COUNT={$otherEligible}\n");
    fwrite(STDOUT, "WORKER_KILL_SWITCH={$killSwitch}\n");
    fwrite(STDOUT, "CLINICAL_DATE_SOURCE=DB_AT_TIME_ZONE\n");
    fwrite(STDOUT, "DB_READ_ONLY=PASS\n");
    if ($killSwitchEnabled) {
        fwrite(STDERR, "PREFLIGHT_REASON=kill_switch\n");
    } elseif (!$targetEligible) {
        fwrite(STDERR, "PREFLIGHT_REASON=target_not_eligible\n");
    } elseif ($allowOtherEligible && !ReportDeliveryOneShotEligibility::exactMatch($actualOtherIds, $otherEligibleIds)) {
        fwrite(STDERR, "PREFLIGHT_REASON=other_eligible_mismatch\n");
    } elseif (!$allowOtherEligible && $otherEligible > 0) {
        fwrite(STDERR, "PREFLIGHT_REASON=other_eligible_jobs\n");
    }
    exit($expectedPass ? 0 : 4);
} catch (\Throwable $error) {
    if (isset($pdo) && $pdo instanceof \PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "PREFLIGHT_RESULT=FAIL\nPREFLIGHT_REASON=bootstrap_or_readonly_query\nDB_READ_ONLY=UNKNOWN\n");
    exit(4);
}
