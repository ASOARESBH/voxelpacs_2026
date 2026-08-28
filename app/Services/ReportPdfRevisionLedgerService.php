<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Cadeia de custódia interna para PDFs já congelados em report_pdf_snapshots.
 * Não gera binário, não cria outbox e não interage com o worker de devolutiva.
 */
final class ReportPdfRevisionLedgerService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{storage_path:string,sha256:string,size:int,created:bool} $snapshot
     */
    public function recordReleasedVersion(
        int $tenantId,
        int $estudoId,
        int $reportId,
        int $reportVersion,
        array $snapshot,
        ?object $peerReview,
        string $releasedAt
    ): void {
        if ($tenantId <= 0 || $estudoId <= 0 || $reportId <= 0 || $reportVersion <= 0) {
            throw new RuntimeException('Contexto inválido para o histórico PDF do laudo.');
        }
        $hash = strtolower(trim((string) ($snapshot['sha256'] ?? '')));
        $size = (int) ($snapshot['size'] ?? 0);
        if (!preg_match('/^[a-f0-9]{64}$/', $hash) || $size <= 0) {
            throw new RuntimeException('Snapshot inválido para o histórico PDF do laudo.');
        }

        $reviewId = $peerReview ? (int) ($peerReview->id ?? 0) : 0;
        $reviewCycle = $peerReview ? (int) ($peerReview->ciclo ?? 0) : 0;
        $kind = $reviewId > 0 && $reviewCycle > 0 ? 'revision' : 'original';
        $revisionNumber = $kind === 'revision' ? $reviewCycle : 0;

        $stmt = $this->pdo->prepare(
            "INSERT INTO report_pdf_revision_ledger
                (tenant_id, estudo_id, report_id, report_version, revision_kind, revision_number,
                 peer_review_id, peer_review_cycle, snapshot_sha256, snapshot_size_bytes, released_at)
             VALUES
                (:tenant_id, :estudo_id, :report_id, :report_version, :revision_kind, :revision_number,
                 :peer_review_id, :peer_review_cycle, :snapshot_sha256, :snapshot_size_bytes, :released_at)
             ON CONFLICT (tenant_id, report_id, report_version)
             DO UPDATE SET
                estudo_id = EXCLUDED.estudo_id,
                revision_kind = EXCLUDED.revision_kind,
                revision_number = EXCLUDED.revision_number,
                peer_review_id = EXCLUDED.peer_review_id,
                peer_review_cycle = EXCLUDED.peer_review_cycle,
                snapshot_sha256 = EXCLUDED.snapshot_sha256,
                snapshot_size_bytes = EXCLUDED.snapshot_size_bytes,
                released_at = EXCLUDED.released_at"
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':estudo_id', $estudoId, PDO::PARAM_INT);
        $stmt->bindValue(':report_id', $reportId, PDO::PARAM_INT);
        $stmt->bindValue(':report_version', $reportVersion, PDO::PARAM_INT);
        $stmt->bindValue(':revision_kind', $kind, PDO::PARAM_STR);
        $stmt->bindValue(':revision_number', $revisionNumber, PDO::PARAM_INT);
        if ($kind === 'revision') {
            $stmt->bindValue(':peer_review_id', $reviewId, PDO::PARAM_INT);
            $stmt->bindValue(':peer_review_cycle', $reviewCycle, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':peer_review_id', null, PDO::PARAM_NULL);
            $stmt->bindValue(':peer_review_cycle', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':snapshot_sha256', $hash, PDO::PARAM_STR);
        $stmt->bindValue(':snapshot_size_bytes', $size, PDO::PARAM_INT);
        $stmt->bindValue(':released_at', $releasedAt, PDO::PARAM_STR);
        $stmt->execute();
    }
}
