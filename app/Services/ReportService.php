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

<?php
namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\TenantContext;
use App\Repositories\ReportRepository;

/**
 * Regras de negócio do módulo de Laudos: assumir estudo, editar/salvar,
 * versionar, assinar e controlar o lock de edição concorrente.
 */
class ReportService {
    private const LOCK_TTL_MINUTES = 20;

    private ReportRepository $repo;

    public function __construct() {
        $this->repo = new ReportRepository();
    }

    /**
     * Botão "Assumir" na Worklist. Marca o estudo como em_laudo para o usuário atual.
     *
     * @return array{ok:bool, ...}
     */
    public function assumir(int $estudoId): array {
        $estudo = $this->repo->findEstudoById($estudoId);
        if (!$estudo) {
            return ['ok' => false, 'error' => 'estudo_nao_encontrado'];
        }

        $userId = Auth::userId();
        $assumido = $this->repo->assumirEstudo($estudoId, $userId);

        if (!$assumido) {
            $estudoAtual = $this->repo->findEstudoById($estudoId);
            return [
                'ok' => false,
                'error' => 'em_laudo_outro_usuario',
                'usuario_responsavel_nome' => $this->repo->findUserNome((int) $estudoAtual->usuario_responsavel_id),
                'hora_inicio_laudo' => $estudoAtual->hora_inicio_laudo,
            ];
        }

        $estudo = $this->repo->findEstudoById($estudoId);
        $report = $this->getOrCreateReport($estudo, $userId);

        AuditLogger::log('estudo.assumir', 'bi_pacs_estudos', $estudoId, [
            'study_instance_uid' => $estudo->study_instance_uid,
            'report_id' => $report->id,
        ]);

        return [
            'ok' => true,
            'situacao' => 'em_laudo',
            'usuario_responsavel_id' => $userId,
            'usuario_responsavel_nome' => Auth::user()?->name,
            'data_inicio_laudo' => $estudo->data_inicio_laudo,
            'hora_inicio_laudo' => $estudo->hora_inicio_laudo,
            'study_instance_uid' => $estudo->study_instance_uid,
            'reports_url' => '/reports/' . rawurlencode($estudo->study_instance_uid),
        ];
    }

    /**
     * Carrega o estudo + laudo para a tela /reports/{studyUid}, decidindo
     * se abre em modo edição ou somente-leitura (lock de outro usuário).
     */
    public function carregarParaEdicao(string $studyUid): array {
        $estudo = $this->repo->findEstudoByStudyUid($studyUid);
        if (!$estudo) {
            return ['ok' => false, 'error' => 'estudo_nao_encontrado'];
        }

        $userId = Auth::userId();
        $readonly = false;
        $lockInfo = null;

        $donoId = $estudo->usuario_responsavel_id ? (int) $estudo->usuario_responsavel_id : null;
        if ($donoId && $donoId !== $userId && in_array($estudo->situacao, ['em_laudo', 'rascunho', 'revisao'], true)) {
            if ($this->lockExpirado($estudo->lock_heartbeat_em)) {
                $this->repo->reatribuirLock($estudo->id, $userId);
                AuditLogger::log('report.lock_expirado_reatribuido', 'bi_pacs_estudos', $estudo->id, [
                    'dono_anterior_id' => $donoId,
                    'novo_dono_id' => $userId,
                ]);
                $estudo = $this->repo->findEstudoById($estudo->id);
            } else {
                $readonly = true;
                $lockInfo = [
                    'nome' => $this->repo->findUserNome($donoId),
                    'desde' => $estudo->hora_inicio_laudo,
                ];
            }
        }

        $report = $this->getOrCreateReport($estudo, $userId);

        if (in_array($report->status, ['assinado', 'liberado'], true)) {
            $readonly = true;
        }

        AuditLogger::log('report.visualizar', 'reports', $report->id);

        return [
            'ok' => true,
            'estudo' => $estudo,
            'report' => $report,
            'readonly' => $readonly,
            'lockInfo' => $lockInfo,
        ];
    }

    public function getOrCreateReport(object $estudo, int $userId): object {
        $report = $this->repo->findReportByEstudoId($estudo->id);
        if ($report) return $report;

        $report = $this->repo->createReport(
            $estudo->id,
            $estudo->tenant_id ?? TenantContext::id(),
            $estudo->study_instance_uid,
            $userId,
            $this->conteudoVazio($estudo->modalities ?? null)
        );

        AuditLogger::log('report.criar', 'reports', $report->id, ['bi_pacs_estudos_id' => $estudo->id]);

        return $report;
    }

    public function conteudoVazio(?string $modalidade = null): array {
        return [
            'versao_schema' => 1,
            'secoes' => [
                'exame' => '',
                'tecnica' => '',
                'achados' => '',
                'conclusao' => '',
                'recomendacao' => '',
            ],
            'meta' => [
                'modalidade' => $modalidade,
                'template_id' => null,
                'ultima_edicao_por' => null,
                'ultima_edicao_em' => null,
            ],
        ];
    }

