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
        private readonly PhilipsSubmissionAuthorResolver $authorResolver = new PhilipsSubmissionAuthorResolver(),
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

    /**
     * Resolve o SITE_ID de transporte sem permitir que o alias enfraqueça o
     * vínculo canônico do Destination 7 ao servidor PACS autorizado.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $deliveryContext
     */
    public static function resolveTaskSiteId(array $payload, mixed $configuredTaskSiteId, array $deliveryContext): mixed
    {
        $isAliasScopedProduction = (string) ($deliveryContext['transport'] ?? '') === PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT
            && (int) ($deliveryContext['destination_id'] ?? 0) === 7
            && (string) ($deliveryContext['ambiente'] ?? '') === 'producao'
            && (string) ($deliveryContext['delivery_profile'] ?? '') === PhilipsFolderDeliveryService::PROFILE_SUBMISSION_DOCUMENT
            && in_array((string) ($deliveryContext['dispatch_mode'] ?? ''), ['controlled_production', 'automatic_production'], true);
        if (!$isAliasScopedProduction) {
            return $configuredTaskSiteId;
        }

        $alias = (string) ($deliveryContext['dispatch_mode'] ?? '') === 'automatic_production'
            ? trim((string) ($deliveryContext['task_site_id_alias'] ?? ''))
            : trim((string) ($payload['task_site_id_alias'] ?? ''));
        if (preg_match('/^[A-Za-z0-9._-]{1,120}$/', $alias) !== 1) {
            throw new PhilipsXmlFieldUnresolvedException('task_site_id');
        }

        return $alias;
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
        $deliveryContext = $this->deliveryContext($job, $configuration, $payload);

        $input = $this->resolvedInput($payload, $configuration, $pdfFilename, $deliveryContext);
        $input['pdf_filename'] = $pdfFilename;
        $document = $this->generator->generate($input, $deliveryContext);
        $xmlStoragePath = $this->artifacts->storeGeneratedArtifact(
            $job,
            $document->filename,
            $document->content
        );

        return new ReportDeliveryPackage(array_replace($pdf, ['filename' => $pdfFilename]), $document, $xmlStoragePath);
    }

    /**
     * Valida o contrato do submission sem produzir PDF, persistir XML ou chamar transporte.
     * O conteúdo do documento fica somente em memória e é descartado antes do retorno.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $configuration
     * @param array<string,mixed> $payload
     * @return array{xml_serialized:string,artifact_written:string,bridge_called:string,smb_called:string}
     */
    public function validateNoSend(array $job, array $configuration, array $payload): array
    {
        $composition = $this->composeNoSend($job, $configuration, $payload);

        return [
            'xml_serialized' => 'PASS',
            'author_source' => (string) ($composition['author_source'] ?? PhilipsSubmissionAuthorResolver::SOURCE_UNRESOLVED),
            'author_decision' => (string) ($composition['author_decision'] ?? PhilipsSubmissionAuthorResolver::DECISION_AUTHOR_UNRESOLVED),
            'author_fallback_used' => (string) ($composition['author_fallback_used'] ?? 'NO'),
            'task_author_id_resolution' => (string) ($composition['task_author_id_resolution'] ?? 'NOT_PRESENT'),
            'artifact_written' => 'NO',
            'bridge_called' => 'NO',
            'smb_called' => 'NO',
        ];
    }

    /**
     * Compõe o XML oficial e o filename do PDF somente em memória.
     * Não lê o PDF, não cria arquivo, não registra artifact e não transporta.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $configuration
     * @param array<string,mixed> $payload
     * @return array{document:PhilipsSubmissionDocument,pdf_filename:string,author_source:string,author_decision:string,author_fallback_used:string,task_author_id_resolution:string}
     */
    public function composeNoSend(array $job, array $configuration, array $payload): array
    {
        $payload = $this->requestSnapshot->hydratePayload($job, $payload, false);
        $reportId = (int) ($job['report_id'] ?? 0);
        $reportVersion = (int) ($job['report_version'] ?? 0);
        if ($reportId <= 0 || $reportVersion <= 0) {
            throw new PhilipsXmlFieldUnresolvedException('task_document_name');
        }

        $pdfFilename = (new PhilipsFolderDeliveryService())->fileName($payload, $reportId, $reportVersion);
        $deliveryContext = $this->deliveryContext($job, $configuration, $payload);
        $input = $this->resolvedInput($payload, $configuration, $pdfFilename, $deliveryContext, true);
        $input['pdf_filename'] = $pdfFilename;
        $document = $this->generator->generate($input, $deliveryContext);

        return [
            'document' => $document,
            'pdf_filename' => $pdfFilename,
            'author_source' => (string) ($input['author_source'] ?? PhilipsSubmissionAuthorResolver::SOURCE_UNRESOLVED),
            'author_decision' => (string) ($input['author_decision'] ?? PhilipsSubmissionAuthorResolver::DECISION_AUTHOR_UNRESOLVED),
            'author_fallback_used' => (string) ($input['author_fallback_used'] ?? 'NO'),
            'task_author_id_resolution' => (string) ($input['task_author_id_resolution'] ?? 'NOT_PRESENT'),
        ];
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $configuration @param array<string,mixed> $payload @return array<string,mixed> */
    private function deliveryContext(array $job, array $configuration, array $payload): array
    {
        return [
            'tenant_id' => (int) ($job['tenant_id'] ?? 0),
            'report_id' => (int) ($job['report_id'] ?? 0),
            'report_version' => (int) ($job['report_version'] ?? 0),
            'estudo_id' => (int) ($job['estudo_id'] ?? 0),
            'destination_id' => (int) ($job['effective_destination_id'] ?? $job['destination_id'] ?? 0),
            'ambiente' => (string) ($job['ambiente'] ?? ''),
            'delivery_profile' => (string) ($job['delivery_profile'] ?? ($configuration['delivery_profile'] ?? '')),
            'transport' => (string) ($job['transport'] ?? ''),
            'dispatch_mode' => (string) ($payload['dispatch_mode'] ?? $job['dispatch_mode'] ?? ''),
            'task_site_id_alias' => (string) ($job['task_site_id_alias'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $configuration @param array<string,mixed> $deliveryContext @return array<string,mixed> */
    private function resolvedInput(
        array $payload,
        array $configuration,
        string $pdfFilename,
        array $deliveryContext,
        bool $diagnosticFallbackAllowed = false
    ): array
    {
        $input = $this->metadata->resolve($payload, $deliveryContext);
        $settings = $configuration['philips_submission'] ?? null;
        if (!is_array($settings)) {
            throw new PhilipsXmlFieldUnresolvedException('task_file_path');
        }
        $configuredFields = [
            'task_file_path',
            'task_site_id',
            'task_document_name',
            'task_author_id',
            'task_author_source',
            'task_delete_file',
            'task_document_type_applicable',
            'task_document_type',
        ];
        foreach ($configuredFields as $field) {
            if (array_key_exists($field, $settings)) {
                $input[$field] = $settings[$field];
            }
        }
        $authorResolution = $diagnosticFallbackAllowed
            ? $this->authorResolver->resolveForNoSendDiagnostic($payload, $configuration, $deliveryContext, $input)
            : $this->authorResolver->resolve($payload, $configuration, $deliveryContext, $input);
        $input = array_replace($input, $authorResolution);
        $input['task_site_id'] = self::resolveTaskSiteId(
            $payload,
            $input['task_site_id'] ?? null,
            $deliveryContext
        );
        if (!array_key_exists('task_file_path', $settings) || !is_string($settings['task_file_path'])) {
            throw new PhilipsXmlFieldUnresolvedException('task_file_path');
        }
        $input['task_file_path'] = self::resolveTaskFilePath($settings['task_file_path'], $pdfFilename);
        return $input;
    }
}
