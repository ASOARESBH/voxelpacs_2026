<?php

namespace App\Repositories;

use App\Core\Database;
use App\Core\SqlHelper;
use PDO;

/** Persistência tenant-scoped de políticas de alerta e modalidades por grupo. */
final class GrupoNotificacaoRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function listGroupsWithConfig(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT g.id, g.nome, g.descricao, g.ativo,
                    COALESCE(c.ativo, FALSE) AS notificacao_ativa,
                    COALESCE(c.canal_email, TRUE) AS canal_email,
                    COALESCE(c.canal_whatsapp, FALSE) AS canal_whatsapp,
                    COALESCE(c.canal_telegram, FALSE) AS canal_telegram,
                    (SELECT COUNT(*) FROM bi_grupo_usuarios gu
                       INNER JOIN bi_users u ON u.id = gu.usuario_id AND u.status = 'ativo'
                     WHERE gu.tenant_id = g.tenant_id AND gu.grupo_id = g.id) AS total_membros
             FROM bi_grupos g
             LEFT JOIN bi_grupo_notificacao_config c ON c.tenant_id = g.tenant_id AND c.grupo_id = g.id
             WHERE g.tenant_id = :tenant_id
             ORDER BY g.ativo DESC, g.nome ASC"
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findGroup(int $groupId, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, nome, descricao, ativo FROM bi_grupos WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $stmt->execute(['id' => $groupId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findConfig(int $groupId, int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ativo, canal_email, canal_whatsapp, canal_telegram
             FROM bi_grupo_notificacao_config WHERE grupo_id = :grupo_id AND tenant_id = :tenant_id LIMIT 1'
        );
        $stmt->execute(['grupo_id' => $groupId, 'tenant_id' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'ativo' => false, 'canal_email' => true, 'canal_whatsapp' => false, 'canal_telegram' => false,
        ];
    }

    public function listPriorities(int $groupId, int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT prioridade FROM bi_grupo_notificacao_prioridades
             WHERE grupo_id = :grupo_id AND tenant_id = :tenant_id ORDER BY prioridade'
        );
        $stmt->execute(['grupo_id' => $groupId, 'tenant_id' => $tenantId]);
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    public function listModalities(int $groupId, int $tenantId, string $context): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT modalidade FROM bi_grupo_modalidades
             WHERE grupo_id = :grupo_id AND tenant_id = :tenant_id AND contexto = :contexto ORDER BY modalidade'
        );
        $stmt->execute(['grupo_id' => $groupId, 'tenant_id' => $tenantId, 'contexto' => $context]);
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    public function listAvailableModalities(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT modalities FROM bi_pacs_estudos
             WHERE tenant_id = :tenant_id AND modalities IS NOT NULL AND BTRIM(modalities) <> ''"
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $stored) {
            foreach (explode('\\', (string) $stored) as $modality) {
                $modality = strtoupper(trim($modality));
                if (preg_match('/^[A-Z0-9]{1,16}$/', $modality)) {
                    $result[$modality] = true;
                }
            }
        }
        $modalities = array_keys($result);
        sort($modalities);
        return $modalities;
    }

    public function savePolicy(int $groupId, int $tenantId, array $config, array $priorities, array $notificationModalities, array $worklistModalities): void
    {
        $sql = SqlHelper::isPostgres()
            ? 'INSERT INTO bi_grupo_notificacao_config
                  (tenant_id, grupo_id, ativo, canal_email, canal_whatsapp, canal_telegram, updated_at)
               VALUES (:tenant_id, :grupo_id, :ativo, :email, :whatsapp, :telegram, NOW())
               ON CONFLICT (tenant_id, grupo_id) DO UPDATE SET
                  ativo = EXCLUDED.ativo, canal_email = EXCLUDED.canal_email,
                  canal_whatsapp = EXCLUDED.canal_whatsapp, canal_telegram = EXCLUDED.canal_telegram,
                  updated_at = NOW()'
            : 'INSERT INTO bi_grupo_notificacao_config
                  (tenant_id, grupo_id, ativo, canal_email, canal_whatsapp, canal_telegram, updated_at)
               VALUES (:tenant_id, :grupo_id, :ativo, :email, :whatsapp, :telegram, NOW())
               ON DUPLICATE KEY UPDATE ativo = VALUES(ativo), canal_email = VALUES(canal_email),
                  canal_whatsapp = VALUES(canal_whatsapp), canal_telegram = VALUES(canal_telegram), updated_at = NOW()';
        $this->pdo->prepare($sql)->execute([
            'tenant_id' => $tenantId, 'grupo_id' => $groupId,
            'ativo' => !empty($config['ativo']), 'email' => !empty($config['canal_email']),
            'whatsapp' => !empty($config['canal_whatsapp']), 'telegram' => !empty($config['canal_telegram']),
        ]);

        $this->pdo->prepare('DELETE FROM bi_grupo_notificacao_prioridades WHERE tenant_id = :tenant_id AND grupo_id = :grupo_id')
            ->execute(['tenant_id' => $tenantId, 'grupo_id' => $groupId]);
        $priorityStmt = $this->pdo->prepare(
            'INSERT INTO bi_grupo_notificacao_prioridades (tenant_id, grupo_id, prioridade) VALUES (:tenant_id, :grupo_id, :prioridade)'
        );
        foreach ($priorities as $priority) {
            $priorityStmt->execute(['tenant_id' => $tenantId, 'grupo_id' => $groupId, 'prioridade' => $priority]);
        }

        $this->pdo->prepare('DELETE FROM bi_grupo_modalidades WHERE tenant_id = :tenant_id AND grupo_id = :grupo_id')
            ->execute(['tenant_id' => $tenantId, 'grupo_id' => $groupId]);
        $modalityStmt = $this->pdo->prepare(
            'INSERT INTO bi_grupo_modalidades (tenant_id, grupo_id, contexto, modalidade)
             VALUES (:tenant_id, :grupo_id, :contexto, :modalidade)'
        );
        foreach (['notificacao' => $notificationModalities, 'worklist' => $worklistModalities] as $context => $modalities) {
            foreach ($modalities as $modality) {
                $modalityStmt->execute([
                    'tenant_id' => $tenantId, 'grupo_id' => $groupId, 'contexto' => $context, 'modalidade' => $modality,
                ]);
            }
        }
    }

    public function listEligibleRules(int $tenantId, string $priority): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.grupo_id, c.canal_email, c.canal_whatsapp, c.canal_telegram, g.nome,
                    (SELECT COUNT(*) FROM bi_grupo_usuarios gu
                       INNER JOIN bi_users u ON u.id = gu.usuario_id AND u.status = 'ativo'
                     WHERE gu.grupo_id = g.id AND gu.tenant_id = g.tenant_id) AS total_membros
             FROM bi_grupo_notificacao_config c
             INNER JOIN bi_grupos g ON g.id = c.grupo_id AND g.tenant_id = c.tenant_id
             INNER JOIN bi_grupo_notificacao_prioridades p
                     ON p.grupo_id = c.grupo_id AND p.tenant_id = c.tenant_id
             WHERE c.tenant_id = :tenant_id AND c.ativo = TRUE AND g.ativo = 1 AND p.prioridade = :prioridade
             ORDER BY g.nome"
        );
        $stmt->execute(['tenant_id' => $tenantId, 'prioridade' => $priority]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listMemberEmails(int $groupId, int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.email FROM bi_grupo_usuarios gu
             INNER JOIN bi_users u ON u.id = gu.usuario_id
             WHERE gu.tenant_id = :tenant_id AND gu.grupo_id = :grupo_id
               AND u.status = 'ativo' AND u.email IS NOT NULL AND BTRIM(u.email) <> ''"
        );
        $stmt->execute(['tenant_id' => $tenantId, 'grupo_id' => $groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listWorklistModalitiesForUser(int $userId, int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT gm.modalidade FROM bi_grupo_usuarios gu
             INNER JOIN bi_grupos g ON g.id = gu.grupo_id AND g.tenant_id = gu.tenant_id AND g.ativo = 1
             INNER JOIN bi_grupo_modalidades gm ON gm.grupo_id = g.id AND gm.tenant_id = g.tenant_id
             WHERE gu.tenant_id = :tenant_id AND gu.usuario_id = :user_id AND gm.contexto = 'worklist'
             ORDER BY gm.modalidade"
        );
        $stmt->execute(['tenant_id' => $tenantId, 'user_id' => $userId]);
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    public function registerDelivery(int $tenantId, int $studyId, int $groupId, string $priority, string $channel, string $status, int $total, int $sent, ?string $technicalMessage = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO bi_grupo_notificacao_entregas
             (tenant_id, estudo_id, grupo_id, prioridade, canal, status, destinatarios_total, destinatarios_enviados, mensagem_tecnica)
             VALUES (:tenant_id, :estudo_id, :grupo_id, :prioridade, :canal, :status, :total, :sent, :mensagem)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId, 'estudo_id' => $studyId, 'grupo_id' => $groupId,
            'prioridade' => $priority, 'canal' => $channel, 'status' => $status,
            'total' => $total, 'sent' => $sent,
            'mensagem' => $technicalMessage !== null ? mb_substr($technicalMessage, 0, 500) : null,
        ]);
    }
}
