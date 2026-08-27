<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\SqlHelper;

/** Persistência tenant-scoped das regras de acesso por usuário. */
final class RegraAcessoRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    /** @return array<string,mixed>|null */
    public function findForUser(int $userId, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM bi_user_regras_acesso WHERE user_id = :user_id AND tenant_id = :tenant_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findTenantUser(int $userId, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.name, u.email, u.status, ut.perfil, ut.ativo AS tenant_ativo
             FROM bi_users u
             INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = :tenant_id
             WHERE u.id = :user_id
             LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.name, u.email, u.status, ut.perfil,
                    r.sessao_timeout_ativo, r.sessao_timeout_minutos,
                    r.ip_restricao_ativa, r.ip_lista_permitida,
                    r.horario_restricao_ativa, r.horario_inicio, r.horario_fim, r.horario_dias_semana
             FROM bi_users u
             INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = :tenant_id
             LEFT JOIN bi_user_regras_acesso r ON r.user_id = u.id AND r.tenant_id = ut.tenant_id
             WHERE ut.ativo = 1
             ORDER BY CASE ut.perfil WHEN 'admin' THEN 1 WHEN 'medico' THEN 2 WHEN 'secretaria' THEN 3 ELSE 9 END, u.name ASC"
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return int[] */
    public function activeTenantIdsForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT ut.tenant_id
             FROM bi_user_tenants ut
             INNER JOIN bi_tenants t ON t.id = ut.tenant_id AND t.status = 'ativo'
             WHERE ut.user_id = :user_id AND ut.ativo = 1
             ORDER BY ut.tenant_id ASC"
        );
        $stmt->execute(['user_id' => $userId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /** @param array<string,mixed> $rule */
    public function save(int $userId, int $tenantId, array $rule): void
    {
        $fields = [
            'sessao_timeout_ativo' => (int) $rule['sessao_timeout_ativo'],
            'sessao_timeout_minutos' => $rule['sessao_timeout_minutos'],
            'ip_restricao_ativa' => (int) $rule['ip_restricao_ativa'],
            'ip_lista_permitida' => $rule['ip_lista_permitida'],
            'horario_restricao_ativa' => (int) $rule['horario_restricao_ativa'],
            'horario_inicio' => $rule['horario_inicio'],
            'horario_fim' => $rule['horario_fim'],
            'horario_dias_semana' => $rule['horario_dias_semana'],
        ];

        $params = array_merge(['user_id' => $userId, 'tenant_id' => $tenantId], $fields);
        $sql = SqlHelper::isPostgres()
            ? "INSERT INTO bi_user_regras_acesso
                    (user_id, tenant_id, sessao_timeout_ativo, sessao_timeout_minutos,
                     ip_restricao_ativa, ip_lista_permitida, horario_restricao_ativa,
                     horario_inicio, horario_fim, horario_dias_semana, created_at, updated_at)
               VALUES
                    (:user_id, :tenant_id, :sessao_timeout_ativo, :sessao_timeout_minutos,
                     :ip_restricao_ativa, :ip_lista_permitida, :horario_restricao_ativa,
                     :horario_inicio, :horario_fim, :horario_dias_semana, NOW(), NOW())
               ON CONFLICT (user_id, tenant_id) DO UPDATE SET
                    sessao_timeout_ativo = EXCLUDED.sessao_timeout_ativo,
                    sessao_timeout_minutos = EXCLUDED.sessao_timeout_minutos,
                    ip_restricao_ativa = EXCLUDED.ip_restricao_ativa,
                    ip_lista_permitida = EXCLUDED.ip_lista_permitida,
                    horario_restricao_ativa = EXCLUDED.horario_restricao_ativa,
                    horario_inicio = EXCLUDED.horario_inicio,
                    horario_fim = EXCLUDED.horario_fim,
                    horario_dias_semana = EXCLUDED.horario_dias_semana,
                    updated_at = NOW()"
            : "INSERT INTO bi_user_regras_acesso
                    (user_id, tenant_id, sessao_timeout_ativo, sessao_timeout_minutos,
                     ip_restricao_ativa, ip_lista_permitida, horario_restricao_ativa,
                     horario_inicio, horario_fim, horario_dias_semana, created_at, updated_at)
               VALUES
                    (:user_id, :tenant_id, :sessao_timeout_ativo, :sessao_timeout_minutos,
                     :ip_restricao_ativa, :ip_lista_permitida, :horario_restricao_ativa,
                     :horario_inicio, :horario_fim, :horario_dias_semana, NOW(), NOW())
               ON DUPLICATE KEY UPDATE
                    sessao_timeout_ativo = VALUES(sessao_timeout_ativo),
                    sessao_timeout_minutos = VALUES(sessao_timeout_minutos),
                    ip_restricao_ativa = VALUES(ip_restricao_ativa),
                    ip_lista_permitida = VALUES(ip_lista_permitida),
                    horario_restricao_ativa = VALUES(horario_restricao_ativa),
                    horario_inicio = VALUES(horario_inicio),
                    horario_fim = VALUES(horario_fim),
                    horario_dias_semana = VALUES(horario_dias_semana),
                    updated_at = NOW()";
        $this->pdo->prepare($sql)->execute($params);
    }
}
