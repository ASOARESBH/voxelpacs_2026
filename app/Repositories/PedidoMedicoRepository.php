<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Logger;
use PDO;

/**
 * Persistência dos pedidos médicos anexados aos estudos.
 *
 * O tenant_id é sempre usado na leitura e escrita. Mesmo quando o estudo_id
 * já seria suficiente, a coluna denormalizada mantém defesa em profundidade
 * contra IDOR entre Negócios.
 */
class PedidoMedicoRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    /** Busca um estudo visível para o tenant efetivo ou para o superadmin global. */
    public function findEstudoById(int $estudoId, ?int $tenantId, bool $bypassGlobal): ?array
    {
        $where  = 'e.id = :id';
        $params = ['id' => $estudoId];

        if ($tenantId !== null) {
            $where .= ' AND e.tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        } elseif (!$bypassGlobal) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT e.id, e.tenant_id, e.study_instance_uid, e.patient_name,
                        e.patient_name_display, e.institution_name
                   FROM bi_pacs_estudos e
                  WHERE {$where}
                  LIMIT 1"
            );
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            Logger::error('[PedidoMedicoRepository::findEstudoById] ' . $e->getMessage(), [
                'estudo_id' => $estudoId,
                'tenant_id' => $tenantId,
            ]);
            return null;
        }
    }

    /** Busca o único pedido permitido para um estudo dentro do tenant. */
    public function findByEstudoId(int $estudoId, int $tenantId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, tenant_id, estudo_id, nome_original, nome_arquivo,
                        mime_type, extensao, tamanho_bytes, hash_sha256,
                        caminho_arquivo, usuario_id, criado_em, atualizado_em
                   FROM bi_pacs_estudos_pedidos
                  WHERE estudo_id = :estudo_id AND tenant_id = :tenant_id
                  LIMIT 1'
            );
            $stmt->execute(['estudo_id' => $estudoId, 'tenant_id' => $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            Logger::error('[PedidoMedicoRepository::findByEstudoId] ' . $e->getMessage(), [
                'estudo_id' => $estudoId,
                'tenant_id' => $tenantId,
            ]);
            return null;
        }
    }

    /** Busca um pedido por id, sempre com defesa de tenant. */
    public function findById(int $pedidoId, ?int $tenantId, bool $bypassGlobal): ?array
    {
        if ($tenantId === null && !$bypassGlobal) {
            return null;
        }

        $where  = 'id = :id';
        $params = ['id' => $pedidoId];
        if ($tenantId !== null) {
            $where .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, tenant_id, estudo_id, nome_original, nome_arquivo,
                        mime_type, extensao, tamanho_bytes, hash_sha256,
                        caminho_arquivo, usuario_id, criado_em, atualizado_em
                   FROM bi_pacs_estudos_pedidos
                  WHERE {$where}
                  LIMIT 1"
            );
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            Logger::error('[PedidoMedicoRepository::findById] ' . $e->getMessage(), [
                'pedido_id' => $pedidoId,
                'tenant_id' => $tenantId,
            ]);
            return null;
        }
    }

    /** Cria ou substitui o pedido do estudo dentro do par tenant/estudo. */
    public function upsert(array $data): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO bi_pacs_estudos_pedidos
                    (tenant_id, estudo_id, nome_original, nome_arquivo, mime_type,
                     extensao, tamanho_bytes, hash_sha256, caminho_arquivo, usuario_id)
                 VALUES
                    (:tenant_id, :estudo_id, :nome_original, :nome_arquivo, :mime_type,
                     :extensao, :tamanho_bytes, :hash_sha256, :caminho_arquivo, :usuario_id)
                 ON DUPLICATE KEY UPDATE
                    nome_original   = VALUES(nome_original),
                    nome_arquivo    = VALUES(nome_arquivo),
                    mime_type      = VALUES(mime_type),
                    extensao        = VALUES(extensao),
                    tamanho_bytes  = VALUES(tamanho_bytes),
                    hash_sha256    = VALUES(hash_sha256),
                    caminho_arquivo = VALUES(caminho_arquivo),
                    usuario_id     = VALUES(usuario_id),
                    atualizado_em  = CURRENT_TIMESTAMP'
            );
            $stmt->execute([
                'tenant_id'      => (int) $data['tenant_id'],
                'estudo_id'      => (int) $data['estudo_id'],
                'nome_original'  => (string) $data['nome_original'],
                'nome_arquivo'   => (string) $data['nome_arquivo'],
                'mime_type'      => (string) $data['mime_type'],
                'extensao'       => (string) $data['extensao'],
                'tamanho_bytes'  => (int) $data['tamanho_bytes'],
                'hash_sha256'    => (string) $data['hash_sha256'],
                'caminho_arquivo'=> (string) $data['caminho_arquivo'],
                'usuario_id'     => (int) $data['usuario_id'],
            ]);

            $row = $this->findByEstudoId((int) $data['estudo_id'], (int) $data['tenant_id']);
            if (!$row) {
                throw new \RuntimeException('Pedido não localizado após persistência.');
            }
            return $row;
        } catch (\Throwable $e) {
            Logger::error('[PedidoMedicoRepository::upsert] ' . $e->getMessage(), [
                'estudo_id' => $data['estudo_id'] ?? null,
                'tenant_id' => $data['tenant_id'] ?? null,
            ]);
            throw $e;
        }
    }

    /** Remove o registro do pedido e informa se uma linha foi apagada. */
    public function deleteByEstudoId(int $estudoId, int $tenantId): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM bi_pacs_estudos_pedidos
                  WHERE estudo_id = :estudo_id AND tenant_id = :tenant_id'
            );
            $stmt->execute(['estudo_id' => $estudoId, 'tenant_id' => $tenantId]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            Logger::error('[PedidoMedicoRepository::deleteByEstudoId] ' . $e->getMessage(), [
                'estudo_id' => $estudoId,
                'tenant_id' => $tenantId,
            ]);
            throw $e;
        }
    }
}
