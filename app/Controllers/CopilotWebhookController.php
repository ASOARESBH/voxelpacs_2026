<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Services\CopilotWebhookService;

/**
 * CopilotWebhookController
 *
 * Recebe eventos enviados pelo VOXEL Copilot para o PACS.
 *
 * Rotas (web.php):
 *   POST /api/copilot/webhook/laudo-finalizado
 *   POST /api/copilot/webhook/evento
 */
class CopilotWebhookController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/copilot/webhook/laudo-finalizado
    // Recebe o laudo finalizado do Copilot e atualiza o estudo no PACS.
    // Autenticação: Bearer token no header Authorization.
    // ─────────────────────────────────────────────────────────────────────────
    public function laudoFinalizado(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // Extrai Bearer token
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
            $token = trim($m[1]);
        }
        if (!$token) {
            // Fallback: token via query string (para testes)
            $token = trim($_GET['token'] ?? '');
        }

        if (!$token) {
            echo json_encode(['ok' => false, 'erro' => 'token_ausente']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $svc    = new CopilotWebhookService();
        $result = $svc->receberLaudo($input, $token);

        echo json_encode($result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/copilot/webhook/evento
    // Endpoint genérico para receber qualquer evento do Copilot.
    // ─────────────────────────────────────────────────────────────────────────
    public function evento(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
            $token = trim($m[1]);
        }

        if (!$token) {
            echo json_encode(['ok' => false, 'erro' => 'token_ausente']);
            return;
        }

        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $evento = $input['evento'] ?? '';

        switch ($evento) {
            case 'laudo.finalizado':
            case 'laudo.assinado':
                $svc    = new CopilotWebhookService();
                $result = $svc->receberLaudo($input, $token);
                echo json_encode($result);
                break;

            default:
                echo json_encode(['ok' => true, 'msg' => 'Evento recebido (sem ação específica).', 'evento' => $evento]);
        }
    }
}
