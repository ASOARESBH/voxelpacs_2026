<?php

declare(strict_types=1);

namespace App\Services;

/** Produz o package Philips sem transformar campos ausentes em heurísticas. */
final class PhilipsSubmissionPackageProducer
{
    public function __construct(
        private readonly ReportDeliveryArtifactService $artifacts = new ReportDeliveryArtifactService(),
        private readonly PhilipsSubmissionDocumentGenerator $generator = new PhilipsSubmissionDocumentGenerator(),
        private readonly PhilipsSubmissionMetadataResolver $metadata = new PhilipsSubmissionMetadataResolver(),
        private readonly ReportDeliveryRequestSnapshotService $requestSnapshot = new ReportDeliveryRequestSnapshotService()
    ) {
    }

    public static function resolveTaskFilePath(string $directory, string $pdfFilename): string
    {
        $directory = trim($directory);
        if ($directory === '' || preg_match('/[\x00-\x1F\x7F]/', $directory) === 1
            || !preg_match('/^VOXEL_[A-Za-z0-9._-]{1,160}\.pdf$/', $pdfFilename)) {
            throw new PhilipsXmlFieldUnresolvedException('task_file_path');
        }
        $separator = str_contains($directory, '\\') ? '\\' : '/';
        return rtrim($directory, "\\/") . $separator . $pdfFilename;
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $configuration @param array<string,mixed> $payload */
    public function produce(array $job, array $configuration, array $payload, string $workerId): ReportDeliveryPackage
    {
        $jobId = (int) ($job['id'] ?? 0);
        $pdf = (new PdfNonDicomArtifactProducer($this->artifacts))->produce($jobId, $workerId);
        $reportId = (int) ($job['report_id'] ?? 0);
        $reportVersion = (int) ($job['report_version'] ?? 0);
        $payload = $this->requestSnapshot->hydratePayload($job, $payload);
        $pdfFilename = (new PhilipsFolderDeliveryService())->fileName($payload, $reportId, $reportVersion);

        $input = $this->resolvedInput($payload, $configuration, $pdfFilename);
        $input['pdf_filename'] = $pdfFilename;
        $document = $this->generator->generate($input, $this->deliveryContext($job, $configuration));
        $xmlStoragePath = $this->artifacts->storeGeneratedArtifact(
            $job,
            $document->filename,
            $document->content
        );

        return new ReportDeliveryPackage($pdf + ['filename' => $pdfFilename], $document, $xmlStoragePath);
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $configuration @return array<string,mixed> */
    private function deliveryContext(array $job, array $configuration): array
    {
        return [
            'tenant_id' => (int) ($job['tenant_id'] ?? 0),
            'report_id' => (int) ($job['report_id'] ?? 0),
            'report_version' => (int) ($job['report_version'] ?? 0),
            'estudo_id' => (int) ($job['estudo_id'] ?? 0),
            'destination_id' => (int) ($job['destination_id'] ?? 0),
            'ambiente' => (string) ($job['ambiente'] ?? ''),
            'delivery_profile' => (string) ($job['delivery_profile'] ?? ($configuration['delivery_profile'] ?? '')),
            'transport' => (string) ($job['transport'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $configuration @return array<string,mixed> */
    private function resolvedInput(array $payload, array $configuration, string $pdfFilename): array
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
        if (!array_key_exists('task_file_path', $settings) || !is_string($settings['task_file_path'])) {
            throw new PhilipsXmlFieldUnresolvedException('task_file_path');
        }
        $input['task_file_path'] = self::resolveTaskFilePath($settings['task_file_path'], $pdfFilename);
        return $input;
    }
}
