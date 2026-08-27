<?php
declare(strict_types=1);

use App\Core\Logger;
use App\Repositories\ReportDeliveryWorkerRepository;
use App\Services\ReportDeliveryArtifactService;

require dirname(__DIR__) . '/app/bootstrap.php';

final class DeliveryWorkerFailure extends RuntimeException
{
    public function __construct(public readonly string $stage)
    {
        parent::__construct($stage);
    }
}

final class LocalDicomDeliveryWorker
{
    private const SUPPORTED_TRANSPORTS = ['dicom_pdf'];

    private ReportDeliveryWorkerRepository $repository;
    private ReportDeliveryArtifactService $artifactService;
    private string $workerId;
    private int $idleSeconds;

    public function __construct()
    {
        $this->repository = new ReportDeliveryWorkerRepository(\App\Core\Database::getInstance());
        $this->artifactService = new ReportDeliveryArtifactService();
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

    public function run(): void
    {
        Logger::info('[ReportDeliveryWorker] Serviço local iniciado', ['worker_id' => $this->workerId]);
        while (true) {
            try {
                $clinicalDate = date('Y-m-d');
                $expired = $this->repository->expireAutomaticJobsBefore($clinicalDate);
                if ($expired > 0) {
                    Logger::warning('[ReportDeliveryWorker] Pendências automáticas expiradas', ['count' => $expired]);
                }
                $job = $this->repository->claimNextJob($this->workerId, self::SUPPORTED_TRANSPORTS, $clinicalDate);
                if ($job === null) {
                    sleep($this->idleSeconds);
                    continue;
                }
                $this->deliver($job);
            } catch (Throwable $error) {
                Logger::error('[ReportDeliveryWorker] Ciclo local interrompido', [
                    'worker_id' => $this->workerId,
                    'error' => $error->getMessage(),
                ]);
                sleep($this->idleSeconds);
            }
        }
    }

    /** @param array<string,mixed> $job */
    private function deliver(array $job): void
    {
        $jobId = (int) ($job['id'] ?? 0);
        try {
            if ($jobId <= 0 || !in_array((string) ($job['transport'] ?? ''), self::SUPPORTED_TRANSPORTS, true)) {
                throw new DeliveryWorkerFailure('invalid_job');
            }

            $configuration = $this->decodeMap($job['configuration_json'] ?? null, 'invalid_configuration');
            $payload = $this->decodeMap($job['payload_json'] ?? null, 'invalid_payload');
            $this->validateDestination($configuration, $payload);

            $artifact = $this->artifactService->buildPdfForLeasedJob($jobId, $this->workerId);
            $result = $this->sendDicomPdf($job, $configuration, $payload, $artifact);
            $this->repository->completeJob($jobId, $this->workerId, $result['reference'], [
                'transport' => 'dicom_pdf',
                'environment' => (string) ($job['ambiente'] ?? ''),
                'artifact_sha256' => $result['sha256'],
                'artifact_size_bytes' => $result['size'],
            ]);
            Logger::info('[ReportDeliveryWorker] Entrega DICOM concluída', [
                'job_id' => $jobId,
                'transport' => 'dicom_pdf',
                'environment' => (string) ($job['ambiente'] ?? ''),
            ]);
        } catch (DeliveryWorkerFailure $error) {
            $this->failSafely($jobId, $error->stage);
        } catch (Throwable $error) {
            Logger::error('[ReportDeliveryWorker] Falha técnica de entrega', [
                'job_id' => $jobId,
                'error' => $error->getMessage(),
            ]);
            $this->failSafely($jobId, 'unexpected_error');
        }
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $configuration @param array<string,mixed> $payload @param array<string,mixed> $artifact @return array{reference:string,sha256:string,size:int} */
    private function sendDicomPdf(array $job, array $configuration, array $payload, array $artifact): array
    {
        if (!empty($configuration['use_tls'])) {
            throw new DeliveryWorkerFailure('tls_profile_required');
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

            $this->runCommand(['/usr/bin/dump2dcm', '--quiet', $metadataDumpPath, $metadataDicomPath], 'metadata_conversion_failed');
            $this->runCommand(['/usr/bin/pdf2dcm', '--quiet', '--study-from', $metadataDicomPath, '--instance-one', $pdfPath, $dicomPath], 'pdf_encapsulation_failed');
            if (!is_file($dicomPath) || filesize($dicomPath) < 256) {
                throw new DeliveryWorkerFailure('invalid_dicom_artifact');
            }

            $timeout = max(5, min(120, (int) ($job['timeout_seconds'] ?? 30)));
            $this->runCommand([
                '/usr/bin/storescu', '--quiet', '--disable-tls',
                '--aetitle', (string) $configuration['calling_ae'],
                '--call', (string) $configuration['called_ae'],
                '--timeout', (string) $timeout,
                '--socket-timeout', (string) $timeout,
                (string) $configuration['host'],
                (string) $configuration['port'],
                $dicomPath,
            ], 'cstore_failed');

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
                'reference' => 'dicom-cstore:' . substr($sha256, 0, 16),
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

    /** @param list<string> $command */
    private function runCommand(array $command, string $stage): void
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/bin:/bin']);
        if (!is_resource($process)) {
            throw new DeliveryWorkerFailure($stage);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                stream_get_contents($pipe);
                fclose($pipe);
            }
        }
        if (proc_close($process) !== 0) {
            throw new DeliveryWorkerFailure($stage);
        }
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

    private function failSafely(int $jobId, string $stage): void
    {
        if ($jobId <= 0) {
            return;
        }
        try {
            $this->repository->failJob($jobId, $this->workerId, 'Falha técnica no worker de devolução.', ['stage' => $stage]);
        } catch (Throwable $failure) {
            Logger::error('[ReportDeliveryWorker] Não foi possível registrar falha', [
                'job_id' => $jobId,
                'error' => $failure->getMessage(),
            ]);
        }
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

$worker = new LocalDicomDeliveryWorker();
if (in_array('--check', $argv, true)) {
    exit($worker->check());
}
$worker->run();
