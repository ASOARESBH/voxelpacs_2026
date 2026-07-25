<?php
namespace App\Controllers\Platform;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\WebhookHubConfig;
use App\Models\WebhookHubEvent;
use App\Services\WebhookHubService;

/**
 * WebhookHubController
 *
 * Endpoints da Aba "Webhooks HUB" no painel de Negócios.
 * Todas as rotas exigem autenticação de superadmin (plataforma).
 *
 * POST /platform/negocios/{id}/webhook-hub/save
 * POST /platform/negocios/{id}/webhook-hub/health-check
 * POST /platform/negocios/{id}/webhook-hub/test-connection
 * GET  /platform/negocios/{id}/webhook-hub/logs
 * POST /platform/negocios/{id}/webhook-hub/retry/{eventId}
 */
class WebhookHubController extends Controller {

    private WebhookHubConfig  $configModel;
    private WebhookHubEvent   $eventModel;
    private WebhookHubService $service;

    public function __construct() {
        $this->configModel = new WebhookHubConfig();
        $this->eventModel  = new WebhookHubEvent();
        $this->service     = new WebhookHubService();
    }

    // ============================================================
    // Salvar Configuração
    // ============================================================

    /**
     * POST /platform/negocios/{id}/webhook-hub/save
     */
    public function save(int $id): void {
        if (!Auth::check()) {
            $this->json(['error' => 'Não autenticado.'], 401);
            return;
        }

        $body = $this->getJsonBody();

        // Validação básica
        if (empty($body['hub_url'])) {
            $this->json(['success' => false, 'message' => 'URL do HUB é obrigatória.'], 422);
            return;
        }

        if (!filter_var($body['hub_url'], FILTER_VALIDATE_URL)) {
            $this->json(['success' => false, 'message' => 'URL do HUB inválida.'], 422);
            return;
        }

        if (empty($body['jwt_secret']) || strlen($body['jwt_secret']) < 16) {
            $this->json(['success' => false, 'message' => 'JWT Secret deve ter pelo menos 16 caracteres.'], 422);
            return;
        }

        try {
            $configId = $this->configModel->upsert($id, $body, Auth::userId());

            // Log de auditoria em arquivo
            $this->writeAuditLog($id, 'save', "Configuração de Webhook HUB salva. Config ID: {$configId}");

            $this->json([
                'success'   => true,
                'message'   => 'Configuração salva com sucesso.',
                'config_id' => $configId,
            ]);
        } catch (\Throwable $e) {
            error_log("[WebhookHubController::save] tenant={$id} error=" . $e->getMessage());
            $this->writeAuditLog($id, 'save_error', $e->getMessage());
            $this->json(['success' => false, 'message' => 'Erro ao salvar configuração: ' . $e->getMessage()], 500);
        }
    }

    // ============================================================
    // Health Check
    // ============================================================

    /**
     * POST /platform/negocios/{id}/webhook-hub/health-check
     */
    public function healthCheck(int $id): void {
        if (!Auth::check()) {
            $this->json(['error' => 'Não autenticado.'], 401);
            return;
        }

        try {
            $result = $this->service->healthCheck($id);
            $this->writeAuditLog($id, 'health_check', "Status: {$result['status']} — {$result['message']}");
            $this->json($result);
        } catch (\Throwable $e) {
            error_log("[WebhookHubController::healthCheck] tenant={$id} error=" . $e->getMessage());
            $this->json(['status' => 'error', 'message' => 'Erro interno: ' . $e->getMessage()], 500);
        }
    }

    // ============================================================
    // Test Connection (envia evento de teste)
    // ============================================================

