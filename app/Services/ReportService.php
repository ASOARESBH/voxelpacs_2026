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

        // Schema de produção usa 'situacao' (não 'status')
        $reportSituacao = $report->situacao ?? $report->status ?? 'rascunho';
        if (in_array($reportSituacao, ['assinado', 'liberado'], true)) {
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

        $reportSituacao = $report->situacao ?? $report->status ?? 'rascunho';
        if (in_array($reportSituacao, ['assinado', 'liberado'], true)) {
            return ['ok' => false, 'error' => 'report_assinado_somente_leitura'];
        }

        $userId = Auth::userId();
        // Schema de produção: colunas separadas (sem JSON conteudo)
        // Mantém compatibilidade: se vier array de secões, usa direto
        $conteudoAtual = ['secoes' => $secoes];

        $novoStatus = $modo === 'auto' ? null : ($modo === 'rascunho' || $modo === 'salvar' ? 'rascunho' : null);

        // estudo_id é o nome da FK no schema de produção
        $estudoIdFK = (int) ($report->estudo_id ?? $report->bi_pacs_estudos_id ?? 0);

        $this->repo->atualizarConteudo($reportId, $conteudoAtual, $novoStatus, $templateId);
        if ($estudoIdFK) $this->repo->marcarHeartbeat($estudoIdFK);

        if ($novoStatus === 'rascunho' && $estudoIdFK) {
            $this->repo->atualizarSituacaoEstudo($estudoIdFK, 'rascunho');

            // ── Notifica o VoxelCopilot sobre o rascunho salvo ─────────────
            try {
                $tenantId = Auth::tenantId();
                $svc = new \App\Services\CopilotWebhookService();
                $estudo = $this->repo->findEstudoById($estudoIdFK);
                if ($estudo && $tenantId) {
                    $svc->notificarEstudoAberto(
                        (int) $tenantId,
                        (array) $estudo,
                        ['id' => Auth::userId(), 'nome' => Auth::user()->nome ?? '', 'crm' => '']
                    );
                }
            } catch (\Throwable $e) {
                \App\Core\Logger::error('[ReportService::salvar] Webhook Copilot rascunho falhou: ' . $e->getMessage());
            }
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

        // ── Notifica o VoxelCopilot sobre o laudo assinado/liberado ────────
        try {
            $tenantId = Auth::tenantId();
            $svc = new \App\Services\CopilotWebhookService();
            if ($tenantId) {
                $svc->notificarLaudoLiberado(
                    (int) $tenantId,
                    (array) $estudo,
                    ['id' => $userId, 'nome' => $user->nome ?? $user->name ?? '', 'crm' => $crm ?? ''],
                    ['texto' => null, 'assinado_em' => $assinadoEm, 'hash' => $hash]
                );
            }
        } catch (\Throwable $e) {
            \App\Core\Logger::error('[ReportService::assinar] Webhook Copilot liberado falhou: ' . $e->getMessage());
        }

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
