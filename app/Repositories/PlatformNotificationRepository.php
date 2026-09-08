<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\SqlHelper;
use PDO;

/** Persistência dos comunicados de plataforma, sempre filtrada por usuário e tenant. */
final class PlatformNotificationRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    public function pdo(): PDO { return $this->pdo; }

    public function listForPlatform(): array
    {
        $sql = "SELECT n.*, COUNT(DISTINCT s.user_id) AS vistos, COUNT(DISTINCT s.confirmado_em) AS confirmados,
                    COUNT(DISTINCT nt.tenant_id) AS tenants_alvo
                FROM bi_platform_notificacoes n
                LEFT JOIN bi_platform_notificacao_status_usuario s ON s.notificacao_id = n.id
                LEFT JOIN bi_platform_notificacao_tenants nt ON nt.notificacao_id = n.id
                GROUP BY n.id ORDER BY n.created_at DESC LIMIT 100";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function find(int $notificationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bi_platform_notificacoes WHERE id = ? LIMIT 1');
        $stmt->execute([$notificationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function targetTenants(int $notificationId): array
    {
        $stmt = $this->pdo->prepare('SELECT tenant_id FROM bi_platform_notificacao_tenants WHERE notificacao_id = ? ORDER BY tenant_id');
        $stmt->execute([$notificationId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function targetProfiles(int $notificationId): array
    {
        $stmt = $this->pdo->prepare('SELECT perfil FROM bi_platform_notificacao_perfis WHERE notificacao_id = ? ORDER BY perfil');
        $stmt->execute([$notificationId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function listActiveTenants(): array
    {
        return $this->pdo->query("SELECT id, nome FROM bi_tenants WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function save(array $data, ?int $notificationId, int $authorId): int
    {
        $fields = ['nome_alerta','assunto','mensagem_html','tipo','escopo','exibicao','requer_confirmacao','canal_email','status','origem','data_inicio','data_fim'];
        if ($notificationId) {
            $sets = implode(', ', array_map(static fn(string $field): string => "$field = ?", $fields));
            $values = array_map(static fn(string $field): mixed => $data[$field], $fields);
            $values[] = $notificationId;
            $stmt = $this->pdo->prepare("UPDATE bi_platform_notificacoes SET $sets, updated_at = NOW() WHERE id = ?");
            $stmt->execute($values);
            return $notificationId;
        }
        $columns = implode(',', array_merge($fields, ['criado_por']));
        $marks = implode(',', array_fill(0, count($fields) + 1, '?'));
        $values = array_map(static fn(string $field): mixed => $data[$field], $fields);
        $values[] = $authorId;
        if (SqlHelper::isPostgres()) {
            $stmt = $this->pdo->prepare("INSERT INTO bi_platform_notificacoes ($columns) VALUES ($marks) RETURNING id");
            $stmt->execute($values);
            return (int) $stmt->fetchColumn();
        }
        $stmt = $this->pdo->prepare("INSERT INTO bi_platform_notificacoes ($columns) VALUES ($marks)");
        $stmt->execute($values);
        return (int) $this->pdo->lastInsertId();
    }

    public function replaceSegments(int $notificationId, array $tenantIds, array $profiles): void
    {
        $this->pdo->prepare('DELETE FROM bi_platform_notificacao_tenants WHERE notificacao_id = ?')->execute([$notificationId]);
        $this->pdo->prepare('DELETE FROM bi_platform_notificacao_perfis WHERE notificacao_id = ?')->execute([$notificationId]);
        $tenantStmt = $this->pdo->prepare('INSERT INTO bi_platform_notificacao_tenants (notificacao_id, tenant_id) VALUES (?, ?)');
        foreach (array_unique(array_map('intval', $tenantIds)) as $tenantId) if ($tenantId > 0) $tenantStmt->execute([$notificationId, $tenantId]);
        $profileStmt = $this->pdo->prepare('INSERT INTO bi_platform_notificacao_perfis (notificacao_id, perfil) VALUES (?, ?)');
        foreach (array_unique($profiles) as $profile) $profileStmt->execute([$notificationId, $profile]);
    }

    public function eligibleUsers(array $notification): array
    {
        $params = [];
        $conditions = ["u.status = 'ativo'", 'ut.ativo = 1', "t.status = 'ativo'", "u.role <> 'superadmin'"];
        if (($notification['escopo'] ?? '') === 'tenant') {
            $conditions[] = 'EXISTS (SELECT 1 FROM bi_platform_notificacao_tenants nt WHERE nt.notificacao_id = ? AND nt.tenant_id = ut.tenant_id)';
            $params[] = (int) $notification['id'];
        }
        $profiles = $this->targetProfiles((int) $notification['id']);
        if ($profiles) {
            $conditions[] = 'ut.perfil IN (' . implode(',', array_fill(0, count($profiles), '?')) . ')';
            array_push($params, ...$profiles);
        }
        $sql = 'SELECT DISTINCT u.id AS user_id, ut.tenant_id FROM bi_users u JOIN bi_user_tenants ut ON ut.user_id = u.id JOIN bi_tenants t ON t.id = ut.tenant_id WHERE ' . implode(' AND ', $conditions);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listForUser(int $userId, int $tenantId, string $profile, bool $onlyUnseen = false): array
    {
        $sql = "SELECT n.id, n.nome_alerta, n.assunto, n.mensagem_html, n.tipo, n.exibicao, n.requer_confirmacao,
                       n.data_inicio, n.data_fim, n.origem, s.visto_em, s.confirmado_em
                FROM bi_platform_notificacoes n
                LEFT JOIN bi_platform_notificacao_status_usuario s
                  ON s.notificacao_id = n.id AND s.user_id = ? AND s.tenant_id = ?
                WHERE n.status IN ('ativa','agendada') AND n.data_inicio <= NOW() AND n.data_fim > NOW()
                  AND (n.escopo = 'global' OR EXISTS (SELECT 1 FROM bi_platform_notificacao_tenants nt WHERE nt.notificacao_id = n.id AND nt.tenant_id = ?))
                  AND (NOT EXISTS (SELECT 1 FROM bi_platform_notificacao_perfis np WHERE np.notificacao_id = n.id)
                       OR EXISTS (SELECT 1 FROM bi_platform_notificacao_perfis np WHERE np.notificacao_id = n.id AND np.perfil = ?))
                  AND (NOT EXISTS (SELECT 1 FROM bi_platform_notificacao_usuarios nu WHERE nu.notificacao_id = n.id)
                       OR EXISTS (SELECT 1 FROM bi_platform_notificacao_usuarios nu WHERE nu.notificacao_id = n.id AND nu.user_id = ? AND nu.tenant_id = ?))";
        $params = [$userId, $tenantId, $tenantId, $profile, $userId, $tenantId];
        if ($onlyUnseen) $sql .= ' AND s.visto_em IS NULL';
        $sql .= " ORDER BY CASE n.tipo WHEN 'critico' THEN 0 WHEN 'manutencao' THEN 1 WHEN 'aviso' THEN 2 ELSE 3 END, n.data_inicio DESC, n.id DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function isEligibleForUser(int $notificationId, int $userId, int $tenantId, string $profile): bool
    {
        foreach ($this->listForUser($userId, $tenantId, $profile) as $item) if ((int) $item['id'] === $notificationId) return true;
        return false;
    }

    public function markViewed(int $notificationId, int $userId, int $tenantId, bool $confirmed = false): void
    {
        $columns = 'notificacao_id, user_id, tenant_id, visto_em, confirmado_em, updated_at';
        if (SqlHelper::isPostgres()) {
            $sql = "INSERT INTO bi_platform_notificacao_status_usuario ($columns) VALUES (?, ?, ?, NOW(), CASE WHEN ? THEN NOW() ELSE NULL END, NOW())
                    ON CONFLICT (notificacao_id, user_id, tenant_id) DO UPDATE SET visto_em = COALESCE(bi_platform_notificacao_status_usuario.visto_em, NOW()), confirmado_em = CASE WHEN EXCLUDED.confirmado_em IS NOT NULL THEN COALESCE(bi_platform_notificacao_status_usuario.confirmado_em, NOW()) ELSE bi_platform_notificacao_status_usuario.confirmado_em END, updated_at = NOW()";
        } else {
            $sql = "INSERT INTO bi_platform_notificacao_status_usuario ($columns) VALUES (?, ?, ?, NOW(), IF(?, NOW(), NULL), NOW())
                    ON DUPLICATE KEY UPDATE visto_em = COALESCE(visto_em, NOW()), confirmado_em = IF(VALUES(confirmado_em) IS NOT NULL, COALESCE(confirmado_em, NOW()), confirmado_em), updated_at = NOW()";
        }
        $this->pdo->prepare($sql)->execute([$notificationId, $userId, $tenantId, $confirmed ? 1 : 0]);
    }

    public function enqueueEmail(int $notificationId, array $recipients): void
    {
        $sql = SqlHelper::isPostgres()
            ? "INSERT INTO bi_platform_notificacao_envios (notificacao_id,user_id,tenant_id,canal,status,proxima_tentativa_em) VALUES (?, ?, ?, 'email', 'pendente', NOW()) ON CONFLICT (notificacao_id,user_id,tenant_id,canal) DO NOTHING"
            : "INSERT IGNORE INTO bi_platform_notificacao_envios (notificacao_id,user_id,tenant_id,canal,status,proxima_tentativa_em) VALUES (?, ?, ?, 'email', 'pendente', NOW())";
        $stmt = $this->pdo->prepare($sql);
        foreach ($recipients as $recipient) $stmt->execute([$notificationId, (int) $recipient['user_id'], (int) $recipient['tenant_id']]);
    }

    public function createSystemEvent(array $data, array $recipientIds, int $tenantId): ?int
    {
        if (!$recipientIds) return null;
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("INSERT INTO bi_platform_notificacoes (nome_alerta,assunto,mensagem_html,tipo,escopo,exibicao,requer_confirmacao,canal_email,status,origem,chave_evento,data_inicio,data_fim,criado_por) VALUES (?, ?, ?, ?, 'tenant', 'unica_vez', ?, FALSE, 'ativa', 'evento_sistema', ?, NOW(), NOW() + INTERVAL '30 days', ?)");
            if (!SqlHelper::isPostgres()) {
                $stmt = $this->pdo->prepare("INSERT INTO bi_platform_notificacoes (nome_alerta,assunto,mensagem_html,tipo,escopo,exibicao,requer_confirmacao,canal_email,status,origem,chave_evento,data_inicio,data_fim,criado_por) VALUES (?, ?, ?, ?, 'tenant', 'unica_vez', ?, 0, 'ativa', 'evento_sistema', ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), ?)");
            }
            if (SqlHelper::isPostgres()) {
                $stmt = $this->pdo->prepare("INSERT INTO bi_platform_notificacoes (nome_alerta,assunto,mensagem_html,tipo,escopo,exibicao,requer_confirmacao,canal_email,status,origem,chave_evento,data_inicio,data_fim,criado_por) VALUES (?, ?, ?, ?, 'tenant', 'unica_vez', ?, FALSE, 'ativa', 'evento_sistema', ?, NOW(), NOW() + INTERVAL '30 days', ?) RETURNING id");
                $stmt->execute([$data['nome_alerta'], $data['assunto'], $data['mensagem_html'], $data['tipo'], !empty($data['requer_confirmacao']), $data['chave_evento'], $data['criado_por'] ?: null]);
                $notificationId = (int) $stmt->fetchColumn();
            } else {
                $stmt = $this->pdo->prepare("INSERT INTO bi_platform_notificacoes (nome_alerta,assunto,mensagem_html,tipo,escopo,exibicao,requer_confirmacao,canal_email,status,origem,chave_evento,data_inicio,data_fim,criado_por) VALUES (?, ?, ?, ?, 'tenant', 'unica_vez', ?, 0, 'ativa', 'evento_sistema', ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), ?)");
                $stmt->execute([$data['nome_alerta'], $data['assunto'], $data['mensagem_html'], $data['tipo'], !empty($data['requer_confirmacao']), $data['chave_evento'], $data['criado_por'] ?: null]);
                $notificationId = (int) $this->pdo->lastInsertId();
            }
            $target = $this->pdo->prepare('INSERT INTO bi_platform_notificacao_usuarios (notificacao_id,user_id,tenant_id) VALUES (?, ?, ?)');
            foreach (array_unique(array_map('intval', $recipientIds)) as $userId) if ($userId > 0) $target->execute([$notificationId, $userId, $tenantId]);
            $this->pdo->commit();
            return $notificationId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if (str_contains(strtolower($e->getMessage()), 'unique')) return null;
            throw $e;
        }
    }

    /** Claim serial para e-mail: não expõe destinatário, corpo ou erro SMTP ao chamador. */
    public function claimNextEmail(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $sql = "SELECT e.id, e.notificacao_id, e.user_id, e.tenant_id, u.email, n.assunto, n.mensagem_html
                    FROM bi_platform_notificacao_envios e
                    JOIN bi_users u ON u.id = e.user_id AND u.status = 'ativo'
                    JOIN bi_platform_notificacoes n ON n.id = e.notificacao_id AND n.status IN ('ativa','agendada') AND n.data_inicio <= NOW() AND n.data_fim > NOW()
                    WHERE e.status IN ('pendente','falha') AND e.tentativas < 3 AND e.proxima_tentativa_em <= NOW()
                    ORDER BY e.id ASC LIMIT 1 FOR UPDATE";
            $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $this->pdo->commit(); return null; }
            $token = bin2hex(random_bytes(24));
            $stmt = $this->pdo->prepare("UPDATE bi_platform_notificacao_envios SET status = 'processando', tentativas = tentativas + 1, claim_token = ?, claim_em = NOW(), updated_at = NOW() WHERE id = ? AND status IN ('pendente','falha')");
            $stmt->execute([$token, (int) $row['id']]);
            if ($stmt->rowCount() !== 1) { $this->pdo->rollBack(); return null; }
            $this->pdo->commit();
            $row['claim_token'] = $token;
            return $row;
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function finishEmail(int $deliveryId, string $claimToken, bool $sent): void
    {
        if ($sent) {
            $sql = "UPDATE bi_platform_notificacao_envios SET status = 'enviado', enviado_em = NOW(), claim_token = NULL, claim_em = NULL, erro_categoria = NULL, updated_at = NOW() WHERE id = ? AND status = 'processando' AND claim_token = ?";
            $this->pdo->prepare($sql)->execute([$deliveryId, $claimToken]);
            return;
        }
        $stmt = $this->pdo->prepare('SELECT tentativas FROM bi_platform_notificacao_envios WHERE id = ? AND status = ? AND claim_token = ?');
        $stmt->execute([$deliveryId, 'processando', $claimToken]);
        $attempts = (int) $stmt->fetchColumn();
        if ($attempts <= 0) return;
        $minutes = match ($attempts) { 1 => 5, 2 => 15, default => 60 };
        $retryAt = gmdate('Y-m-d H:i:s', time() + ($minutes * 60));
        $sql = "UPDATE bi_platform_notificacao_envios SET status = 'falha', proxima_tentativa_em = ?, claim_token = NULL, claim_em = NULL, erro_categoria = 'smtp_failed', updated_at = NOW() WHERE id = ? AND status = 'processando' AND claim_token = ?";
        $this->pdo->prepare($sql)->execute([$retryAt, $deliveryId, $claimToken]);
    }
}
