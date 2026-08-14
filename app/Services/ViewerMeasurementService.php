<?php

namespace App\Services;

use App\Core\Logger;
use App\Repositories\ViewerMeasurementRepository;

/**
 * Boundary seguro entre o VOXEL VIEW e o backend PHP.
 *
 * O browser nunca informa tenant, usuário ou estudo efetivo: esses dados são
 * sempre derivados da sessão bearer criada a partir do token de abertura.
 */
class ViewerMeasurementService
{
    private const ALLOWED_TOOLS = [
        'Length',
        'Bidirectional',
        'SegmentBidirectional',
        'ArrowAnnotate',
        'EllipticalROI',
        'CircleROI',
        'RectangleROI',
        'PlanarFreehandROI',
        'SplineROI',
        'LivewireContour',
        'Probe',
        'Angle',
        'CobbAngle',
        'UltrasoundDirectional',
        'UltrasoundPleuraBLine',
    ];

    private ViewerMeasurementRepository $repository;

    public function __construct()
    {
        $this->repository = new ViewerMeasurementRepository();
    }

    /**
     * Gera a credencial de curta duração entregue ao adapter do viewer.
     * Apenas o hash é persistido; o token bruto existe somente durante o redirect.
     */
    public function createAdapterToken(array $viewerToken): string
    {
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = $viewerToken['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+30 minutes'));

        $this->repository->createSession(
            (int) $viewerToken['id'],
            hash('sha256', $rawToken),
            (int) $viewerToken['estudo_id'],
            (string) $viewerToken['study_instance_uid'],
            isset($viewerToken['tenant_id']) ? (int) $viewerToken['tenant_id'] : null,
            isset($viewerToken['usuario_id']) ? (int) $viewerToken['usuario_id'] : null,
            $expiresAt
        );

