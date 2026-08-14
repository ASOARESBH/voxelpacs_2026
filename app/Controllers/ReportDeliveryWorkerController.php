<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Repositories\ReportDeliveryWorkerRepository;
use App\Services\ReportDeliveryCryptoService;
use Throwable;

/**
 * API exclusiva do worker persistente do Report Delivery Hub.
 *
 * Não depende de sessão PHP. Cada chamada exige bearer token do ambiente e a
 * API expõe somente o job atualmente concedido ao worker autenticado.
 */
class ReportDeliveryWorkerController extends Controller
{
    private ReportDeliveryWorkerRepository $repository;
    private ReportDeliveryCryptoService $crypto;

    public function __construct()
    {
        $this->repository = new ReportDeliveryWorkerRepository(Database::getInstance());
        $this->crypto = new ReportDeliveryCryptoService();
    }

    public function lease(): void
    {
        $workerId = $this->authenticate();
        if ($workerId === null) {
            return;
        }

        try {
            $job = $this->repository->claimNextJob($workerId);
            if (!$job) {
                $this->json(['success' => true, 'job' => null]);
            }

            $secret = '';
            if (!empty($job['configuration_secret'])) {
                $secret = $this->crypto->decrypt((string) $job['configuration_secret']);
            }
            unset($job['configuration_secret']);
            $job['configuration_secret'] = $secret;
            $job['payload'] = json_decode((string) $job['payload_json'], true) ?: [];
            unset($job['payload_json']);

            $this->json(['success' => true, 'job' => $job]);
        } catch (Throwable $e) {
            Logger::error('[ReportDeliveryWorker::lease] Falha ao conceder job', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'message' => 'Falha interna ao consultar a fila.'], 500);
        }
    }

    public function complete(int $id): void
    {
        $workerId = $this->authenticate();
        if ($workerId === null) {
            return;
        }
        $body = $this->jsonBody();

        try {
            $completed = $this->repository->completeJob(
                $id,
                $workerId,
                $this->nullableShortText($body['remote_reference'] ?? null, 255),
                $this->safeMetadata($body['metadata'] ?? [])
            );
            if (!$completed) {
                $this->json(['success' => false, 'message' => 'Job não está reservado para este worker.'], 409);
            }
            $this->json(['success' => true]);
        } catch (Throwable $e) {
            Logger::error('[ReportDeliveryWorker::complete] Falha ao concluir job', ['job_id' => $id, 'error' => $e->getMessage()]);
            $this->json(['success' => false, 'message' => 'Falha ao concluir job.'], 500);
        }
    }

    public function fail(int $id): void
    {
        $workerId = $this->authenticate();
        if ($workerId === null) {
            return;
        }
        $body = $this->jsonBody();
        $error = trim((string) ($body['error'] ?? 'Falha técnica não especificada.'));
        if ($error === '') {
            $error = 'Falha técnica não especificada.';
        }

        try {
            $failed = $this->repository->failJob($id, $workerId, $error, $this->safeMetadata($body['metadata'] ?? []));
            if (!$failed) {
                $this->json(['success' => false, 'message' => 'Job não está reservado para este worker.'], 409);
            }
            $this->json(['success' => true]);
        } catch (Throwable $e) {
            Logger::error('[ReportDeliveryWorker::fail] Falha ao registrar erro do job', ['job_id' => $id, 'error' => $e->getMessage()]);
            $this->json(['success' => false, 'message' => 'Falha ao registrar a tentativa.'], 500);
        }
    }

    private function authenticate(): ?string
    {
        $expected = (string) getenv('VOXEL_REPORT_DELIVERY_WORKER_TOKEN');
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) || strlen($expected) < 32 || !hash_equals($expected, $matches[1])) {
            $this->json(['success' => false, 'message' => 'Credencial de worker inválida.'], 401);
        }

        $workerId = trim($_SERVER['HTTP_X_VOXEL_WORKER_ID'] ?? '');
        if ($workerId === '' || !preg_match('/^[a-zA-Z0-9._:-]{3,120}$/', $workerId)) {
            $this->json(['success' => false, 'message' => 'Identificador de worker inválido.'], 422);
        }

        return $workerId;
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw ?: '{}', true);

        return is_array($body) ? $body : [];
    }

    /** @param mixed $metadata @return array<string,mixed> */
    private function safeMetadata(mixed $metadata): array
    {
        if (!is_array($metadata)) {
            return [];
        }
        unset($metadata['authorization'], $metadata['token'], $metadata['password'], $metadata['secret']);

        return $metadata;
    }

    private function nullableShortText(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
