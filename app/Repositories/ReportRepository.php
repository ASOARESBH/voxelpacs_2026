<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\TenantContext;
use PDO;

/**
 * Acesso a dados do módulo de Laudos. Concentra as queries que cruzam
 * bi_pacs_estudos + reports/report_versions/report_templates/report_autotext/report_signatures,
 * deixando o ReportService livre de SQL.
 */
class ReportRepository {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance();
    }

    public function findEstudoById(int $id): ?object {
        $sql = "SELECT * FROM bi_pacs_estudos WHERE id = :id";
        if (TenantContext::isSet()) $sql .= " AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $params = ['id' => $id];
        if (TenantContext::isSet()) $params['tenant_id'] = TenantContext::id();
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    public function findEstudoByStudyUid(string $studyUid): ?object {
        $sql = "SELECT * FROM bi_pacs_estudos WHERE study_instance_uid = :uid";
        if (TenantContext::isSet()) $sql .= " AND tenant_id = :tenant_id";
        $stmt = $this->pdo->prepare($sql);
        $params = ['uid' => $studyUid];
        if (TenantContext::isSet()) $params['tenant_id'] = TenantContext::id();
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    public function findUserNome(int $userId): ?string {
        $stmt = $this->pdo->prepare("SELECT name FROM bi_users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $name = $stmt->fetchColumn();
        return $name ?: null;
    }

    /**
     * Tenta assumir o estudo para o usuário atual.
     * Retorna true se assumiu (ou já era o dono), false se pertence a outro usuário.
     */
    public function assumirEstudo(int $estudoId, int $userId): bool {
        $stmt = $this->pdo->prepare("
            UPDATE bi_pacs_estudos
            SET situacao = 'em_laudo',
                usuario_responsavel_id = :uid,
                data_inicio_laudo = CURDATE(),
                hora_inicio_laudo = CURTIME(),
                lock_heartbeat_em = NOW()
            WHERE id = :id
              AND (usuario_responsavel_id IS NULL OR usuario_responsavel_id = :uid2 OR situacao IN ('novo','aberto','urgente'))
        ");
        $stmt->execute(['uid' => $userId, 'id' => $estudoId, 'uid2' => $userId]);
        if ($stmt->rowCount() > 0) return true;

        // rowCount 0: ou já está exatamente nesse estado (nada mudou), ou é de outro usuário.
        $estudo = $this->findEstudoById($estudoId);
        return $estudo && (int) $estudo->usuario_responsavel_id === $userId;
    }

    public function reatribuirLock(int $estudoId, int $userId): void {
        $this->pdo->prepare("
            UPDATE bi_pacs_estudos
            SET usuario_responsavel_id = :uid, data_inicio_laudo = CURDATE(), hora_inicio_laudo = CURTIME(), lock_heartbeat_em = NOW()
            WHERE id = :id
        ")->execute(['uid' => $userId, 'id' => $estudoId]);
    }

    public function marcarHeartbeat(int $estudoId): void {
        $this->pdo->prepare("UPDATE bi_pacs_estudos SET lock_heartbeat_em = NOW() WHERE id = :id")
            ->execute(['id' => $estudoId]);
    }

    public function atualizarSituacaoEstudo(int $estudoId, string $situacao): void {
        $this->pdo->prepare("UPDATE bi_pacs_estudos SET situacao = :situacao WHERE id = :id")
            ->execute(['situacao' => $situacao, 'id' => $estudoId]);
    }

    public function findReportById(int $id): ?object {
        $stmt = $this->pdo->prepare("SELECT * FROM reports WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findReportByEstudoId(int $estudoId): ?object {
        $stmt = $this->pdo->prepare("SELECT * FROM reports WHERE bi_pacs_estudos_id = :id");
        $stmt->execute(['id' => $estudoId]);
        return $stmt->fetch() ?: null;
    }

    public function createReport(int $estudoId, ?int $tenantId, ?string $studyUid, int $medicoId, array $conteudo): object {
        $stmt = $this->pdo->prepare("
            INSERT INTO reports (tenant_id, bi_pacs_estudos_id, study_instance_uid, medico_id, status, conteudo, versao_atual)
            VALUES (:tenant_id, :estudo_id, :study_uid, :medico_id, 'em_laudo', :conteudo, 1)
        ");
        $stmt->execute([
            'tenant_id'  => $tenantId,
            'estudo_id'  => $estudoId,
            'study_uid'  => $studyUid,
            'medico_id'  => $medicoId,
            'conteudo'   => json_encode($conteudo, JSON_UNESCAPED_UNICODE),
        ]);
        return $this->findReportById((int) $this->pdo->lastInsertId());
    }

    public function atualizarConteudo(int $reportId, array $conteudo, ?string $status = null, ?int $templateId = null): void {
        $sets = ['conteudo = :conteudo', 'versao_atual = versao_atual + 1'];
        $params = ['conteudo' => json_encode($conteudo, JSON_UNESCAPED_UNICODE), 'id' => $reportId];

        if ($status !== null) { $sets[] = 'status = :status'; $params['status'] = $status; }
        if ($templateId !== null) { $sets[] = 'template_id = :template_id'; $params['template_id'] = $templateId; }

        $this->pdo->prepare("UPDATE reports SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
    }

    public function marcarAssinado(int $reportId, string $status): void {
        $col = $status === 'liberado' ? 'liberado_em' : 'assinado_em';
        $this->pdo->prepare("UPDATE reports SET status = :status, {$col} = NOW() WHERE id = :id")
            ->execute(['status' => $status, 'id' => $reportId]);
    }

    public function proximaVersao(int $reportId): int {
        $stmt = $this->pdo->prepare("SELECT versao_atual FROM reports WHERE id = :id");
        $stmt->execute(['id' => $reportId]);
        return ((int) $stmt->fetchColumn()) + 1;
    }

    public function createVersion(int $reportId, array $conteudo, string $acao, int $userId, int $versaoNumero): void {
        $this->pdo->prepare("
            INSERT INTO report_versions (report_id, versao_numero, conteudo, acao, user_id)
            VALUES (:report_id, :versao, :conteudo, :acao, :user_id)
        ")->execute([
            'report_id' => $reportId,
            'versao'    => $versaoNumero,
            'conteudo'  => json_encode($conteudo, JSON_UNESCAPED_UNICODE),
            'acao'      => $acao,
            'user_id'   => $userId,
        ]);
    }

    public function listVersions(int $reportId): array {
        $stmt = $this->pdo->prepare("
            SELECT rv.id, rv.versao_numero, rv.acao, rv.criado_em, u.name AS user_nome
            FROM report_versions rv
            LEFT JOIN bi_users u ON u.id = rv.user_id
            WHERE rv.report_id = :report_id
            ORDER BY rv.versao_numero DESC
        ");
        $stmt->execute(['report_id' => $reportId]);
        return $stmt->fetchAll();
    }

    public function findVersion(int $versionId): ?object {
        $stmt = $this->pdo->prepare("SELECT * FROM report_versions WHERE id = :id");
        $stmt->execute(['id' => $versionId]);
        return $stmt->fetch() ?: null;
    }

    public function createSignature(int $reportId, int $userId, string $nomeMedico, ?string $crm, string $hash, ?string $ip): void {
        $this->pdo->prepare("
            INSERT INTO report_signatures (report_id, user_id, nome_medico, crm, data, hora, hash, ip)
            VALUES (:report_id, :user_id, :nome, :crm, CURDATE(), CURTIME(), :hash, :ip)
        ")->execute([
            'report_id' => $reportId,
            'user_id'   => $userId,
            'nome'      => $nomeMedico,
            'crm'       => $crm,
            'hash'      => $hash,
            'ip'        => $ip,
        ]);
    }

    public function findSignatureByReportId(int $reportId): ?object {
        $stmt = $this->pdo->prepare("SELECT * FROM report_signatures WHERE report_id = :id ORDER BY id DESC LIMIT 1");
        $stmt->execute(['id' => $reportId]);
        return $stmt->fetch() ?: null;
    }

    public function listTemplates(string $modalidade): array {
        $sql = "SELECT id, titulo, modalidade, conteudo FROM report_templates
                WHERE ativo = 1 AND modalidade = :modalidade AND (tenant_id IS NULL";
        $params = ['modalidade' => $modalidade];
        if (TenantContext::isSet()) { $sql .= " OR tenant_id = :tenant_id"; $params['tenant_id'] = TenantContext::id(); }
        $sql .= ") ORDER BY titulo ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function listAutotext(?string $modalidade): array {
        $sql = "SELECT gatilho, texto_sugerido, secao_destino FROM report_autotext
                WHERE ativo = 1 AND (modalidade IS NULL";
        $params = [];
        if ($modalidade) { $sql .= " OR modalidade = :modalidade"; $params['modalidade'] = $modalidade; }
        $sql .= ") AND (tenant_id IS NULL";
        if (TenantContext::isSet()) { $sql .= " OR tenant_id = :tenant_id"; $params['tenant_id'] = TenantContext::id(); }
        $sql .= ")";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Registra log de auditoria (Abertura, Salvar, Assinar, PDF, etc)
     */
    public function logAction(int $reportId, int $estudoId, int $tenantId, int $usuarioId, string $usuarioNome, string $acao, ?string $descricao = null): void
    {
        $sql = "INSERT INTO report_logs (
                    report_id, estudo_id, tenant_id, usuario_id, usuario_nome, acao, descricao, ip, user_agent
                ) VALUES (
                    :report_id, :estudo_id, :tenant_id, :usuario_id, :usuario_nome, :acao, :descricao, :ip, :ua
                )";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':report_id'    => $reportId,
                ':estudo_id'    => $estudoId,
                ':tenant_id'    => $tenantId,
                ':usuario_id'   => $usuarioId,
                ':usuario_nome' => $usuarioNome,
                ':acao'         => $acao,
                ':descricao'    => $descricao,
                ':ip'           => $_SERVER['REMOTE_ADDR'] ?? null,
                ':ua'           => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (\PDOException $e) {
            \App\Core\Logger::error('Erro ao registrar log de laudo', [
                'report_id' => $reportId,
                'acao' => $acao,
                'error' => $e->getMessage()
            ]);
            // Não lança exceção para não travar o fluxo principal por erro de log
        }
    }
}