        return $rawToken;
    }

    public function receive(string $bearerToken, array $payload): array
    {
        $session = $this->resolveSession($bearerToken);
        if (!$session) {
            return ['ok' => false, 'status' => 401, 'error' => 'sessao_viewer_invalida'];
        }

        $action = (string) ($payload['action'] ?? 'upsert');
        if (!in_array($action, ['upsert', 'remove'], true)) {
            return ['ok' => false, 'status' => 422, 'error' => 'acao_invalida'];
        }

        $studyUid = trim((string) ($payload['study_instance_uid'] ?? ''));
        if ($studyUid === '' || !hash_equals((string) $session->study_instance_uid, $studyUid)) {
            Logger::warning('ViewerMeasurementService: estudo divergente no adapter', [
                'session_id' => $session->id,
                'received_study_uid' => $studyUid,
            ]);
            return ['ok' => false, 'status' => 403, 'error' => 'estudo_nao_autorizado'];
        }

        $measurement = is_array($payload['measurement'] ?? null) ? $payload['measurement'] : [];
        $measurementUid = trim((string) ($measurement['uid'] ?? ''));
        if ($measurementUid === '' || strlen($measurementUid) > 128) {
            return ['ok' => false, 'status' => 422, 'error' => 'measurement_uid_invalido'];
        }

        try {
            if ($action === 'remove') {
                $this->repository->markMeasurementRemoved((int) $session->id, $measurementUid);
                $this->repository->touchSession((int) $session->id);

                return ['ok' => true, 'action' => 'removed', 'measurement_uid' => $measurementUid];
            }

            $normalized = $this->normalizeMeasurement($measurement, $session);
            $measurementId = $this->repository->upsertMeasurement((int) $session->id, $normalized);
            $this->repository->touchSession((int) $session->id);

            return [
                'ok' => true,
                'action' => 'upserted',
                'measurement_id' => $measurementId,
                'measurement_uid' => $normalized['measurement_uid'],
            ];
        } catch (\Throwable $e) {
            Logger::error('ViewerMeasurementService: falha ao gravar snapshot', [
                'session_id' => $session->id,
                'action' => $action,
                'measurement_uid' => $measurementUid,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'status' => 500, 'error' => 'falha_ao_gravar_medida'];
        }
    }

    private function resolveSession(string $bearerToken): ?object
    {
        if ($bearerToken === '' || !preg_match('/^[a-f0-9]{64}$/', $bearerToken)) {
            return null;
        }

        return $this->repository->findActiveSessionByTokenHash(hash('sha256', $bearerToken));
    }

    private function normalizeMeasurement(array $measurement, object $session): array
    {
        $toolName = trim((string) ($measurement['tool_name'] ?? $measurement['toolName'] ?? ''));
        if (!in_array($toolName, self::ALLOWED_TOOLS, true)) {
            throw new \InvalidArgumentException('Ferramenta de medição não permitida.');
        }

        $measurementUid = trim((string) ($measurement['uid'] ?? ''));
        $displayValue = trim((string) ($measurement['display_value'] ?? ''));
        if ($measurementUid === '' || $displayValue === '' || strlen($displayValue) > 255) {
            throw new \InvalidArgumentException('Snapshot de medição incompleto.');
        }

        $numericValue = $measurement['numeric_value'] ?? null;
        if ($numericValue !== null && $numericValue !== '' && !is_numeric($numericValue)) {
            throw new \InvalidArgumentException('Valor numérico inválido.');
        }
        $numericValue = ($numericValue === null || $numericValue === '')
            ? null
            : number_format((float) $numericValue, 6, '.', '');

        $points = $measurement['points'] ?? null;
        if ($points !== null && !is_array($points)) {
            throw new \InvalidArgumentException('Geometria de pontos inválida.');
        }

        $rawPayload = [
            'schema_version' => 1,
            'uid' => $measurementUid,
            'tool_name' => $toolName,
            'source_name' => $this->limit($measurement['source_name'] ?? '', 80),
            'source_version' => $this->limit($measurement['source_version'] ?? '', 32),
            'study_instance_uid' => (string) $session->study_instance_uid,
            'series_instance_uid' => $this->limit($measurement['series_instance_uid'] ?? '', 255),
            'sop_instance_uid' => $this->limit($measurement['sop_instance_uid'] ?? '', 255),
            'frame_of_reference_uid' => $this->limit($measurement['frame_of_reference_uid'] ?? '', 255),
            'frame_number' => $this->positiveIntOrNull($measurement['frame_number'] ?? null),
            'label' => $this->limit($measurement['label'] ?? '', 255),
            'display_value' => $displayValue,
            'numeric_value' => $numericValue,
            'unit' => $this->limit($measurement['unit'] ?? '', 32),
            'points' => $points,
            'captured_at_client' => $this->limit($measurement['captured_at_client'] ?? '', 40),
        ];

        $encodedRaw = json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $encodedPoints = $points === null ? null : json_encode($points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedRaw === false || ($encodedPoints !== null && $encodedPoints === false)) {
            throw new \InvalidArgumentException('Não foi possível serializar a medição.');
        }
        if (strlen($encodedRaw) > 60000 || ($encodedPoints !== null && strlen($encodedPoints) > 50000)) {
            throw new \InvalidArgumentException('Snapshot de medição excede o limite permitido.');
        }

        return [
            'tenant_id' => $session->tenant_id === null ? null : (int) $session->tenant_id,
            'estudo_id' => (int) $session->estudo_id,
            'study_instance_uid' => (string) $session->study_instance_uid,
            'measurement_uid' => $measurementUid,
            'tool_name' => $toolName,
            'source_name' => $rawPayload['source_name'] ?: null,
            'source_version' => $rawPayload['source_version'] ?: null,
            'series_instance_uid' => $rawPayload['series_instance_uid'] ?: null,
            'sop_instance_uid' => $rawPayload['sop_instance_uid'] ?: null,
            'frame_of_reference_uid' => $rawPayload['frame_of_reference_uid'] ?: null,
            'frame_number' => $rawPayload['frame_number'],
            'label' => $rawPayload['label'] ?: null,
            'display_value' => $displayValue,
            'numeric_value' => $numericValue,
            'unit' => $rawPayload['unit'] ?: null,
            'points_payload' => $encodedPoints,
            'raw_payload' => $encodedRaw,
            'payload_hash' => hash('sha256', $encodedRaw),
        ];
    }

    private function limit(mixed $value, int $limit): string
    {
        $value = trim((string) $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $value = (int) $value;
        return $value > 0 ? $value : null;
    }
}
