<?php

declare(strict_types=1);

namespace App\Services;

/** Produz o package Philips sem transformar campos ausentes em heurísticas. */
final class PhilipsSubmissionPackageProducer
{
    public function __construct(
        private readonly ReportDeliveryArtifactService $artifacts = new ReportDeliveryArtifactService(),
        private readonly PhilipsSubmissionDocumentGenerator $generator = new PhilipsSubmissionDocumentGenerator(),
        private readonly PhilipsSubmissionMetadataResolver $metadata = new PhilipsSubmissionMetadataResolver()
    ) {
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $configuration @param array<string,mixed> $payload */
    public function produce(array $job, array $configuration, array $payload, string $workerId): ReportDeliveryPackage
    {
        $jobId = (int) ($job['id'] ?? 0);
        $pdf = (new PdfNonDicomArtifactProducer($this->artifacts))->produce($jobId, $workerId);
        $reportId = (int) ($job['report_id'] ?? 0);
        $reportVersion = (int) ($job['report_version'] ?? 0);
        $pdfFilename = (new PhilipsFolderDeliveryService())->fileName($payload, $reportId, $reportVersion);

        $input = $this->resolvedInput($payload, $configuration);
        $input['pdf_filename'] = $pdfFilename;
        $document = $this->generator->generate($input);
        $xmlStoragePath = $this->artifacts->storeGeneratedArtifact(
            $job,
            $document->filename,
            $document->content
        );

        return new ReportDeliveryPackage($pdf + ['filename' => $pdfFilename], $document, $xmlStoragePath);
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $configuration @return array<string,mixed> */
    private function resolvedInput(array $payload, array $configuration): array
    {
        $input = $this->metadata->resolve($payload);
        $settings = $configuration['philips_submission'] ?? null;
        if (!is_array($settings)) {
            throw new PhilipsXmlFieldUnresolvedException('task_file_path');
        }
        foreach ([
            'task_file_path',
            'task_site_id',
            'task_document_name',
            'task_author_id',
            'task_author_humanname_family',
            'task_author_humanname_given',
            'task_author_humanname_middle',
            'task_delete_file',
            'task_document_type_applicable',
            'task_document_type',
        ] as $field) {
            if (array_key_exists($field, $settings)) {
                $input[$field] = $settings[$field];
            }
        }
        return $input;
    }
}
