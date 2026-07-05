<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\ReportPdfService;
use App\Services\ReportService;

/**
 * VOXEL PACS — ReportsController
 *
 * Editor de laudo médico (/reports/{studyUid}): autosave, templates,
 * autotexto, histórico/versionamento, assinatura e geração de PDF.
 */
class ReportsController extends Controller
{
    private ReportService $service;

    public function __construct()
    {
        $this->service = new ReportService();
    }

    public function show(string $studyUid): void
    {
        $resultado = $this->service->carregarParaEdicao($studyUid);

        if (!$resultado['ok']) {
            http_response_code(404);
            echo 'Estudo não encontrado.';
            return;
        }

        $this->view('reports/show', [
            'estudo'     => $resultado['estudo'],
            'report'     => $resultado['report'],
            'readonly'   => $resultado['readonly'],
            'lockInfo'   => $resultado['lockInfo'],
            'csrfToken'  => $this->csrfToken(),
        ], 'reports');
    }

    public function save(): void
    {
        if (!$this->verifyCsrf()) { $this->json(['ok' => false, 'error' => 'csrf_invalido'], 403); return; }

        $body = $this->readJsonBody();
        $reportId = (int) ($body['report_id'] ?? 0);
        $secoes   = is_array($body['secoes'] ?? null) ? $body['secoes'] : [];
        $modo     = in_array($body['modo'] ?? '', ['auto', 'rascunho', 'salvar'], true) ? $body['modo'] : 'auto';
        $templateId = isset($body['template_id']) ? (int) $body['template_id'] : null;

        $resultado = $this->service->salvar($reportId, $secoes, $modo, $templateId);
        $this->json($resultado, $resultado['ok'] ? 200 : 422);
    }

    public function sign(): void
    {
        if (!$this->verifyCsrf()) { $this->json(['ok' => false, 'error' => 'csrf_invalido'], 403); return; }

        $body = $this->readJsonBody();
        $reportId = (int) ($body['report_id'] ?? 0);
        $senha    = (string) ($body['senha'] ?? '');
        $crm      = trim((string) ($body['crm'] ?? '')) ?: null;

        if ($crm) {
            // Salva o CRM informado no cadastro do usuário para próximas assinaturas.
            \App\Core\Database::getInstance()
                ->prepare("UPDATE bi_users SET crm = :crm WHERE id = :id")
                ->execute(['crm' => $crm, 'id' => Auth::userId()]);
        }

        $resultado = $this->service->assinar($reportId, $senha, $crm);
        $status = $resultado['ok'] ? 200 : ($resultado['error'] === 'senha_invalida' ? 401 : 422);
        $this->json($resultado, $status);
    }

    public function templates(): void
    {
        $modalidade = trim($_GET['modalidade'] ?? '');
        if ($modalidade === '') { $this->json(['templates' => []]); return; }
        $this->json(['templates' => $this->service->listTemplates($modalidade)]);
    }

    public function autotext(): void
    {
        $modalidade = trim($_GET['modalidade'] ?? '') ?: null;
        $this->json(['items' => $this->service->listAutotext($modalidade)]);
    }

    public function history(): void
    {
        $reportId = (int) ($_GET['report_id'] ?? 0);
        $this->json(['versions' => $this->service->listVersions($reportId)]);
    }

    public function restoreVersion(): void
    {
        if (!$this->verifyCsrf()) { $this->json(['ok' => false, 'error' => 'csrf_invalido'], 403); return; }

        $body = $this->readJsonBody();
        $reportId  = (int) ($body['report_id'] ?? 0);
        $versionId = (int) ($body['version_id'] ?? 0);

        $resultado = $this->service->restoreVersion($reportId, $versionId);
        $this->json($resultado, $resultado['ok'] ? 200 : 422);
    }

    public function aiGenerate(): void
    {
        if (!$this->verifyCsrf()) { $this->json(['ok' => false, 'error' => 'csrf_invalido'], 403); return; }
        $this->json($this->service->aiGenerate());
    }

    public function pdf(string $studyUid): void
    {
        $resultado = $this->service->carregarParaEdicao($studyUid);
        if (!$resultado['ok']) { http_response_code(404); echo 'Estudo não encontrado.'; return; }

        $report = $resultado['report'];
        $this->service->registrarPdfGerado((int) $report->id);

        $pdfService = new ReportPdfService();
        $pdfService->stream($resultado['estudo'], $report);
    }

    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