    /**
     * POST /reports/save — autosave (modo=auto), salvar rascunho ou salvar explícito.
     */
    public function salvar(int $reportId, array $secoes, string $modo, ?int $templateId = null): array {
        $report = $this->repo->findReportById($reportId);
        if (!$report) return ['ok' => false, 'error' => 'report_nao_encontrado'];

        if (in_array($report->status, ['assinado', 'liberado'], true)) {
            return ['ok' => false, 'error' => 'report_assinado_somente_leitura'];
        }

        $userId = Auth::userId();
        $conteudoAtual = json_decode($report->conteudo, true) ?: $this->conteudoVazio();
        $conteudoAtual['secoes'] = array_merge($conteudoAtual['secoes'] ?? [], $secoes);
        $conteudoAtual['meta']['ultima_edicao_por'] = $userId;
        $conteudoAtual['meta']['ultima_edicao_em'] = date('Y-m-d H:i:s');
        if ($templateId) $conteudoAtual['meta']['template_id'] = $templateId;

        $novoStatus = $modo === 'auto' ? null : ($modo === 'rascunho' || $modo === 'salvar' ? 'rascunho' : null);

        $this->repo->atualizarConteudo($reportId, $conteudoAtual, $novoStatus, $templateId);
        $this->repo->marcarHeartbeat((int) $report->bi_pacs_estudos_id);

        if ($novoStatus === 'rascunho') {
            $this->repo->atualizarSituacaoEstudo((int) $report->bi_pacs_estudos_id, 'rascunho');
        }

        $versaoNumero = $this->repo->proximaVersao($reportId) - 1;
        $acao = $modo === 'auto' ? 'salvo' : 'rascunho';
        $this->repo->createVersion($reportId, $conteudoAtual, $acao, $userId, $versaoNumero);

        // Autosave silencioso não audita (evita inundar bi_audit_logs a cada 30s).
        if ($modo !== 'auto') {
            AuditLogger::log('report.salvar', 'reports', $reportId, ['modo' => $modo]);
        }

        return [
            'ok' => true,
            'versao_atual' => $versaoNumero,
            'situacao' => $novoStatus ?? $report->status,
            'atualizado_em' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * POST /reports/sign — exige reautenticação por senha.
     */
    public function assinar(int $reportId, string $senha, ?string $crm): array {
        if (!Auth::verifyPassword($senha)) {
            return ['ok' => false, 'error' => 'senha_invalida'];
        }

        $report = $this->repo->findReportById($reportId);
        if (!$report) return ['ok' => false, 'error' => 'report_nao_encontrado'];

        $estudo = $this->repo->findEstudoById((int) $report->bi_pacs_estudos_id);
        $user = Auth::user();
        $userId = Auth::userId();
        $assinadoEm = date('Y-m-d H:i:s');

        $payload = json_encode([
            'report_id' => $reportId,
            'study_instance_uid' => $report->study_instance_uid,
            'tenant_id' => $report->tenant_id,
            'medico_id' => $userId,
            'crm' => $crm,
            'conteudo' => json_decode($report->conteudo, true),
            'assinado_em' => $assinadoEm,
        ], JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $payload);

        $this->repo->createSignature($reportId, $userId, $user->name ?? '', $crm, $hash, $_SERVER['REMOTE_ADDR'] ?? null);

        // Nesta entrega, assinar já progride para liberado (sem etapa manual de aprovação).
        $this->repo->marcarAssinado($reportId, 'assinado');
        $this->repo->marcarAssinado($reportId, 'liberado');
        $this->repo->atualizarSituacaoEstudo((int) $report->bi_pacs_estudos_id, 'liberado');

        $versaoNumero = $this->repo->proximaVersao($reportId) - 1;
        $this->repo->createVersion($reportId, json_decode($report->conteudo, true), 'assinado', $userId, $versaoNumero);

        AuditLogger::log('report.assinar', 'reports', $reportId, ['crm' => $crm, 'hash' => $hash]);

        return [
            'ok' => true,
            'situacao' => 'liberado',
            'assinado_em' => $assinadoEm,
            'hash' => $hash,
            'pdf_url' => '/reports/' . rawurlencode((string) $estudo->study_instance_uid) . '/pdf',
        ];
    }

    public function listTemplates(string $modalidade): array {
        return $this->repo->listTemplates($modalidade);
    }

    public function listAutotext(?string $modalidade): array {
        return $this->repo->listAutotext($modalidade);
    }

    public function listVersions(int $reportId): array {
        return $this->repo->listVersions($reportId);
    }

    public function restoreVersion(int $reportId, int $versionId): array {
        $version = $this->repo->findVersion($versionId);
        if (!$version || (int) $version->report_id !== $reportId) {
            return ['ok' => false, 'error' => 'versao_nao_encontrada'];
        }

        $report = $this->repo->findReportById($reportId);
        $conteudo = json_decode($version->conteudo, true);
        $userId = Auth::userId();

        $this->repo->atualizarConteudo($reportId, $conteudo, 'rascunho');
        $versaoNumero = $this->repo->proximaVersao($reportId) - 1;
        $this->repo->createVersion($reportId, $conteudo, 'restaurado', $userId, $versaoNumero);

        AuditLogger::log('report.versao_restaurada', 'reports', $reportId, ['version_id' => $versionId]);

        return ['ok' => true, 'versao_atual' => $versaoNumero, 'secoes' => $conteudo['secoes'] ?? []];
    }

    public function aiGenerate(): array {
        AuditLogger::log('report.ai_generate_solicitado', 'reports');
        return [
            'status' => 'not_implemented',
            'message' => 'Geração por IA será disponibilizada em versão futura.',
        ];
    }

    public function registrarPdfGerado(int $reportId): void {
        AuditLogger::log('report.pdf_gerado', 'reports', $reportId);
    }

    private function lockExpirado(?string $heartbeat): bool {
        if (!$heartbeat) return true;
        $diffMinutos = (time() - strtotime($heartbeat)) / 60;
        return $diffMinutos > self::LOCK_TTL_MINUTES;
    }
}
