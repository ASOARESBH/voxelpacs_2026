<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\ReportMeasurementService;

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

        $result = $this->service->insert(
            (int) ($input['report_id'] ?? 0),
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
