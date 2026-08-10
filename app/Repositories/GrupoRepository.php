<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\Logger;

/**
 * GrupoRepository — toda a SQL do módulo de Grupos centralizada aqui.
 * Nenhuma query de grupo deve existir fora desta classe.
 */
class GrupoRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // -------------------------------------------------------------------------
    // READ — Grupos
    // -------------------------------------------------------------------------

    /** Lista os grupos ativos e inativos do tenant, com contagem de membros. */
    public function findAll(int $tenantId): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT g.*,
                       (SELECT COUNT(*) FROM bi_grupo_usuarios gu WHERE gu.grupo_id = g.id) AS total_membros
                FROM bi_grupos g
                WHERE g.tenant_id = :tenant_id
                ORDER BY g.ativo DESC, g.nome ASC
            ");
            $stmt->execute(['tenant_id' => $tenantId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::error('[GrupoRepository::findAll] ' . $e->getMessage(), ['tenant_id' => $tenantId]);
            return [];
        }
    }

    /** Busca um grupo por ID garantindo isolamento de tenant. */
    public function findById(int $id, int $tenantId): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM bi_grupos WHERE id = :id AND tenant_id = :tenant_id LIMIT 1");
            $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            Logger::error('[GrupoRepository::findById] ' . $e->getMessage(), ['id' => $id, 'tenant_id' => $tenantId]);
            return null;
        }
    }

    /** Verifica se já existe grupo com o mesmo nome no tenant (prevenção de duplicata). */
    public function existeNome(string $nome, int $tenantId, int $excluirId = 0): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM bi_grupos
                WHERE tenant_id = :tenant_id AND nome = :nome AND id != :excluir_id
            ");
            $stmt->execute(['tenant_id' => $tenantId, 'nome' => $nome, 'excluir_id' => $excluirId]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            Logger::error('[GrupoRepository::existeNome] ' . $e->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // WRITE — Grupos
    // -------------------------------------------------------------------------

    /** Insere um novo grupo e retorna o ID gerado. */
    public function inserir(int $tenantId, string $nome, ?string $descricao): int
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO bi_grupos (tenant_id, nome, descricao, ativo)
                VALUES (:tenant_id, :nome, :descricao, 1)
            ");
            $stmt->execute(['tenant_id' => $tenantId, 'nome' => $nome, 'descricao' => $descricao]);
            $id = (int) $this->pdo->lastInsertId();
            Logger::error('[GrupoRepository::inserir] Grupo criado', ['id' => $id, 'tenant_id' => $tenantId, 'nome' => $nome]);
            return $id;
        } catch (\Throwable $e) {
            Logger::error('[GrupoRepository::inserir] ERRO: ' . $e->getMessage(), ['tenant_id' => $tenantId, 'nome' => $nome]);
            throw $e;
        }
    }

    /** Atualiza nome/descrição de um grupo garantindo isolamento de tenant. */
    public function atualizar(int $id, int $tenantId, string $nome, ?string $descricao): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE bi_grupos SET nome = :nome, descricao = :descricao
                WHERE id = :id AND tenant_id = :tenant_id
            ");
            $stmt->execute(['nome' => $nome, 'descricao' => $descricao, 'id' => $id, 'tenant_id' => $tenantId]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            Logger::error('[GrupoRepository::atualizar] ERRO: ' . $e->getMessage(), ['id' => $id, 'tenant_id' => $tenantId]);
            throw $e;
        }
    }

    /** Alterna o status ativo/inativo do grupo (soft delete), escopado por tenant. */
    public function toggleStatus(int $id, int $tenantId): void
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE bi_grupos
                SET ativo = CASE WHEN ativo = 1 THEN 0 ELSE 1 END
                WHERE id = :id AND tenant_id = :tenant_id
            ");
            $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        } catch (\Throwable $e) {
            Logger::error('[GrupoRepository::toggleStatus] ERRO: ' . $e->getMessage(), ['id' => $id, 'tenant_id' => $tenantId]);
        }
    }

    // -------------------------------------------------------------------------
    // MEMBROS (bi_grupo_usuarios)
    // -------------------------------------------------------------------------

    /** Membros atuais do grupo (dados do usuário + perfil no tenant). */
    public function membros(int $grupoId, int $tenantId): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT gu.id AS vinculo_id, u.id, u.name, u.email, ut.perfil
                FROM bi_grupo_usuarios gu
                INNER JOIN bi_users u ON u.id = gu.usuario_id
                LEFT  JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = :tenant_id
                WHERE gu.grupo_id = :grupo_id AND gu.tenant_id = :tenant_id2
                ORDER BY u.name ASC
            ");
            $stmt->execute(['tenant_id' => $tenantId, 'grupo_id' => $grupoId, 'tenant_id2' => $tenantId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::error('[GrupoRepository::membros] ' . $e->getMessage(), ['grupo_id' => $grupoId, 'tenant_id' => $tenantId]);
            return [];
        }
    }

    /** Usuários do tenant que ainda não pertencem a este grupo (candidatos a adicionar). */
    public function usuariosDisponiveis(int $grupoId, int $tenantId): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT u.id, u.name, u.email, ut.perfil
                FROM bi_users u
                INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = :tenant_id
                WHERE u.status = 'ativo'
                  AND u.id NOT IN (
                      SELECT usuario_id FROM bi_grupo_usuarios WHERE grupo_id = :grupo_id AND tenant_id = :tenant_id2
                  )
                ORDER BY u.name ASC
            ");
            $stmt->execute(['tenant_id' => $tenantId, 'grupo_id' => $grupoId, 'tenant_id2' => $tenantId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::error('[GrupoRepository::usuariosDisponiveis] ' . $e->getMessage(), ['grupo_id' => $grupoId, 'tenant_id' => $tenantId]);
            return [];
        }
    }

    /** Vincula um usuário ao grupo (idempotente — INSERT IGNORE evita duplicidade). */
    public function adicionarMembro(int $grupoId, int $usuarioId, int $tenantId): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO bi_grupo_usuarios (tenant_id, grupo_id, usuario_id)
                VALUES (:tenant_id, :grupo_id, :usuario_id)
            ");
            $stmt->execute(['tenant_id' => $tenantId, 'grupo_id' => $grupoId, 'usuario_id' => $usuarioId]);
        } catch (\Throwable $e) {
            Logger::error('[GrupoRepository::adicionarMembro] ERRO: ' . $e->getMessage(), [
                'grupo_id' => $grupoId, 'usuario_id' => $usuarioId, 'tenant_id' => $tenantId,
            ]);
        }
    }

    /** Desvincula um usuário do grupo, escopado por tenant. */
    public function removerMembro(int $grupoId, int $usuarioId, int $tenantId): void
    {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM bi_grupo_usuarios
                WHERE grupo_id = :grupo_id AND usuario_id = :usuario_id AND tenant_id = :tenant_id
            ");
            $stmt->execute(['grupo_id' => $grupoId, 'usuario_id' => $usuarioId, 'tenant_id' => $tenantId]);
        } catch (\Throwable $e) {
            Logger::error('[GrupoRepository::removerMembro] ERRO: ' . $e->getMessage(), [
                'grupo_id' => $grupoId, 'usuario_id' => $usuarioId, 'tenant_id' => $tenantId,
            ]);
        }
    }

    /** Verifica se um usuário pertence ao tenant (guard antes de vincular). */
    public function usuarioPertenceAoTenant(int $usuarioId, int $tenantId): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM bi_user_tenants WHERE user_id = :usuario_id AND tenant_id = :tenant_id
            ");
            $stmt->execute(['usuario_id' => $usuarioId, 'tenant_id' => $tenantId]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            Logger::error('[GrupoRepository::usuarioPertenceAoTenant] ' . $e->getMessage());
            return false;
        }
    }
}
