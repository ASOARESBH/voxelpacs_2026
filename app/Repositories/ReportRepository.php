<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\Report;
use PDO;
use PDOException;
use App\Core\Logger;

class ReportRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    /**
     * Busca um laudo pelo ID do estudo (estudo_id)
     */
    public function findByEstudoId(int $estudoId, ?int $tenantId = null): ?Report
    {
        $sql = "SELECT r.*, u.nome as medico_nome, u.crm as medico_crm 
                FROM reports r
                JOIN bi_users u ON u.id = r.usuario_id
                WHERE r.estudo_id = :estudo_id";
                
        $params = [':estudo_id' => $estudoId];
        
        if ($tenantId) {
            $sql .= " AND r.tenant_id = :tenant_id";
            $params[':tenant_id'] = $tenantId;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        $report = new Report();
        $report->fill($data);
        // Anexar dados extras
        $report->medico_nome = $data['medico_nome'];
        $report->medico_crm = $data['medico_crm'];
        
        return $report;
    }

    /**
     * Busca um laudo pelo StudyInstanceUID
     */
    public function findByStudyUid(string $studyUid, ?int $tenantId = null): ?Report
    {
        $sql = "SELECT r.*, u.nome as medico_nome, u.crm as medico_crm 
                FROM reports r
                JOIN bi_users u ON u.id = r.usuario_id
                WHERE r.study_instance_uid = :study_uid";
                
        $params = [':study_uid' => $studyUid];
        
        if ($tenantId) {
            $sql .= " AND r.tenant_id = :tenant_id";
            $params[':tenant_id'] = $tenantId;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        $report = new Report();
        $report->fill($data);
        // Anexar dados extras
        $report->medico_nome = $data['medico_nome'];
        $report->medico_crm = $data['medico_crm'];
        
        return $report;
    }

    /**
     * Cria um novo laudo
     * @throws PDOException
     */
    public function create(Report $report): int
    {
        // Prevenção de Cadastros Duplicados
        $existente = $this->findByEstudoId($report->estudo_id);
        if ($existente) {
            return $existente->id;
        }

        $sql = "INSERT INTO reports (
                    tenant_id, estudo_id, study_instance_uid, usuario_id, situacao,
                    secao_exame, secao_tecnica, secao_achados, secao_conclusao, secao_recomendacao,
                    ip_criacao, ip_ultima_edicao, bloqueado_por, bloqueado_em
                ) VALUES (
                    :tenant_id, :estudo_id, :study_uid, :usuario_id, :situacao,
                    :secao_exame, :secao_tecnica, :secao_achados, :secao_conclusao, :secao_recomendacao,
                    :ip_criacao, :ip_ultima_edicao, :bloqueado_por, :bloqueado_em
                )";

        $params = [
            ':tenant_id'          => $report->tenant_id,
            ':estudo_id'          => $report->estudo_id,
            ':study_uid'          => $report->study_instance_uid,
            ':usuario_id'         => $report->usuario_id,
            ':situacao'           => $report->situacao ?? 'rascunho',
            ':secao_exame'        => $report->secao_exame,
            ':secao_tecnica'      => $report->secao_tecnica,
            ':secao_achados'      => $report->secao_achados,
            ':secao_conclusao'    => $report->secao_conclusao,
            ':secao_recomendacao' => $report->secao_recomendacao,
            ':ip_criacao'         => $report->ip_criacao,
            ':ip_ultima_edicao'   => $report->ip_ultima_edicao,
            ':bloqueado_por'      => $report->bloqueado_por,
            ':bloqueado_em'       => $report->bloqueado_em
        ];

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            Logger::error('Erro ao criar laudo', [
                'estudo_id' => $report->estudo_id,
                'usuario_id' => $report->usuario_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Atualiza um laudo existente (autosave/salvar)
     */
    public function update(Report $report): bool
    {
        $sql = "UPDATE reports SET 
                    secao_exame = :secao_exame,
                    secao_tecnica = :secao_tecnica,
                    secao_achados = :secao_achados,
                    secao_conclusao = :secao_conclusao,
                    secao_recomendacao = :secao_recomendacao,
                    situacao = :situacao,
                    tempo_edicao_seg = tempo_edicao_seg + :tempo_add,
                    ip_ultima_edicao = :ip,
                    bloqueado_por = :bloqueado_por,
                    bloqueado_em = :bloqueado_em
                WHERE id = :id";

        $params = [
            ':id'                 => $report->id,
            ':secao_exame'        => $report->secao_exame,
            ':secao_tecnica'      => $report->secao_tecnica,
            ':secao_achados'      => $report->secao_achados,
            ':secao_conclusao'    => $report->secao_conclusao,
            ':secao_recomendacao' => $report->secao_recomendacao,
            ':situacao'           => $report->situacao,
            ':tempo_add'          => $report->tempo_edicao_seg, // tempo a adicionar nesta requisição
            ':ip'                 => $report->ip_ultima_edicao,
            ':bloqueado_por'      => $report->bloqueado_por,
            ':bloqueado_em'       => $report->bloqueado_em
        ];

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            Logger::error('Erro ao atualizar laudo', [
                'report_id' => $report->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Assina o laudo
     */
    public function sign(int $reportId, int $usuarioId, string $crm, string $hash): bool
    {
        $sql = "UPDATE reports SET 
                    situacao = 'assinado',
                    assinado_por = :usuario_id,
                    assinado_em = NOW(),
                    assinatura_hash = :hash,
                    assinatura_crm = :crm,
                    bloqueado_por = NULL,
                    bloqueado_em = NULL
                WHERE id = :id";

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':id'         => $reportId,
                ':usuario_id' => $usuarioId,
                ':hash'       => $hash,
                ':crm'        => $crm
            ]);
        } catch (PDOException $e) {
            Logger::error('Erro ao assinar laudo', [
                'report_id' => $reportId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
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
        } catch (PDOException $e) {
            Logger::error('Erro ao registrar log de laudo', [
                'report_id' => $reportId,
                'acao' => $acao,
                'error' => $e->getMessage()
            ]);
            // Não lança exceção para não travar o fluxo principal por erro de log
        }
    }

    /**
     * Salva uma versão no histórico
     */
    public function saveVersion(Report $report, string $acao, string $usuarioNome): void
    {
        // Pega o último número de versão
        $stmt = $this->pdo->prepare("SELECT MAX(versao) as v FROM report_versions WHERE report_id = :id");
        $stmt->execute([':id' => $report->id]);
        $v = $stmt->fetchColumn();
        $novaVersao = $v ? $v + 1 : 1;

        $sql = "INSERT INTO report_versions (
                    report_id, versao, usuario_id, usuario_nome, acao,
                    secao_exame, secao_tecnica, secao_achados, secao_conclusao, secao_recomendacao, ip
                ) VALUES (
                    :report_id, :versao, :usuario_id, :usuario_nome, :acao,
                    :secao_exame, :secao_tecnica, :secao_achados, :secao_conclusao, :secao_recomendacao, :ip
                )";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':report_id'          => $report->id,
                ':versao'             => $novaVersao,
                ':usuario_id'         => $report->usuario_id, // quem está salvando a versão
                ':usuario_nome'       => $usuarioNome,
                ':acao'               => $acao,
                ':secao_exame'        => $report->secao_exame,
                ':secao_tecnica'      => $report->secao_tecnica,
                ':secao_achados'      => $report->secao_achados,
                ':secao_conclusao'    => $report->secao_conclusao,
                ':secao_recomendacao' => $report->secao_recomendacao,
                ':ip'                 => $report->ip_ultima_edicao
            ]);
        } catch (PDOException $e) {
            Logger::error('Erro ao salvar versão de laudo', [
                'report_id' => $report->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Busca autotextos
     */
    public function getAutotexts(?int $tenantId, ?int $usuarioId): array
    {
        $sql = "SELECT * FROM report_autotext 
                WHERE ativo = 1 
                AND (tenant_id IS NULL OR tenant_id = :tenant_id)
                AND (usuario_id IS NULL OR usuario_id = :usuario_id)
                ORDER BY gatilho ASC";
                
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':usuario_id' => $usuarioId
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca templates
     */
    public function getTemplates(?int $tenantId, ?int $usuarioId): array
    {
        $sql = "SELECT * FROM report_templates 
                WHERE ativo = 1 
                AND (tenant_id IS NULL OR tenant_id = :tenant_id)
                AND (usuario_id IS NULL OR usuario_id = :usuario_id)
                ORDER BY modalidade ASC, nome ASC";
                
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':usuario_id' => $usuarioId
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Registra assinatura digital
     */
    public function saveSignature(int $reportId, int $usuarioId, string $usuarioNome, string $crm, string $hash, string $conteudo): void
    {
        $sql = "INSERT INTO report_signatures (
                    report_id, usuario_id, usuario_nome, crm, hash, conteudo_hash, ip, user_agent
                ) VALUES (
                    :report_id, :usuario_id, :usuario_nome, :crm, :hash, :conteudo, :ip, :ua
                )";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':report_id'    => $reportId,
                ':usuario_id'   => $usuarioId,
                ':usuario_nome' => $usuarioNome,
                ':crm'          => $crm,
                ':hash'         => $hash,
                ':conteudo'     => $conteudo,
                ':ip'           => $_SERVER['REMOTE_ADDR'] ?? null,
                ':ua'           => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (PDOException $e) {
            Logger::error('Erro ao salvar registro de assinatura', [
                'report_id' => $reportId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
