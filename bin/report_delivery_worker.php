<?php
// Materialização de runtime inerte da Fase 1 Philips Non-DICOM; não ativa SMB, bridge, XML ou automação.

declare(strict_types=1);
use App\Contracts\PhilipsFolderDeliveryPort;
use App\Config\ReportDeliveryRuntimeConfig;
use App\Core\Logger;
use App\Repositories\ReportDeliveryWorkerRepository;
use App\Services\ReportDeliveryArtifactService;
use App\Services\ReportDeliveryGatewayBridgeClient;
use App\Services\PhilipsFolderDeliveryException;
use App\Services\PhilipsFolderDeliveryService;
use App\Services\PdfNonDicomArtifactProducer;

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
} catch (Throwable $bootstrapError) {
    fwrite(STDERR, "PHASE=BOOTSTRAP_FAILED\nERROR_CLASS=" . get_class($bootstrapError) . "\nSQLSTATE=NONE\nERROR_LOCATION=" . basename($bootstrapError->getFile()) . ':' . $bootstrapError->getLine() . "\nRUNONE_RESULT=FAILED\nREASON=bootstrap_failed\nJOB_ID=UNKNOWN\nATTEMPT_ID=NONE\n");
    exit(4);
}

final class DeliveryWorkerFailure extends RuntimeException
{
    public function __construct(
        public readonly string $stage,
        public readonly ?string $reasonCategory = null
    )
    {
        parent::__construct($stage);
    }
}

final class LocalDicomDeliveryWorker
{
    private const SUPPORTED_TRANSPORTS = ['dicom_pdf'];
    private const CSTORE_REASON_CATEGORIES = [
        'timeout',
        'connect_failed',
        'association_rejected',
        'tls_required',
        'command_failed',
    ];
    // A bridge devolve somente categorias sanitizadas; nenhum detalhe de rota, credencial ou artefato é persistido.
    private const PHILIPS_FOLDER_REASON_CATEGORIES = [
        'feature_disabled',
        'invalid_configuration',
        'invalid_artifact',
        'artifact_unreadable',
        'xml_field_unresolved',
        'patient_name_raw_unavailable',
        'gateway_policy_rejected',
        'author_signer_mismatch',
        'credentials_unavailable',
        'gateway_unavailable',
            'gateway_delivery_failed',
            'gateway_smb_test_failed',
        'remote_integrity_unconfirmed',
        'connectivity',
        'timeout',
        'authentication',
        'host_key',
        'permission',
        'remote_io',
        'remote_effect_uncertain',
        'configuration',
    ];
    private const CSTORE_DIAGNOSTIC_MAX_BYTES = 8192;
    private const PHILIPS_PRE_BRIDGE_STAGES = [
        'feature_disabled',
        'invalid_configuration',
        'invalid_artifact',
        'credentials_unavailable',
        'author_resolution_failed',
        'xml_field_unresolved',
    ];

    private ReportDeliveryWorkerRepository $repository;
    private ?ReportDeliveryArtifactService $artifactService;
    private PhilipsFolderDeliveryPort $folderDeliveryService;
    private string $workerId;
    private int $idleSeconds;

    public function __construct(
        ?ReportDeliveryWorkerRepository $repository = null,
        ?ReportDeliveryArtifactService $artifactService = null,
        ?PhilipsFolderDeliveryPort $folderDeliveryService = null
    )
    {
        $this->repository = $repository ?? new ReportDeliveryWorkerRepository(\App\Core\Database::getInstance());
        $this->artifactService = $artifactService;
        $this->folderDeliveryService = $folderDeliveryService ?? new PhilipsFolderDeliveryService();
        $this->workerId = $this->workerId();
        $this->idleSeconds = max(1, min(30, (int) (getenv('VOXEL_REPORT_DELIVERY_WORKER_IDLE_SECONDS') ?: 3)));
    }

    public function check(): int
    {
        foreach (['/usr/bin/pdf2dcm', '/usr/bin/dump2dcm', '/usr/bin/storescu'] as $binary) {
            if (!is_executable($binary)) {
                fwrite(STDERR, "missing_binary\n");
                return 2;
            }
        }
        fwrite(STDOUT, "worker_ready\n");
        return 0;
    }

