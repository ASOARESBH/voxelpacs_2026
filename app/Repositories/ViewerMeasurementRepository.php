<?php

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Persistência da VOXEL Measurement Integration Layer.
 *
 * Guarda snapshots normalizados enviados pelo adapter do VOXEL VIEW e o
 * histórico das medidas efetivamente inseridas no laudário. Não substitui
 * o MeasurementService do OHIF nem o DICOM SR.
 */
class ViewerMeasurementRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function createSession(
        int $viewerTokenId,
        string $tokenHash,
        int $estudoId,
        string $studyInstanceUid,
        ?int $tenantId,
        ?int $usuarioId,
        string $expiresAt
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pacs_viewer_measurement_sessions
                (viewer_token_id, token_hash, estudo_id, study_instance_uid, tenant_id, usuario_id, expires_at)
             VALUES
                (:viewer_token_id, :token_hash, :estudo_id, :study_instance_uid, :tenant_id, :usuario_id, :expires_at)'
        );
        $stmt->execute([
            ':viewer_token_id' => $viewerTokenId,
            ':token_hash' => $tokenHash,
            ':estudo_id' => $estudoId,
            ':study_instance_uid' => $studyInstanceUid,
            ':tenant_id' => $tenantId,
            ':usuario_id' => $usuarioId,
            ':expires_at' => $expiresAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findActiveSessionByTokenHash(string $tokenHash): ?object
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM pacs_viewer_measurement_sessions
             WHERE token_hash = :token_hash
               AND expires_at > NOW()
               AND revogado_em IS NULL
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => $tokenHash]);

        return $stmt->fetch() ?: null;
    }

    public function touchSession(int $sessionId): void
    {
        $this->pdo->prepare(
            'UPDATE pacs_viewer_measurement_sessions
             SET last_seen_at = NOW()
             WHERE id = :id'
        )->execute([':id' => $sessionId]);
    }

    /**
     * Insere ou atualiza o snapshot atual de uma measurement no escopo da sessão.
     * A unique key (measurement_session_id, measurement_uid) evita duplicação
     * quando o OHIF publica atualizações sucessivas do mesmo desenho.
     */
    public function upsertMeasurement(int $sessionId, array $measurement): int
    {
        $sql = 'INSERT INTO pacs_viewer_measurements (
                    measurement_session_id, tenant_id, estudo_id, study_instance_uid,
                    measurement_uid, tool_name, source_name, source_version,
                    series_instance_uid, sop_instance_uid, frame_of_reference_uid, frame_number,
                    label, display_value, numeric_value, unit, points_payload,
                    raw_payload, payload_hash, is_removed, captured_at
                ) VALUES (
                    :measurement_session_id, :tenant_id, :estudo_id, :study_instance_uid,
                    :measurement_uid, :tool_name, :source_name, :source_version,
                    :series_instance_uid, :sop_instance_uid, :frame_of_reference_uid, :frame_number,
                    :label, :display_value, :numeric_value, :unit, :points_payload,
                    :raw_payload, :payload_hash, 0, NOW()
                ) ON DUPLICATE KEY UPDATE
                    tool_name = VALUES(tool_name),
                    source_name = VALUES(source_name),
                    source_version = VALUES(source_version),
                    series_instance_uid = VALUES(series_instance_uid),
                    sop_instance_uid = VALUES(sop_instance_uid),
                    frame_of_reference_uid = VALUES(frame_of_reference_uid),
                    frame_number = VALUES(frame_number),
                    label = VALUES(label),
                    display_value = VALUES(display_value),
                    numeric_value = VALUES(numeric_value),
                    unit = VALUES(unit),
                    points_payload = VALUES(points_payload),
                    raw_payload = VALUES(raw_payload),
                    payload_hash = VALUES(payload_hash),
                    is_removed = 0,
                    removed_at = NULL,
                    captured_at = NOW()';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':measurement_session_id' => $sessionId,
            ':tenant_id' => $measurement['tenant_id'],
            ':estudo_id' => $measurement['estudo_id'],
            ':study_instance_uid' => $measurement['study_instance_uid'],
            ':measurement_uid' => $measurement['measurement_uid'],
            ':tool_name' => $measurement['tool_name'],
            ':source_name' => $measurement['source_name'],
            ':source_version' => $measurement['source_version'],
            ':series_instance_uid' => $measurement['series_instance_uid'],
            ':sop_instance_uid' => $measurement['sop_instance_uid'],
            ':frame_of_reference_uid' => $measurement['frame_of_reference_uid'],
            ':frame_number' => $measurement['frame_number'],
            ':label' => $measurement['label'],
            ':display_value' => $measurement['display_value'],
            ':numeric_value' => $measurement['numeric_value'],
            ':unit' => $measurement['unit'],
            ':points_payload' => $measurement['points_payload'],
            ':raw_payload' => $measurement['raw_payload'],
            ':payload_hash' => $measurement['payload_hash'],
        ]);

        $idStmt = $this->pdo->prepare(
            'SELECT id FROM pacs_viewer_measurements
             WHERE measurement_session_id = :session_id AND measurement_uid = :measurement_uid
             LIMIT 1'
        );
        $idStmt->execute([
            ':session_id' => $sessionId,
            ':measurement_uid' => $measurement['measurement_uid'],
        ]);

        return (int) $idStmt->fetchColumn();
    }

    public function markMeasurementRemoved(int $sessionId, string $measurementUid): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE pacs_viewer_measurements
             SET is_removed = 1, removed_at = NOW()
             WHERE measurement_session_id = :session_id
               AND measurement_uid = :measurement_uid'
        );
        $stmt->execute([
            ':session_id' => $sessionId,
            ':measurement_uid' => $measurementUid,
        ]);
    }

    public function listActiveMeasurementsForReport(object $report): array
    {
        $sql = 'SELECT m.id, m.measurement_uid, m.tool_name, m.source_name, m.source_version,
                       m.series_instance_uid, m.sop_instance_uid, m.frame_number,
                       m.label, m.display_value, m.numeric_value, m.unit,
                       m.payload_hash, m.captured_at, m.updated_at
                FROM pacs_viewer_measurements m
                WHERE m.estudo_id = :estudo_id
                  AND m.is_removed = 0';
        $params = [':estudo_id' => (int) $report->estudo_id];

        if ($report->tenant_id === null) {
            $sql .= ' AND m.tenant_id IS NULL';
        } else {
            $sql .= ' AND m.tenant_id = :tenant_id';
            $params[':tenant_id'] = (int) $report->tenant_id;
        }

        $sql .= ' ORDER BY m.updated_at DESC, m.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findActiveMeasurementsByIdsForReport(object $report, array $measurementIds): array
    {
        $measurementIds = array_values(array_unique(array_filter(array_map('intval', $measurementIds))));
        if (!$measurementIds) {
            return [];
        }

        $placeholders = [];
        $params = [':estudo_id' => (int) $report->estudo_id];
        foreach ($measurementIds as $index => $measurementId) {
            $placeholder = ':measurement_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $measurementId;
        }

        $sql = 'SELECT m.*
                FROM pacs_viewer_measurements m
                WHERE m.estudo_id = :estudo_id
                  AND m.is_removed = 0
                  AND m.id IN (' . implode(', ', $placeholders) . ')';

        if ($report->tenant_id === null) {
            $sql .= ' AND m.tenant_id IS NULL';
        } else {
            $sql .= ' AND m.tenant_id = :tenant_id';
            $params[':tenant_id'] = (int) $report->tenant_id;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function usageExists(int $reportId, int $measurementId, string $measurementHash, string $section): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM report_measurement_usages
             WHERE report_id = :report_id
               AND measurement_id = :measurement_id
               AND measurement_hash = :measurement_hash
               AND secao_destino = :section
             LIMIT 1'
        );
        $stmt->execute([
            ':report_id' => $reportId,
            ':measurement_id' => $measurementId,
            ':measurement_hash' => $measurementHash,
            ':section' => $section,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function createUsage(
        int $reportId,
        array $measurement,
        ?int $tenantId,
        int $estudoId,
        string $section,
        string $insertedText,
        int $userId
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO report_measurement_usages
                (report_id, measurement_id, tenant_id, estudo_id, secao_destino,
                 measurement_hash, texto_inserido, usuario_id)
             VALUES
                (:report_id, :measurement_id, :tenant_id, :estudo_id, :section,
                 :measurement_hash, :inserted_text, :user_id)'
        );
        $stmt->execute([
            ':report_id' => $reportId,
            ':measurement_id' => (int) $measurement['id'],
            ':tenant_id' => $tenantId,
            ':estudo_id' => $estudoId,
            ':section' => $section,
            ':measurement_hash' => $measurement['payload_hash'],
            ':inserted_text' => $insertedText,
            ':user_id' => $userId,
        ]);
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
