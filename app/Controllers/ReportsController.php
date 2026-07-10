<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Logger;
use App\Services\ReportService;
use App\Repositories\ReportRepository;
use App\Repositories\EstudosRepository;

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
                       AND e.servidor_id = 1
                     ORDER BY e.study_date DESC
                     LIMIT 10"
                );
                $stmt->execute([
                    ':pid' => $estudo->patient_id,
                    ':uid' => $studyUid
                ]);
                $examesAnteriores = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $ex) {
                Logger::error('Erro ao buscar exames anteriores', ['error' => $ex->getMessage()]);
            }
        }

        $this->view('reports/show', [
            'estudo'            => $estudo,
            'report'            => $report,
            'readonly'          => $readonly,
            'lockInfo'          => $lockInfo,
            'exames_anteriores' => $examesAnteriores,
            'csrfToken'         => $this->csrfToken(),
            'page_title'        => 'Laudo — ' . ($estudo->patient_name_display ?? $estudo->patient_name ?? 'Paciente'),
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

        // CSRF
        if (!$this->validarCsrf($input['csrf'] ?? '')) {
            $this->json(['ok' => false, 'msg' => 'Token inválido.'], 403);
            return;
        }

        try {
            $sucesso = $this->reportService->saveReport([
                'id'                 => (int) ($input['id'] ?? 0),
                'estudo_id'          => (int) ($input['estudo_id'] ?? 0),
                'secao_exame'        => $input['secao_exame'] ?? null,
                'secao_tecnica'      => $input['secao_tecnica'] ?? null,
                'secao_achados'      => $input['secao_achados'] ?? null,
                'secao_conclusao'    => $input['secao_conclusao'] ?? null,
                'secao_recomendacao' => $input['secao_recomendacao'] ?? null,
                'tempo_decorrido'    => (int) ($input['tempo_decorrido'] ?? 0),
                'is_manual'          => (bool) ($input['is_manual'] ?? false),
            ]);

            $this->json(['ok' => $sucesso, 'saved_at' => date('H:i:s')]);
        } catch (\Exception $e) {
            Logger::error('ReportsController::save error', ['msg' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => $e->getMessage()], 422);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST /reports/sign
    // Assina o laudo com senha
    // ══════════════════════════════════════════════════════════════════════════
    public function sign(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401);
            return;
        }

        $input = $this->getJsonInput();

        if (!$this->validarCsrf($input['csrf'] ?? '')) {
            $this->json(['ok' => false, 'msg' => 'Token inválido.'], 403);
            return;
        }

        try {
            $this->reportService->signReport([
                'id'                 => (int) ($input['id'] ?? 0),
                'estudo_id'          => (int) ($input['estudo_id'] ?? 0),
                'secao_exame'        => $input['secao_exame'] ?? null,
                'secao_tecnica'      => $input['secao_tecnica'] ?? null,
                'secao_achados'      => $input['secao_achados'] ?? null,
                'secao_conclusao'    => $input['secao_conclusao'] ?? null,
                'secao_recomendacao' => $input['secao_recomendacao'] ?? null,
                'senha'              => $input['senha'] ?? '',
            ]);

            $this->json(['ok' => true, 'msg' => 'Laudo assinado com sucesso.']);
        } catch (\Exception $e) {
            Logger::error('ReportsController::sign error', ['msg' => $e->getMessage()]);
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
            $pdo = \App\Core\Database::getInstance();
            $stmt = $pdo->prepare(
                "SELECT rv.id, rv.versao, rv.acao, rv.usuario_nome, rv.ip,
                        DATE_FORMAT(rv.created_at, '%d/%m/%Y %H:%i:%s') AS data_fmt
                 FROM report_versions rv
                 WHERE rv.report_id = :rid
                 ORDER BY rv.versao DESC"
            );
            $stmt->execute([':rid' => $reportId]);
            $versoes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json(['ok' => true, 'versoes' => $versoes]);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::history error', ['msg' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Erro ao buscar histórico.'], 500);
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

        try {
            $pdo = \App\Core\Database::getInstance();
            $stmt = $pdo->prepare(
                "SELECT r.*, e.patient_name_display, e.patient_name, e.patient_id,
                        e.patient_birth_date, e.patient_sex, e.patient_age,
                        e.study_date, e.study_time, e.study_description,
                        e.accession_number, e.modalities, e.institution_name,
                        e.referring_physician_name, e.num_instances, e.num_series,
                        u.nome as medico_nome, u.crm as medico_crm,
                        t.nome as tenant_nome
                 FROM reports r
                 JOIN bi_pacs_estudos e ON e.id = r.estudo_id
                 JOIN bi_users u ON u.id = r.usuario_id
                 LEFT JOIN bi_tenants t ON t.id = r.tenant_id
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
                $userId, $user->nome ?? '', 'pdf',
                $download ? 'Download PDF' : 'Visualização PDF'
            );

            // Renderizar a view de PDF
            $this->view('reports/pdf', [
                'report'   => $data,
                'download' => $download,
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
            $stmt = $pdo->prepare("SELECT * FROM report_templates WHERE id = :id AND ativo = 1 LIMIT 1");
            $stmt->execute([':id' => $templateId]);
            $tpl = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$tpl) {
                $this->json(['ok' => false, 'msg' => 'Template não encontrado.'], 404);
                return;
            }

            // Incrementar contador de uso
            $pdo->prepare("UPDATE report_templates SET uso_count = uso_count + 1 WHERE id = :id")
                ->execute([':id' => $templateId]);

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

        $input    = $this->getJsonInput();
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

        // Assumir o estudo
        $this->estudosRepo->assumirEstudo($estudoId, $userId);

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
    // GET /api/reports/autotext?q=torax
    // Retorna autotextos para o autocomplete
    // ══════════════════════════════════════════════════════════════════════════
    public function autotextSearch(): void
    {
        if (!Auth::check()) {
            $this->json([], 401);
            return;
        }

        $q        = trim($_GET['q'] ?? '');
        $tenantId = Auth::tenantId();
        $userId   = Auth::userId();

        if (strlen($q) < 2) {
            $this->json([]);
            return;
        }

        try {
            $pdo = \App\Core\Database::getInstance();
            $stmt = $pdo->prepare(
                "SELECT id, gatilho, titulo, conteudo
                 FROM report_autotext
                 WHERE ativo = 1
                   AND (tenant_id IS NULL OR tenant_id = :tid)
                   AND (usuario_id IS NULL OR usuario_id = :uid)
                   AND (gatilho LIKE :q OR titulo LIKE :q2)
                 ORDER BY uso_count DESC
                 LIMIT 10"
            );
            $like = '%' . $q . '%';
            $stmt->execute([
                ':tid' => $tenantId,
                ':uid' => $userId,
                ':q'   => $like,
                ':q2'  => $like
            ]);
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json($results);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::autotextSearch error', ['msg' => $e->getMessage()]);
            $this->json([]);
        }
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
            $pdo  = \App\Core\Database::getInstance();
            $stmt = $pdo->prepare(
                "SELECT id FROM reports WHERE estudo_id = :eid ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([':eid' => $estudoId]);
            $id = $stmt->fetchColumn();

            $this->json(['report_id' => $id ?: null]);
        } catch (\Throwable $e) {
            Logger::error('ReportsController::byEstudo error', ['msg' => $e->getMessage()]);
            $this->json(['report_id' => null]);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers privados
    // ══════════════════════════════════════════════════════════════════════════

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
