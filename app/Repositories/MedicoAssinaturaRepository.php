<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\Logger;

/**
 * MedicoAssinaturaRepository — toda a SQL de bi_medico_assinaturas.
 * Toda query inclui tenant_id (denormalizado na tabela) além de medico_id —
 * mesmo padrão de defesa em profundidade de MedicoRepository::getUnidades()/
 * bi_medico_unidades, nunca confia só num JOIN pra escopo de tenant.
 */
class MedicoAssinaturaRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    /** As até 3 linhas (uma por tipo) já cadastradas para o médico. */
    public function findByMedicoId(int $medicoId, int $tenantId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM bi_medico_assinaturas WHERE medico_id = :medico_id AND tenant_id = :tenant_id"
            );
            $stmt->execute(['medico_id' => $medicoId, 'tenant_id' => $tenantId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::error('[MedicoAssinaturaRepository::findByMedicoId] ' . $e->getMessage(), ['medico_id' => $medicoId]);
            return [];
        }
    }

    /** A linha de um tipo específico (imagem|livre|certificado), se existir. */
    public function findByTipo(int $medicoId, int $tenantId, string $tipo): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM bi_medico_assinaturas WHERE medico_id = :medico_id AND tenant_id = :tenant_id AND tipo = :tipo LIMIT 1"
            );
            $stmt->execute(['medico_id' => $medicoId, 'tenant_id' => $tenantId, 'tipo' => $tipo]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            Logger::error('[MedicoAssinaturaRepository::findByTipo] ' . $e->getMessage(), ['medico_id' => $medicoId, 'tipo' => $tipo]);
            return null;
        }
    }

    /** A assinatura ativa do médico (no máximo 1), se houver — usada por ReportService::assinar(). */
    public function findAtiva(int $medicoId, int $tenantId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM bi_medico_assinaturas WHERE medico_id = :medico_id AND tenant_id = :tenant_id AND ativa = 1 LIMIT 1"
            );
            $stmt->execute(['medico_id' => $medicoId, 'tenant_id' => $tenantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            Logger::error('[MedicoAssinaturaRepository::findAtiva] ' . $e->getMessage(), ['medico_id' => $medicoId]);
            return null;
        }
    }

    /**
     * Cria ou substitui o arquivo de um tipo (imagem|livre) — nunca mexe em
     * `ativa` (isso é decisão exclusiva de ativar()/desativar()). Retorna o id.
     */
    public function upsertArquivo(int $medicoId, int $tenantId, string $tipo, string $caminhoArquivo): int
    {
        $existente = $this->findByTipo($medicoId, $tenantId, $tipo);
        if ($existente) {
            $this->pdo->prepare(
                "UPDATE bi_medico_assinaturas SET caminho_arquivo = :caminho WHERE id = :id"
            )->execute(['caminho' => $caminhoArquivo, 'id' => $existente['id']]);
            return (int) $existente['id'];
        }

        $this->pdo->prepare(
            "INSERT INTO bi_medico_assinaturas (tenant_id, medico_id, tipo, ativa, caminho_arquivo)
             VALUES (:tenant_id, :medico_id, :tipo, 0, :caminho)"
        )->execute([
            'tenant_id' => $tenantId, 'medico_id' => $medicoId, 'tipo' => $tipo, 'caminho' => $caminhoArquivo,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Ativa um único tipo especificado — NUNCA desativa outros automaticamente.
     * Exclusividade (bloquear se já houver outro tipo ativo, exigindo
     * desativação explícita antes) é decisão do Service, não daqui — troca
     * automática/silenciosa foi explicitamente descartada no pedido original.
     */
    public function ativar(int $medicoId, int $tenantId, string $tipo): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE bi_medico_assinaturas SET ativa = 1, ativado_em = NOW()
                 WHERE medico_id = :medico_id AND tenant_id = :tenant_id AND tipo = :tipo"
            );
            $stmt->execute(['medico_id' => $medicoId, 'tenant_id' => $tenantId, 'tipo' => $tipo]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            Logger::error('[MedicoAssinaturaRepository::ativar] ' . $e->getMessage(), ['medico_id' => $medicoId, 'tipo' => $tipo]);
            return false;
        }
    }

    public function desativar(int $medicoId, int $tenantId, string $tipo): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE bi_medico_assinaturas SET ativa = 0
                 WHERE medico_id = :medico_id AND tenant_id = :tenant_id AND tipo = :tipo"
            );
            $stmt->execute(['medico_id' => $medicoId, 'tenant_id' => $tenantId, 'tipo' => $tipo]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            Logger::error('[MedicoAssinaturaRepository::desativar] ' . $e->getMessage(), ['medico_id' => $medicoId, 'tipo' => $tipo]);
            return false;
        }
    }
}