    /**
     * POST /platform/negocios/{id}/webhook-hub/test-connection
     */
    public function testConnection(int $id): void {
        if (!Auth::check()) {
            $this->json(['error' => 'Não autenticado.'], 401);
            return;
        }

        try {
            // Forçar status 'testing' temporariamente
            $this->configModel->updateStatus($id, 'testing');

            $result = $this->service->sendEvent($id, 'system.test', [
                'message'    => 'Teste de conexão VOXEL PACS → VOXEL HUB',
                'triggered_by' => Auth::userId(),
                'timestamp'  => date('c'),
            ]);

            // Restaurar status anterior
            $config = $this->configModel->getByTenant($id);
            if ($config && $config['status'] === 'testing') {
                $this->configModel->updateStatus($id, $result['success'] ? 'enabled' : 'disabled');
            }

            $this->writeAuditLog($id, 'test_connection', $result['success'] ? 'Teste bem-sucedido.' : ($result['error'] ?? 'Falha.'));

            $this->json([
                'success' => $result['success'],
                'message' => $result['success']
                    ? 'Evento de teste enviado com sucesso ao VOXEL HUB.'
                    : ($result['error'] ?? 'Falha ao enviar evento de teste.'),
                'event_id' => $result['event_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log("[WebhookHubController::testConnection] tenant={$id} error=" . $e->getMessage());
            $this->json(['success' => false, 'message' => 'Erro interno: ' . $e->getMessage()], 500);
        }
    }

    // ============================================================
    // Logs de Eventos
    // ============================================================

    /**
     * GET /platform/negocios/{id}/webhook-hub/logs
     * Parâmetros: ?status=&event_type=&date_from=&date_to=&page=1&per_page=25
     */
    public function logs(int $id): void {
        if (!Auth::check()) {
            $this->json(['error' => 'Não autenticado.'], 401);
            return;
        }

        try {
            $page    = max(1, (int)($_GET['page']     ?? 1));
            $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
            $offset  = ($page - 1) * $perPage;

            $filters = [
                'status'     => $_GET['status']     ?? '',
                'event_type' => $_GET['event_type'] ?? '',
                'date_from'  => $_GET['date_from']  ?? '',
                'date_to'    => $_GET['date_to']    ?? '',
            ];

            $events = $this->eventModel->listByTenant($id, $filters, $perPage, $offset);
            $total  = $this->eventModel->countByTenant($id, $filters);
            $stats  = $this->eventModel->statsByTenant($id);

            $this->json([
                'success'    => true,
                'events'     => $events,
                'pagination' => [
                    'total'    => $total,
                    'page'     => $page,
                    'per_page' => $perPage,
                    'pages'    => (int)ceil($total / $perPage),
                ],
                'stats'      => $stats,
            ]);
        } catch (\Throwable $e) {
            error_log("[WebhookHubController::logs] tenant={$id} error=" . $e->getMessage());
            $this->json(['success' => false, 'message' => 'Erro ao buscar logs: ' . $e->getMessage()], 500);
        }
    }

    // ============================================================
    // Retry de Evento DLQ
    // ============================================================

    /**
     * POST /platform/negocios/{id}/webhook-hub/retry/{eventId}
     */
    public function retryEvent(int $id, int $eventId): void {
        if (!Auth::check()) {
            $this->json(['error' => 'Não autenticado.'], 401);
            return;
        }

        try {
            $result = $this->service->retryEvent($eventId, $id);
            $this->writeAuditLog($id, 'retry_event', "Evento DB ID {$eventId}: " . ($result['success'] ? 'sucesso' : $result['message']));
            $this->json($result);
        } catch (\Throwable $e) {
            error_log("[WebhookHubController::retryEvent] tenant={$id} eventId={$eventId} error=" . $e->getMessage());
            $this->json(['success' => false, 'message' => 'Erro ao reenviar evento: ' . $e->getMessage()], 500);
        }
    }

    // ============================================================
    // Utilitários
    // ============================================================

    /**
     * Lê o corpo JSON da requisição.
     */
    private function getJsonBody(): array {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return $_POST ?: [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Log de auditoria em arquivo para qualquer eventualidade.
     */
    private function writeAuditLog(int $tenantId, string $action, string $detail): void {
        try {
            $logDir = dirname(__DIR__, 3) . '/storage/logs/webhook';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            $logFile = $logDir . '/webhook_hub_audit_' . date('Y-m-d') . '.log';
            $userId  = Auth::userId() ?? 0;
            $line    = sprintf(
                "[%s] [AUDIT] [tenant:%d] [user:%d] [action:%s] %s\n",
                date('Y-m-d H:i:s'), $tenantId, $userId, $action, $detail
            );
            file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Silencioso
        }
    }
}
