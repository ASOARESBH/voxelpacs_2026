<?php

namespace App\Services;

use App\Repositories\ReportRepository;
use App\Repositories\EstudosRepository;
use App\Models\Report;
use App\Core\Auth;
use Exception;

class ReportService
{
    private ReportRepository $reportRepo;
    private EstudosRepository $estudosRepo;

    public function __construct()
    {
        $this->reportRepo = new ReportRepository();
        $this->estudosRepo = new EstudosRepository();
    }

    /**
     * Busca um estudo e seu laudo correspondente, ou inicializa um novo laudo.
     * Retorna array com ['estudo' => array, 'report' => Report, 'is_new' => bool, 'bloqueado' => bool]
     */
    public function getOrInitializeReport(string $studyUid, ?int $tenantId, bool $isAdmin): array
    {
        // 1. Buscar o estudo
        $estudo = $this->estudosRepo->findByStudyUid($studyUid, $tenantId, $isAdmin);
        if (!$estudo) {
            throw new Exception("Estudo não encontrado ou acesso negado.");
        }

        $estudoId = (int) $estudo['id'];
        $tenantId = (int) $estudo['tenant_id'];

        // 2. Buscar o laudo
        $report = $this->reportRepo->findByEstudoId($estudoId, $tenantId);
        $isNew = false;
        $bloqueado = false;

        $userId = Auth::userId();
        $userNome = Auth::user()->nome ?? 'Usuário';

        if (!$report) {
            // 3. Se não existe, inicializa
            $report = new Report();
            $report->tenant_id = $tenantId;
            $report->estudo_id = $estudoId;
            $report->study_instance_uid = $studyUid;
            $report->usuario_id = $userId;
            $report->situacao = 'rascunho';
            $report->ip_criacao = $_SERVER['REMOTE_ADDR'] ?? null;
            $report->ip_ultima_edicao = $_SERVER['REMOTE_ADDR'] ?? null;
            $report->bloqueado_por = $userId;
            $report->bloqueado_em = date('Y-m-d H:i:s');
            
            $report->id = $this->reportRepo->create($report);
            $isNew = true;

            // Log e versão inicial
            $this->reportRepo->logAction($report->id, $estudoId, $tenantId, $userId, $userNome, 'abertura', 'Novo laudo iniciado');
            $this->reportRepo->saveVersion($report, 'rascunho', $userNome);

            // Atualiza status do estudo na worklist
            $this->estudosRepo->atualizarStatus($estudoId, 'em_laudo', $userId);

        } else {
            // 4. Verifica bloqueio
            if ($report->bloqueado_por && $report->bloqueado_por !== $userId) {
                // Outro médico está editando. Verifica timeout (ex: 30 minutos)
                $bloqueadoEm = strtotime($report->bloqueado_em);
                $agora = time();
                if (($agora - $bloqueadoEm) < 1800) { // 30 min
                    $bloqueado = true;
                } else {
                    // Timeout do bloqueio, assumir
                    $report->bloqueado_por = $userId;
                    $report->bloqueado_em = date('Y-m-d H:i:s');
                    $this->reportRepo->update($report);
                }
            } else if ($report->situacao === 'rascunho' || $report->situacao === 'em_laudo') {
                // Eu mesmo estou editando, renova o bloqueio
                $report->bloqueado_por = $userId;
                $report->bloqueado_em = date('Y-m-d H:i:s');
                $this->reportRepo->update($report);
            }

            // Log de abertura
            $this->reportRepo->logAction($report->id, $estudoId, $tenantId, $userId, $userNome, 'abertura', 'Laudo aberto para ' . ($bloqueado ? 'visualização' : 'edição'));
            
            // Atualiza status do estudo se estava como novo/aberto
            if (!$bloqueado && in_array($estudo['situacao'], ['novo', 'aberto'])) {
                $this->estudosRepo->atualizarStatus($estudoId, 'em_laudo', $userId);
            }
        }

        return [
            'estudo' => $estudo,
            'report' => $report,
            'is_new' => $isNew,
            'bloqueado' => $bloqueado
        ];
    }

