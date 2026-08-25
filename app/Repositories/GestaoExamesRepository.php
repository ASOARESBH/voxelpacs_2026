<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Consultas administrativas da Gestão de Exames.
 *
 * A prioridade bruta vem de bi_pacs_estudos.dicom_priority, que representa a
 * tag DICOM (0040,1003). O override operacional é separado e auditável.
 */
class GestaoExamesRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: Database::getInstance();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function findStudyContext(int $studyId, int $tenantId): ?array
    {
        $sql = "
            SELECT
                e.id,
                e.tenant_id,
                e.study_instance_uid,
                e.patient_name,
                e.modalities,
                e.study_description,
                e.study_description_manual,
                e.situacao,
                COALESCE(e.dicom_priority, '') AS dicom_priority,
                COALESCE(NULLIF(e.dicom_priority_override, ''), e.dicom_priority, 'ROUTINE') AS prioridade_efetiva,
                e.dicom_priority_override,
                r.id AS report_id,
                r.public_token AS report_public_token,
                r.situacao AS report_situacao,
                c.id AS chat_id,
                c.status AS chat_status,
                c.assunto AS chat_assunto
            FROM bi_pacs_estudos e
            LEFT JOIN reports r
                   ON r.estudo_id = e.id AND r.tenant_id = e.tenant_id
            LEFT JOIN pacs_report_chats c
                   ON c.report_id = r.id AND c.tenant_id = r.tenant_id
            WHERE e.id = :study_id
              AND e.tenant_id = :tenant_id
            LIMIT 1
        ";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['study_id' => $studyId, 'tenant_id' => $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\PDOException $e) {
            // Permite que a tela informe migration pendente sem esconder a causa.
            if (stripos($e->getMessage(), 'dicom_priority_override') === false) {
                throw $e;
            }
            $fallback = $this->pdo->prepare("
                SELECT
                    e.id, e.tenant_id, e.study_instance_uid, e.patient_name,
                    e.modalities, e.study_description, 0 AS study_description_manual, e.situacao,
                    COALESCE(e.dicom_priority, '') AS dicom_priority,
                    COALESCE(e.dicom_priority, 'ROUTINE') AS prioridade_efetiva,
                    NULL AS dicom_priority_override,
                    r.id AS report_id, r.public_token AS report_public_token, r.situacao AS report_situacao,
                    c.id AS chat_id, c.status AS chat_status,
                    c.assunto AS chat_assunto
                FROM bi_pacs_estudos e
                LEFT JOIN reports r ON r.estudo_id = e.id AND r.tenant_id = e.tenant_id
                LEFT JOIN pacs_report_chats c ON c.report_id = r.id AND c.tenant_id = r.tenant_id
                WHERE e.id = :study_id AND e.tenant_id = :tenant_id
                LIMIT 1
            ");
            $fallback->execute(['study_id' => $studyId, 'tenant_id' => $tenantId]);
            $row = $fallback->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }
    }

    public function lockStudyContext(int $studyId, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                id, tenant_id, study_instance_uid, situacao, modalities, study_description,
                COALESCE(dicom_priority, '') AS dicom_priority,
                COALESCE(NULLIF(dicom_priority_override, ''), dicom_priority, 'ROUTINE') AS prioridade_efetiva,
                dicom_priority_override
            FROM bi_pacs_estudos
            WHERE id = :study_id AND tenant_id = :tenant_id
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute(['study_id' => $studyId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updatePriorityOverride(int $studyId, int $tenantId, string $priority): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE bi_pacs_estudos
             SET dicom_priority_override = :priority
             WHERE id = :study_id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            'priority' => $priority,
            'study_id' => $studyId,
            'tenant_id' => $tenantId,
        ]);
        // A linha já pode conter o mesmo override; a existência foi garantida
        // pelo SELECT ... FOR UPDATE executado pelo Service antes deste UPDATE.
    }

    public function addPriorityAudit(
        int $studyId,
        int $tenantId,
        ?string $rawDicom,
        string $previous,
        string $next,
        string $reason,
        int $userId
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO bi_pacs_estudos_prioridade_auditoria
                (tenant_id, estudo_id, prioridade_dicom_original, prioridade_anterior,
                 prioridade_nova, motivo, usuario_id, criado_em)
             VALUES (:tenant_id, :estudo_id, :raw_dicom, :previous, :next, :reason, :user_id, NOW())'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'estudo_id' => $studyId,
            'raw_dicom' => $rawDicom !== null && $rawDicom !== '' ? $rawDicom : null,
            'previous' => $previous,
            'next' => $next,
            'reason' => $reason,
            'user_id' => $userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function listPriorityAudit(int $studyId, int $tenantId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT id, prioridade_dicom_original, prioridade_anterior, prioridade_nova,
                    motivo, usuario_id, criado_em
             FROM bi_pacs_estudos_prioridade_auditoria
             WHERE estudo_id = :study_id AND tenant_id = :tenant_id
             ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute(['study_id' => $studyId, 'tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
