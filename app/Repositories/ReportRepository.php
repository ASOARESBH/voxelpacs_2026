<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use PDO;

/**
 * Acesso a dados do módulo de Laudos.
 * Schema de produção (2026-07-05_reports_module.sql):
 *   reports.estudo_id       (FK → bi_pacs_estudos.id)
 *   reports.usuario_id      (FK → bi_users.id)
 *   reports.situacao        ENUM('rascunho','assinado','liberado')
 *   reports.secao_exame, secao_tecnica, secao_achados, secao_conclusao, secao_recomendacao
 */
class ReportRepository {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance();
    }

    // ── bi_pacs_estudos ───────────────────────────────────────────────────────

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
        $params = ['uid' => $userId, 'id' => $estudoId, 'uid2' => $userId];
        $dataAtualSql = \App\Core\SqlHelper::isPostgres() ? 'CURRENT_DATE' : 'CURDATE()';
        $horaAtualSql = \App\Core\SqlHelper::isPostgres() ? 'CURRENT_TIME' : 'CURTIME()';
        try {
            $stmt = $this->pdo->prepare("
                UPDATE bi_pacs_estudos
                SET situacao = 'em_laudo',
                    usuario_responsavel_id = :uid,
                    data_inicio_laudo = {$dataAtualSql},
                    hora_inicio_laudo = {$horaAtualSql},
                    lock_heartbeat_em = NOW()
                WHERE id = :id
                  AND (
                        (usuario_responsavel_id IS NULL AND COALESCE(situacao, 'novo') IN ('novo','aberto','urgente'))
                     OR (usuario_responsavel_id = :uid2 AND COALESCE(situacao, 'novo') IN ('a_laudar','em_laudo','rascunho'))
                  )
            ");
            $stmt->execute($params);
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'lock_heartbeat_em') === false) throw $e;
            Logger::warning('ReportRepository::assumirEstudo sem heartbeat — migration pendente', [
                'estudo_id' => $estudoId,
                'error' => $e->getMessage(),
            ]);
            $stmt = $this->pdo->prepare("
                UPDATE bi_pacs_estudos
                SET situacao = 'em_laudo', usuario_responsavel_id = :uid,
                    data_inicio_laudo = {$dataAtualSql}, hora_inicio_laudo = {$horaAtualSql}
                WHERE id = :id
                  AND (
                        (usuario_responsavel_id IS NULL AND COALESCE(situacao, 'novo') IN ('novo','aberto','urgente'))
                     OR (usuario_responsavel_id = :uid2 AND COALESCE(situacao, 'novo') IN ('a_laudar','em_laudo','rascunho'))
                  )
            ");
            $stmt->execute($params);
        }
        if ($stmt->rowCount() > 0) return true;

        $estudo = $this->findEstudoById($estudoId);
        return $estudo && (int) $estudo->usuario_responsavel_id === $userId;
    }

    public function reatribuirLock(int $estudoId, int $userId): void {
        $dataAtualSql = \App\Core\SqlHelper::isPostgres() ? 'CURRENT_DATE' : 'CURDATE()';
        $horaAtualSql = \App\Core\SqlHelper::isPostgres() ? 'CURRENT_TIME' : 'CURTIME()';
        try {
            $this->pdo->prepare("
                UPDATE bi_pacs_estudos
                SET usuario_responsavel_id = :uid, data_inicio_laudo = {$dataAtualSql},
                    hora_inicio_laudo = {$horaAtualSql}, lock_heartbeat_em = NOW()
                WHERE id = :id
            ")->execute(['uid' => $userId, 'id' => $estudoId]);
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'lock_heartbeat_em') === false) throw $e;
            Logger::warning('ReportRepository::reatribuirLock sem heartbeat — migration pendente', [
                'estudo_id' => $estudoId,
                'error' => $e->getMessage(),
            ]);
            $this->pdo->prepare("
                UPDATE bi_pacs_estudos
                SET usuario_responsavel_id = :uid, data_inicio_laudo = {$dataAtualSql}, hora_inicio_laudo = {$horaAtualSql}
                WHERE id = :id
            ")->execute(['uid' => $userId, 'id' => $estudoId]);
        }
    }

    public function marcarHeartbeat(int $estudoId): void {
        try {
            $this->pdo->prepare("UPDATE bi_pacs_estudos SET lock_heartbeat_em = NOW() WHERE id = :id")
                ->execute(['id' => $estudoId]);
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'lock_heartbeat_em') === false) throw $e;
            Logger::warning('ReportRepository::marcarHeartbeat ignorado — migration pendente', [
                'estudo_id' => $estudoId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function atualizarSituacaoEstudo(int $estudoId, string $situacao): void {
        try {
            $this->pdo->prepare(
                "UPDATE bi_pacs_estudos
                 SET situacao = :situacao,
                     laudo_assinado_em = CASE WHEN :situacao_ts IN ('assinado','liberado') THEN NOW() ELSE laudo_assinado_em END
                 WHERE id = :id"
            )->execute(['situacao' => $situacao, 'situacao_ts' => $situacao, 'id' => $estudoId]);
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'laudo_assinado_em') === false) throw $e;
            Logger::warning('ReportRepository::atualizarSituacaoEstudo sem laudo_assinado_em — migration pendente', [
                'estudo_id' => $estudoId, 'situacao' => $situacao, 'error' => $e->getMessage(),
            ]);
            $this->pdo->prepare("UPDATE bi_pacs_estudos SET situacao = :situacao WHERE id = :id")
                ->execute(['situacao' => $situacao, 'id' => $estudoId]);
        }
    }

    // ── reports (schema de produção: estudo_id, situacao, secao_*) ───────────

    public function findReportById(int $id): ?object {
        $sql = "SELECT * FROM reports WHERE id = :id";
        $params = ['id' => $id];
        if (TenantContext::isSet()) {
            $sql .= " AND tenant_id = :tenant_id";
            $params['tenant_id'] = TenantContext::id();
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /**
     * Resolve a URL pública do laudário sem expor id sequencial ou Study UID.
     */
    public function findReportByPublicToken(string $token): ?object {
        $stmt = $this->pdo->prepare("SELECT * FROM reports WHERE public_token = :token LIMIT 1");
        $stmt->execute(['token' => $token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Busca o laudo pelo id do estudo (FK estudo_id).
     */
    public function findReportByEstudoId(int $estudoId): ?object {
        $sql = "SELECT * FROM reports WHERE estudo_id = :id";
        $params = ['id' => $estudoId];
        if (TenantContext::isSet()) {
            $sql .= " AND tenant_id = :tenant_id";
            $params['tenant_id'] = TenantContext::id();
        }
        $sql .= " LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /**
     * Cria um novo laudo com seções vazias.
     * Schema de produção: estudo_id, usuario_id, situacao, secao_*
     */
    public function createReport(int $estudoId, ?int $tenantId, ?string $studyUid, int $medicoId, array $conteudo): object {
        // Extrai seções do array de conteúdo (compatibilidade com ReportService)
        $secoes = $conteudo['secoes'] ?? [];
        $sql = "
            INSERT INTO reports
                (tenant_id, estudo_id, study_instance_uid, public_token, usuario_id, situacao,
                 secao_exame, secao_tecnica, secao_achados, secao_conclusao, secao_recomendacao)
            VALUES
                (:tenant_id, :estudo_id, :study_uid, :public_token, :usuario_id, 'rascunho',
                 :secao_exame, :secao_tecnica, :secao_achados, :secao_conclusao, :secao_recomendacao)
        ";

        // A unicidade é garantida pelo índice. Uma colisão de 192 bits é
        // improvável, mas a tentativa limitada preserva disponibilidade.
        for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    'tenant_id'         => $tenantId,
                    'estudo_id'         => $estudoId,
                    'study_uid'         => $studyUid,
                    'public_token'      => bin2hex(random_bytes(24)),
                    'usuario_id'        => $medicoId,
                    'secao_exame'       => $secoes['exame']        ?? '',
                    'secao_tecnica'     => $secoes['tecnica']      ?? '',
                    'secao_achados'     => $secoes['achados']      ?? '',
                    'secao_conclusao'   => $secoes['conclusao']    ?? '',
                    'secao_recomendacao'=> $secoes['recomendacao'] ?? '',
                ]);
                return $this->findReportById((int) $this->pdo->lastInsertId());
            } catch (\PDOException $e) {
                Logger::warning('ReportRepository::createReport falhou ao gerar token público', [
                    'estudo_id' => $estudoId,
                    'tentativa' => $tentativa,
                    'error' => $e->getMessage(),
                ]);
                if ($tentativa === 3 || stripos($e->getMessage(), 'public_token') === false) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Não foi possível gerar token público para o laudo.');
    }

    /**
     * Atualiza o conteúdo do laudo.
     * Converte do formato JSON (secoes array) para colunas separadas.
     */
    public function atualizarConteudo(int $reportId, array $conteudo, ?string $status = null, ?int $templateId = null): void {
        $secoes = $conteudo['secoes'] ?? $conteudo; // suporta ambos os formatos

        if (array_key_exists('corpo', $secoes)) {
            // O modo livre é a fonte única do laudo. Zerar as colunas estruturadas
            // impede que um rascunho anterior reapareça ao gerar o PDF.
            $sets = [
                'corpo_laudo = :corpo_laudo',
                "secao_exame = ''",
                "secao_tecnica = ''",
                "secao_achados = ''",
                "secao_conclusao = ''",
                "secao_recomendacao = ''",
            ];
            $params = [
                'corpo_laudo' => (string) $secoes['corpo'],
                'id' => $reportId,
            ];
            if ($status !== null) {
                $statusMap = ['em_laudo' => 'rascunho', 'rascunho' => 'rascunho', 'assinado' => 'assinado', 'liberado' => 'liberado'];
                $sets[] = 'situacao = :situacao';
                $params['situacao'] = $statusMap[$status] ?? 'rascunho';
            }
            if ($templateId !== null) {
                $sets[] = 'template_id = :template_id';
                $params['template_id'] = $templateId;
            }

            try {
                $this->pdo->prepare("UPDATE reports SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
                return;
            } catch (\PDOException $e) {
                if (stripos($e->getMessage(), 'corpo_laudo') === false) {
                    throw $e;
                }
                // Deploy tolerante: antes da migration, mantém o editor livre
                // usando a coluna legada sem expor rótulos clínicos na interface.
                Logger::warning('ReportRepository::atualizarConteudo sem corpo_laudo — migration pendente', [
                    'report_id' => $reportId,
                    'error' => $e->getMessage(),
                ]);
                $sets = [
                    "secao_exame = ''",
                    "secao_tecnica = ''",
                    'secao_achados = :corpo_laudo',
                    "secao_conclusao = ''",
                    "secao_recomendacao = ''",
                ];
                $this->pdo->prepare("UPDATE reports SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
                return;
            }
        }

        $sets   = [
            'secao_exame        = :secao_exame',
            'secao_tecnica      = :secao_tecnica',
            'secao_achados      = :secao_achados',
            'secao_conclusao    = :secao_conclusao',
            'secao_recomendacao = :secao_recomendacao',
        ];
        $params = [
            'secao_exame'       => $secoes['exame']        ?? '',
            'secao_tecnica'     => $secoes['tecnica']      ?? '',
            'secao_achados'     => $secoes['achados']      ?? '',
            'secao_conclusao'   => $secoes['conclusao']    ?? '',
            'secao_recomendacao'=> $secoes['recomendacao'] ?? '',
            'id'                => $reportId,
        ];

        if ($status !== null) {
            // Mapeia status antigo para enum de produção
            $statusMap = ['em_laudo' => 'rascunho', 'rascunho' => 'rascunho', 'assinado' => 'assinado', 'liberado' => 'liberado'];
            $sets[]    = 'situacao = :situacao';
            $params['situacao'] = $statusMap[$status] ?? 'rascunho';
        }
        if ($templateId !== null) {
            $sets[]    = 'template_id = :template_id';
            $params['template_id'] = $templateId;
        }

        $this->pdo->prepare("UPDATE reports SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
    }

    public function marcarAssinado(int $reportId, string $status): void {
        $col = $status === 'liberado' ? 'liberado_em' : 'assinado_em';
        $uid = $status === 'liberado' ? 'liberado_por' : 'assinado_por';
        $situacao = in_array($status, ['assinado', 'liberado']) ? $status : 'assinado';
        $this->pdo->prepare("UPDATE reports SET situacao = :situacao, {$col} = NOW(), {$uid} = :uid WHERE id = :id")
            ->execute(['situacao' => $situacao, 'uid' => \App\Core\Auth::userId(), 'id' => $reportId]);
    }

    /**
     * Grava o hash + a assinatura visual congelada (tipo + cópia do arquivo)
     * usada nesta assinatura específica. `assinatura_hash`/`assinatura_crm`
     * já existiam no schema de `reports` mas nunca eram populados por código
     * nenhum antes desta tarefa — reports/pdf.php já lia `$r['assinatura_hash']`
     * esperando isso (ver modules/assinatura-medico.md).
     */
    public function salvarAssinaturaVisual(int $reportId, string $hash, ?string $crm, ?string $tipo, ?string $caminhoArquivo): void {
        try {
            $this->pdo->prepare(
                "UPDATE reports SET assinatura_hash = :hash, assinatura_crm = :crm, assinatura_tipo = :tipo, assinatura_caminho_arquivo = :caminho WHERE id = :id"
            )->execute([
                'hash' => $hash, 'crm' => $crm, 'tipo' => $tipo, 'caminho' => $caminhoArquivo, 'id' => $reportId,
            ]);
        } catch (\PDOException $e) {
            // A migration visual é complementar; não pode impedir a assinatura
            // legal, que fica registrada em reports.situacao/assinado_em/hash.
            Logger::warning('ReportRepository::salvarAssinaturaVisual ignorado — migration visual pendente', [
                'report_id' => $reportId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function proximaVersao(int $reportId): int {
        try {
            $stmt = $this->pdo->prepare("SELECT COALESCE(MAX(versao), 0) FROM report_versions WHERE report_id = :id");
            $stmt->execute(['id' => $reportId]);
            return ((int) $stmt->fetchColumn()) + 1;
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'versao') === false) throw $e;
            $stmt = $this->pdo->prepare("SELECT COALESCE(MAX(versao_numero), 0) FROM report_versions WHERE report_id = :id");
            $stmt->execute(['id' => $reportId]);
            return ((int) $stmt->fetchColumn()) + 1;
        }
    }

    public function createVersion(int $reportId, array $conteudo, string $acao, int $userId, int $versaoNumero): void {
        $secoes = $conteudo['secoes'] ?? $conteudo;
        // Tenta inserir no schema de produção (colunas separadas)
        try {
            $this->pdo->prepare("
                INSERT INTO report_versions
                    (report_id, versao, usuario_id, acao, secao_exame, secao_tecnica, secao_achados, secao_conclusao, secao_recomendacao)
                VALUES
                    (:report_id, :versao, :user_id, :acao, :se, :st, :sa, :sc, :sr)
            ")->execute([
                'report_id' => $reportId,
                'versao'    => $versaoNumero,
                'user_id'   => $userId,
                'acao'      => $acao,
                'se'        => $secoes['exame']        ?? '',
                'st'        => $secoes['tecnica']      ?? '',
                'sa'        => $secoes['achados']      ?? '',
                'sc'        => $secoes['conclusao']    ?? '',
                'sr'        => $secoes['recomendacao'] ?? '',
            ]);
        } catch (\PDOException $e) {
            try {
                $this->pdo->prepare("
                    INSERT INTO report_versions (report_id, versao_numero, conteudo, acao, user_id)
                    VALUES (:report_id, :versao, :conteudo, :acao, :user_id)
                ")->execute([
                    'report_id' => $reportId,
                    'versao' => $versaoNumero,
                    'conteudo' => json_encode(['secoes' => $secoes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'acao' => $acao,
                    'user_id' => $userId,
                ]);
            } catch (\PDOException $legacyError) {
                \App\Core\Logger::error('ReportRepository::createVersion falhou nos schemas conhecidos', [
                    'error' => $legacyError->getMessage(), 'report_id' => $reportId,
                ]);
            }
        }
    }

    public function listVersions(int $reportId): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT rv.id, rv.versao AS versao_numero, rv.acao, rv.created_at AS criado_em, u.name AS user_nome
                FROM report_versions rv
                LEFT JOIN bi_users u ON u.id = rv.usuario_id
                WHERE rv.report_id = :report_id
                ORDER BY rv.versao DESC
            ");
            $stmt->execute(['report_id' => $reportId]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            $stmt = $this->pdo->prepare("
                SELECT rv.id, rv.versao_numero, rv.acao, rv.criado_em,
                       COALESCE(u.name, '') AS user_nome
                FROM report_versions rv
                LEFT JOIN bi_users u ON u.id = rv.user_id
                WHERE rv.report_id = :report_id
                ORDER BY rv.versao_numero DESC
            ");
            $stmt->execute(['report_id' => $reportId]);
            return $stmt->fetchAll();
        }
    }

    public function findVersion(int $versionId): ?object {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM report_versions WHERE id = :id");
            $stmt->execute(['id' => $versionId]);
            return $stmt->fetch() ?: null;
        } catch (\PDOException $e) {
            $stmt = $this->pdo->prepare("SELECT * FROM report_versions WHERE id = :id");
            $stmt->execute(['id' => $versionId]);
            return $stmt->fetch() ?: null;
        }
    }

    public function createSignature(int $reportId, int $userId, string $nomeMedico, ?string $crm, string $hash, ?string $ip): void {
        $dataAtualSql = \App\Core\SqlHelper::isPostgres() ? 'CURRENT_DATE' : 'CURDATE()';
        $horaAtualSql = \App\Core\SqlHelper::isPostgres() ? 'CURRENT_TIME' : 'CURTIME()';
        try {
            $this->pdo->prepare("
                INSERT INTO report_signatures
                    (report_id, usuario_id, usuario_nome, crm, hash, ip, user_agent, conteudo_hash)
                VALUES (:report_id, :user_id, :nome, :crm, :hash, :ip, :ua, :conteudo_hash)
            ")->execute([
                'report_id'    => $reportId,
                'user_id'      => $userId,
                'nome'         => $nomeMedico,
                'crm'          => $crm,
                'hash'         => $hash,
                'ip'           => $ip,
                'ua'           => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'conteudo_hash'=> $hash,
            ]);
            return;
        } catch (\PDOException $e) {
            Logger::warning('ReportRepository::createSignature usando schema legado', [
                'report_id' => $reportId,
                'error' => $e->getMessage(),
            ]);
        }

        // Compatibilidade com a definição antiga de 2026-07-04.
        try {
            $this->pdo->prepare("
                INSERT INTO report_signatures (report_id, user_id, nome_medico, crm, data, hora, hash, ip)
                VALUES (:report_id, :user_id, :nome, :crm, {$dataAtualSql}, {$horaAtualSql}, :hash, :ip)
            ")->execute([
                'report_id' => $reportId,
                'user_id'   => $userId,
                'nome'      => $nomeMedico,
                'crm'       => $crm,
                'hash'      => $hash,
                'ip'        => $ip,
            ]);
        } catch (\PDOException $legacyError) {
            // report_signatures possui três schemas históricos conflitantes.
            // A assinatura principal já será persistida em reports; registrar o
            // motivo permite corrigir a migration sem bloquear o médico agora.
            Logger::error('ReportRepository::createSignature indisponível nos schemas conhecidos', [
                'report_id' => $reportId,
                'error' => $legacyError->getMessage(),
            ]);
        }
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
        try {
            $this->pdo->prepare("
                INSERT INTO report_logs
                    (report_id, estudo_id, tenant_id, usuario_id, usuario_nome, acao, descricao, ip, user_agent)
                VALUES
                    (:report_id, :estudo_id, :tenant_id, :usuario_id, :usuario_nome, :acao, :descricao, :ip, :ua)
            ")->execute([
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
                'acao'      => $acao,
                'error'     => $e->getMessage()
            ]);
        }
    }
}
