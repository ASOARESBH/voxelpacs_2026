<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Logger;
use App\Services\ViewerMeasurementService;

/**
 * Endpoint público sem cookie para o adapter executado no domínio do VOXEL VIEW.
 * A autorização vem exclusivamente do bearer token de sessão curta emitido em
 * ViewerTokenController; CORS é permitido somente para o origin oficial.
 */
class ViewerMeasurementsController extends Controller
{
    private ViewerMeasurementService $service;

    public function __construct()
    {
        $this->service = new ViewerMeasurementService();
    }

    // OPTIONS /api/viewer/measurements
    public function options(): void
    {
        if (!$this->applyCors()) {
            http_response_code(403);
            exit;
        }

        http_response_code(204);
        header('Cache-Control: no-store');
        exit;
    }

    // POST /api/viewer/measurements
    public function ingest(): void
    {
        if (!$this->applyCors()) {
            $this->json(['ok' => false, 'error' => 'origin_nao_autorizada'], 403);
            return;
        }

        $bearer = $this->getBearerToken();
        $payload = $this->getJsonInput();
        $result = $this->service->receive($bearer, $payload);
        header('Cache-Control: no-store');
        $this->json($result, (int) ($result['status'] ?? ($result['ok'] ? 200 : 422)));
    }

    private function applyCors(): bool
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $trustedOrigin = rtrim(getenv('VIEWER_URL') ?: 'https://view.voxelpacs.com.br', '/');
        if ($origin !== $trustedOrigin) {
            Logger::warning('ViewerMeasurementsController: origin negada', ['origin' => $origin]);
            return false;
        }

        header('Access-Control-Allow-Origin: ' . $trustedOrigin);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Max-Age: 600');
        header('Vary: Origin');
        return true;
    }

    private function getBearerToken(): string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }

        return preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($header), $matches)
            ? $matches[1]
            : '';
    }

    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            Logger::warning('ViewerMeasurementsController: JSON inválido recebido');
            return [];
        }

        return $decoded;
    }
}
