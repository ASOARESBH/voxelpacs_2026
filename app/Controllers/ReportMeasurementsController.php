<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\ReportMeasurementService;
use App\Services\ReportAccessService;

/**
 * Endpoints autenticados do laudário para consulta e inserção de medições
 * recebidas previamente pelo adapter do VOXEL VIEW.
 */
class ReportMeasurementsController extends Controller
{
    private ReportMeasurementService $service;

    public function __construct()
    {
        $this->service = new ReportMeasurementService();
    }

    // GET /api/reports/measurements?report_id={id}
    public function index(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'error' => 'nao_autenticado'], 401);
            return;
        }

        $reportId = (int) ($_GET['report_id'] ?? 0);
        if (!(new ReportAccessService())->findAuthorizedReport($reportId)) {
            $this->json(['ok' => false, 'error' => 'laudo_nao_encontrado'], 404);
            return;
        }
        $result = $this->service->listAvailable($reportId);
        header('Cache-Control: no-store, private');

        $this->json($result, $result['ok'] ? 200 : 404);
    }

    // POST /api/reports/measurements/insert
    public function insert(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'error' => 'nao_autenticado'], 401);
            return;
        }

        $input = $this->getJsonInput();
        if (!$this->validarCsrf($input['csrf'] ?? '')) {
            $this->json(['ok' => false, 'error' => 'csrf_invalido'], 403);
            return;
        }

        $reportId = (int) ($input['report_id'] ?? 0);
        if (!(new ReportAccessService())->findAuthorizedReport($reportId)) {
            $this->json(['ok' => false, 'error' => 'laudo_nao_encontrado'], 404);
            return;
        }

        $result = $this->service->insert(
            $reportId,
            is_array($input['measurement_ids'] ?? null) ? $input['measurement_ids'] : [],
            (string) ($input['secao_destino'] ?? 'achados')
        );

        header('Cache-Control: no-store, private');
        $this->json($result, $result['ok'] ? 200 : 422);
    }

    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $_POST;
    }

    private function validarCsrf(string $token): bool
    {
        if ($token === '') {
            $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        }

        return $token !== ''
            && isset($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}
