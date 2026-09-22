<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Logger;
use PDO;

/** Repositório do anexo complementar privado, isolado por tenant e estudo. */
final class ExamesComplementaresRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function findEstudoById(int $estudoId, ?int $tenantId, bool $bypassGlobal): ?array
    {
        if ($tenantId === null && !$bypassGlobal) return null;
        $where = 'e.id = :id';
        $params = ['id' => $estudoId];
        if ($tenantId !== null) {
            $where .= ' AND e.tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        try {
            $stmt = $this->pdo->prepare("SELECT e.id, e.tenant_id FROM bi_pacs_estudos e WHERE {$where} LIMIT 1");
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            Logger::error('[ExamesComplementaresRepository::findEstudoById] ' . $e->getMessage(), ['estudo_id' => $estudoId, 'tenant_id' => $tenantId]);
            return null;
        }
    }

    public function findByEstudoId(int $estudoId, int $tenantId): ?array
    {
        return $this->findOne('estudo_id = :estudo_id AND tenant_id = :tenant_id', ['estudo_id' => $estudoId, 'tenant_id' => $tenantId]);
    }

    public function findById(int $id, ?int $tenantId, bool $bypassGlobal): ?array
    {
        if ($tenantId === null && !$bypassGlobal) return null;
        $where = 'id = :id';
        $params = ['id' => $id];
        if ($tenantId !== null) {
            $where .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        return $this->findOne($where, $params);
    }

    public function upsert(array $data): array
    {
        $sql = \App\Core\SqlHelper::isPostgres()
            ? 'INSERT INTO bi_pacs_estudos_exames_complementares
                 (tenant_id, estudo_id, nome_original, nome_arquivo, mime_type, extensao, tamanho_bytes, hash_sha256, caminho_arquivo, usuario_id)
               VALUES (:tenant_id, :estudo_id, :nome_original, :nome_arquivo, :mime_type, :extensao, :tamanho_bytes, :hash_sha256, :caminho_arquivo, :usuario_id)
               ON CONFLICT (tenant_id, estudo_id) DO UPDATE SET nome_original = EXCLUDED.nome_original, nome_arquivo = EXCLUDED.nome_arquivo,
                 mime_type = EXCLUDED.mime_type, extensao = EXCLUDED.extensao, tamanho_bytes = EXCLUDED.tamanho_bytes,
                 hash_sha256 = EXCLUDED.hash_sha256, caminho_arquivo = EXCLUDED.caminho_arquivo, usuario_id = EXCLUDED.usuario_id, atualizado_em = CURRENT_TIMESTAMP'
            : 'INSERT INTO bi_pacs_estudos_exames_complementares
                 (tenant_id, estudo_id, nome_original, nome_arquivo, mime_type, extensao, tamanho_bytes, hash_sha256, caminho_arquivo, usuario_id)
               VALUES (:tenant_id, :estudo_id, :nome_original, :nome_arquivo, :mime_type, :extensao, :tamanho_bytes, :hash_sha256, :caminho_arquivo, :usuario_id)
               ON DUPLICATE KEY UPDATE nome_original = VALUES(nome_original), nome_arquivo = VALUES(nome_arquivo), mime_type = VALUES(mime_type),
                 extensao = VALUES(extensao), tamanho_bytes = VALUES(tamanho_bytes), hash_sha256 = VALUES(hash_sha256),
                 caminho_arquivo = VALUES(caminho_arquivo), usuario_id = VALUES(usuario_id), atualizado_em = CURRENT_TIMESTAMP';
        try {
            $this->pdo->prepare($sql)->execute([
                'tenant_id' => (int) $data['tenant_id'], 'estudo_id' => (int) $data['estudo_id'],
                'nome_original' => (string) $data['nome_original'], 'nome_arquivo' => (string) $data['nome_arquivo'],
                'mime_type' => (string) $data['mime_type'], 'extensao' => (string) $data['extensao'],
                'tamanho_bytes' => (int) $data['tamanho_bytes'], 'hash_sha256' => (string) $data['hash_sha256'],
                'caminho_arquivo' => (string) $data['caminho_arquivo'], 'usuario_id' => (int) $data['usuario_id'],
            ]);
            $row = $this->findByEstudoId((int) $data['estudo_id'], (int) $data['tenant_id']);
            if (!$row) throw new \RuntimeException('Anexo complementar não localizado após persistência.');
            return $row;
        } catch (\Throwable $e) {
            Logger::error('[ExamesComplementaresRepository::upsert] ' . $e->getMessage(), ['estudo_id' => $data['estudo_id'] ?? null, 'tenant_id' => $data['tenant_id'] ?? null]);
            throw $e;
        }
    }

    public function deleteByEstudoId(int $estudoId, int $tenantId): bool
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM bi_pacs_estudos_exames_complementares WHERE estudo_id = :estudo_id AND tenant_id = :tenant_id');
            $stmt->execute(['estudo_id' => $estudoId, 'tenant_id' => $tenantId]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            Logger::error('[ExamesComplementaresRepository::deleteByEstudoId] ' . $e->getMessage(), ['estudo_id' => $estudoId, 'tenant_id' => $tenantId]);
            throw $e;
        }
    }

    private function findOne(string $where, array $params): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id, tenant_id, estudo_id, nome_original, nome_arquivo, mime_type, extensao, tamanho_bytes, hash_sha256, caminho_arquivo, usuario_id, criado_em, atualizado_em FROM bi_pacs_estudos_exames_complementares WHERE {$where} LIMIT 1");
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            Logger::error('[ExamesComplementaresRepository::findOne] ' . $e->getMessage(), ['anexo_complementar' => true]);
            return null;
        }
    }
}
