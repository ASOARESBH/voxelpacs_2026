<?php
namespace App\Models;

use App\Core\Model;
use App\Core\TenantContext;

class User extends Model {
    protected string $table = 'bi_users';
    protected bool $hasTenant = false;

    public function findByEmail(string $email): ?object {
        $stmt = $this->pdo->prepare("SELECT * FROM bi_users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        return $stmt->fetch() ?: null;
    }

    public function findByTenant(int $tenantId): array {
        $stmt = $this->pdo->prepare("
            SELECT u.*, ut.role as tenant_role
            FROM bi_users u
            JOIN bi_user_tenants ut ON ut.user_id = u.id
            WHERE ut.tenant_id = :tid AND ut.ativo = 1
            ORDER BY u.name
        ");
        $stmt->execute(['tid' => $tenantId]);
        return $stmt->fetchAll();
    }

        public function create(array $data): int {
        $data['password'] = password_hash($data['password'], PASSWORD_ARGON2ID);
        $isPostgres = \App\Core\SqlHelper::isPostgres();
        $sql = "INSERT INTO bi_users (name, email, password, role, status)
                VALUES (:name, :email, :password, :role, :status)"
            . ($isPostgres ? ' RETURNING id' : '');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        return $isPostgres ? (int) $stmt->fetchColumn() : (int) $this->pdo->lastInsertId();
    }

    public function attachToTenant(int $userId, int $tenantId, string $role = 'viewer'): void {
        $sql = \App\Core\SqlHelper::isPostgres()
            ? 'INSERT INTO bi_user_tenants (user_id, tenant_id, role) VALUES (:uid, :tid, :role) ON CONFLICT DO NOTHING'
            : 'INSERT IGNORE INTO bi_user_tenants (user_id, tenant_id, role) VALUES (:uid, :tid, :role)';
        $stmt = $this->pdo->prepare($sql);

        $stmt->execute(['uid' => $userId, 'tid' => $tenantId, 'role' => $role]);
    }
}
