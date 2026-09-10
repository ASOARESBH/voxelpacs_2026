<?php

declare(strict_types=1);

namespace App\Services;

/** Produtor PDF-only da Fase 1, baseado na versão imutável do Delivery Hub. */
final class PdfNonDicomArtifactProducer implements NonDicomArtifactProducer
{
    public function __construct(private readonly ReportDeliveryArtifactService $artifacts = new ReportDeliveryArtifactService())
    {
    }

    public function produce(int $jobId, string $workerId): array
    {
        $artifact = $this->artifacts->buildPdfForLeasedJob($jobId, $workerId);
        return [
            'type' => 'pdf',
            'storage_path' => (string) $artifact['storage_path'],
            'sha256' => (string) $artifact['sha256'],
            'size' => (int) $artifact['size'],
            'filename' => (string) $artifact['filename'],
        ];
    }
}
