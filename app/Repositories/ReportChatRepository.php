<?php

namespace App\Repositories;

use App\Core\Database;
use App\Services\InstitutionResolverService;

/**
 * Persistência do CHAT contextual de Reports.
 * Toda consulta exige tenant_id e não confia em IDs recebidos do navegador.
 */
class ReportChatRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function findByReport(int $reportId, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pacs_report_chats
             WHERE report_id = :report_id AND tenant_id = :tenant_id
             LIMIT 1'
        );
        $stmt->execute(['report_id' => $reportId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByReportForUpdate(int $reportId, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pacs_report_chats
             WHERE report_id = :report_id AND tenant_id = :tenant_id
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['report_id' => $reportId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findReportContext(int $reportId, int $tenantId): ?array
    {
        $params = ['report_id' => $reportId, 'tenant_id' => $tenantId];
        $sql = 'SELECT r.id AS report_id, r.estudo_id, r.public_token,
                       e.patient_name, e.study_description, e.modalities,
                       COALESCE(r.situacao, e.situacao, "novo") AS situacao,
                       r.situacao AS report_situacao
                  FROM reports r
                  INNER JOIN bi_pacs_estudos e ON e.id = r.estudo_id
                 WHERE r.id = :report_id AND r.tenant_id = :tenant_id';
        $sql .= $this->institutionScope($tenantId, 'e', 'report_context_institution', $params);
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listMessages(int $chatId, int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.chat_id, m.autor_id, m.corpo, m.criado_em,
                    COALESCE(u.name, "Usuário") AS autor_nome
               FROM pacs_report_chat_mensagens m
               LEFT JOIN bi_users u ON u.id = m.autor_id
              WHERE m.chat_id = :chat_id AND m.tenant_id = :tenant_id
              ORDER BY m.criado_em ASC, m.id ASC'
        );
        $stmt->execute(['chat_id' => $chatId, 'tenant_id' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function lastMessageAuthorId(int $chatId, int $tenantId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT autor_id
               FROM pacs_report_chat_mensagens
              WHERE chat_id = :chat_id AND tenant_id = :tenant_id
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute(['chat_id' => $chatId, 'tenant_id' => $tenantId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    /** Usuários ativos do tenant para o destinatário individual. */
    public function listActiveUsers(int $tenantId, int $excludeUserId = 0): array
    {
        $sql = 'SELECT u.id, u.name, u.email, ut.perfil
                  FROM bi_users u
                  INNER JOIN bi_user_tenants ut
                          ON ut.user_id = u.id AND ut.tenant_id = :tenant_id
                 WHERE ut.ativo = 1 AND u.status = \'ativo\'';
        $params = ['tenant_id' => $tenantId];
        if ($excludeUserId > 0) {
            $sql .= ' AND u.id <> :exclude_user_id';
            $params['exclude_user_id'] = $excludeUserId;
        }
        $sql .= ' ORDER BY u.name ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** Administradores ativos do tenant, destinatários obrigatórios de achado crítico. */
    public function listActiveTenantAdmins(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT u.id, u.name, u.email, ut.perfil
               FROM bi_users u
               INNER JOIN bi_user_tenants ut
                       ON ut.user_id = u.id AND ut.tenant_id = :tenant_id
              WHERE ut.ativo = 1
                AND u.status = \'ativo\'
                AND ut.perfil = \'admin\'
              ORDER BY u.name ASC'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function findActiveUser(int $userId, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.name, u.email, ut.perfil
               FROM bi_users u
               INNER JOIN bi_user_tenants ut
                       ON ut.user_id = u.id AND ut.tenant_id = :tenant_id
              WHERE u.id = :user_id AND ut.ativo = 1 AND u.status = \'ativo\'
              LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Lista os grupos organizacionais ativos do tenant, priorizando Administrativo. */
    public function listActiveGroups(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT g.id, g.nome, g.descricao, g.ativo,
                    (SELECT COUNT(*)
                       FROM bi_grupo_usuarios gu
                      WHERE gu.grupo_id = g.id AND gu.tenant_id = g.tenant_id) AS total_membros
               FROM bi_grupos g
              WHERE g.tenant_id = :tenant_id AND g.ativo = 1
              ORDER BY CASE WHEN LOWER(TRIM(g.nome)) = "administrativo" THEN 0 ELSE 1 END,
                       g.nome ASC'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function findActiveGroup(int $groupId, int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, nome, descricao, ativo
               FROM bi_grupos
              WHERE id = :group_id AND tenant_id = :tenant_id AND ativo = 1
              LIMIT 1'
        );
        $stmt->execute(['group_id' => $groupId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findDefaultAdministrativeGroup(int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, nome, descricao, ativo
               FROM bi_grupos
              WHERE tenant_id = :tenant_id AND ativo = 1
                AND LOWER(TRIM(nome)) = "administrativo"
              ORDER BY id ASC
              LIMIT 1'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Membros ativos de um grupo ativo, sempre com tenant no grupo e no pivot. */
    public function listUsersByGroup(int $groupId, int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.name, u.email, ut.perfil
               FROM bi_grupo_usuarios gu
               INNER JOIN bi_grupos g
                       ON g.id = gu.grupo_id AND g.tenant_id = gu.tenant_id
               INNER JOIN bi_users u ON u.id = gu.usuario_id
               INNER JOIN bi_user_tenants ut
                       ON ut.user_id = u.id AND ut.tenant_id = gu.tenant_id
              WHERE gu.grupo_id = :group_id
                AND gu.tenant_id = :tenant_id
                AND g.ativo = 1
                AND ut.ativo = 1
                AND u.status = \'ativo\'
              ORDER BY u.name ASC'
        );
        $stmt->execute(['group_id' => $groupId, 'tenant_id' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function upsertPending(
        int $reportId,
        int $estudoId,
        int $tenantId,
        string $destinatarioTipo,
        ?string $destinatarioGrupo,
        ?int $destinatarioGrupoId,
        ?int $destinatarioUserId,
        string $assuntoCodigo,
        string $assunto,
        string $situacaoAnterior,
        int $autorId
    ): int {
        $existing = $this->findByReport($reportId, $tenantId);
        if ($existing) {
            $stmt = $this->pdo->prepare(
                'UPDATE pacs_report_chats
                    SET status = \'pendente\',
                        destinatario_tipo = :destinatario_tipo,
                        destinatario_grupo = :destinatario_grupo,
                        destinatario_grupo_id = :destinatario_grupo_id,
                        destinatario_user_id = :destinatario_user_id,
                        assunto_codigo = :assunto_codigo,
                        assunto = :assunto,
                        situacao_anterior = CASE
                            WHEN status = \'concluido\' OR situacao_anterior IS NULL OR situacao_anterior = \'\'
                            THEN :situacao_anterior ELSE situacao_anterior END,
                        concluido_por = NULL,
                        concluido_em = NULL,
                        atualizado_em = NOW()
                  WHERE id = :id AND report_id = :report_id AND tenant_id = :tenant_id'
            );
            $stmt->execute([
                'destinatario_tipo' => $destinatarioTipo,
                'destinatario_grupo' => $destinatarioGrupo,
                'destinatario_grupo_id' => $destinatarioGrupoId,
                'destinatario_user_id' => $destinatarioUserId,
                'assunto_codigo' => $assuntoCodigo,
                'assunto' => $assunto,
                'situacao_anterior' => $situacaoAnterior,
                'id' => (int) $existing['id'],
                'report_id' => $reportId,
                'tenant_id' => $tenantId,
            ]);
            return (int) $existing['id'];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO pacs_report_chats
                (tenant_id, report_id, estudo_id, status, destinatario_tipo,
                 destinatario_grupo, destinatario_grupo_id, destinatario_user_id,
                 assunto_codigo, assunto, situacao_anterior, criado_por)
             VALUES
                (:tenant_id, :report_id, :estudo_id, \'pendente\', :destinatario_tipo,
                 :destinatario_grupo, :destinatario_grupo_id, :destinatario_user_id,
                 :assunto_codigo, :assunto, :situacao_anterior, :criado_por)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'report_id' => $reportId,
            'estudo_id' => $estudoId,
            'destinatario_tipo' => $destinatarioTipo,
            'destinatario_grupo' => $destinatarioGrupo,
            'destinatario_grupo_id' => $destinatarioGrupoId,
            'destinatario_user_id' => $destinatarioUserId,
            'assunto_codigo' => $assuntoCodigo,
            'assunto' => $assunto,
            'situacao_anterior' => $situacaoAnterior,
            'criado_por' => $autorId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function addMessage(int $chatId, int $tenantId, int $autorId, string $corpo): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pacs_report_chat_mensagens (tenant_id, chat_id, autor_id, corpo)
             VALUES (:tenant_id, :chat_id, :autor_id, :corpo)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'chat_id' => $chatId,
            'autor_id' => $autorId,
            'corpo' => $corpo,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Marca o alerta clínico sem alterar prioridade, situação ou demais tags DICOM. */
    public function markCriticalFinding(int $estudoId, int $tenantId, int $userId, string $assunto): bool
    {
        $params = [
            'user_id' => $userId,
            'assunto' => $assunto,
            'estudo_id' => $estudoId,
        ];
        $sql = 'UPDATE bi_pacs_estudos
                   SET achado_critico_em = NOW(),
                       achado_critico_por = :user_id,
                       achado_critico_assunto = :assunto
                 WHERE id = :estudo_id';
        $sql .= $this->institutionScope($tenantId, '', 'critical_institution', $params);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    }

    public function updateStudySituation(int $estudoId, int $tenantId, string $situacao): void
    {
        $params = ['situacao' => $situacao, 'estudo_id' => $estudoId];
        $sql = 'UPDATE bi_pacs_estudos SET situacao = :situacao WHERE id = :estudo_id';
        $sql .= $this->institutionScope($tenantId, '', 'situation_institution', $params);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * bi_pacs_estudos é isolada por InstitutionName, não por tenant_id.
     * Mantém o deny-by-default para tenant sem Unidade vinculada.
     *
     * @param array<string, mixed> $params
     */
    private function institutionScope(int $tenantId, string $alias, string $prefix, array &$params): string
    {
        $institutionNames = InstitutionResolverService::getInstitutionNamesByTenant($tenantId);
        $institutionNames = array_values(array_filter(array_map('trim', $institutionNames), static fn(string $name): bool => $name !== ''));
        if (!$institutionNames) {
            return ' AND 1 = 0';
        }

        $placeholders = [];
        foreach ($institutionNames as $index => $institutionName) {
            $placeholder = ':' . $prefix . '_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $institutionName;
        }
        $column = $alias === '' ? 'institution_name' : $alias . '.institution_name';
        return ' AND ' . $column . ' IN (' . implode(', ', $placeholders) . ')';
    }

    public function complete(int $chatId, int $reportId, int $tenantId, int $userId): ?array
    {
        $chat = $this->findByReport($reportId, $tenantId);
        if (!$chat || (int) $chat['id'] !== $chatId || $chat['status'] !== 'pendente') return null;

        $stmt = $this->pdo->prepare(
            'UPDATE pacs_report_chats
                SET status = \'concluido\', concluido_por = :user_id,
                    concluido_em = NOW(), atualizado_em = NOW()
              WHERE id = :id AND report_id = :report_id AND tenant_id = :tenant_id
                AND status = \'pendente\''
        );
        $stmt->execute([
            'user_id' => $userId,
            'id' => $chatId,
            'report_id' => $reportId,
            'tenant_id' => $tenantId,
        ]);
        return $chat;
    }

    public function hasPending(int $reportId, int $tenantId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM pacs_report_chats
              WHERE report_id = :report_id AND tenant_id = :tenant_id AND status = \'pendente\'
              LIMIT 1'
        );
        $stmt->execute(['report_id' => $reportId, 'tenant_id' => $tenantId]);
        return (bool) $stmt->fetchColumn();
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }
}