    public function runOne(int $jobId): int
    {
        if (ReportDeliveryRuntimeConfig::workerKillSwitchEnabled()) {
            $this->emitRunOneResult('NOT_CLAIMED', 'kill_switch', $jobId, null);
            return 4;
        }
        try {
            $this->repository->enableOneShotForJob($jobId);
            $clinicalDate = $this->repository->currentClinicalDate();
            $job = $this->repository->claimJobById($jobId, $this->workerId, $this->supportedTransports(), $clinicalDate);
            if ($job === null) {
                $reason = $this->repository->lastClaimFailureReason() ?? 'not_eligible';
                $this->logPhase('NOT_ELIGIBLE', null, ['job_id' => $jobId, 'reason_category' => $reason]);
                $this->emitRunOneResult('NOT_ELIGIBLE', $reason, $jobId, null);
                return 3;
            }
            $outcome = $this->deliver($job);
            if (($outcome['result'] ?? 'FAILED') === 'DELIVERED') {
                $this->logPhase('DELIVERED', null, ['job_id' => $jobId]);
            }
            $this->emitRunOneResult(
                (string) ($outcome['result'] ?? 'FAILED'),
                (string) ($outcome['reason'] ?? 'unknown'),
                $jobId,
                isset($outcome['attempt_id']) ? (int) $outcome['attempt_id'] : null
            );
            return ($outcome['result'] ?? 'FAILED') === 'DELIVERED' ? 0 : 4;
        } catch (Throwable $error) {
            $this->logPhase('CLAIM_EXCEPTION', $error, ['job_id' => $jobId]);
            $this->emitRunOneResult('FAILED', 'claim_exception', $jobId, null);
            return 4;
        }
    }

    public function run(): void
    {
        if (ReportDeliveryRuntimeConfig::workerKillSwitchEnabled()) {
            Logger::warning('[ReportDeliveryWorker] Worker bloqueado pelo kill switch', [
                'worker_kill_switch_enabled' => true,
            ]);
            return;
        }
        Logger::info('[ReportDeliveryWorker] Serviço local iniciado', ['worker_id' => $this->workerId]);
        /** @phpstan-ignore-next-line while.alwaysTrue */
        while (true) {
            try {
                $outcome = $this->runCycleOnce();
                if ($outcome === null) {
                    sleep($this->idleSeconds);
                }
            } catch (Throwable $error) {
                Logger::error('[ReportDeliveryWorker] Ciclo local interrompido', [
                    'worker_id' => $this->workerId,
                    'error_class' => get_class($error),
                ]);
                sleep($this->idleSeconds);
            }
        }
    }

    /** @return array{result:string,reason:string,attempt_id:?int}|null */
    public function runCycleOnce(): ?array
    {
        $clinicalDate = $this->repository->currentClinicalDate();
        $expired = $this->repository->expireAutomaticJobsBefore($clinicalDate);
        if ($expired > 0) {
            Logger::warning('[ReportDeliveryWorker] Pendências automáticas expiradas', ['count' => $expired]);
        }
        $job = $this->repository->claimNextJob($this->workerId, $this->supportedTransports(), $clinicalDate);
        if ($job === null) {
            $eligibleCount = $this->repository->countEligibleJobs($this->supportedTransports(), $clinicalDate);
            Logger::sanitized('[ReportDeliveryWorker] Sem job elegível para claim', [
                'eligible_count' => $eligibleCount,
                'last_no_claim_reason' => $this->repository->lastClaimFailureReason() ?? 'no_eligible_job',
            ]);
            return null;
        }

        return $this->deliver($job);
    }