    /**
     * Salva o laudo (Autosave ou Save manual)
     */
    public function saveReport(array $data): bool
    {
        $reportId = (int) $data['id'];
        $userId = Auth::userId();
        
        // Buscar para garantir que existe e que não está bloqueado por outro
        $report = clone $this->reportRepo->findByEstudoId((int)$data['estudo_id']);
        if (!$report || $report->id !== $reportId) {
            throw new Exception("Laudo não encontrado.");
        }

        if ($report->bloqueado_por && $report->bloqueado_por !== $userId) {
            throw new Exception("Laudo está bloqueado por outro médico.");
        }

        if ($report->situacao === 'assinado' || $report->situacao === 'liberado') {
            throw new Exception("Laudo já assinado, não pode ser modificado.");
        }

        // Atualizar dados
        $report->secao_exame = $data['secao_exame'] ?? null;
        $report->secao_tecnica = $data['secao_tecnica'] ?? null;
        $report->secao_achados = $data['secao_achados'] ?? null;
        $report->secao_conclusao = $data['secao_conclusao'] ?? null;
        $report->secao_recomendacao = $data['secao_recomendacao'] ?? null;
        
        $report->tempo_edicao_seg = (int) ($data['tempo_decorrido'] ?? 30);
        $report->ip_ultima_edicao = $_SERVER['REMOTE_ADDR'] ?? null;
        
        $report->bloqueado_por = $userId;
        $report->bloqueado_em = date('Y-m-d H:i:s');

        $isManualSave = ($data['is_manual'] ?? false) === true;

        $sucesso = $this->reportRepo->update($report);

        if ($sucesso) {
            $userNome = Auth::user()->nome ?? 'Usuário';
            
            // Log apenas se for save manual (para não flodar o banco no autosave)
            if ($isManualSave) {
                $this->reportRepo->logAction($report->id, $report->estudo_id, $report->tenant_id, $userId, $userNome, 'salvar', 'Salvamento manual');
                $this->reportRepo->saveVersion($report, 'rascunho', $userNome);
            }
        }

        return $sucesso;
    }

    /**
     * Assina o laudo
     */
    public function signReport(array $data): bool
    {
        $reportId = (int) $data['id'];
        $userId = Auth::userId();
        $user = Auth::user();
        $senha = $data['senha'] ?? '';
        
        if (!password_verify($senha, $user->password)) {
            throw new Exception("Senha incorreta.");
        }

        $report = $this->reportRepo->findByEstudoId((int)$data['estudo_id']);
        if (!$report || $report->id !== $reportId) {
            throw new Exception("Laudo não encontrado.");
        }

        if ($report->bloqueado_por && $report->bloqueado_por !== $userId) {
            throw new Exception("Laudo está bloqueado por outro médico.");
        }

        // Gerar Hash do conteúdo
        $conteudo = json_encode([
            'exame' => $data['secao_exame'] ?? '',
            'tecnica' => $data['secao_tecnica'] ?? '',
            'achados' => $data['secao_achados'] ?? '',
            'conclusao' => $data['secao_conclusao'] ?? '',
            'recomendacao' => $data['secao_recomendacao'] ?? '',
            'medico' => $user->nome,
            'crm' => $user->crm ?? '',
            'data' => date('Y-m-d H:i:s')
        ]);
        $hash = hash('sha256', $conteudo);

        // Salvar as seções primeiro (garantir que o que foi assinado está no banco)
        $report->secao_exame = $data['secao_exame'] ?? null;
        $report->secao_tecnica = $data['secao_tecnica'] ?? null;
        $report->secao_achados = $data['secao_achados'] ?? null;
        $report->secao_conclusao = $data['secao_conclusao'] ?? null;
        $report->secao_recomendacao = $data['secao_recomendacao'] ?? null;
        $report->ip_ultima_edicao = $_SERVER['REMOTE_ADDR'] ?? null;
        $report->bloqueado_por = null;
        $report->bloqueado_em = null;
        $report->situacao = 'assinado';
        $report->tempo_edicao_seg = 0; // Não add tempo no sign

        $this->reportRepo->update($report);

        // Efetivar assinatura
        $this->reportRepo->sign($reportId, $userId, $user->crm ?? '', $hash);
        
        // Registrar tabela de assinaturas
        $this->reportRepo->saveSignature($reportId, $userId, $user->nome, $user->crm ?? '', $hash, $conteudo);

        // Logs e Versão
        $this->reportRepo->logAction($reportId, $report->estudo_id, $report->tenant_id, $userId, $user->nome, 'assinatura', 'Laudo assinado digitalmente');
        $this->reportRepo->saveVersion($report, 'assinado', $user->nome);

        // Atualizar status na worklist
        $this->estudosRepo->atualizarStatus($report->estudo_id, 'assinado', $userId);

        return true;
    }

    /**
     * Retorna dados para o painel lateral direito (Autotextos, Templates, etc)
     */
    public function getSidebarData(?int $tenantId, int $usuarioId): array
    {
        return [
            'autotexts' => $this->reportRepo->getAutotexts($tenantId, $usuarioId),
            'templates' => $this->reportRepo->getTemplates($tenantId, $usuarioId)
        ];
    }
}
