<?php
namespace App\Controllers;
use App\Core\Controller;
use App\Core\Auth;
use App\Core\Logger;
use App\Core\SqlHelper;

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
    // GET /reports/r/{token}
    // A única rota pública do editor. Não aceita Study UID nem id sequencial.
    // ══════════════════════════════════════════════════════════════════════════
    public function showByToken(string $token): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }

        $reportAutorizado = (new ReportAccessService())->findAuthorizedReportByPublicToken($token);
        if (!$reportAutorizado) {
            http_response_code(404);
            $this->view('reports/error', [
                'mensagem' => 'Laudo não encontrado ou você não tem permissão de acesso.',
            ], 'pacs');
            return;
        }

        try {
            $data = $this->reportService->carregarParaEdicaoPorReport($reportAutorizado);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::showByToken error', [
                'report_id' => (int) $reportAutorizado->id,
                'msg' => $e->getMessage(),
            ]);
            http_response_code(500);
            $this->view('reports/error', ['mensagem' => 'Erro ao abrir o laudo. Tente novamente.'], 'pacs');
            return;
        }

        if (!$data['ok']) {
            Logger::warning('ReportsController::showByToken laudo indisponível', [
                'report_id' => (int) $reportAutorizado->id,
                'error' => $data['error'] ?? null,
            ]);
            http_response_code(404);
            $this->view('reports/error', [
                'mensagem' => 'Laudo não encontrado ou você não tem permissão de acesso.',
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
                                                        e.study_instance_uid, r.public_token
                     FROM bi_pacs_estudos e
                     INNER JOIN reports r ON r.estudo_id = e.id
                     WHERE e.patient_id = :pid
                       AND e.study_instance_uid != :uid
                       AND e.tenant_id = :tenant_id
                     ORDER BY e.study_date DESC
                     LIMIT 10"
                );
                $stmt->execute([
                    ':pid' => $estudo->patient_id,
                                        ':uid' => (string) ($estudo->study_instance_uid ?? ''),
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

        // A tela do Laudário precisa conhecer o mesmo contexto visual do PDF para
        // que a unidade que escolheu Moderno Lateral veja o documento no próprio
        // formulário, sem duplicar ou alterar qualquer dado clínico do report.
        $contextoVisual = $this->carregarContextoVisualLaudo($estudo);
        $reportLayoutCodigo = (new \App\Services\ReportLayoutService())->resolverCodigo(
            isset($contextoVisual['report_layout_template_id'])
                ? (int) $contextoVisual['report_layout_template_id']
                : null
        );
        $mascaraTitulo = '';
        try {
            $templateId = (int) ($report->template_id ?? 0);
            if ($templateId > 0) {
                $mascara = $this->carregarMascaraParaPdf(
                    \App\Core\Database::getInstance(),
                    $templateId,
                    (int) ($estudo->tenant_id ?? \App\Core\TenantContext::id())
                );
                $mascaraTitulo = trim((string) ($mascara['titulo'] ?? ''));
            }
        } catch (\Throwable $ex) {
            Logger::warning('ReportsController::showByToken contexto visual de mascara indisponivel', [
                'report_id' => (int) ($report->id ?? 0),
                'error' => $ex->getMessage(),
            ]);
        }

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
            'reportLayoutCodigo' => $reportLayoutCodigo,
            'reportVisual'       => $contextoVisual,
            'mascaraTitulo'      => $mascaraTitulo,
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
                    'devolutiva_dados_insuficientes' => 'A assinatura não foi concluída porque a devolutiva do laudo não recebeu todos os dados obrigatórios. Tente novamente; se persistir, informe o suporte técnico.',
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
    // GET /reports/r/{token}/pdf
    // Resolve o token opaco antes de reutilizar a geração interna de PDF.
    // ══════════════════════════════════════════════════════════════════════════
    public function pdfByToken(string $token): void
    {
        $portalService = new \App\Services\PatientPortalService();
        $portalScope = $portalService->activeScope(substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45));
        if ($portalScope !== null) {
            $portalReport = $portalService->releasedReportByToken($token, $portalScope);
            if (!$portalReport) { http_response_code(404); echo 'Laudo não encontrado.'; return; }
            $_GET['report_id'] = (int) $portalReport['report_id'];
            $_GET['portal_patient_pdf'] = '1';
            $portalService->auditLaudoAberto((int) $portalReport['report_id'], $portalScope, $token);
            $this->pdf();
            return;
        }

        if (!Auth::check()) { $this->redirect('/login'); return; }
        $report = (new ReportAccessService())->findAuthorizedReportByPublicToken($token);
        if (!$report) { http_response_code(404); echo 'Laudo não encontrado.'; return; }
        $_GET['report_id'] = (int) $report->id;
        $this->pdf();
    }

    /** Geração interna de PDF; não é exposta diretamente por rota pública. */
    public function pdf(): void
    {
        $portalPatientPdf = ($_GET['portal_patient_pdf'] ?? '') === '1';
        if (!$portalPatientPdf && !Auth::check()) {
            $this->redirect('/login');
            return;
        }
        $reportId = (int) ($_GET['report_id'] ?? 0);
        $download = ($_GET['download'] ?? '0') === '1';
        $tenantId = Auth::tenantId();

        if (!$portalPatientPdf && !(new ReportAccessService())->findAuthorizedReport($reportId)) {
            http_response_code(404);
            echo 'Laudo não encontrado.';
            return;
        }

                try {
            $pdo = \App\Core\Database::getInstance();
            $institutionJoinSql = SqlHelper::caseInsensitiveEquals('bnin.institution_name', 'e.institution_name');
            $institutionParameterSql = SqlHelper::caseInsensitiveEquals('bnin.institution_name', ':institution_name');
            $stmt = $pdo->prepare(
                "SELECT r.*, e.patient_name_display, e.patient_name, e.patient_id,

                        e.patient_birth_date, e.patient_sex, e.patient_age,
                        e.study_date, e.study_time, e.study_description,
                        e.scheduled_procedure_step_desc, e.requested_procedure_desc,
                        e.body_part_examined, e.accession_number, e.modalities, e.institution_name,
                        e.referring_physician_name, e.num_instances, e.num_series,
                        COALESCE(m.nome, u.name) AS medico_nome,
                        m.crm AS medico_crm,
                        m.crm_uf AS medico_crm_uf,
                        m.especialidade AS medico_especialidade,
                        t.nome as tenant_nome,
                        t.cnpj AS tenant_cnpj,
                        bnin.id AS institution_unit_id,
                        un.id AS rich_unit_id,
                        bnin.report_layout_template_id AS institution_report_layout_id,
                        un.report_layout_template_id AS rich_report_layout_id,
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
                                              AND {$institutionJoinSql}

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

            // Canais institucionais são opcionais e dependem da migration de Unidade.
            // A ausência das colunas mantém o PDF operacional sem placeholders ativos.
            foreach (['qrcode', 'site', 'instagram', 'facebook'] as $canal) {
                $data['unidade_personalizado_' . $canal . '_habilitado'] = 0;
                $data['unidade_personalizado_' . $canal . '_url'] = null;
            }
            try {
                $stmtCanais = $pdo->prepare(
                    "SELECT
                        COALESCE(bnin.personalizado_qrcode_habilitado, un.personalizado_qrcode_habilitado, 0) AS qrcode_habilitado,
                        COALESCE(NULLIF(bnin.personalizado_qrcode_url, ''), un.personalizado_qrcode_url) AS qrcode_url,
                        COALESCE(bnin.personalizado_site_habilitado, un.personalizado_site_habilitado, 0) AS site_habilitado,
                        COALESCE(NULLIF(bnin.personalizado_site_url, ''), un.personalizado_site_url) AS site_url,
                        COALESCE(bnin.personalizado_instagram_habilitado, un.personalizado_instagram_habilitado, 0) AS instagram_habilitado,
                        COALESCE(NULLIF(bnin.personalizado_instagram_url, ''), un.personalizado_instagram_url) AS instagram_url,
                        COALESCE(bnin.personalizado_facebook_habilitado, un.personalizado_facebook_habilitado, 0) AS facebook_habilitado,
                        COALESCE(NULLIF(bnin.personalizado_facebook_url, ''), un.personalizado_facebook_url) AS facebook_url
                     FROM bi_negocio_institution_names bnin
                     LEFT JOIN bi_unidades un ON un.id = bnin.unidade_id AND un.tenant_id = bnin.tenant_id
                     WHERE bnin.tenant_id = :tenant_id
                       AND {$institutionParameterSql}
                     LIMIT 1"
                );
                $stmtCanais->execute([
                    'tenant_id' => (int) ($data['tenant_id'] ?? 0),
                    'institution_name' => (string) ($data['institution_name'] ?? ''),
                ]);
                $canaisUnidade = $stmtCanais->fetch(\PDO::FETCH_ASSOC) ?: [];
                foreach (['qrcode', 'site', 'instagram', 'facebook'] as $canal) {
                    $data['unidade_personalizado_' . $canal . '_habilitado'] = (int) ($canaisUnidade[$canal . '_habilitado'] ?? 0);
                    $data['unidade_personalizado_' . $canal . '_url'] = $canaisUnidade[$canal . '_url'] ?? null;
                }
            } catch (\PDOException $canaisError) {
                Logger::warning('ReportsController::pdf canais institucionais indisponiveis — migration pendente', [
                    'tenant_id' => (int) ($data['tenant_id'] ?? 0), 'error' => $canaisError->getMessage(),
                ]);
            }

            // Registro profissional corporativo é opcional e depende da migration
            // de Negócios. O fallback impede que laudos parem de abrir enquanto a
            // coluna ainda não foi aplicada em um ambiente legado.
            $data['registro_crm_uf'] = null;
            $data['registro_crm_numero'] = null;
            try {
                $stmtRegistro = $pdo->prepare(
                    'SELECT registro_crm_uf, registro_crm_numero FROM bi_tenants WHERE id = :id LIMIT 1'
                );
                $stmtRegistro->execute(['id' => (int) ($data['tenant_id'] ?? 0)]);
                $registroEmpresa = $stmtRegistro->fetch(\PDO::FETCH_ASSOC) ?: [];
                $data['registro_crm_uf'] = $registroEmpresa['registro_crm_uf'] ?? null;
                $data['registro_crm_numero'] = $registroEmpresa['registro_crm_numero'] ?? null;
            } catch (\PDOException $registroError) {
                Logger::warning('ReportsController::pdf registro CRM institucional indisponivel — migration pendente', [
                    'tenant_id' => (int) ($data['tenant_id'] ?? 0),
                    'error' => $registroError->getMessage(),
                ]);
            }

            // O report guarda somente template_id. Resolve a Máscara no momento
            // do PDF para exibir seu título e recuperar as seções como fallback
            // de laudos antigos que salvaram apenas corpo_laudo.
            $mascara = $this->carregarMascaraParaPdf(
                $pdo,
                (int) ($data['template_id'] ?? 0),
                (int) ($data['tenant_id'] ?? 0)
            );
            if ($mascara !== null) {
                $data['mascara_titulo'] = $mascara['titulo'];
                $data['mascara_secoes'] = $mascara['secoes'];
                $data['mascara_conteudo_livre'] = trim((string) ($mascara['conteudo_livre'] ?? '')) !== '';
            }

            // Modalidade, Study Description e título da Máscara identificam o exame,
            // mas nunca constituem texto clínico. Sem conteúdo real, PDF, impressão
            // e download devem permanecer indisponíveis para todos os perfis.
            if (!\App\Services\ReportClinicalContentService::hasReportContent($data)) {
                if ($portalPatientPdf) {
                    http_response_code(404);
                    echo 'Laudo indisponível.';
                    return;
                }

                http_response_code(422);
                $this->view('reports/pdf_empty', [
                    'reportToken' => (string) ($data['public_token'] ?? ''),
                ], 'pacs');
                return;
            }

            // Log de visualização de PDF
            if (!$portalPatientPdf) {
                $userId = Auth::userId();
                $user = Auth::user();
                $this->reportRepo->logAction(
                    $reportId, (int)$data['estudo_id'], (int)$data['tenant_id'],
                    $userId, $user->name ?? $user->nome ?? '', 'pdf',
                    $download ? 'Download PDF' : 'Visualização PDF'
                );
            }
            // Template visual (camada de apresentação — ver App\Services\ReportLayoutService).
            // Unidade resolvida via institution_name; sem unidade vinculada ou sem
            // template escolhido, cai no padrão (classico_centralizado).
            $layoutService = new \App\Services\ReportLayoutService();
            $templateCodigo = $layoutService
                ->resolverCodigo(isset($data['report_layout_template_id']) ? (int) $data['report_layout_template_id'] : null);
            $customTemplate = null;
            if ($templateCodigo === 'personalizado') {
                $customService = new \App\Services\ReportCustomTemplateService();
                $snapshotId = (int) ($data['report_custom_template_id'] ?? 0);
                if ($snapshotId > 0) {
                    $customTemplate = $customService->getById($snapshotId, (int) $data['tenant_id']);
                }
                if ($customTemplate === null) {
                    $origem = ((int) ($data['institution_report_layout_id'] ?? 0) === (int) ($data['report_layout_template_id'] ?? 0))
                        ? \App\Services\ReportCustomTemplateService::SOURCE_INSTITUTION
                        : \App\Services\ReportCustomTemplateService::SOURCE_UNIDADE;
                    $unidadeId = $origem === \App\Services\ReportCustomTemplateService::SOURCE_INSTITUTION
                        ? (int) ($data['institution_unit_id'] ?? 0)
                        : (int) ($data['rich_unit_id'] ?? 0);
                    $customTemplate = $customService->getPublished((int) $data['tenant_id'], $origem, $unidadeId);
                }
                if ($customTemplate === null) {
                    Logger::warning('ReportsController::pdf layout personalizado sem versão publicada; aplicado fallback', [
                        'report_id' => $reportId, 'tenant_id' => $data['tenant_id'] ?? null,
                    ]);
                    $templateCodigo = \App\Services\ReportLayoutService::PADRAO;
                }
            }
            // Renderizar a view de PDF
            $this->view('reports/pdf', [
                'report'         => $data,
                'download'       => $download,
                'templateCodigo' => $templateCodigo,
                'customTemplate' => $customTemplate,
                'portalPatientPdf' => $portalPatientPdf,
                'qr_data'  => base64_encode(json_encode([
                    'id'   => $reportId,
                    'hash' => $data['assinatura_hash'] ?? '',
                    'data' => $data['assinado_em'] ?? ''
                ]))
            ], $portalPatientPdf ? 'portal_pdf' : 'pacs');
        } catch (\Throwable $e) {
            Logger::error('ReportsController::pdf error', ['msg' => $e->getMessage()]);
            http_response_code(500);
            echo 'Erro ao gerar PDF.';
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET /reports/r/{token}/assinatura
    // Proxy autenticado da assinatura visual CONGELADA deste laudo (ver
    // ReportService::congelarAssinaturaVisual) — arquivo fica fora de public/,
    // nunca exposto direto. A rota usa ReportAccessService, a mesma defesa de
    // tenant, InstitutionName e posse médica aplicada ao PDF e ao editor.

    // ══════════════════════════════════════════════════════════════════════════
    public function assinaturaImagemByToken(string $token): void
    {
        if (!Auth::check()) { http_response_code(401); return; }
        $report = (new ReportAccessService())->findAuthorizedReportByPublicToken($token);
        if (!$report) { http_response_code(404); return; }
        $_GET['report_id'] = (int) $report->id;
        $this->assinaturaImagem();
    }

    /** Proxy interno, acionado somente após resolução de token opaco. */
    public function assinaturaImagem(): void
    {
        if (!Auth::check()) { http_response_code(401); return; }
        $reportId = (int) ($_GET['report_id'] ?? 0);

        if (!$reportId || !(new ReportAccessService())->findAuthorizedReport($reportId)) {
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

        $tenantId = (int) (Auth::tenantId() ?? 0);
        if ($tenantId <= 0) {
            $this->json(['ok' => false, 'msg' => 'Tenant inválido.'], 403);
            return;
        }

        try {
            $pdo = \App\Core\Database::getInstance();
            $modalidades = $this->normalizarModalidades((string) ($_GET['modalidades'] ?? $_GET['modalidade'] ?? ''));
            $studyDescription = $this->normalizarStudyDescription((string) ($_GET['study_description'] ?? ''));
            $medicoId = $this->medicoIdAtual($pdo, $tenantId);
            $perfilMedico = strtolower((string) Auth::perfilAtual()) === 'medico';

            $where = "WHERE ativo = 1 AND (tenant_id IS NULL OR tenant_id = :tenant_id)";
            $params = ['tenant_id' => $tenantId, 'medico_ordem' => $medicoId];
            if ($perfilMedico) {
                $where .= " AND (medico_id = :medico_id OR compartilhar = 1 OR medico_id IS NULL)";
                $params['medico_id'] = $medicoId;
            }

            if ($studyDescription !== '') $params['study_description'] = $studyDescription;
            if ($modalidades) {
                $holders = [];
                foreach ($modalidades as $index => $modalidade) {
                    $key = 'modalidade_' . $index;
                    $holders[] = ':' . $key;
                    $params[$key] = $modalidade;
                }
                $where .= " AND (modalidade IN (" . implode(', ', $holders) . ") OR modalidade IS NULL OR TRIM(modalidade) = ''";
                if ($studyDescription !== '') {
                    // A TAG DICOM exata é um vínculo clínico explícito e não deve
                    // ser descartada por uma modalidade importada incompleta.
                    $where .= " OR UPPER(TRIM(COALESCE(study_description_tag, ''))) = :study_description";
                }
                $where .= ')';
            }

            $matchField = $studyDescription !== ''
                ? "CASE WHEN UPPER(TRIM(COALESCE(study_description_tag, ''))) = :study_description THEN 1 ELSE 0 END AS study_description_match"
                : "0 AS study_description_match";
            $order = "ORDER BY study_description_match DESC, CASE WHEN medico_id = :medico_ordem THEN 0 ELSE 1 END ASC, COALESCE(uso_count, 0) DESC, nome ASC";

            $rows = null;
            $queries = [
                "SELECT id, nome, modalidade, compartilhar, study_description_tag, conteudo_livre, medico_id, uso_count,
                        secao_exame, secao_tecnica, secao_achados, secao_conclusao, secao_recomendacao, {$matchField}
                 FROM report_templates {$where} {$order}",
                "SELECT id, nome, modalidade, compartilhar, study_description_tag, medico_id, uso_count, conteudo, {$matchField}
                 FROM report_templates {$where} {$order}",
                "SELECT id, titulo AS nome, modalidade, compartilhar, study_description_tag, medico_id, uso_count, conteudo, {$matchField}
                 FROM report_templates {$where} {$order}",
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
            $sugeridos = array_values(array_filter($templates, static fn(array $template): bool => !empty($template['study_description_match'])));
            $this->json([
                'ok' => true,
                'templates' => $templates,
                'sugeridos' => $sugeridos,
                'study_description' => $studyDescription,
                'modalidades' => $modalidades,
            ]);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::templates error', ['msg' => $e->getMessage(), 'tenant_id' => $tenantId]);
            $this->json(['ok' => false, 'templates' => [], 'sugeridos' => [], 'msg' => 'Erro ao listar templates.'], 500);
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
            $tenantId = (int) (Auth::tenantId() ?? 0);
            $medicoId = $this->medicoIdAtual($pdo, $tenantId);
            $where = "WHERE id = :id AND ativo = 1 AND (tenant_id IS NULL OR tenant_id = :tenant_id)";
            $params = ['id' => $templateId, 'tenant_id' => $tenantId];
            if (strtolower((string) Auth::perfilAtual()) === 'medico') {
                $where .= " AND (medico_id = :medico_id OR compartilhar = 1 OR medico_id IS NULL)";
                $params['medico_id'] = $medicoId;
            }
            $stmt = $pdo->prepare("SELECT * FROM report_templates {$where} LIMIT 1");
            $stmt->execute($params);
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
                try {
            $report = $this->reportRepo->findReportByEstudoId($estudoId)
                ?: $this->reportRepo->createReport(
                    $estudoId,
                    (int) ($estudo['tenant_id'] ?? $tenantId),
                    (string) ($estudo['study_instance_uid'] ?? ''),
                    $userId,
                    ['secoes' => []]
                );
            $url = $this->reportService->urlPublica($report);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::assumir não gerou URL pública', [
                'estudo_id' => $estudoId,
                'usuario_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->json(['ok' => false, 'msg' => 'Não foi possível preparar o Laudário. Verifique a migration de URL segura.'], 503);
            return;
        }

        // Log de auditoria sem incluir o token ou Study UID público.
        $this->reportRepo->logAction(
            (int) $report->id, $estudoId, (int)($estudo['tenant_id'] ?? $tenantId),
            $userId, $user->nome ?? '',
            'assumir',
            'Estudo assumido pelo médico'
        );

        $this->json([
            'ok'          => true,
            'msg'         => 'Estudo assumido com sucesso.',
            'url'         => $url,
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
            foreach (SqlHelper::tableColumns($pdo, 'report_autotext') as $field) {
                $field = strtolower((string) $field);
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
        $conteudoLivre = trim((string) ($row['conteudo_livre'] ?? ''));
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
            'study_description_tag' => trim((string) ($row['study_description_tag'] ?? '')),
            'study_description_match' => !empty($row['study_description_match']),
            'conteudo_livre' => $conteudoLivre,
            'conteudo' => $conteudoLivre !== ''
                ? json_encode(['corpo' => $conteudoLivre], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : json_encode(['secoes' => $secoes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'secoes' => $secoes,
        ];
    }

    /** @return array<int,string> */
    private function normalizarModalidades(string $modalidades): array
    {
        $modalidades = strtoupper(trim($modalidades));
        if ($modalidades === '') return [];
        preg_match_all('/[A-Z0-9]{1,16}/', $modalidades, $matches);
        return array_values(array_unique($matches[0] ?? []));
    }

    private function normalizarStudyDescription(string $descricao): string
    {
        $descricao = strtoupper(trim(preg_replace('/\\s+/', ' ', strip_tags($descricao)) ?? ''));
        return mb_strlen($descricao, 'UTF-8') <= 255 ? $descricao : '';
    }

    private function medicoIdAtual(\PDO $pdo, int $tenantId): int
    {
        if ($tenantId <= 0 || !Auth::userId()) return 0;
        $stmt = $pdo->prepare('SELECT id FROM bi_medicos WHERE usuario_id = :usuario_id AND tenant_id = :tenant_id LIMIT 1');
        $stmt->execute(['usuario_id' => (int) Auth::userId(), 'tenant_id' => $tenantId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * Resolve somente os metadados institucionais necessários para espelhar o
     * layout de impressão na tela do Laudário. Falhas não impedem a edição.
     *
     * @return array{report_layout_template_id:int,unidade_nome:string,unidade_logo_path:string}
     */
    private function carregarContextoVisualLaudo(object $estudo): array
    {
        $contexto = [
            'report_layout_template_id' => 0,
            'unidade_nome' => trim((string) ($estudo->institution_name ?? 'Clínica')) ?: 'Clínica',
            'unidade_logo_path' => '',
        ];
        $tenantId = (int) ($estudo->tenant_id ?? \App\Core\TenantContext::id());
        $institutionName = trim((string) ($estudo->institution_name ?? ''));
        if ($tenantId <= 0 || $institutionName === '') {
            return $contexto;
        }

        try {
            $pdo = \App\Core\Database::getInstance();
            $institutionParameterSql = SqlHelper::caseInsensitiveEquals('bnin.institution_name', ':institution_name');
            $stmt = $pdo->prepare(
                "SELECT bnin.report_layout_template_id,
                        COALESCE(NULLIF(bnin.nome_fantasia, ''), NULLIF(bnin.razao_social, ''), un.nome_fantasia, un.razao_social) AS unidade_nome,
                        COALESCE(NULLIF(bnin.logo_path, ''), un.logo_path) AS unidade_logo_path
                 FROM bi_negocio_institution_names bnin
                 LEFT JOIN bi_unidades un ON un.id = bnin.unidade_id AND un.tenant_id = bnin.tenant_id
                 WHERE bnin.tenant_id = :tenant_id
                   AND {$institutionParameterSql}
                 LIMIT 1"
            );
            $stmt->execute([
                'tenant_id' => $tenantId,
                'institution_name' => $institutionName,
            ]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $contexto['report_layout_template_id'] = (int) ($row['report_layout_template_id'] ?? 0);
                $contexto['unidade_nome'] = trim((string) ($row['unidade_nome'] ?? '')) ?: $contexto['unidade_nome'];
                $contexto['unidade_logo_path'] = trim((string) ($row['unidade_logo_path'] ?? ''));
            }
        } catch (\Throwable $e) {
            Logger::warning('ReportsController::carregarContextoVisualLaudo indisponivel', [
                'tenant_id' => $tenantId,
                'institution_name' => $institutionName,
                'error' => $e->getMessage(),
            ]);
        }

        return $contexto;
    }

    /**
     * Resolve a Máscara vinculada ao report sem depender de um único schema
     * histórico. O acesso permanece isolado pelo tenant do report.
     */
    private function carregarMascaraParaPdf(\PDO $pdo, int $templateId, int $tenantId): ?array
    {
        if ($templateId <= 0 || $tenantId <= 0) {
            return null;
        }

        $where = 'WHERE id = :id AND (tenant_id IS NULL OR tenant_id = :tenant_id) LIMIT 1';
        $params = ['id' => $templateId, 'tenant_id' => $tenantId];
        $queries = [
            "SELECT id, nome, modalidade, conteudo_livre, secao_exame, secao_tecnica, secao_achados, secao_conclusao, secao_recomendacao FROM report_templates {$where}",
            "SELECT id, titulo AS nome, modalidade, conteudo_livre, secao_exame, secao_tecnica, secao_achados, secao_conclusao, secao_recomendacao FROM report_templates {$where}",
            "SELECT id, nome, modalidade, conteudo FROM report_templates {$where}",
            "SELECT id, titulo AS nome, modalidade, conteudo FROM report_templates {$where}",
        ];

        foreach ($queries as $sql) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row !== false) {
                    return $this->normalizarTemplate($row);
                }
                return null;
            } catch (\PDOException $queryError) {
                Logger::warning('ReportsController::pdf tentando schema alternativo de mascara', [
                    'template_id' => $templateId,
                    'error' => $queryError->getMessage(),
                ]);
            }
        }

        return null;
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