    /** @param array<string,mixed> $job @return array{result:string,reason:string,attempt_id:?int} */
    private function deliver(array $job): array
    {
        $jobId = (int) ($job['id'] ?? 0);
        try {
            $transport = (string) ($job['transport'] ?? '');
            $deliveryProfile = (string) ($job['delivery_profile'] ?? PhilipsFolderDeliveryService::PROFILE_PDF_ONLY);
            if ($jobId <= 0 || !in_array($transport, $this->supportedTransports(), true)) {
                throw new DeliveryWorkerFailure('invalid_job');
            }
            if ($transport === PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT
                && !in_array($deliveryProfile, [PhilipsFolderDeliveryService::PROFILE_PDF_ONLY, PhilipsFolderDeliveryService::PROFILE_SUBMISSION_DOCUMENT], true)) {
                throw new DeliveryWorkerFailure('invalid_configuration');
            }

            $configuration = $this->decodeMap($job['configuration_json'] ?? null, 'invalid_configuration');
            $payload = $this->decodeMap($job['payload_json'] ?? null, 'invalid_payload');
            if ($transport === 'dicom_pdf') {
                $this->validateDestination($configuration, $payload);
            }

            $artifact = $transport === PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT
                && $deliveryProfile === PhilipsFolderDeliveryService::PROFILE_SUBMISSION_DOCUMENT
                ? []
                : ($transport === PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT
                    ? (new PdfNonDicomArtifactProducer($this->artifactService()))->produce($jobId, $this->workerId)
                    : $this->artifactService()->buildPdfForLeasedJob($jobId, $this->workerId));
            if ($transport === PhilipsFolderDeliveryService::TRANSPORT) {
                Logger::info('[PhilipsFolderDelivery] PHILIPS_EXPORT_PROCESSING', ['job_id' => $jobId]);
                $result = $this->folderDeliveryService->deliver($job, $configuration, $payload, $artifact);
                $this->repository->recordArtifact(
                    (int) $job['outbox_id'],
                    (int) $job['tenant_id'],
                    isset($job['estabelecimento_id']) ? (int) $job['estabelecimento_id'] : null,
                    'philips_folder_pdf',
                    (string) ($artifact['storage_path'] ?? ''),
                    $result['sha256'],
                    $result['size']
                );
            } elseif ($transport === PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT) {
                Logger::info('[PhilipsNonDicomDelivery] PHILIPS_NON_DICOM_PROCESSING', ['job_id' => $jobId]);
                if ($deliveryProfile === PhilipsFolderDeliveryService::PROFILE_SUBMISSION_DOCUMENT) {
                    $result = $this->folderDeliveryService->deliverNonDicomSubmissionPackage(
                        $job,
                        $configuration,
                        $payload,
                        (string) ($job['configuration_secret'] ?? ''),
                        $this->workerId
                    );
                    $pdfArtifact = is_array($result['pdf_artifact'] ?? null) ? $result['pdf_artifact'] : [];
                    $xmlArtifact = is_array($result['xml_artifact'] ?? null) ? $result['xml_artifact'] : [];
                    $this->repository->recordArtifact(
                        (int) $job['outbox_id'],
                        (int) $job['tenant_id'],
                        isset($job['estabelecimento_id']) ? (int) $job['estabelecimento_id'] : null,
                        'philips_non_dicom_pdf',
                        (string) ($pdfArtifact['storage_path'] ?? ''),
                        (string) ($pdfArtifact['sha256'] ?? ''),
                        (int) ($pdfArtifact['size'] ?? 0)
                    );
                    $this->repository->recordArtifact(
                        (int) $job['outbox_id'],
                        (int) $job['tenant_id'],
                        isset($job['estabelecimento_id']) ? (int) $job['estabelecimento_id'] : null,
                        'philips_submission_xml',
                        (string) ($xmlArtifact['storage_path'] ?? ''),
                        (string) ($xmlArtifact['sha256'] ?? ''),
                        (int) ($xmlArtifact['size'] ?? 0)
                    );
                } else {
                    $result = $this->folderDeliveryService->deliverNonDicomPdf(
                        $job,
                        $configuration,
                        $payload,
                        $artifact,
                        (string) ($job['configuration_secret'] ?? '')
                    );
                    $this->repository->recordArtifact(
                        (int) $job['outbox_id'],
                        (int) $job['tenant_id'],
                        isset($job['estabelecimento_id']) ? (int) $job['estabelecimento_id'] : null,
                        'philips_non_dicom_pdf',
                        (string) ($artifact['storage_path'] ?? ''),
                        $result['sha256'],
                        $result['size']
                    );
                }
            } else {
                $result = $this->sendDicomPdf($job, $configuration, $payload, $artifact);
            }
            $completionMetadata = [
                'transport' => $transport,
                'environment' => (string) ($job['ambiente'] ?? ''),
                'delivery_profile' => $deliveryProfile,
                'original_destination_id' => (int) ($job['destination_id'] ?? 0),
                'effective_destination_id' => (int) ($job['effective_destination_id'] ?? $job['destination_id'] ?? 0),
                'destination_resolution' => (string) ($job['destination_resolution'] ?? 'persisted_creation_time'),
                'artifact_sha256' => $result['sha256'],
                'artifact_size_bytes' => $result['size'],
            ];
            if ($deliveryProfile === PhilipsFolderDeliveryService::PROFILE_SUBMISSION_DOCUMENT) {
                $completionMetadata['package_identity'] = (string) ($result['package_identity'] ?? '');
                $completionMetadata['package_verified'] = (string) ($result['package_verified'] ?? 'FAIL');
                if (array_key_exists('patient_name_components_omitted', $result)) {
                    $completionMetadata['patient_name_components_omitted'] = (bool) $result['patient_name_components_omitted'];
                }
                if (($result['confirmation_source'] ?? null) === 'bridge_state') {
                    $completionMetadata['confirmation_source'] = 'bridge_state';
                }
            }
            if (!$this->repository->completeJob($jobId, $this->workerId, $result['reference'], $completionMetadata)) {
                throw new DeliveryWorkerFailure('completion_not_confirmed');
            }
            Logger::info(
                in_array($transport, [PhilipsFolderDeliveryService::TRANSPORT, PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT], true)
                    ? '[PhilipsNonDicomDelivery] PHILIPS_EXPORT_SUCCESS'
                    : '[ReportDeliveryWorker] Entrega DICOM concluída',
                ['job_id' => $jobId, 'transport' => $transport, 'environment' => (string) ($job['ambiente'] ?? '')]
            );
            return ['result' => 'DELIVERED', 'reason' => 'delivered', 'attempt_id' => $this->repository->lastAttemptId()];
        } catch (PhilipsFolderDeliveryException $error) {
            $this->logPhase('DELIVERY_FAILED', $error, ['job_id' => $jobId, 'reason_category' => $error->reasonCategory ?: $error->stage]);
            Logger::warning('[PhilipsFolderDelivery] PHILIPS_EXPORT_FAILED', [
                'job_id' => $jobId,
                'stage' => $error->stage,
                'reason_category' => $error->reasonCategory,
            ]);
            $attemptId = $this->failSafely(
                $jobId,
                $error->stage,
                $error->reasonCategory,
                (int) ($job['effective_destination_id'] ?? 0),
                (int) ($job['destination_id'] ?? 0),
                $this->isPhilipsPreBridgeStage($error->stage),
                $error->packageIdentity,
                $error->packageSize
            );
            $result = in_array($error->reasonCategory, ['timeout', 'remote_integrity_unconfirmed', 'gateway_delivery_failed', 'remote_io', 'remote_effect_uncertain'], true) ? 'UNCERTAIN' : 'FAILED';
            return ['result' => $result, 'reason' => $error->reasonCategory ?: $error->stage, 'attempt_id' => $attemptId];
        } catch (DeliveryWorkerFailure $error) {
            $this->logPhase('DELIVERY_FAILED', $error, ['job_id' => $jobId, 'reason_category' => $error->reasonCategory ?: $error->stage]);
            $preBridge = (string) ($job['transport'] ?? '') === PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT
                && in_array($error->stage, ['invalid_job', 'invalid_configuration', 'invalid_payload'], true);
            $attemptId = $this->failSafely($jobId, $error->stage, $error->reasonCategory, (int) ($job['effective_destination_id'] ?? 0), (int) ($job['destination_id'] ?? 0), $preBridge);
            return ['result' => 'FAILED', 'reason' => $error->reasonCategory ?: $error->stage, 'attempt_id' => $attemptId];
        } catch (Throwable $error) {
            $this->logPhase('DELIVERY_FAILED', $error, ['job_id' => $jobId, 'reason_category' => 'unexpected_error']);
            Logger::error('[ReportDeliveryWorker] Falha técnica de entrega', [
                'job_id' => $jobId,
                'error_class' => get_class($error),
            ]);
            $attemptId = $this->failSafely($jobId, 'unexpected_error', null, (int) ($job['effective_destination_id'] ?? 0), (int) ($job['destination_id'] ?? 0));
            return ['result' => 'FAILED', 'reason' => 'unexpected_error', 'attempt_id' => $attemptId];
        }
    }

