<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Logger;
use App\Services\ReportService;
use App\Services\ReportAccessService;

use App\Repositories\ReportRepository;
use App\Repositories\EstudosRepository;
use App\Services\ReportChatService;

class ReportsController extends Controller
{
    private ReportService $reportService;
    private ReportRepository $reportRepo;
    private EstudosRepository $estudosRepo;

    public function __construct()
    {
        $this->reportService = new ReportService();
        $this->reportRepo    = new ReportRepository();
        $this->estudosRepo   = new EstudosRepository();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET /reports/{study_uid}
    // Abre o editor de laudo para um estudo
    // ══════════════════════════════════════════════════════════════════════════
    public function show(string $studyUid): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }

        try {
            $data = $this->reportService->carregarParaEdicao($studyUid);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::show error', ['msg' => $e->getMessage(), 'uid' => $studyUid]);
            http_response_code(500);
            $this->view('reports/error', ['mensagem' => 'Erro ao abrir o laudo. Tente novamente.'], 'pacs');
            return;
        }

        if (!$data['ok']) {
            Logger::warning('ReportsController::show estudo não encontrado', ['uid' => $studyUid, 'error' => $data['error'] ?? null]);
            http_response_code(404);
            $this->view('reports/error', [
                'mensagem' => 'Estudo não encontrado ou você não tem permissão de acesso.',
            ], 'pacs');
            return;
        }

        $estudo   = $data['estudo'];
        $report   = $data['report'];
        $pedido   = $data['pedido'] ?? null;
        $chat     = $data['chat'] ?? null;
        $peerReview = $data['peerReview'] ?? null;
        $readonly = $data['readonly'];
        $lockInfo = $data['lockInfo'];

        // Exames anteriores do mesmo paciente
        $examesAnteriores = [];
        if (!empty($estudo->patient_id)) {
            try {
                $pdo = \App\Core\Database::getInstance();
                $stmt = $pdo->prepare(
                    "SELECT e.id, e.study_date, e.study_description, e.modalities,
                            COALESCE(e.situacao,'novo') AS situacao,
                            e.study_instance_uid
                     FROM bi_pacs_estudos e
                     WHERE e.patient_id = :pid
                       AND e.study_instance_uid != :uid
                       AND e.tenant_id = :tenant_id
                     ORDER BY e.study_date DESC
                     LIMIT 10"
                );
                $stmt->execute([
                    ':pid' => $estudo->patient_id,
                    ':uid' => $studyUid,
                    ':tenant_id' => (int) ($estudo->tenant_id ?? \App\Core\TenantContext::id()),
                ]);
                $examesAnteriores = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $ex) {
                Logger::error('Erro ao buscar exames anteriores', ['error' => $ex->getMessage()]);
            }
        }

        // Buscar medico_id do usuário logado (para auto-carregar template)
        $medicoIdLogado = 0;
        try {
            $pdo  = \App\Core\Database::getInstance();
            $stmt = $pdo->prepare("SELECT id FROM bi_medicos WHERE usuario_id = :uid AND tenant_id = :tid LIMIT 1");
            $stmt->execute(['uid' => Auth::userId(), 'tid' => \App\Core\TenantContext::id()]);
            $medicoIdLogado = (int) ($stmt->fetchColumn() ?: 0);
        } catch (\Throwable $ex) {}

