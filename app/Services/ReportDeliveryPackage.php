<?php

declare(strict_types=1);

namespace App\Services;

/** Package lógico imutável: os dois artifacts pertencem ao mesmo outbox/job. */
final class ReportDeliveryPackage
{
    /** @param array<string,mixed> $pdfArtifact */
    public function __construct(
        public readonly array $pdfArtifact,
        public readonly PhilipsSubmissionDocument $xmlDocument,
    public readonly string $xmlStoragePath
    ) {
        $pdfPath = (string) ($pdfArtifact['storage_path'] ?? '');
        if ((string) ($pdfArtifact['type'] ?? '') !== 'pdf'
            || !is_file($pdfPath)
            || $xmlStoragePath === ''
            || !is_file($xmlStoragePath)
            || (int) ($pdfArtifact['size'] ?? 0) !== (int) filesize($pdfPath)
            || !hash_equals((string) ($pdfArtifact['sha256'] ?? ''), (string) hash_file('sha256', $pdfPath))
            || !hash_equals($this->xmlDocument->sha256, (string) hash_file('sha256', $xmlStoragePath))
            || $this->xmlDocument->size !== (int) filesize($xmlStoragePath)) {
            throw new \InvalidArgumentException('Package de devolutiva inválido.');
        }
    }

    /** @return array{pdf:array<string,mixed>,xml:array<string,mixed>} */
    public function artifactMetadata(): array
    {
        return [
            'pdf' => [
                'type' => 'pdf',
                'filename' => (string) ($this->pdfArtifact['filename'] ?? ''),
                'sha256' => (string) ($this->pdfArtifact['sha256'] ?? ''),
                'size' => (int) ($this->pdfArtifact['size'] ?? 0),
                'storage_path' => (string) ($this->pdfArtifact['storage_path'] ?? ''),
            ],
            'xml' => [
                'type' => 'philips_submission_xml',
                'filename' => $this->xmlDocument->filename,
                'sha256' => $this->xmlDocument->sha256,
                'size' => $this->xmlDocument->size,
                'storage_path' => $this->xmlStoragePath,
                'pdf_filename' => $this->xmlDocument->pdfFilename,
            ],
        ];
    }
}