    /** @return list<string> */
    private function supportedTransports(): array
    {
        $transports = self::SUPPORTED_TRANSPORTS;
        if (PhilipsFolderDeliveryService::enabled()) {
            $transports[] = PhilipsFolderDeliveryService::TRANSPORT;
        }
        if (PhilipsFolderDeliveryService::nonDicomEnabled()) {
            $transports[] = PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT;
        }
        return $transports;
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $configuration @param array<string,mixed> $payload @param array<string,mixed> $artifact @return array{reference:string,sha256:string,size:int} */
    private function sendDicomPdf(array $job, array $configuration, array $payload, array $artifact): array
    {
        $jobId = (int) ($job['id'] ?? 0);
        if ($jobId <= 0) {
            throw new DeliveryWorkerFailure('invalid_job');
        }
        if (!empty($configuration['use_tls'])) {
            throw new DeliveryWorkerFailure('tls_profile_required', 'tls_required');
        }

        $basePath = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : dirname(__DIR__);
        $privateDirectory = sprintf('%s/storage/report_delivery/%d/%d', $basePath, (int) $job['tenant_id'], (int) $job['outbox_id']);
        if (!is_dir($privateDirectory) && !mkdir($privateDirectory, 0700, true) && !is_dir($privateDirectory)) {
            throw new DeliveryWorkerFailure('private_storage_unavailable');
        }

        $temporaryDirectory = $privateDirectory . '/worker-' . bin2hex(random_bytes(12));
        if (!mkdir($temporaryDirectory, 0700, true)) {
            throw new DeliveryWorkerFailure('temporary_storage_unavailable');
        }

        try {
            $pdfPath = $temporaryDirectory . '/report.pdf';
            $metadataDumpPath = $temporaryDirectory . '/metadata.txt';
            $metadataDicomPath = $temporaryDirectory . '/metadata.dcm';
            $dicomPath = $temporaryDirectory . '/report.dcm';

            if (file_put_contents($pdfPath, (string) ($artifact['content'] ?? ''), LOCK_EX) === false) {
                throw new DeliveryWorkerFailure('artifact_write_failed');
            }
            chmod($pdfPath, 0600);

            if (file_put_contents($metadataDumpPath, $this->metadataDump($payload, $configuration), LOCK_EX) === false) {
                throw new DeliveryWorkerFailure('metadata_write_failed');
            }
            chmod($metadataDumpPath, 0600);

            $this->runCommand(['/usr/bin/dump2dcm', '--quiet', $metadataDumpPath, $metadataDicomPath], 'metadata_conversion_failed', 45);
            $pdf2dcmCommand = ['/usr/bin/pdf2dcm', '--quiet', '--study-from', $metadataDicomPath];
            $issuerOfPatientId = $this->issuerOfPatientId($payload, $configuration);
            if ($issuerOfPatientId !== '') {
                $pdf2dcmCommand[] = '--key';
                $pdf2dcmCommand[] = '0010,0021=' . $issuerOfPatientId;
            }
            $pdf2dcmCommand[] = '--instance-one';
            $pdf2dcmCommand[] = $pdfPath;
            $pdf2dcmCommand[] = $dicomPath;
            $this->runCommand($pdf2dcmCommand, 'pdf_encapsulation_failed', 45);
            if (!is_file($dicomPath) || filesize($dicomPath) < 256) {
                throw new DeliveryWorkerFailure('invalid_dicom_artifact');
            }

            $timeout = max(5, min(120, (int) ($job['timeout_seconds'] ?? 30)));
            if (!empty($configuration['gateway_bridge'])) {
                $deliveryResult = (new ReportDeliveryGatewayBridgeClient())->send($jobId, $configuration, $dicomPath, $timeout);
            } else {
                $this->runCommand([
                    '/usr/bin/storescu', '--quiet', '--disable-tls',
                    '--aetitle', (string) $configuration['calling_ae'],
                    '--call', (string) $configuration['called_ae'],
                    '--timeout', (string) $timeout,
                    '--socket-timeout', (string) $timeout,
                    (string) $configuration['host'],
                    (string) $configuration['port'],
                    $dicomPath,
                ], 'cstore_failed', min(135, $timeout + 15));
                $deliveryResult = ['reference' => '', 'sha256' => '', 'size' => 0];
            }

            $finalPath = sprintf('%s/laudo-%d-v%d.dcm', $privateDirectory, (int) $job['report_id'], (int) $job['report_version']);
            if (!copy($dicomPath, $finalPath)) {
                throw new DeliveryWorkerFailure('delivered_artifact_store_failed');
            }
            chmod($finalPath, 0600);
            $sha256 = hash_file('sha256', $finalPath);
            $size = (int) filesize($finalPath);
            $this->repository->recordArtifact(
                (int) $job['outbox_id'],
                (int) $job['tenant_id'],
                isset($job['estabelecimento_id']) ? (int) $job['estabelecimento_id'] : null,
                'dicom_pdf',
                $finalPath,
                $sha256,
                $size
            );

            return [
                'reference' => $deliveryResult['reference'] !== '' ? $deliveryResult['reference'] : 'dicom-cstore:' . substr($sha256, 0, 16),
                'sha256' => $sha256,
                'size' => $size,
            ];
        } finally {
            $this->removeDirectory($temporaryDirectory);
        }
    }

    /** @param array<string,mixed> $configuration @param array<string,mixed> $payload */
    private function validateDestination(array $configuration, array $payload): void
    {
        $host = trim((string) ($configuration['host'] ?? ''));
        $port = (int) ($configuration['port'] ?? 0);
        $aetPattern = '/^[A-Za-z0-9 _.\-]{1,16}$/';
        if ($host === '' || !preg_match('/^[A-Za-z0-9.:-]{1,253}$/', $host) || $port < 1 || $port > 65535) {
            throw new DeliveryWorkerFailure('invalid_destination_network');
        }
        if (preg_match($aetPattern, trim((string) ($configuration['calling_ae'] ?? ''))) !== 1
            || preg_match($aetPattern, trim((string) ($configuration['called_ae'] ?? ''))) !== 1) {
            throw new DeliveryWorkerFailure('invalid_destination_aet');
        }
        if (!$this->validUid((string) ($payload['study_instance_uid'] ?? ''))) {
            throw new DeliveryWorkerFailure('missing_study_uid');
        }
        if (trim($this->normalizedPatientId($payload, $configuration)) === '') {
            throw new DeliveryWorkerFailure('missing_patient_id');
        }
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $configuration */
    private function metadataDump(array $payload, array $configuration): string
    {
        $patientId = $this->normalizedPatientId($payload, $configuration);
        $issuerOfPatientId = $this->issuerOfPatientId($payload, $configuration);
        $studyDate = $this->dicomDate((string) ($payload['study_date'] ?? ''));
        $studyTime = $this->dicomTime((string) ($payload['study_time'] ?? ''));
        $birthDate = $this->dicomDate((string) ($payload['patient_birth_date'] ?? ''));
        $sex = strtoupper(trim((string) ($payload['patient_sex'] ?? '')));
        $sex = in_array($sex, ['M', 'F', 'O'], true) ? $sex : '';

        $fields = [
            ['0008,0005', 'CS', 'ISO_IR 192'],
            ['0008,0020', 'DA', $studyDate],
            ['0008,0030', 'TM', $studyTime],
            ['0008,0050', 'SH', (string) ($payload['accession_number'] ?? '')],
            ['0008,0060', 'CS', 'DOC'],
            ['0010,0010', 'PN', (string) ($payload['patient_name'] ?? '')],
            ['0010,0020', 'LO', $patientId],
            ['0010,0021', 'LO', $issuerOfPatientId],
            ['0010,0030', 'DA', $birthDate],
            ['0010,0040', 'CS', $sex],
            ['0020,000D', 'UI', (string) $payload['study_instance_uid']],
        ];
        return implode("\n", array_map(
            fn(array $field): string => sprintf('(%s) %s [%s]', $field[0], $field[1], $this->dicomText((string) $field[2])),
            $fields
        )) . "\n";
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $configuration */
    private function normalizedPatientId(array $payload, array $configuration): string
    {
        $patientId = trim((string) ($payload['patient_id'] ?? ''));
        if (($configuration['patient_id_normalization'] ?? '') === 'vue_prefix_before_triple_dollar') {
            $patientId = trim(explode('$$$', $patientId, 2)[0]);
        }
        return mb_substr($patientId, 0, 64);
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $configuration */
    private function issuerOfPatientId(array $payload, array $configuration): string
    {
        $configured = trim((string) ($configuration['issuer_of_patient_id'] ?? ''));
        $payloadIssuer = trim((string) ($payload['issuer_of_patient_id'] ?? ''));
        $issuer = $configured !== '' ? $configured : $payloadIssuer;
        return mb_substr(str_replace(["\r", "\n", '[', ']'], [' ', ' ', '(', ')'], $issuer), 0, 64);
    }

    private function dicomText(string $value): string
    {
        return mb_substr(str_replace(["\r", "\n", '[', ']'], [' ', ' ', '(', ')'], trim($value)), 0, 240);
    }

    private function dicomDate(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        return preg_match('/^\d{8}$/', $digits) === 1 ? $digits : '';
    }

    private function dicomTime(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        return preg_match('/^\d{2,14}$/', $digits) === 1 ? $digits : '';
    }

    private function validUid(string $value): bool
    {
        return strlen($value) <= 64 && preg_match('/^(?:[0-9]+)(?:\.[0-9]+)*$/', $value) === 1;
    }

    /**
     * Executa somente binários allowlisted com watchdog local; nunca delega
     * prazo de término exclusivamente ao processo externo.
     *
     * @param list<string> $command
     */
    private function runCommand(array $command, string $stage, int $timeoutSeconds = 60): void
    {
        $timeoutSeconds = max(1, min(180, $timeoutSeconds));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/bin:/bin']);
        if (!is_resource($process)) {
            throw new DeliveryWorkerFailure($stage, $stage === 'cstore_failed' ? 'command_failed' : null);
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                stream_set_blocking($pipe, false);
            }
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;
        $exitCode = -1;
        $cstoreStderr = $stage === 'cstore_failed' ? '' : null;
        try {
            while (true) {
                $status = proc_get_status($process);
                $this->drainCommandPipes($pipes, $cstoreStderr);
                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    proc_terminate($process);
                    usleep(250000);
                    $afterTerminate = proc_get_status($process);
                    if ($afterTerminate['running']) {
                        proc_terminate($process, 9);
                    }
                    break;
                }
                usleep(50000);
            }
        } finally {
            $this->drainCommandPipes($pipes, $cstoreStderr);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $closeCode = proc_close($process);
        }

        if ($timedOut || ($exitCode !== 0 && $closeCode !== 0)) {
            throw new DeliveryWorkerFailure(
                $stage,
                $stage === 'cstore_failed' ? $this->classifyCstoreFailure($timedOut, $cstoreStderr ?? '') : null
            );
        }
    }

    /**
     * Drena stdout/stderr para não bloquear o subprocesso. Somente stderr de
     * C-STORE fica em memória, limitado e efêmero, para classificação local.
     * Nenhuma saída bruta é persistida, registrada ou devolvida pela API.
     *
     * @param array<int,resource> $pipes
     */
    private function drainCommandPipes(array $pipes, ?string &$cstoreStderr): void
    {
        foreach ($pipes as $descriptor => $pipe) {
            if (!is_resource($pipe)) {
                continue;
            }
            $output = stream_get_contents($pipe);
            if ($descriptor !== 2 || $cstoreStderr === null || !is_string($output) || $output === '') {
                continue;
            }
            $remaining = self::CSTORE_DIAGNOSTIC_MAX_BYTES - strlen($cstoreStderr);
            if ($remaining > 0) {
                $cstoreStderr .= substr($output, 0, $remaining);
            }
        }
    }

    private function classifyCstoreFailure(bool $timedOut, string $cstoreStderr): string
    {
        if ($timedOut) {
            return 'timeout';
        }

        if (preg_match('/\b(?:connection refused|connection reset by peer|network is unreachable|no route to host|connection timed out)\b/i', $cstoreStderr) === 1) {
            return 'connect_failed';
        }

        if (preg_match('/\bassociation(?: request)? rejected\b/i', $cstoreStderr) === 1) {
            return 'association_rejected';
        }

        if (preg_match('/\b(?:tls|ssl)\b.*\b(?:required|handshake|certificate|protocol)\b|\b(?:required|handshake|certificate|protocol)\b.*\b(?:tls|ssl)\b/i', $cstoreStderr) === 1) {
            return 'tls_required';
        }

        return 'command_failed';
    }

    /** @param array<string,mixed>|null $data */
    private function decodeMap(mixed $data, string $stage): array
    {
        $decoded = json_decode((string) $data, true);
        if (!is_array($decoded)) {
            throw new DeliveryWorkerFailure($stage);
        }
        return $decoded;
    }

    private function isPhilipsPreBridgeStage(string $stage): bool
    {
        return in_array($stage, self::PHILIPS_PRE_BRIDGE_STAGES, true);
    }

    private function failSafely(
        int $jobId,
        string $stage,
        ?string $reasonCategory = null,
        int $effectiveDestinationId = 0,
        int $originalDestinationId = 0,
        bool $preBridge = false,
        ?string $packageIdentity = null,
        ?int $packageSize = null
    ): ?int
    {
        if ($jobId <= 0) {
            return null;
        }
        try {
            $metadata = ['stage' => $stage];
            if (in_array($reasonCategory, array_merge(self::CSTORE_REASON_CATEGORIES, self::PHILIPS_FOLDER_REASON_CATEGORIES), true)) {
                $metadata['reason_category'] = $reasonCategory;
            }
            if ($effectiveDestinationId > 0) {
                $metadata['original_destination_id'] = $originalDestinationId;
                $metadata['effective_destination_id'] = $effectiveDestinationId;
                $metadata['destination_resolution'] = 'execution_time';
            }
            if ($reasonCategory === 'remote_effect_uncertain'
                && is_string($packageIdentity)
                && preg_match('/\A[a-f0-9]{64}\z/i', $packageIdentity) === 1) {
                $metadata['package_identity'] = strtolower($packageIdentity);
                $metadata['package_verified'] = 'NOT_CONFIRMED';
                if (is_int($packageSize) && $packageSize > 0) {
                    $metadata['package_size'] = $packageSize;
                }
                $metadata['confirmation_source'] = 'bridge_state_pending';
            }
            $recorded = $preBridge
                ? $this->repository->failPreBridgeJob($jobId, $this->workerId, 'Falha pré-Bridge no worker de devolução.', $metadata)
                : $this->repository->failJob($jobId, $this->workerId, 'Falha técnica no worker de devolução.', $metadata);
            if (!$recorded) {
                Logger::error('[ReportDeliveryWorker] Falha não registrada: lease ausente', [
                    'job_id' => $jobId,
                    'ledger_stage' => 'lock_job_not_found',
                ]);
                return null;
            }
            return $preBridge ? null : $this->repository->lastAttemptId();
        } catch (Throwable $failure) {
            Logger::error('[ReportDeliveryWorker] Não foi possível registrar falha', [
                'job_id' => $jobId,
                'error_class' => get_class($failure),
                'ledger_stage' => $this->repository->lastLedgerFailureStage() ?? 'unknown',
            ]);
            return null;
        }
    }

    private function emitRunOneResult(string $result, string $reason, int $jobId, ?int $attemptId): void
    {
        $allowedResults = ['CLAIMED', 'NOT_CLAIMED', 'NOT_ELIGIBLE', 'DELIVERED', 'FAILED', 'UNCERTAIN'];
        $result = in_array($result, $allowedResults, true) ? $result : 'FAILED';
        $reason = preg_match('/\A[a-z0-9_:-]{1,64}\z/i', $reason) === 1 ? $reason : 'unknown';
        echo "RUNONE_RESULT={$result}\n";
        echo "REASON={$reason}\n";
        echo "JOB_ID={$jobId}\n";
        echo "ATTEMPT_ID=" . ($attemptId !== null && $attemptId > 0 ? (string) $attemptId : 'NONE') . "\n";
    }

    /** @param array<string,mixed> $context */
    private function logPhase(string $phase, ?Throwable $error, array $context = []): void
    {
        $data = [
            'phase' => $phase,
            'error_class' => $error !== null ? get_class($error) : 'NONE',
            'sqlstate' => $this->sqlState($error),
            'error_location' => $error !== null ? basename($error->getFile()) . ':' . $error->getLine() : basename(__FILE__) . ':' . __LINE__,
        ];
        foreach (['job_id', 'reason_category'] as $key) {
            if (array_key_exists($key, $context)) {
                $data[$key] = is_int($context[$key]) ? $context[$key] : (string) $context[$key];
            }
        }
        if (in_array($phase, ['DELIVERED', 'NOT_ELIGIBLE'], true)) {
            Logger::sanitized('[ReportDeliveryWorker] RUNONE_PHASE', $data);
        } else {
            Logger::error('[ReportDeliveryWorker] RUNONE_PHASE', $data);
        }
    }

    private function sqlState(?Throwable $error): string
    {
        if (!$error instanceof PDOException || !is_array($error->errorInfo ?? null)) {
            return 'NONE';
        }
        $state = (string) ($error->errorInfo[0] ?? '');
        return preg_match('/\A[A-Z0-9]{2,5}\z/', $state) === 1 ? $state : 'NONE';
    }

    private function artifactService(): ReportDeliveryArtifactService
    {
        return $this->artifactService ??= new ReportDeliveryArtifactService();
    }

    private function workerId(): string
    {
        $configured = trim((string) getenv('VOXEL_REPORT_DELIVERY_WORKER_ID'));
        if ($configured !== '' && preg_match('/^[a-zA-Z0-9._:-]{3,120}$/', $configured)) {
            return $configured;
        }
        return 'local-dicom-worker';
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}

if (!defined('VOXEL_REPORT_DELIVERY_WORKER_LIBRARY')) {
    $checkRequested = in_array('--check', $argv, true);
    $explicitJobId = null;
    foreach ($argv as $argument) {
        if (preg_match('/^--job-id=([1-9][0-9]*)$/', $argument, $matches) === 1) {
            $explicitJobId = (int) $matches[1];
            break;
        }
    }
    if (!$checkRequested && $explicitJobId === null && ReportDeliveryRuntimeConfig::workerKillSwitchEnabled()) {
        fwrite(STDERR, "worker_kill_switch_enabled\n");
        exit(78);
    }
    if ($explicitJobId !== null && ReportDeliveryRuntimeConfig::workerKillSwitchEnabled()) {
        fwrite(STDERR, "RUNONE_RESULT=NOT_CLAIMED\nREASON=kill_switch\nJOB_ID={$explicitJobId}\nATTEMPT_ID=NONE\n");
        exit(4);
    }
    try {
        $worker = new LocalDicomDeliveryWorker();
        if ($checkRequested) {
            exit($worker->check());
        }
        if ($explicitJobId !== null) {
            exit($worker->runOne($explicitJobId));
        }
        $worker->run();
    } catch (Throwable $error) {
        $jobLabel = $explicitJobId !== null ? (string) $explicitJobId : 'UNKNOWN';
        fwrite(STDERR, "PHASE=BOOTSTRAP_FAILED\nERROR_CLASS=" . get_class($error) . "\nSQLSTATE=NONE\nERROR_LOCATION=" . basename($error->getFile()) . ':' . $error->getLine() . "\nRUNONE_RESULT=FAILED\nREASON=bootstrap_failed\nJOB_ID={$jobLabel}\nATTEMPT_ID=NONE\n");
        exit(4);
    }
}
