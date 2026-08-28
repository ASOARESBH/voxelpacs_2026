<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\ReportPdfRevisionLedgerService;

$pdo = Database::getInstance();
$tenantId = 987654321;
$reportId = 987654322;
$studyId = 987654323;
$hashOriginal = str_repeat('a', 64);
$hashRevision = str_repeat('b', 64);

try {
    $pdo->beginTransaction();

    $insertSnapshot = $pdo->prepare(
        'INSERT INTO report_pdf_snapshots
            (tenant_id, report_id, report_version, estabelecimento_id, storage_path, sha256, file_size_bytes)
         VALUES
            (:tenant_id, :report_id, :report_version, NULL, :storage_path, :sha256, :file_size_bytes)'
    );
    $insertSnapshot->execute([
        ':tenant_id' => $tenantId,
        ':report_id' => $reportId,
        ':report_version' => 1,
        ':storage_path' => '/tmp/synthetic-original.pdf',
        ':sha256' => $hashOriginal,
        ':file_size_bytes' => 1024,
    ]);

    $ledger = new ReportPdfRevisionLedgerService($pdo);
    $ledger->recordReleasedVersion($tenantId, $studyId, $reportId, 1, [
        'storage_path' => '/tmp/synthetic-original.pdf',
        'sha256' => $hashOriginal,
        'size' => 1024,
        'created' => true,
    ], null, '2026-08-28 12:00:00+00');

    $insertSnapshot->execute([
        ':tenant_id' => $tenantId,
        ':report_id' => $reportId,
        ':report_version' => 2,
        ':storage_path' => '/tmp/synthetic-revision.pdf',
        ':sha256' => $hashRevision,
        ':file_size_bytes' => 2048,
    ]);
    $peerReview = (object) ['id' => 123456789, 'ciclo' => 1];
    $ledger->recordReleasedVersion($tenantId, $studyId, $reportId, 2, [
        'storage_path' => '/tmp/synthetic-revision.pdf',
        'sha256' => $hashRevision,
        'size' => 2048,
        'created' => true,
    ], $peerReview, '2026-08-28 12:01:00+00');

    $rows = $pdo->prepare(
        'SELECT revision_kind, revision_number, peer_review_cycle
         FROM report_pdf_revision_ledger
         WHERE tenant_id = :tenant_id AND report_id = :report_id
         ORDER BY report_version'
    );
    $rows->execute([':tenant_id' => $tenantId, ':report_id' => $reportId]);
    $actual = $rows->fetchAll(PDO::FETCH_ASSOC);
    $expected = [
        ['revision_kind' => 'original', 'revision_number' => 0, 'peer_review_cycle' => null],
        ['revision_kind' => 'revision', 'revision_number' => 1, 'peer_review_cycle' => 1],
    ];
    if ($actual !== $expected) {
        throw new RuntimeException('ledger_contract_invalid');
    }

    $pdo->rollBack();
    fwrite(STDOUT, "ledger_runtime_ok original=1 revision=1 persisted=0\n");
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'ledger_runtime_error=' . get_class($error) . ':' . $error->getMessage() . "\n");
    exit(1);
}
