<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\ReportDeliveryWorkerRepository;
use PDO;
use RuntimeException;

/**
 * Entrega somente o snapshot PDF gerado na liberação clínica. O worker não
 * re-renderiza conteúdo, layout, assinatura ou dados institucionais.
 */
final class ReportDeliveryArtifactService
{
    private PDO $pdo;
    private ReportDeliveryWorkerRepository $workerRepository;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->workerRepository = new ReportDeliveryWorkerRepository($this->pdo);
    }

    /** @return array{content:string,sha256:string,size:int,filename:string,report_id:int,study_instance_uid:string} */
    public function buildPdfForLeasedJob(int $jobId, string $workerId): array
    {
        $job = $this->workerRepository->findLeasedJobContext($jobId, $workerId);
        if (!$job) {
            throw new RuntimeException('Job não está reservado para este worker.');
        }
        if (($job['transport'] ?? '') !== 'dicom_pdf') {
            throw new RuntimeException('Artefato PDF DICOM solicitado para um transporte incompatível.');
        }

        $tenantId = (int) $job['tenant_id'];
        $reportId = (int) $job['report_id'];
        $reportVersion = (int) $job['report_version'];
        $outboxId = (int) $job['outbox_id'];
        $snapshot = (new ReportPdfSnapshotService($this->pdo))->readForDelivery(
            $outboxId,
            $tenantId,
            $reportId,
            $reportVersion
        );

        $this->workerRepository->recordArtifact(
            $outboxId,
            $tenantId,
            isset($job['estabelecimento_id']) ? (int) $job['estabelecimento_id'] : null,
            'pdf',
            $snapshot['storage_path'],
            $snapshot['sha256'],
            $snapshot['size']
        );

        return [
            'content' => $snapshot['content'],
            'sha256' => $snapshot['sha256'],
            'size' => $snapshot['size'],
            'filename' => sprintf('laudo-%d-v%d.pdf', $reportId, $reportVersion),
            'report_id' => $reportId,
            'study_instance_uid' => (string) ($job['study_instance_uid'] ?? ''),
        ];
    }
}