        $this->view('reports/show', [
            'estudo'            => $estudo,
            'report'            => $report,
            'pedido'            => $pedido,
            'chat'              => $chat,
            'peerReview'        => $peerReview,
            'readonly'          => $readonly,
            'lockInfo'          => $lockInfo,
            'exames_anteriores' => $examesAnteriores,
            'csrfToken'         => $this->csrfToken(),
            'page_title'        => 'Laudo — ' . ($estudo->patient_name_display ?? $estudo->patient_name ?? 'Paciente'),
            'medicoIdLogado'    => $medicoIdLogado,
        ], 'reports');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /reports/save
    // Salva o laudo (autosave ou manual)
    // ══════════════════════════════════════════════════════════════════════════
    public function save(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401);
            return;
        }

        $input = $this->getJsonInput();

        // CSRF: reports-autosave.js manda o token só no header X-CSRF-Token, nunca
        // no corpo — sem o fallback de header aqui, validarCsrf('') falhava sempre
        // (ver diagnostics/pendencias-conhecidas.md).
        $csrfToken = $input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$this->validarCsrf($csrfToken)) {
            $this->json(['ok' => false, 'msg' => 'Token inválido.'], 403);
            return;
        }

        try {
            // O frontend envia modo=auto|salvar|rascunho; is_manual é legado.
            $modo = (string) ($input['modo'] ?? (($input['is_manual'] ?? false) ? 'salvar' : 'auto'));
            if (!in_array($modo, ['auto', 'salvar', 'rascunho'], true)) $modo = 'auto';
            $templateId = isset($input['template_id']) && (int) $input['template_id'] > 0
                ? (int) $input['template_id'] : null;
            // O editor atual envia um único corpo clínico livre. O payload de
            // seções permanece aceito para não quebrar versões antigas da tela.
            $secoes = array_key_exists('corpo_laudo', $input)
                ? ['corpo' => (string) $input['corpo_laudo']]
                : ($input['secoes'] ?? [
                    'exame'        => $input['secao_exame']        ?? '',
                    'tecnica'      => $input['secao_tecnica']      ?? '',
                    'achados'      => $input['secao_achados']      ?? '',
                    'conclusao'    => $input['secao_conclusao']    ?? '',
                    'recomendacao' => $input['secao_recomendacao'] ?? '',
                ]);

            // reports-autosave.js manda report_id, não id.
            $reportId = (int) ($input['report_id'] ?? $input['id'] ?? 0);
            $sectionLengths = [];
            foreach ($secoes as $section => $conteudo) {
                $sectionLengths[$section] = strlen(strip_tags((string) $conteudo));
            }
            if (array_sum($sectionLengths) === 0) {
                Logger::warning('ReportsController::save payload sem conteúdo', [
                    'report_id' => $reportId, 'modo' => $modo, 'section_lengths' => $sectionLengths,
                ]);
            }
            $resultado = $this->reportService->salvar($reportId, $secoes, $modo, $templateId);

            $msg = match ($resultado['error'] ?? null) {
                'report_nao_encontrado'           => 'Laudo não encontrado.',
                'report_assinado_somente_leitura' => 'Este laudo já foi assinado e não pode mais ser editado.',
                'payload_vazio_ignorado'          => 'Não foi possível identificar o conteúdo do editor. O laudo salvo anteriormente NÃO foi apagado — recarregue a página; se o texto sumir do editor, restaure pela aba Histórico.',
                'estudo_assumido_por_outro'       => 'Este estudo foi assumido por outro médico e não pode ser alterado.',
                default                            => null, // sucesso — sem erro
            };

            $this->json([
                'ok' => $resultado['ok'],
                'saved_at' => date('H:i:s'),
                'situacao' => $resultado['situacao'] ?? null,
                'versao_atual' => $resultado['versao_atual'] ?? null,
                'msg' => $msg,
            ], $resultado['ok'] ? 200 : 422);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::save error', ['msg' => $e->getMessage(), 'report_id' => $input['report_id'] ?? null]);
            $this->json(['ok' => false, 'msg' => $e->getMessage()], 422);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /reports/sign
    // Assina o laudo — autenticação 100% por sessão, sem senha/CRM manual
    // (decisão de negócio; ver diagnostics/pendencias-conhecidas.md).
    // ══════════════════════════════════════════════════════════════════════════
    public function sign(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401);
            return;
        }

        $input = $this->getJsonInput();

        // Mesmo fallback de header aplicado em save() — ver comentário lá.
        $csrfToken = $input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$this->validarCsrf($csrfToken)) {
            $this->json(['ok' => false, 'msg' => 'Token inválido.'], 403);
            return;
        }

        // reports-signature.js manda report_id, não id.
        $reportId = (int) ($input['report_id'] ?? $input['id'] ?? 0);
        $modo     = ($input['modo'] ?? 'somente') === 'fechar' ? 'fechar' : 'somente';

        try {
            // A tela de texto livre envia o conteúdo junto da assinatura. Salva
            // primeiro para que nenhum texto recém-colado fique fora do hash.
            if (array_key_exists('corpo_laudo', $input)) {
                $corpoLivre = (string) $input['corpo_laudo'];
                if (trim(strip_tags($corpoLivre)) !== '') {
                    $salvamento = $this->reportService->salvar($reportId, ['corpo' => $corpoLivre], 'rascunho');
                    if (!$salvamento['ok']) {
                        $this->json(['ok' => false, 'msg' => 'Não foi possível salvar o texto do laudo antes da assinatura.'], 422);
                        return;
                    }
                }
            }
            $resultado = $this->reportService->assinar($reportId, $modo);

            if (!$resultado['ok']) {
                $msg = match ($resultado['error'] ?? null) {
                    'report_nao_encontrado'          => 'Laudo não encontrado.',
                    'report_ja_assinado'             => 'Este laudo já foi assinado e não pode ser assinado novamente.',
                    'peer_review_ciclo_nao_aberto'   => 'O laudo está marcado para Peer Review, mas o ciclo aberto não foi localizado.',
                    'laudo_vazio'                    => 'Não é possível assinar um laudo em branco. Salve o conteúdo antes de assinar.',
                    'chat_pendente'                  => 'Existe uma pendência aberta no CHAT. Conclua a conversa antes de assinar ou finalizar o laudo.',
                    'medico_sem_assinatura_ativa'    => 'Cadastre uma assinatura na aba Assinatura do seu cadastro de médico antes de assinar laudos.',
                    'medico_assinatura_inativa'      => 'A assinatura está cadastrada, mas inativa. Acesse o cadastro do médico, clique em Ativar e tente novamente.',
                    'medico_nao_vinculado'           => 'Sua conta não está vinculada a um médico ativo neste tenant. Solicite a vinculação antes de assinar laudos.',
                    'estudo_nao_encontrado'          => 'O estudo vinculado ao laudo não foi encontrado no tenant atual.',
                    'estudo_assumido_por_outro'      => 'Este estudo foi assumido por outro médico e não pode ser assinado por sua conta.',
                    'assinatura_persistencia_falhou' => 'A assinatura não foi concluída porque houve uma falha de persistência. Verifique o log e tente novamente.',
                    default                           => 'Erro ao assinar.',
                };
                $this->json(['ok' => false, 'msg' => $msg], 422);
                return;
            }

            $this->json(['ok' => true, 'msg' => 'Laudo assinado com sucesso.', 'situacao' => $resultado['situacao']]);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::sign error', ['msg' => $e->getMessage(), 'report_id' => $reportId]);
            $this->json(['ok' => false, 'msg' => $e->getMessage()], 422);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET /reports/history?report_id=X
    // Retorna histórico de versões
    // ══════════════════════════════════════════════════════════════════════════
    public function history(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401);
            return;
        }

        $reportId = (int) ($_GET['report_id'] ?? 0);
        if (!$reportId) {
            $this->json(['ok' => false, 'msg' => 'ID inválido.'], 422);
            return;
        }

                try {
            if (!(new ReportAccessService())->findAuthorizedReport($reportId)) {
                $this->json(['ok' => false, 'msg' => 'Laudo não encontrado.'], 404);
                return;
            }
            $versoes = $this->reportRepo->listVersions($reportId);

            $this->json(['ok' => true, 'versions' => $versoes]);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::history error', ['msg' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Erro ao buscar histórico.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /reports/history/restore
    // Restaura uma versão já pertencente ao tenant atual.
    // ══════════════════════════════════════════════════════════════════════════
    public function restoreHistory(): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        $input = $this->getJsonInput();
        $csrfToken = $input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$this->validarCsrf($csrfToken)) { $this->json(['ok' => false, 'msg' => 'Token inválido.'], 403); return; }

        $reportId = (int) ($input['report_id'] ?? 0);
        $versionId = (int) ($input['version_id'] ?? 0);
        if (!$reportId || !$versionId) { $this->json(['ok' => false, 'msg' => 'Parâmetros inválidos.'], 422); return; }

        try {
            $resultado = $this->reportService->restoreVersion($reportId, $versionId);
            $this->json($resultado, $resultado['ok'] ? 200 : 422);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::restoreHistory error', [
                'report_id' => $reportId, 'version_id' => $versionId, 'msg' => $e->getMessage(),
            ]);
            $this->json(['ok' => false, 'msg' => 'Não foi possível restaurar a versão.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET /reports/pdf?report_id=X
    // Gera/exibe o PDF do laudo
    // ══════════════════════════════════════════════════════════════════════════
    public function pdf(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }

                $reportId = (int) ($_GET['report_id'] ?? 0);
        $download = ($_GET['download'] ?? '0') === '1';
        $tenantId = Auth::tenantId();

        if (!(new ReportAccessService())->findAuthorizedReport($reportId)) {
            http_response_code(404);
            echo 'Laudo não encontrado.';
            return;
        }

        try {

            $pdo = \App\Core\Database::getInstance();
            $stmt = $pdo->prepare(
                "SELECT r.*, e.patient_name_display, e.patient_name, e.patient_id,
                        e.patient_birth_date, e.patient_sex, e.patient_age,
                        e.study_date, e.study_time, e.study_description,
                        e.accession_number, e.modalities, e.institution_name,
                        e.referring_physician_name, e.num_instances, e.num_series,
                        COALESCE(m.nome, u.name) AS medico_nome,
                        m.crm AS medico_crm,
                        t.nome as tenant_nome,
                        COALESCE(bnin.report_layout_template_id, un.report_layout_template_id) AS report_layout_template_id,
                        COALESCE(NULLIF(bnin.nome_fantasia, ''), un.nome_fantasia)   AS unidade_nome_fantasia,
                        COALESCE(NULLIF(bnin.razao_social, ''), un.razao_social)     AS unidade_razao_social,
                        COALESCE(NULLIF(bnin.cnpj, ''), un.cnpj)                     AS unidade_cnpj,
                        COALESCE(NULLIF(bnin.logo_path, ''), un.logo_path)           AS unidade_logo_path,
                        COALESCE(NULLIF(bnin.telefone, ''), un.telefone)             AS unidade_telefone,
                        COALESCE(NULLIF(bnin.email, ''), un.email)                   AS unidade_email,
                        COALESCE(NULLIF(bnin.logradouro, ''), un.logradouro)         AS unidade_logradouro,
                        COALESCE(NULLIF(bnin.numero, ''), un.numero)                 AS unidade_numero,
                        COALESCE(NULLIF(bnin.complemento, ''), un.complemento)       AS unidade_complemento,
                        COALESCE(NULLIF(bnin.bairro, ''), un.bairro)                 AS unidade_bairro,
                        COALESCE(NULLIF(bnin.cidade, ''), un.cidade)                 AS unidade_cidade,
                        COALESCE(NULLIF(bnin.estado, ''), un.estado)                 AS unidade_estado
                 FROM reports r
                                  JOIN bi_pacs_estudos e ON e.id = r.estudo_id

                 LEFT JOIN bi_users u ON u.id = r.usuario_id
                 LEFT JOIN bi_medicos m ON m.usuario_id = r.usuario_id AND m.tenant_id = r.tenant_id
                 LEFT JOIN bi_tenants t ON t.id = r.tenant_id
                 -- Unidade: duas tabelas coexistem (ver modules/unidades.md) — a tela
                 -- realmente usada em produção (/unidades/{id}/edit) grava direto em
                 -- bi_negocio_institution_names; bi_unidades é um 2º sistema, mais novo,
                 -- ainda sem dado real confirmado. Prioriza bnin, cai pra un se faltar.
                 LEFT JOIN bi_negocio_institution_names bnin
                        ON bnin.tenant_id = r.tenant_id
                       AND bnin.institution_name COLLATE utf8mb4_general_ci = e.institution_name COLLATE utf8mb4_general_ci
                 LEFT JOIN bi_unidades un ON un.id = bnin.unidade_id AND un.tenant_id = r.tenant_id
                                  WHERE r.id = :id
                 LIMIT 1"
            );
            $stmt->execute([':id' => $reportId]);

            $data = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$data) {
                http_response_code(404);
                echo 'Laudo não encontrado.';
                return;
            }

            // Log de visualização de PDF
            $userId = Auth::userId();
            $user = Auth::user();
            $this->reportRepo->logAction(
                $reportId, (int)$data['estudo_id'], (int)$data['tenant_id'],
                $userId, $user->name ?? $user->nome ?? '', 'pdf',
                $download ? 'Download PDF' : 'Visualização PDF'
            );

            // Template visual (camada de apresentação — ver App\Services\ReportLayoutService).
            // Unidade resolvida via institution_name; sem unidade vinculada ou sem
            // template escolhido, cai no padrão (classico_centralizado).
            $templateCodigo = (new \App\Services\ReportLayoutService())
                ->resolverCodigo(isset($data['report_layout_template_id']) ? (int) $data['report_layout_template_id'] : null);

            // Renderizar a view de PDF
            $this->view('reports/pdf', [
                'report'         => $data,
                'download'       => $download,
                'templateCodigo' => $templateCodigo,
                'qr_data'  => base64_encode(json_encode([
                    'id'   => $reportId,
                    'hash' => $data['assinatura_hash'] ?? '',
                    'data' => $data['assinado_em'] ?? ''
                ]))
            ], 'pacs');

        } catch (\Throwable $e) {
            Logger::error('ReportsController::pdf error', ['msg' => $e->getMessage()]);
            http_response_code(500);
            echo 'Erro ao gerar PDF.';
        }
    }

    /** Compatibilidade para /reports/{study_uid}/pdf. */
    public function pdfByStudyUid(string $studyUid): void
    {
        if (!Auth::check()) { $this->redirect('/login'); return; }
        try {
            $pdo = \App\Core\Database::getInstance();
            $stmt = $pdo->prepare(
                                "SELECT id FROM reports WHERE study_instance_uid = :uid LIMIT 1"
            );
            $stmt->execute([':uid' => $studyUid]);

            $reportId = (int) ($stmt->fetchColumn() ?: 0);
            if (!$reportId) { http_response_code(404); echo 'Laudo não encontrado.'; return; }
            $_GET['report_id'] = $reportId;
            $this->pdf();
        } catch (\Throwable $e) {
            Logger::error('ReportsController::pdfByStudyUid error', ['uid' => $studyUid, 'msg' => $e->getMessage()]);
            http_response_code(500);
            echo 'Erro ao gerar PDF.';
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET /reports/assinatura-imagem?report_id=X
    // Proxy autenticado da assinatura visual CONGELADA deste laudo (ver
    // ReportService::congelarAssinaturaVisual) — arquivo fica fora de public/,
        // nunca exposto direto. A rota usa ReportAccessService, a mesma defesa de
    // tenant, InstitutionName e posse médica aplicada ao PDF e ao editor.

    // ══════════════════════════════════════════════════════════════════════════
    public function assinaturaImagem(): void
    {
        if (!Auth::check()) { http_response_code(401); return; }
                $reportId = (int) ($_GET['report_id'] ?? 0);
        $tenantId = Auth::tenantId();
        if (!$reportId || !$tenantId || !(new ReportAccessService())->findAuthorizedReport($reportId)) {
            http_response_code(404);
            return;
        }

        try {

            $pdo  = \App\Core\Database::getInstance();
            $stmt = $pdo->prepare(
                                "SELECT assinatura_tipo, assinatura_caminho_arquivo FROM reports WHERE id = :id LIMIT 1"
            );
            $stmt->execute(['id' => $reportId]);

            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row || empty($row['assinatura_caminho_arquivo'])) { http_response_code(404); return; }

            $caminho = BASE_PATH . '/storage/uploads/assinaturas_laudos/' . $row['assinatura_caminho_arquivo'];
            if (!is_file($caminho)) { http_response_code(404); return; }

            $mime = $row['assinatura_tipo'] === 'imagem' ? 'image/jpeg' : 'image/png';
            header("Content-Type: {$mime}");
            header('Cache-Control: private, no-store');
            readfile($caminho);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::assinaturaImagem error', ['msg' => $e->getMessage()]);
            http_response_code(500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET /reports/templates?modalidade=CT
    // Lista templates do tenant no formato único usado pelo editor Quill.
    // ══════════════════════════════════════════════════════════════════════════
    public function templates(): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        $tenantId = Auth::tenantId();
        $modalidade = trim((string) ($_GET['modalidade'] ?? ''));
        $where = "WHERE ativo = 1 AND (tenant_id IS NULL OR tenant_id = :tenant_id)";
        $params = ['tenant_id' => $tenantId];
        if ($modalidade !== '') { $where .= " AND modalidade = :modalidade"; $params['modalidade'] = $modalidade; }

        try {
            $pdo = \App\Core\Database::getInstance();
            $rows = null;
            $queries = [
                "SELECT id, nome, modalidade, secao_exame, secao_tecnica, secao_achados, secao_conclusao, secao_recomendacao
                 FROM report_templates {$where} ORDER BY nome ASC",
                "SELECT id, nome, modalidade, conteudo
                 FROM report_templates {$where} ORDER BY nome ASC",
                "SELECT id, titulo AS nome, modalidade, conteudo
                 FROM report_templates {$where} ORDER BY nome ASC",
            ];
            foreach ($queries as $sql) {
                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    break;
                } catch (\PDOException $queryError) {
                    Logger::warning('ReportsController::templates tentando schema alternativo', [
                        'error' => $queryError->getMessage(),
                    ]);
                }
            }
            if ($rows === null) throw new \RuntimeException('Nenhum schema de templates compatível.');

            $templates = array_map(fn(array $row): array => $this->normalizarTemplate($row), $rows);
            $this->json(['ok' => true, 'templates' => $templates]);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::templates error', ['msg' => $e->getMessage(), 'tenant_id' => $tenantId]);
            $this->json(['ok' => false, 'templates' => [], 'msg' => 'Erro ao listar templates.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET /reports/template?id=X
    // Retorna o conteúdo de um template (AJAX)
    // ══════════════════════════════════════════════════════════════════════════
    public function template(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401);
            return;
        }

        $templateId = (int) ($_GET['id'] ?? 0);
        if (!$templateId) {
            $this->json(['ok' => false, 'msg' => 'ID inválido.'], 422);
            return;
        }

        try {
            $pdo = \App\Core\Database::getInstance();
            $stmt = $pdo->prepare(
                "SELECT * FROM report_templates
                 WHERE id = :id AND ativo = 1
                   AND (tenant_id IS NULL OR tenant_id = :tenant_id)
                 LIMIT 1"
            );
            $stmt->execute([':id' => $templateId, ':tenant_id' => Auth::tenantId()]);
            $tpl = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($tpl) $tpl = $this->normalizarTemplate($tpl);

            if (!$tpl) {
                $this->json(['ok' => false, 'msg' => 'Template não encontrado.'], 404);
                return;
            }

            // Incrementar contador quando a coluna existir; schemas mínimos não a possuem.
            try {
                $pdo->prepare("UPDATE report_templates SET uso_count = uso_count + 1 WHERE id = :id")
                    ->execute([':id' => $templateId]);
            } catch (\PDOException $counterError) {
                Logger::warning('ReportsController::template sem uso_count', ['error' => $counterError->getMessage()]);
            }

            $this->json(['ok' => true, 'template' => $tpl]);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::template error', ['msg' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Erro ao buscar template.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /reports/assumir
    // Botão "Assumir" na worklist — registra médico e abre editor
    // ══════════════════════════════════════════════════════════════════════════
    public function assumir(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401);
            return;
        }

        $input = $this->getJsonInput();
        $csrfToken = $input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$this->validarCsrf($csrfToken)) { $this->json(['ok' => false, 'msg' => 'Token inválido.'], 403); return; }
        $estudoId = (int) ($input['estudo_id'] ?? 0);
        $userId   = Auth::userId();
        $user     = Auth::user();
        $tenantId = Auth::tenantId();
        $isAdmin  = Auth::isPlatformAdmin();

        if (!$estudoId) {
            $this->json(['ok' => false, 'msg' => 'ID do estudo inválido.'], 422);
            return;
        }

        // Buscar o estudo para obter o study_uid
        $estudo = $this->estudosRepo->getEstudoById($estudoId, $tenantId, $isAdmin);
        if (!$estudo) {
            $this->json(['ok' => false, 'msg' => 'Estudo não encontrado.'], 404);
            return;
        }

        // Assumir o estudo; não abrir o report se o lock não foi persistido.
        if (!$this->estudosRepo->assumirEstudo($estudoId, $userId)) {
            $this->json(['ok' => false, 'msg' => 'Não foi possível assumir o estudo. Tente novamente.'], 409);
            return;
        }

        // Log de auditoria
        $this->reportRepo->logAction(
            0, $estudoId, (int)($estudo['tenant_id'] ?? $tenantId),
            $userId, $user->nome ?? '',
            'assumir',
            'Estudo assumido pelo médico'
        );

        $studyUid = $estudo['study_instance_uid'] ?? '';

        $this->json([
            'ok'          => true,
            'msg'         => 'Estudo assumido com sucesso.',
            'study_uid'   => $studyUid,
            'url'         => '/reports/' . urlencode($studyUid),
            'assumido_em' => date('c'),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /reports/ai-generate
    // Endpoint estável; a geração por IA ainda é um recurso futuro.
    // ══════════════════════════════════════════════════════════════════════════
    public function aiGenerate(): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        $input = $this->getJsonInput();
        $csrfToken = $input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$this->validarCsrf($csrfToken)) { $this->json(['ok' => false, 'msg' => 'Token inválido.'], 403); return; }
        $resultado = $this->reportService->aiGenerate();
        $this->json(['ok' => false, 'status' => $resultado['status'], 'message' => $resultado['message']], 501);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET /api/reports/autotext?q=torax
    // Retorna autotextos para o autocomplete
    // ══════════════════════════════════════════════════════════════════════════
    public function autotextSearch(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'items' => []], 401);
            return;
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        $modalidade = trim((string) ($_GET['modalidade'] ?? ''));
        $tenantId = Auth::tenantId();
        $userId = Auth::userId();
        $like = '%' . $q . '%';
        $items = [];

        try {
            $pdo = \App\Core\Database::getInstance();
            // Descobre o schema uma única vez. Assim, bancos com a versão
            // HostGator (`nome/conteudo`) não geram warnings por tentativas
            // contra colunas de outra versão (`texto_sugerido`/`gatilho`).
            $columns = [];
            foreach ($pdo->query('SHOW COLUMNS FROM report_autotext')->fetchAll(\PDO::FETCH_ASSOC) as $column) {
                $field = strtolower((string) ($column['Field'] ?? $column['field'] ?? ''));
                if ($field !== '') $columns[$field] = true;
            }
            $has = static fn (string $name): bool => isset($columns[$name]);

            if (!$has('id')) throw new \RuntimeException('Tabela report_autotext sem coluna id.');

            if ($has('gatilho') && $has('conteudo')) {
                $triggerColumn = 'gatilho';
                $titleColumn = $has('titulo') ? 'titulo' : 'gatilho';
                $contentColumn = 'conteudo';
            } elseif ($has('gatilho') && $has('texto_sugerido')) {
                $triggerColumn = 'gatilho';
                $titleColumn = $has('titulo') ? 'titulo' : 'gatilho';
                $contentColumn = 'texto_sugerido';
            } elseif ($has('nome') && $has('conteudo')) {
                // Schema pendente do HostGator: nome/modalidade/conteudo.
                $triggerColumn = 'nome';
                $titleColumn = 'nome';
                $contentColumn = 'conteudo';
            } elseif ($has('chave') && $has('texto')) {
                $triggerColumn = 'chave';
                $titleColumn = 'chave';
                $contentColumn = 'texto';
            } else {
                throw new \RuntimeException('Schema report_autotext sem colunas de conteúdo reconhecidas.');
            }

            $where = [];
            $params = [];
            if ($has('ativo')) $where[] = 'ativo = 1';
            if ($has('tenant_id')) {
                $where[] = '(tenant_id IS NULL OR tenant_id = :tenant_id)';
                $params[':tenant_id'] = $tenantId;
            }
            if ($has('usuario_id')) {
                $where[] = '(usuario_id IS NULL OR usuario_id = :usuario_id)';
                $params[':usuario_id'] = $userId;
            }
            if ($has('modalidade') && $modalidade !== '') {
                $where[] = '(modalidade IS NULL OR modalidade = :modalidade)';
                $params[':modalidade'] = $modalidade;
            }
            if ($q !== '') {
                $where[] = "({$triggerColumn} LIKE :query_trigger OR {$titleColumn} LIKE :query_title)";
                $params[':query_trigger'] = $like;
                $params[':query_title'] = $like;
            }

            $sql = "SELECT id, {$triggerColumn} AS gatilho, {$titleColumn} AS titulo, {$contentColumn} AS conteudo"
                 . " FROM report_autotext"
                 . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                 . ' ORDER BY id DESC LIMIT 50';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::autotextSearch error', [
                'msg' => $e->getMessage(), 'tenant_id' => $tenantId,
            ]);
            $items = [];
        }

        $items = array_map(static function (array $item): array {
            $texto = (string) ($item['texto_sugerido'] ?? $item['conteudo'] ?? $item['texto'] ?? '');
            return [
                'id' => (int) ($item['id'] ?? 0),
                'gatilho' => (string) ($item['gatilho'] ?? ''),
                'titulo' => (string) ($item['titulo'] ?? $item['gatilho'] ?? ''),
                'texto_sugerido' => $texto,
                'conteudo' => $texto,
            ];
        }, $items);
        $this->json(['ok' => true, 'items' => $items]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET /api/reports/by-estudo?estudo_id=X
    // Retorna o report_id de um estudo (usado pelo botão PDF na worklist)
    // ══════════════════════════════════════════════════════════════════════════
    public function byEstudo(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false], 401);
            return;
        }

        $estudoId = (int) ($_GET['estudo_id'] ?? 0);
        if (!$estudoId) {
            $this->json(['report_id' => null]);
            return;
        }

                try {
            $report = (new ReportAccessService())->findAuthorizedReportByEstudoId($estudoId);
            $this->json(['report_id' => $report ? (int) $report->id : null]);

        } catch (\Throwable $e) {
            Logger::error('ReportsController::byEstudo error', ['msg' => $e->getMessage()]);
            $this->json(['report_id' => null]);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /api/reports/status
    // Atualiza situacao do laudo: em_laudo (ao abrir) ou rascunho (ao fechar sem assinar)
    // ══════════════════════════════════════════════════════════════════════════
    public function atualizarStatus(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401);
            return;
        }
        $input    = $this->getJsonInput();
        $csrfToken = $input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$this->validarCsrf($csrfToken)) { $this->json(['ok' => false, 'msg' => 'Token inválido.'], 403); return; }
        $reportId = (int) ($input['report_id'] ?? 0);
        $situacao = trim((string) ($input['situacao'] ?? ''));
        $allowed  = ['em_laudo', 'rascunho'];
        if (!$reportId || !in_array($situacao, $allowed, true)) {
            $this->json(['ok' => false, 'msg' => 'Parâmetros inválidos.'], 422);
            return;
        }
        try {
                        $tenantId = (int) Auth::tenantId();
            $report = (new ReportAccessService())->findAuthorizedReport($reportId);
            if (!$report) {
                $this->json(['ok' => false, 'msg' => 'Laudo não encontrado.'], 404);
                return;
            }

            if ((new ReportChatService())->hasPending($reportId, $tenantId)) {
                Logger::warning('ReportsController::atualizarStatus bloqueado por CHAT pendente', [
                    'report_id' => $reportId, 'tenant_id' => $tenantId, 'situacao_solicitada' => $situacao,
                ]);
                $this->json(['ok' => false, 'msg' => 'Conclua a pendência do CHAT antes de alterar a situação do laudo.'], 422);
                return;
            }
                        $pdo = \App\Core\Database::getInstance();
            $pdo->prepare("UPDATE reports SET situacao = :sit WHERE id = :id")
                ->execute(['sit' => $situacao, 'id' => $reportId]);
            // O report já foi autorizado; espelha pelo estudo canônico sem
            // depender de uma coluna tenant_id em bi_pacs_estudos.
            $pdo->prepare("UPDATE bi_pacs_estudos SET situacao = :sit WHERE id = :estudo_id")
                ->execute(['sit' => $situacao, 'estudo_id' => (int) $report->estudo_id]);

            Logger::info('ReportsController::atualizarStatus', [
                'report_id' => $reportId, 'situacao' => $situacao, 'usuario' => Auth::userId(),
            ]);
            $this->json(['ok' => true, 'situacao' => $situacao]);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::atualizarStatus error', ['msg' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Erro interno.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /api/reports/liberar
    // Libera o laudo: muda para liberado + atualiza estudo + fecha tela
    // ══════════════════════════════════════════════════════════════════════════
    public function liberar(): void
    {
        if (!Auth::check()) { $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401); return; }
        $input = $this->getJsonInput();
        $csrfToken = $input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$this->validarCsrf($csrfToken)) { $this->json(['ok' => false, 'msg' => 'Token inválido.'], 403); return; }

        $reportId = (int) ($input['report_id'] ?? 0);
        if (!$reportId) { $this->json(['ok' => false, 'msg' => 'report_id obrigatório.'], 422); return; }

        try {
                        $report = (new ReportAccessService())->findAuthorizedReport($reportId);
            if (!$report) { $this->json(['ok' => false, 'msg' => 'Laudo não encontrado.'], 404); return; }

            if ((new ReportChatService())->hasPending($reportId, (int) Auth::tenantId())) {
                Logger::warning('ReportsController::liberar bloqueado por CHAT pendente', [
                    'report_id' => $reportId, 'tenant_id' => Auth::tenantId(), 'usuario_id' => Auth::userId(),
                ]);
                $this->json(['ok' => false, 'msg' => 'Existe uma pendência aberta no CHAT. Conclua a conversa antes de liberar o laudo.'], 422);
                return;
            }
            $situacao = $report->situacao ?? $report->status ?? 'rascunho';

            // Se ainda não foi assinado, usa a mesma validação de conteúdo,
            // assinatura visual, hash e atualização de estudo do fluxo principal.
            if ($situacao !== 'assinado') {
                $secoes = array_key_exists('corpo_laudo', $input)
                    ? ['corpo' => (string) $input['corpo_laudo']]
                    : ($input['secoes'] ?? [
                        'exame' => $input['secao_exame'] ?? '',
                        'tecnica' => $input['secao_tecnica'] ?? '',
                        'achados' => $input['secao_achados'] ?? '',
                        'conclusao' => $input['secao_conclusao'] ?? '',
                        'recomendacao' => $input['secao_recomendacao'] ?? '',
                    ]);
                if (array_filter($secoes, static fn($v) => $v !== null && $v !== '')) {
                    $save = $this->reportService->salvar($reportId, $secoes, 'rascunho');
                    if (!$save['ok']) { $this->json(['ok' => false, 'msg' => 'Não foi possível salvar o laudo antes de liberar.'], 422); return; }
                }
                $resultado = $this->reportService->assinar($reportId, 'fechar');
                if (!$resultado['ok']) {
                    $this->json(['ok' => false, 'msg' => $this->mensagemErroReport($resultado['error'] ?? '')], 422);
                    return;
                }
                $this->json(['ok' => true, 'situacao' => 'liberado', 'msg' => 'Laudo liberado com sucesso.', 'pdf_url' => $resultado['pdf_url'] ?? null]);
                return;
            }

            // Laudo já assinado: liberar não cria uma segunda assinatura.
                        $pdo = \App\Core\Database::getInstance();
            $pdo->prepare(
                "UPDATE reports SET situacao = 'liberado', liberado_em = NOW(), liberado_por = :uid
                 WHERE id = :id"
            )->execute(['uid' => Auth::userId(), 'id' => $reportId]);
            try {
                $pdo->prepare(
                    "UPDATE bi_pacs_estudos
                     SET situacao = 'liberado', laudo_assinado_em = NOW()
                     WHERE id = :estudo_id"
                )->execute(['estudo_id' => (int) $report->estudo_id]);
            } catch (\PDOException $studyError) {
                if (stripos($studyError->getMessage(), 'laudo_assinado_em') === false) throw $studyError;
                Logger::warning('ReportsController::liberar sem laudo_assinado_em — migration pendente', ['error' => $studyError->getMessage()]);
                $pdo->prepare("UPDATE bi_pacs_estudos SET situacao = 'liberado' WHERE id = :estudo_id")
                    ->execute(['estudo_id' => (int) $report->estudo_id]);
            }

            Logger::info('ReportsController::liberar', ['report_id' => $reportId, 'usuario' => Auth::userId()]);
            $this->json(['ok' => true, 'situacao' => 'liberado', 'msg' => 'Laudo liberado com sucesso.']);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::liberar error', ['msg' => $e->getMessage(), 'report_id' => $reportId]);
            $this->json(['ok' => false, 'msg' => 'Erro interno ao liberar laudo.'], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers privados
    // ══════════════════════════════════════════════════════════════════════════

    private function normalizarTemplate(array $row): array
    {
        $secoesJson = [];
        $conteudo = $row['conteudo'] ?? null;
        if (is_string($conteudo) && trim($conteudo) !== '') {
            $decoded = json_decode($conteudo, true);
            if (is_array($decoded)) {
                $secoesJson = is_array($decoded['secoes'] ?? null) ? $decoded['secoes'] : $decoded;
            } else {
                $secoesJson['exame'] = $conteudo;
            }
        }

        $secoes = [];
        foreach (['exame', 'tecnica', 'achados', 'conclusao', 'recomendacao'] as $chave) {
            $campo = 'secao_' . $chave;
            $valor = array_key_exists($campo, $row) && $row[$campo] !== null
                ? (string) $row[$campo]
                : (string) ($secoesJson[$chave] ?? '');
            $secoes[$chave] = $valor;
        }

        $titulo = (string) ($row['nome'] ?? $row['titulo'] ?? ('Template #' . ($row['id'] ?? '')));
        return [
            'id' => (int) ($row['id'] ?? 0),
            'titulo' => $titulo,
            'nome' => $titulo,
            'modalidade' => (string) ($row['modalidade'] ?? ''),
            'conteudo' => json_encode(['secoes' => $secoes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'secoes' => $secoes,
        ];
    }

    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $_POST;
    }

    private function validarCsrf(string $token): bool
    {
        return !empty($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
