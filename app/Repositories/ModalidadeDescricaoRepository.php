<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/** Persistência tenant-aware de sugestões e correções manuais de Study Description. */
class ModalidadeDescricaoRepository
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

    /** @return array<int,array{id:int,descricao:string,uso_count:int}> */
    public function suggestions(int $tenantId, string $modalidade, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT id, descricao, uso_count
             FROM bi_modalidade_descricoes
             WHERE tenant_id = :tenant_id
               AND modalidade = :modalidade
               AND ativo = 1
             ORDER BY uso_count DESC, descricao ASC
             LIMIT {$limit}"
        );
        $stmt->execute(['tenant_id' => $tenantId, 'modalidade' => $modalidade]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function lockStudy(int $studyId, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, modalities, study_description, study_description_manual
             FROM bi_pacs_estudos
             WHERE id = :study_id AND tenant_id = :tenant_id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute(['study_id' => $studyId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateManualDescription(int $studyId, int $tenantId, string $descricao): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE bi_pacs_estudos
             SET study_description = :descricao,
                 study_description_manual = 1,
                 atualizado_em = NOW()
             WHERE id = :study_id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            'descricao' => $descricao,
            'study_id' => $studyId,
            'tenant_id' => $tenantId,
        ]);
    }

    public function countBlankByModality(int $tenantId, string $modalidade): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM bi_pacs_estudos
             WHERE tenant_id = :tenant_id
               AND modalities = :modalidade
               AND study_description_manual = 0
               AND (study_description IS NULL OR TRIM(study_description) = '')"
        );
        $stmt->execute(['tenant_id' => $tenantId, 'modalidade' => $modalidade]);
        return (int) $stmt->fetchColumn();
    }

    public function updateBlankByModality(int $tenantId, string $modalidade, string $descricao): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE bi_pacs_estudos
             SET study_description = :descricao,
                 study_description_manual = 1,
                 atualizado_em = NOW()
             WHERE tenant_id = :tenant_id
               AND modalities = :modalidade
               AND study_description_manual = 0
               AND (study_description IS NULL OR TRIM(study_description) = '')"
        );
        $stmt->execute([
            'descricao' => $descricao,
            'tenant_id' => $tenantId,
            'modalidade' => $modalidade,
        ]);
        return $stmt->rowCount();
    }

    public function registerSuggestion(int $tenantId, string $modalidade, string $descricao, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO bi_modalidade_descricoes
                (tenant_id, modalidade, descricao, uso_count, ativo, criado_por)
             VALUES (:tenant_id, :modalidade, :descricao, 1, 1, :criado_por)
             ON DUPLICATE KEY UPDATE
                uso_count = uso_count + 1,
                ativo = 1,
                criado_por = VALUES(criado_por)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'modalidade' => $modalidade,
            'descricao' => $descricao,
            'criado_por' => $userId > 0 ? $userId : null,
        ]);
    }
}
