<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ReportDeliveryRequestRepository;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Valida o pacote Philips de uma Delivery Request sem produzir efeitos
 * operacionais. O PDF canônico é somente lido; uma renderização adicional é
 * feita apenas em memória para validar o caminho visual oficial.
 */
final class PhilipsSubmissionPdfReadOnlyDiagnostic
{
    private const ALIAS_PATTERN = '/^[A-Za-z0-9._-]{1,120}$/';

    public function __construct(
        private readonly PDO $pdo,
        private readonly PhilipsSubmissionPackageProducer $producer,
        private readonly ?ReportDeliveryRequestRepository $requests = null,
        private readonly ?ReportDeliveryRequestSnapshotService $snapshots = null
    ) {
    }

    /** @return array<string,mixed> */
    public function run(int $tenantId, int $jobId): array
    {
        $result = [
            'status' => 'FAIL',
            'mode' => 'PDF_READ_ONLY_NO_SEND',
            'tenant_id' => $tenantId,
            'job_id' => $jobId,
            'request_id' => null,
            'outbox_id' => null,
            'destination_id' => null,
            'job_status' => null,
            'request_status' => null,
            'outbox_status' => null,
            'attempt_count' => null,
            'snapshot' => 'NOT_EXECUTED',
            'snapshot_digest' => 'NOT_VALIDATED',
            'destination_digest' => 'NOT_VALIDATED',
            'destination_timestamp_validation' => 'NOT_EXECUTED',
            'task_site_id' => 'NOT_VALIDATED',
            'task_site_id_validation' => 'NOT_EXECUTED',
            'task_site_id_alias' => 'NOT_VALIDATED',
            'task_site_id_alias_validation' => 'NOT_EXECUTED',
            'source' => 'NOT_EXECUTED',
            'pdf_generation' => 'NOT_EXECUTED',
            'pdf_validation' => 'NOT_EXECUTED',
            'pdf_xml_correlation' => 'NOT_EXECUTED',
            'xml_generation' => 'NOT_EXECUTED',
            'xml_validation' => 'NOT_EXECUTED',
            'artifact_written' => 'NO',
            'attempt_created' => 'NO',
            'job_claimed' => 'NO',
            'bridge_called' => 'NO',
            'smb_called' => 'NO',
            'transmission' => 'NO',
            'database_changed' => 'NO',
            'failure_code' => null,
        ];

        if ($tenantId <= 0 || $jobId <= 0) {
            $result['failure_code'] = 'INVALID_SCOPE';
            return $result;
        }

        $transactionStarted = false;
        try {
            $this->pdo->beginTransaction();
            $transactionStarted = true;
            $this->pdo->exec('SET TRANSACTION READ ONLY');

            $job = $this->findJob($tenantId, $jobId);
            if ($job === null) {
                throw new RuntimeException('JOB_NOT_FOUND');
            }
            $result['request_id'] = $this->positiveInt($job['delivery_request_id'] ?? null);
            $result['outbox_id'] = $this->positiveInt($job['outbox_id'] ?? null);
            $result['destination_id'] = $this->positiveInt($job['destination_id'] ?? null);
            $result['job_status'] = (string) ($job['status'] ?? '');
            $result['outbox_status'] = (string) ($job['outbox_status'] ?? '');
            $result['attempt_count'] = (int) ($job['attempt_count'] ?? -1);

            $requestId = (int) ($result['request_id'] ?? 0);
            $destinationId = (int) ($result['destination_id'] ?? 0);
            $requestRepository = $this->requests ?? new ReportDeliveryRequestRepository($this->pdo);
            $snapshotService = $this->snapshots ?? new ReportDeliveryRequestSnapshotService($this->pdo, $requestRepository);
            $request = $requestId > 0 ? $requestRepository->findRequest($tenantId, $requestId) : null;
            $destination = $destinationId > 0 ? $requestRepository->findDestination($tenantId, $destinationId) : null;
            if ($request === null || $destination === null) {
                throw new RuntimeException('REQUEST_DESTINATION_NOT_FOUND');
            }

            $this->assertIdentity($job, $request, $destination, $tenantId, $jobId);
            $payload = $this->decodeObject($job['payload_json'] ?? null, 'PAYLOAD_INVALID');
            $configuration = $this->decodeObject($destination['configuration_json'] ?? null, 'CONFIGURATION_INVALID');
            $this->assertPayloadIdentity($job, $request, $payload, $tenantId);

            $aliasPass = $this->aliasSnapshotPass($destination, $request, $payload);
            $result['task_site_id_alias_validation'] = $aliasPass ? 'PASS' : 'FAIL';
            $result['task_site_id_alias'] = $aliasPass ? 'VALID_FROZEN_ASCII' : 'INVALID_OR_DRIFTED';
            if (!$aliasPass) {
                throw new RuntimeException('ALIAS_SNAPSHOT_MISMATCH');
            }

            $reportSnapshot = $snapshotService->resolveExplicit(
                $tenantId,
                (int) $job['report_id'],
                (int) $job['report_version']
            );
            if ($reportSnapshot === null) {
                throw new RuntimeException('SNAPSHOT_NOT_FOUND');
            }
            $result['snapshot'] = 'PASS';

            $digestChecks = $this->digestChecks($tenantId, $requestId, $request, $destination, $reportSnapshot);
            $result['snapshot_digest'] = $digestChecks['snapshot_digest'] ? 'PASS' : 'FAIL';
            $result['destination_digest'] = $digestChecks['destination_digest'] ? 'PASS' : 'FAIL';
            $result['destination_timestamp_validation'] = $digestChecks['destination_timestamp'] ? 'PASS' : 'FAIL';
            if (!$digestChecks['snapshot_digest']) {
                throw new RuntimeException('SNAPSHOT_DIGEST_MISMATCH');
            }
            if (!$digestChecks['destination_digest']) {
                throw new RuntimeException('DESTINATION_DIGEST_MISMATCH');
            }
            if (!$digestChecks['destination_timestamp']) {
                throw new RuntimeException('DESTINATION_CHANGED_AFTER_AUTHORIZATION');
            }

            $canonicalPass = $this->canonicalBindingPass($tenantId, $destination, $reportSnapshot);
            $result['task_site_id_validation'] = $canonicalPass ? 'PASS' : 'FAIL';
            $result['task_site_id'] = $canonicalPass ? 'CANONICAL_BOUND' : 'CANONICAL_BINDING_INVALID';
            if (!$canonicalPass) {
                throw new RuntimeException('CANONICAL_BINDING_MISMATCH');
            }

            $job['delivery_request_id'] = $requestId;
            $job['dispatch_mode'] = (string) ($request['dispatch_mode'] ?? '');
            $job['ambiente'] = (string) ($destination['ambiente'] ?? '');
            $job['pdf_revision_id'] = (int) ($request['pdf_revision_id'] ?? 0);

            $pdf = $this->readImmutablePdf($job);
            $result['source'] = (int) ($job['pdf_revision_id'] ?? 0) > 0
                ? 'IMMUTABLE_PDF_REVISION'
                : 'IMMUTABLE_CANONICAL_PDF_SNAPSHOT';
            $pdfValid = $this->validPdf($pdf['content'] ?? null, $pdf['size'] ?? null, $pdf['sha256'] ?? null);
            $result['pdf_validation'] = $pdfValid ? 'PASS' : 'FAIL';
            if (!$pdfValid) {
                throw new RuntimeException('PDF_SNAPSHOT_INVALID');
            }

            $visualContext = (new ReportPdfDeliveryContextService($this->pdo))->build($job);
            $renderedPdf = (new ReportPdfService())->renderSnapshotBinary($visualContext);
            $pdfGenerated = $this->validPdf($renderedPdf, strlen($renderedPdf), hash('sha256', $renderedPdf));
            $result['pdf_generation'] = $pdfGenerated ? 'PASS' : 'FAIL';
            if (!$pdfGenerated) {
                throw new RuntimeException('PDF_RENDER_INVALID');
            }

            $composition = $this->producer->composeNoSend($job, $configuration, $payload);
            $document = $composition['document'] ?? null;
            if (!$document instanceof PhilipsSubmissionDocument) {
                throw new RuntimeException('XML_COMPOSITION_INVALID');
            }
            $result['xml_generation'] = 'PASS';
            $xmlValid = $this->validXml($document->content);
            $result['xml_validation'] = $xmlValid ? 'PASS' : 'FAIL';
            if (!$xmlValid) {
                throw new RuntimeException('XML_VALIDATION_FAILED');
            }

            $correlationPass = $this->correlates($document, $payload, $aliasPass, $pdfValid, $pdfGenerated);
            $result['pdf_xml_correlation'] = $correlationPass ? 'PASS' : 'FAIL';
            if (!$correlationPass) {
                throw new RuntimeException('PDF_XML_CORRELATION_FAILED');
            }

            $result['status'] = 'PASS';
        } catch (Throwable $error) {
            $result['failure_code'] = $this->failureCode($error);
        } finally {
            if ($transactionStarted && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    private function findJob(int $tenantId, int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT j.id, j.outbox_id, j.destination_id, j.tenant_id, j.estabelecimento_id,
                    j.transport, j.delivery_profile, j.status, j.attempt_count,
                    j.locked_at, j.locked_by,
                    o.delivery_request_id, o.report_id, o.report_version, o.estudo_id,
                    o.payload_json, o.status AS outbox_status
               FROM pacs_report_delivery_jobs j
               INNER JOIN pacs_report_delivery_outbox o
                       ON o.id = j.outbox_id AND o.tenant_id = j.tenant_id
              WHERE j.id = :job_id
                AND j.tenant_id = :tenant_id
              LIMIT 1"
        );
        $stmt->execute([':job_id' => $jobId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $request @param array<string,mixed> $destination */
    private function assertIdentity(array $job, array $request, array $destination, int $tenantId, int $jobId): void
    {
        if ((int) ($job['id'] ?? 0) !== $jobId
            || (int) ($job['tenant_id'] ?? 0) !== $tenantId
            || (int) ($request['tenant_id'] ?? 0) !== $tenantId
            || (int) ($destination['tenant_id'] ?? 0) !== $tenantId
            || (int) ($job['destination_id'] ?? 0) !== 7
            || (int) ($request['destination_id'] ?? 0) !== 7
            || (int) ($destination['id'] ?? 0) !== 7
            || (int) ($request['report_id'] ?? 0) !== (int) ($job['report_id'] ?? 0)
            || (int) ($request['report_version'] ?? 0) !== (int) ($job['report_version'] ?? 0)
            || (int) ($request['estudo_id'] ?? 0) !== (int) ($job['estudo_id'] ?? 0)
            || (string) ($job['transport'] ?? '') !== PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT
            || (string) ($job['delivery_profile'] ?? '') !== PhilipsFolderDeliveryService::PROFILE_SUBMISSION_DOCUMENT
            || (string) ($destination['transport'] ?? '') !== PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT
            || (string) ($destination['ambiente'] ?? '') !== 'producao'
            || (string) ($request['transport'] ?? '') !== PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT
            || (string) ($request['ambiente'] ?? '') !== 'producao'
            || (string) ($request['delivery_profile'] ?? '') !== PhilipsFolderDeliveryService::PROFILE_SUBMISSION_DOCUMENT
            || (string) ($request['dispatch_mode'] ?? '') !== 'controlled_production'
            || (string) ($job['status'] ?? '') !== 'queued'
            || (string) ($job['outbox_status'] ?? '') !== 'queued'
            || (string) ($request['status'] ?? '') !== 'armed'
            || (int) ($job['attempt_count'] ?? -1) !== 0
            || ($job['locked_at'] ?? null) !== null
            || ($job['locked_by'] ?? null) !== null
        ) {
            throw new RuntimeException('JOB_NOT_PRISTINE_OR_IDENTITY_MISMATCH');
        }
    }

    /** @param array<string,mixed> $destination @param array<string,mixed> $request @param array<string,mixed> $payload */
    private function aliasSnapshotPass(array $destination, array $request, array $payload): bool
    {
        $destinationAlias = trim((string) ($destination['task_site_id_alias'] ?? ''));
        $requestAlias = trim((string) ($request['task_site_id_alias'] ?? ''));
        $payloadAlias = trim((string) ($payload['task_site_id_alias'] ?? ''));
        return preg_match(self::ALIAS_PATTERN, $destinationAlias) === 1
            && preg_match(self::ALIAS_PATTERN, $requestAlias) === 1
            && preg_match(self::ALIAS_PATTERN, $payloadAlias) === 1
            && hash_equals($destinationAlias, $requestAlias)
            && hash_equals($requestAlias, $payloadAlias)
            && (string) ($payload['dispatch_mode'] ?? '') === 'controlled_production';
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $request @param array<string,mixed> $payload */
    private function assertPayloadIdentity(array $job, array $request, array $payload, int $tenantId): void
    {
        $expected = [
            'tenant_id' => $tenantId,
            'report_id' => (int) ($job['report_id'] ?? 0),
            'report_version' => (int) ($job['report_version'] ?? 0),
            'estudo_id' => (int) ($job['estudo_id'] ?? 0),
            'destination_id' => 7,
            'transport' => PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT,
            'ambiente' => 'producao',
            'delivery_profile' => PhilipsFolderDeliveryService::PROFILE_SUBMISSION_DOCUMENT,
            'dispatch_mode' => 'controlled_production',
            'delivery_request_id' => (int) ($request['id'] ?? 0),
            'snapshot_digest' => (string) ($request['authorized_snapshot_digest'] ?? ''),
            'destination_config_digest' => (string) ($request['destination_config_digest'] ?? ''),
        ];
        foreach ($expected as $field => $value) {
            $actual = $payload[$field] ?? null;
            if (is_int($value) ? (int) $actual !== $value : (string) $actual !== $value) {
                throw new RuntimeException('PAYLOAD_IDENTITY_MISMATCH');
            }
        }
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $destination @param array<string,mixed> $report @return array{snapshot_digest:bool,destination_digest:bool,destination_timestamp:bool} */
    private function digestChecks(int $tenantId, int $requestId, array $request, array $destination, array $report): array
    {
        $overrideDigest = (new ReportDeliveryRequestPatientNameOverrideService($this->pdo))->digest($tenantId, $requestId);
        $snapshotDigest = DeliveryRequestIdentity::authorizedSnapshotDigest(
            $tenantId,
            (int) ($request['report_id'] ?? 0),
            (int) ($request['report_version'] ?? 0),
            $report,
            $overrideDigest,
            (int) ($request['pdf_revision_id'] ?? 0)
        );
        $destinationDigest = DeliveryRequestIdentity::destinationDigest($destination);
        $storedSnapshotDigest = (string) ($request['authorized_snapshot_digest'] ?? '');
        $storedDestinationDigest = (string) ($request['destination_config_digest'] ?? '');
        return [
            'snapshot_digest' => (int) ($request['snapshot_schema_version'] ?? 0) === 1
                && preg_match('/^[a-f0-9]{64}$/i', $storedSnapshotDigest) === 1
                && hash_equals($storedSnapshotDigest, $snapshotDigest),
            'destination_digest' => preg_match('/^[a-f0-9]{64}$/i', $storedDestinationDigest) === 1
                && hash_equals($storedDestinationDigest, $destinationDigest),
            'destination_timestamp' => $this->sameTimestamp(
                $request['destination_config_observed_at'] ?? null,
                $destination['updated_at'] ?? null
            ),
        ];
    }

    /** @param array<string,mixed> $destination @param array<string,mixed> $report */
    private function canonicalBindingPass(int $tenantId, array $destination, array $report): bool
    {
        $serverId = (int) ($destination['servidor_pacs_id'] ?? 0);
        $studyServerId = (int) ($report['estudo_servidor_id'] ?? 0);
        if ($serverId <= 0 || $serverId !== $studyServerId) {
            return false;
        }
        $repository = $this->requests ?? new ReportDeliveryRequestRepository($this->pdo);
        $server = $repository->findTenantPacsServer($tenantId, $serverId);
        if (!is_array($server)) {
            return false;
        }
        $configuration = json_decode((string) ($destination['configuration_json'] ?? '{}'), true);
        $submission = is_array($configuration['philips_submission'] ?? null)
            ? $configuration['philips_submission']
            : [];
        return trim((string) ($submission['task_site_id'] ?? '')) !== ''
            && hash_equals(
                trim((string) ($submission['task_site_id'] ?? '')),
                trim((string) ($server['nome'] ?? ''))
            );
    }

    /** @return array<string,mixed> */
    private function readImmutablePdf(array $job): array
    {
        $revisionId = (int) ($job['pdf_revision_id'] ?? 0);
        if ($revisionId > 0) {
            return (new ReportVersionPdfRevisionService($this->pdo))->readForJob($job);
        }
        return (new ReportVersionPdfSnapshotService($this->pdo))->readForJob($job);
    }

    private function validPdf(mixed $content, mixed $size, mixed $sha256): bool
    {
        if (!is_string($content) || strlen($content) < 100 || !str_starts_with($content, '%PDF-')) {
            return false;
        }
        if (!is_int($size) && !is_numeric($size)) {
            return false;
        }
        if ((int) $size !== strlen($content) || !is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/i', $sha256)) {
            return false;
        }
        if (!hash_equals(strtolower($sha256), strtolower(hash('sha256', $content)))) {
            return false;
        }
        return strrpos($content, '%%EOF') !== false;
    }

    private function validXml(string $content): bool
    {
        if (!str_starts_with($content, '<?xml version="1.0" encoding="iso-8859-1"?>')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content) === 1
            || !function_exists('simplexml_load_string')) {
            return false;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            return simplexml_load_string(
                $content,
                \SimpleXMLElement::class,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            ) !== false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function correlates(
        PhilipsSubmissionDocument $document,
        array $payload,
        bool $aliasPass,
        bool $pdfValid,
        bool $pdfGenerated
    ): bool {
        if (!$aliasPass || !$pdfValid || !$pdfGenerated) {
            return false;
        }
        if (pathinfo($document->filename, PATHINFO_FILENAME) !== pathinfo($document->pdfFilename, PATHINFO_FILENAME)) {
            return false;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string(
                $document->content,
                \SimpleXMLElement::class,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
            if ($xml === false || !isset($xml->document)) {
                return false;
            }
            $taskFileName = (string) ($xml->document->task_file_name ?? '');
            $taskSiteId = (string) ($xml->document->task_site_id ?? '');
            $alias = trim((string) ($payload['task_site_id_alias'] ?? ''));
            return $taskFileName === $document->pdfFilename
                && $taskSiteId !== ''
                && hash_equals($taskSiteId, $alias);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array<string,mixed> */
    private function decodeObject(mixed $value, string $failureCode): array
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException($failureCode);
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            throw new RuntimeException($failureCode);
        }
        return $decoded;
    }

    private function positiveInt(mixed $value): ?int
    {
        $value = (int) $value;
        return $value > 0 ? $value : null;
    }

    private function sameTimestamp(mixed $left, mixed $right): bool
    {
        $leftTime = strtotime((string) $left);
        $rightTime = strtotime((string) $right);
        return $leftTime !== false && $rightTime !== false && $leftTime === $rightTime;
    }

    private function failureCode(Throwable $error): string
    {
        $message = $error->getMessage();
        return preg_match('/^[A-Z0-9_]{1,100}$/', $message) === 1
            ? $message
            : 'PDF_READ_ONLY_VALIDATION_FAILED';
    }
}
