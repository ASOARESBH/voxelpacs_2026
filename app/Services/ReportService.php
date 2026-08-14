<?php

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Repositories\MedicoRepository;
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
            'reports_url' => $this->urlPublica($report),
        ];
    }

    /**
     * Constrói a única URL pública permitida para o Laudário.
     */
    public function urlPublica(object $report): string {
        $token = strtolower(trim((string) ($report->public_token ?? '')));
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            throw new \RuntimeException('Laudo sem token público; aplique a migration de URL segura.');
        }
        return '/reports/r/' . $token;
    }

    /**
     * Carrega o editor a partir de um report previamente resolvido e autorizado
     * por token público. O Study UID nunca é recebido da URL pública.
     */
    public function carregarParaEdicaoPorReport(object $report): array {
        return $this->carregarParaEdicao((string) ($report->study_instance_uid ?? ''));
    }

    /**
     * Carrega o estudo + laudo para uso interno, decidindo

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

        // A abertura do editor é um acesso clínico sensível. A autorização
        // central confere tenant, InstitutionName permitido e posse exclusiva
        // para médico restrito antes de criar ou carregar qualquer report.
        if (!(new ReportAccessService())->isStudyAllowed($estudo)) {
            AuditLogger::log('report.acesso_negado', 'bi_pacs_estudos', (int) $estudo->id, [
                'usuario_tentativa_id' => $userId,
                'situacao' => $estudo->situacao ?? null,
            ]);
            return [
                'ok' => false,
                'error' => 'estudo_assumido_por_outro',
            ];
        }

        $report = $this->getOrCreateReport($estudo, $userId);

        // O pedido pertence ao estudo, não ao texto do laudo. Carregamos seus
        // metadados aqui para o médico consultar no report sem duplicar o arquivo.
        $pedido = null;
        try {
            $tenantEstudo = (int) ($estudo->tenant_id ?? 0);
            if ($tenantEstudo > 0) {
                $pedido = (new PedidoMedicoService())->buscarPorEstudo((int) $estudo->id, $tenantEstudo);
            }
        } catch (\Throwable $e) {
            Logger::warning('[ReportService::carregarParaEdicao] Pedido não carregado', [
                'estudo_id' => $estudo->id ?? null,
                'error'     => $e->getMessage(),
            ]);
        }

        // CHAT contextual do estudo: uma falha de carregamento não impede a
        // abertura do laudo, mas fica registrada para diagnóstico.
        $chat = null;
        try {
            $chat = (new ReportChatService())->context(
                (int) $report->id,
                (int) ($estudo->tenant_id ?? TenantContext::id()),
                (int) $userId
            );
        } catch (\Throwable $e) {
            Logger::warning('[ReportService::carregarParaEdicao] CHAT não carregado', [
                'report_id' => $report->id ?? null, 'tenant_id' => $estudo->tenant_id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        // Peer Review: a tela recebe o ciclo aberto e o histórico imutável.
        // Se a migration ainda não estiver aplicada, a abertura do laudo não quebra.
        $peerReview = null;
        try {
            $peerReview = (new ReportPeerReviewService())->contexto((int) $report->id);
        } catch (\Throwable $e) {
            Logger::warning('[ReportService::carregarParaEdicao] Peer Review não carregado', [
                'report_id' => $report->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        // Schema de produção usa 'situacao' (não 'status')
        $reportSituacao = $report->situacao ?? $report->status ?? 'rascunho';
        if (in_array($reportSituacao, ['assinado', 'liberado'], true)) {
            $readonly = true;
        } elseif ($reportSituacao === 'peer_review') {
            // O laudo é editável somente dentro do ciclo aberto.
            $readonly = empty($peerReview['pendente']);
        }

        AuditLogger::log('report.visualizar', 'reports', $report->id);

        return [
            'ok' => true,
            'estudo' => $estudo,
            'report' => $report,
            'pedido' => $pedido,
            'chat' => $chat,
            'peerReview' => $peerReview,
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
        $report = (new ReportAccessService())->findAuthorizedReport($reportId);
        if (!$report) return ['ok' => false, 'error' => 'report_nao_encontrado'];

        $reportSituacao = $report->situacao ?? $report->status ?? 'rascunho';
        if (in_array($reportSituacao, ['assinado', 'liberado'], true)) {
            return ['ok' => false, 'error' => 'report_assinado_somente_leitura'];
        }

        // Guard contra perda de dado: extractSecoes() no navegador pode falhar
        // (ver reports-editor.js e diagnostics/pendencias-conhecidas.md — bug
        // de report_id=18, 2026-08-10, onde 3 autosaves seguidos zeraram um
        // laudo que já tinha conteúdo real salvo). Nunca sobrescrever um
        // report que já tem conteúdo com um payload totalmente vazio — só
        // permite gravar vazio quando o report em si já estava vazio (caso
        // legítimo: laudo novo, nada digitado ainda).
        if (!$this->secoesTemConteudo($secoes)) {
            $secoesAtuais = $this->extrairSecoesDoReport($report);
            if ($this->secoesTemConteudo($secoesAtuais)) {
                Logger::error('[ReportService::salvar] payload vazio recusado — report já tinha conteúdo salvo, nada foi sobrescrito', [
                    'report_id' => $reportId,
                    'modo' => $modo,
                    'section_lengths_atual' => $this->tamanhosSecoes($secoesAtuais),
                ]);
                return ['ok' => false, 'error' => 'payload_vazio_ignorado'];
            }
        }

        $userId = Auth::userId();
        // Schema de produção: colunas separadas (sem JSON conteudo)
        // Mantém compatibilidade: se vier array de secões, usa direto
        $conteudoAtual = ['secoes' => $secoes];

        // Durante Peer Review, salvar não pode apagar o estado do ciclo aberto.
        $novoStatus = ($reportSituacao === 'peer_review')
            ? null
            : ($modo === 'auto' ? null : ($modo === 'rascunho' || $modo === 'salvar' ? 'rascunho' : null));

        // estudo_id é o nome da FK no schema de produção.
        $estudoIdFK = (int) ($report->estudo_id ?? $report->bi_pacs_estudos_id ?? 0);
        $estudo = $estudoIdFK ? $this->repo->findEstudoById($estudoIdFK) : null;
        if (!$estudo || (int) ($estudo->usuario_responsavel_id ?? 0) !== (int) $userId) {
            Logger::warning('[ReportService::salvar] tentativa de salvar laudo sem posse', [
                'report_id' => $reportId,
                'estudo_id' => $estudoIdFK,
                'usuario_id' => $userId,
                'usuario_responsavel_id' => $estudo->usuario_responsavel_id ?? null,
            ]);
            return ['ok' => false, 'error' => 'estudo_assumido_por_outro'];
        }

        $this->repo->atualizarConteudo($reportId, $conteudoAtual, $novoStatus, $templateId);
        if ($estudoIdFK) $this->repo->marcarHeartbeat($estudoIdFK);

        $chatPendente = false;
        try {
            $chatPendente = (new ReportChatService())->hasPending($reportId, (int) Auth::tenantId());
        } catch (\Throwable $e) {
            Logger::warning('[ReportService::salvar] não foi possível consultar CHAT', [
                'report_id' => $reportId, 'error' => $e->getMessage(),
            ]);
        }

        if ($novoStatus === 'rascunho' && $estudoIdFK && !$chatPendente) {
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

        $versaoNumero = $this->repo->proximaVersao($reportId);
        $acao = $modo === 'auto' ? 'salvo' : 'rascunho';
        $this->repo->createVersion($reportId, $conteudoAtual, $acao, $userId, $versaoNumero);

        // Autosave silencioso não audita (evita inundar bi_audit_logs a cada 30s).
        if ($modo !== 'auto') {
            AuditLogger::log('report.salvar', 'reports', $reportId, ['modo' => $modo]);
        }

        return [
            'ok' => true,
            'versao_atual' => $versaoNumero,
            'situacao' => $novoStatus ?? ($report->situacao ?? $report->status ?? 'rascunho'),
            'atualizado_em' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * POST /reports/sign — autenticação 100% por sessão (decisão de negócio: sem
     * reautenticação por senha neste fluxo, ver diagnostics/pendencias-conhecidas.md).
     * $modo: 'somente' → situação vai só até 'assinado'; 'fechar' → avança até 'liberado'.
     */
    public function assinar(int $reportId, string $modo): array {
        $report = (new ReportAccessService())->findAuthorizedReport($reportId);
        if (!$report) return ['ok' => false, 'error' => 'report_nao_encontrado'];

        // 4(b) — trava de re-assinatura, mas permite concluir um ciclo de Peer Review.
        $reportSituacao = $report->situacao ?? $report->status ?? 'rascunho';
        if (in_array($reportSituacao, ['assinado', 'liberado'], true)) {
            return ['ok' => false, 'error' => 'report_ja_assinado'];
        }

        $peerReviewService = null;
        $peerReviewAberto = null;
        if ($reportSituacao === 'peer_review') {
            $peerReviewService = new ReportPeerReviewService();
            $peerReviewAberto = $peerReviewService->contexto($reportId)['aberta'] ?? null;
            if (!$peerReviewAberto) {
                Logger::warning('[ReportService::assinar] estado peer_review sem ciclo aberto', [
                    'report_id' => $reportId, 'tenant_id' => $report->tenant_id ?? null,
                ]);
                return ['ok' => false, 'error' => 'peer_review_ciclo_nao_aberto'];
            }
        }

        // Uma conversa aberta é uma pendência operacional do estudo. O bloqueio
        // existe no Service e não depende do botão estar desabilitado no browser.
        $tenantIdAtual = Auth::tenantId();
        if ($tenantIdAtual && (new ReportChatService())->hasPending($reportId, (int) $tenantIdAtual)) {
            Logger::warning('[ReportService::assinar] assinatura bloqueada por CHAT pendente', [
                'report_id' => $reportId, 'tenant_id' => $tenantIdAtual, 'usuario_id' => Auth::userId(),
            ]);
            return ['ok' => false, 'error' => 'chat_pendente'];
        }

        // O schema operacional guarda as cinco seções em colunas secao_*;
        // versões legadas podem ter JSON em conteudo. A assinatura deve usar o
        // mesmo conteúdo que o editor e o PDF exibem, nunca somente o JSON.
        $secoesAtuais = $this->extrairSecoesDoReport($report);
        if (!$this->secoesTemConteudo($secoesAtuais)) {
            Logger::warning('[ReportService::assinar] laudo vazio após leitura do report', [
                'report_id' => $reportId,
                'tenant_id' => $report->tenant_id ?? null,
                'section_lengths' => $this->tamanhosSecoes($secoesAtuais),
                'has_json_conteudo' => isset($report->conteudo) && is_string($report->conteudo) && trim($report->conteudo) !== '',
            ]);
            return ['ok' => false, 'error' => 'laudo_vazio'];
        }
        $conteudoDecodificado = ['secoes' => $secoesAtuais];

        $userId   = Auth::userId();
        $tenantId = $tenantIdAtual;

        // 4(a) — assinatura visual ativa + CRM do médico logado, resolvidos
        // automaticamente (sem input manual). Decisão confirmada: bloquear
        // (não assinar sem representação visual) se não houver nenhuma cadastrada.
        $medico = null;
        $assinaturaAtiva = null;
        if ($tenantId) {
            $medico = (new MedicoRepository())->findByUsuarioId($userId, $tenantId);
            if ($medico) {
                $assinaturaAtiva = (new MedicoAssinaturaService())->buscarAtiva((int) $medico['id'], $tenantId);
            }
        }
        if (!$medico) {
            Logger::warning('[ReportService::assinar] usuário sem médico ativo vinculado', [
                'report_id' => $reportId, 'usuario_id' => $userId, 'tenant_id' => $tenantId,
            ]);
            return ['ok' => false, 'error' => 'medico_nao_vinculado'];
        }
        if (!$assinaturaAtiva) {
            $blocos = (new MedicoAssinaturaService())->listar((int) $medico['id'], (int) $tenantId);
            $temCadastrada = false;
            foreach ($blocos as $bloco) {
                if (!empty($bloco['existe'])) {
                    $temCadastrada = true;
                    break;
                }
            }
            Logger::warning('[ReportService::assinar] assinatura ativa não encontrada', [
                'report_id' => $reportId,
                'usuario_id' => $userId,
                'tenant_id' => $tenantId,
                'medico_id' => (int) $medico['id'],
                'assinatura_cadastrada' => $temCadastrada,
            ]);
            return ['ok' => false, 'error' => $temCadastrada ? 'medico_assinatura_inativa' : 'medico_sem_assinatura_ativa'];
        }
        $crm = $medico['crm'] ?? null;

        $estudoId = (int) ($report->estudo_id ?? $report->bi_pacs_estudos_id ?? 0);
        $estudo = $estudoId ? $this->repo->findEstudoById($estudoId) : null;
        if (!$estudo) {
            return ['ok' => false, 'error' => 'estudo_nao_encontrado'];
        }
        if ((int) ($estudo->usuario_responsavel_id ?? 0) !== (int) $userId) {
            Logger::warning('[ReportService::assinar] tentativa de assinatura sem posse', [
                'report_id' => $reportId,
                'estudo_id' => $estudoId,
                'usuario_id' => $userId,
                'usuario_responsavel_id' => $estudo->usuario_responsavel_id ?? null,
            ]);
            return ['ok' => false, 'error' => 'estudo_assumido_por_outro'];
        }
        $user = Auth::user();
        $assinadoEm = date('Y-m-d H:i:s');

        $payload = json_encode([
            'report_id' => $reportId,
            'study_instance_uid' => $report->study_instance_uid,
            'tenant_id' => $report->tenant_id,
            'medico_id' => $userId,
            'crm' => $crm,
            'conteudo' => $conteudoDecodificado,
            'assinado_em' => $assinadoEm,
        ], JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $payload);

        // Congela uma cópia do arquivo da assinatura visual pra ESTE laudo —
        // se o médico trocar a assinatura ativa depois, laudos já assinados
        // continuam mostrando a que foi usada de fato no momento.
        $caminhoCongelado = $this->congelarAssinaturaVisual($reportId, $tenantId, $assinaturaAtiva);

        $pdo = Database::getInstance();
        try {
            $pdo->beginTransaction();

            // O registro auxiliar possui schemas históricos; o Repository faz
            // fallback sem impedir a persistência principal do laudo.
            $this->repo->createSignature($reportId, $userId, $user->name ?? '', $crm, $hash, $_SERVER['REMOTE_ADDR'] ?? null);
            $this->repo->salvarAssinaturaVisual($reportId, $hash, $crm, $assinaturaAtiva['tipo'], $caminhoCongelado);

            // "Somente Assinar" para em assinado; "Assinar e Fechar" libera.
            $this->repo->marcarAssinado($reportId, 'assinado');
            if ($modo === 'fechar') {
                $this->repo->marcarAssinado($reportId, 'liberado');
                $this->repo->atualizarSituacaoEstudo($estudoId, 'liberado');
            } else {
                $this->repo->atualizarSituacaoEstudo($estudoId, 'assinado');
            }

            $versaoNumero = $this->repo->proximaVersao($reportId);
            $this->repo->createVersion($reportId, $conteudoDecodificado, 'assinado', $userId, $versaoNumero);

            // A outbox é gravada no mesmo commit clínico. A rotina não abre
            // conexões externas e permanece inativa enquanto a feature flag
            // estiver desligada no ambiente de produção.
            if ($modo === 'fechar') {
                (new ReportDeliveryOutboxService($pdo))->queueReleasedReport(
                    (int) $tenantId,
                    $reportId,
                    $estudoId,
                    $versaoNumero,
                    $report,
                    $estudo,
                    (int) $userId,
                    $assinadoEm,
                    $hash
                );
            }

            if ($peerReviewAberto && $peerReviewService) {
                $peerReviewService->concluirNaTransacao(
                    (int) $peerReviewAberto->id,
                    $userId,
                    $modo === 'fechar' ? 'liberado' : 'assinado',
                    $versaoNumero
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $erro = $e->getMessage();
            Logger::error('[ReportService::assinar] Persistência atômica falhou', [
                'report_id' => $reportId,
                'estudo_id' => $estudoId,
                'tenant_id' => $tenantId,
                'modo' => $modo,
                'versao_report' => $versaoNumero ?? null,
                'error' => $erro,
            ]);
            return [
                'ok' => false,
                'error' => str_contains($erro, 'Dados insuficientes para registrar a devolutiva')
                    ? 'devolutiva_dados_insuficientes'
                    : 'assinatura_persistencia_falhou',
            ];
        }

        AuditLogger::log('report.assinar', 'reports', $reportId, ['crm' => $crm, 'hash' => $hash, 'modo' => $modo]);

        // ── Notifica o VoxelCopilot sobre o laudo liberado (só quando o estudo
        // de fato é finalizado — "Somente Assinar" não dispara este webhook,
        // já que o estudo continua em 'assinado', não 'liberado') ───────────
        if ($modo === 'fechar') {
            try {
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
        }

        $situacaoFinal = $modo === 'fechar' ? 'liberado' : 'assinado';
        return [
            'ok' => true,
            'situacao' => $situacaoFinal,
            'assinado_em' => $assinadoEm,
            'hash' => $hash,
            'pdf_url' => $this->urlPublica($report) . '/pdf',
            'peer_review_concluido' => $peerReviewAberto !== null,
        ];
    }

    /**
     * Copia o arquivo da assinatura visual ativa do médico para uma pasta
     * dedicada por laudo (storage/uploads/assinaturas_laudos/{tenant}/{report}.ext)
     * — congela o que foi usado neste laudo especificamente, desacoplado do
     * arquivo "atual" em bi_medico_assinaturas (que o médico pode substituir
     * depois). Retorna o caminho relativo salvo, ou null se falhar (não bloqueia
     * a assinatura — ver comentário em assinar()).
     */
    private function congelarAssinaturaVisual(int $reportId, int $tenantId, array $assinaturaAtiva): ?string {
        try {
            $assinaturaSvc = new MedicoAssinaturaService();
            $origem = $assinaturaSvc->caminhoAbsoluto($assinaturaAtiva['caminho_arquivo']);
            if (!is_file($origem)) return null;

            $ext = pathinfo($origem, PATHINFO_EXTENSION) ?: 'bin';
            $dir = BASE_PATH . "/storage/uploads/assinaturas_laudos/{$tenantId}";
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return null;

            $destinoRelativo = "{$tenantId}/{$reportId}.{$ext}";
            $destinoAbsoluto = BASE_PATH . "/storage/uploads/assinaturas_laudos/{$destinoRelativo}";
            return copy($origem, $destinoAbsoluto) ? $destinoRelativo : null;
        } catch (\Throwable $e) {
            Logger::error('[ReportService::congelarAssinaturaVisual] ' . $e->getMessage(), ['report_id' => $reportId]);
            return null;
        }
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
        $report = (new ReportAccessService())->findAuthorizedReport($reportId);
        if (!$report) return ['ok' => false, 'error' => 'report_nao_encontrado'];

        $version = $this->repo->findVersion($versionId);
        if (!$version || (int) $version->report_id !== $reportId) {
            return ['ok' => false, 'error' => 'versao_nao_encontrada'];
        }

        $conteudo = [];
        if (isset($version->conteudo) && is_string($version->conteudo) && trim($version->conteudo) !== '') {
            $decoded = json_decode($version->conteudo, true);
            if (is_array($decoded)) $conteudo = $decoded;
        }
        if (!isset($conteudo['secoes']) || !is_array($conteudo['secoes'])) {
            $conteudo = ['secoes' => []];
            foreach (['exame', 'tecnica', 'achados', 'conclusao', 'recomendacao'] as $chave) {
                $campo = 'secao_' . $chave;
                $conteudo['secoes'][$chave] = property_exists($version, $campo) ? (string) ($version->{$campo} ?? '') : '';
            }
        }
        $userId = Auth::userId();

        $this->repo->atualizarConteudo($reportId, $conteudo, 'rascunho');
        $versaoNumero = $this->repo->proximaVersao($reportId);
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

    /**
     * Normaliza o conteúdo para um corpo clínico livre. Quando um laudo antigo
     * ainda está apenas nas colunas secao_*, seus trechos são unidos sem criar
     * rótulos obrigatórios, preservando integralmente o texto histórico.
     */
    private function extrairSecoesDoReport(object $report): array {
        $corpoLivre = property_exists($report, 'corpo_laudo') ? (string) ($report->corpo_laudo ?? '') : '';
        if (trim($corpoLivre) !== '') {
            return ['corpo' => $corpoLivre];
        }

        $json = [];
        if (isset($report->conteudo) && is_string($report->conteudo) && trim($report->conteudo) !== '') {
            $decoded = json_decode($report->conteudo, true);
            if (is_array($decoded)) $json = is_array($decoded['secoes'] ?? null) ? $decoded['secoes'] : $decoded;
        }
        if (!empty($json['corpo'])) {
            return ['corpo' => (string) $json['corpo']];
        }

        $blocos = [];
        foreach (['exame', 'tecnica', 'achados', 'conclusao', 'recomendacao'] as $chave) {
            $campo = 'secao_' . $chave;
            $valorColuna = property_exists($report, $campo) ? ($report->{$campo} ?? null) : null;
            $valor = ($valorColuna !== null && $valorColuna !== '')
                ? (string) $valorColuna
                : (string) ($json[$chave] ?? '');
            if (trim(strip_tags($valor)) !== '') {
                $blocos[] = $valor;
            }
        }
        return ['corpo' => implode('<br><br>', $blocos)];
    }

    private function secoesTemConteudo(array $secoes): bool {
        foreach ($secoes as $textoHtml) {
            $texto = html_entity_decode((string) $textoHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $texto = preg_replace('/\x{00A0}|\x{200B}/u', ' ', strip_tags($texto)) ?? '';
            if (trim($texto) !== '') return true;
        }
        return false;
    }

    /** Retorna somente tamanhos para diagnóstico; nunca registra texto clínico. */
    private function tamanhosSecoes(array $secoes): array {
        $tamanhos = [];
        foreach ($secoes as $chave => $textoHtml) {
            $texto = html_entity_decode((string) $textoHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $tamanhos[$chave] = strlen(strip_tags($texto));
        }
        return $tamanhos;
    }

    private function lockExpirado(?string $heartbeat): bool {
        if (!$heartbeat) return true;
        $diffMinutos = (time() - strtotime($heartbeat)) / 60;
        return $diffMinutos > self::LOCK_TTL_MINUTES;
    }
}
