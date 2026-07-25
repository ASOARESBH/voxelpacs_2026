<?php
namespace App\Services;

use App\Models\WebhookHubConfig;
use App\Models\WebhookHubEvent;

/**
 * WebhookHubService
 *
 * Responsável por:
 *  - Gerar JWT HMAC-SHA256 (HS256) sem dependência externa
 *  - Enviar eventos ao VOXEL HUB via cURL com retry + DLQ
 *  - Health check do endpoint remoto
 *  - Log de todos os eventos em business_webhook_hub_events
 */
class WebhookHubService {

    private WebhookHubConfig $configModel;
    private WebhookHubEvent  $eventModel;

    public function __construct() {
        $this->configModel = new WebhookHubConfig();
        $this->eventModel  = new WebhookHubEvent();
    }

    // ============================================================
    // JWT HMAC-SHA256 (sem biblioteca externa)
    // ============================================================

    /**
     * Gera um JWT assinado com HMAC-SHA256.
     */
    public function generateJwt(array $config): string {
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
        ]));

        $now     = time();
        $payload = $this->base64UrlEncode(json_encode([
            'iss' => $config['jwt_issuer']   ?? 'voxel-pacs',
            'aud' => $config['jwt_audience'] ?? 'voxel-hub',
            'iat' => $now,
            'exp' => $now + (int)($config['jwt_expiry_seconds'] ?? 3600),
            'sub' => 'webhook-event',
        ]));

        $signature = $this->base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$payload}", $config['jwt_secret'], true)
        );

        return "{$header}.{$payload}.{$signature}";
    }

    /**
     * Valida um JWT recebido (para uso no HUB ao receber callbacks).
     */
    public function validateJwt(string $token, array $config): bool {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return false;

        [$header, $payload, $signature] = $parts;

        $expectedSig = $this->base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$payload}", $config['jwt_secret'], true)
        );

        if (!hash_equals($expectedSig, $signature)) return false;

        $claims = json_decode($this->base64UrlDecode($payload), true);
        if (!$claims) return false;

        // Verifica expiração
        if (isset($claims['exp']) && $claims['exp'] < time()) return false;

        return true;
    }

    private function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 4 - strlen($data) % 4));
    }

    // ============================================================
    // Envio de Evento
    // ============================================================

    /**
     * Envia um evento ao VOXEL HUB.
     * Registra em business_webhook_hub_events e aplica retry/DLQ.
     *
     * @param int    $tenantId
     * @param string $eventType  Ex: 'study.received'
     * @param array  $payload    Dados do evento
     * @param string $eventId    UUID único (idempotência) — gerado automaticamente se vazio
     */
    public function sendEvent(int $tenantId, string $eventType, array $payload, string $eventId = ''): array {
        $config = $this->configModel->getByTenant($tenantId);

        if (!$config || $config['status'] !== 'enabled') {
            return ['success' => false, 'message' => 'Webhook HUB não configurado ou desabilitado.'];
        }

        // Verificar se evento está habilitado
        $eventsEnabled = json_decode($config['events_enabled'] ?? '[]', true) ?: [];
        if (!in_array($eventType, $eventsEnabled) && !in_array('*', $eventsEnabled)) {
            return ['success' => false, 'message' => "Evento '{$eventType}' não está habilitado."];
        }

        // Gerar event_id único se não fornecido
        if (empty($eventId)) {
            $eventId = $this->generateUuid();
        }

        // Idempotência: não reenviar evento já processado com sucesso
        if ($this->eventModel->existsByEventId($eventId, $tenantId)) {
            return ['success' => true, 'message' => 'Evento já processado (idempotência).', 'event_id' => $eventId];
        }

        // Registrar evento como pending
        $dbEventId = $this->eventModel->create([
            'tenant_id'        => $tenantId,
            'webhook_config_id'=> (int)$config['id'],
            'event_id'         => $eventId,
            'event_type'       => $eventType,
            'event_timestamp'  => date('Y-m-d H:i:s'),
            'payload'          => $payload,
        ]);

        // Tentar envio com retry
        $result = $this->sendWithRetry($config, $eventType, $eventId, $payload, $dbEventId);

        return $result;
    }

    /**
     * Lógica de retry com backoff exponencial.
     */
    private function sendWithRetry(array $config, string $eventType, string $eventId, array $payload, int $dbEventId): array {
        $maxAttempts = (int)($config['retry_max_attempts'] ?? 5);
        $backoffs    = json_decode($config['retry_backoff_seconds'] ?? '[5,15,60,300]', true) ?: [5, 15, 60, 300];
        $dlqEnabled  = (bool)($config['retry_dlq_enabled'] ?? true);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $result = $this->doHttpPost($config, $eventType, $eventId, $payload);

            if ($result['success']) {
                $this->eventModel->updateAttempt($dbEventId, 'sent', $result['http_code'], null);
                $this->writeLog($config['tenant_id'], 'INFO', "Evento {$eventId} enviado com sucesso na tentativa " . ($attempt + 1));
                return ['success' => true, 'event_id' => $eventId, 'attempts' => $attempt + 1];
            }

            // Falhou — registrar tentativa
            $this->eventModel->updateAttempt($dbEventId, 'failed', $result['http_code'] ?? 0, $result['error'] ?? '');
            $this->writeLog($config['tenant_id'], 'WARN', "Tentativa " . ($attempt + 1) . " falhou para evento {$eventId}: " . ($result['error'] ?? ''));

            // Aguardar backoff antes da próxima tentativa (apenas em ambiente de produção)
            if ($attempt < $maxAttempts - 1 && isset($backoffs[$attempt])) {
                // Em ambiente web, sleep curto apenas (não bloquear request)
                // O retry completo deve ser feito via job/cron — aqui registramos para reprocessamento
                if ($backoffs[$attempt] <= 5) {
                    sleep((int)$backoffs[$attempt]);
                } else {
                    // Backoff longo: deixar para reprocessamento via cron
                    break;
                }
            }
        }

        // Mover para DLQ se habilitado
        if ($dlqEnabled) {
            $this->eventModel->moveToDlq($dbEventId, 'Máximo de tentativas atingido.');
            $this->writeLog($config['tenant_id'], 'ERROR', "Evento {$eventId} movido para DLQ após {$maxAttempts} tentativas.");
        }

        return ['success' => false, 'event_id' => $eventId, 'error' => 'Máximo de tentativas atingido.'];
    }

    /**
     * Executa o HTTP POST para o HUB.
     */
    private function doHttpPost(array $config, string $eventType, string $eventId, array $payload): array {
        $url     = rtrim($config['hub_url'], '/') . '/api/webhook/receive';
        $jwt     = $this->generateJwt($config);
        $timeout = (int)($config['request_timeout_seconds'] ?? 30);

        $body = json_encode([
            'event_id'   => $eventId,
            'event_type' => $eventType,
            'timestamp'  => date('c'),
            'source'     => 'voxel-pacs',
            'tenant_id'  => $config['tenant_id'],
            'data'       => $payload,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $jwt,
                'X-Voxel-Event-Id: ' . $eventId,
                'X-Voxel-Source: voxel-pacs',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['success' => false, 'http_code' => 0, 'error' => "cURL error: {$curlErr}"];
        }

        $success = $httpCode >= 200 && $httpCode < 300;
        return [
            'success'   => $success,
            'http_code' => $httpCode,
            'response'  => $response,
            'error'     => $success ? null : "HTTP {$httpCode}: {$response}",
        ];
    }

    // ============================================================
    // Health Check
    // ============================================================

    /**
     * Verifica conectividade com o VOXEL HUB.
     */
    public function healthCheck(int $tenantId): array {
        $config = $this->configModel->getByTenant($tenantId);

        if (!$config || empty($config['hub_url'])) {
            return ['status' => 'error', 'message' => 'URL do HUB não configurada.'];
        }

        $url     = rtrim($config['hub_url'], '/') . '/api/health';
        $jwt     = $this->generateJwt($config);
        $timeout = min((int)($config['request_timeout_seconds'] ?? 30), 10); // max 10s para health check

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $jwt,
                'X-Voxel-Source: voxel-pacs',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $startTime = microtime(true);
        $response  = curl_exec($ch);
        $elapsed   = round((microtime(true) - $startTime) * 1000); // ms
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr   = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $status  = 'error';
            $message = "Erro de conexão: {$curlErr}";
        } elseif ($httpCode === 0) {
            $status  = 'timeout';
            $message = "Timeout após {$elapsed}ms";
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            $status  = 'ok';
            $message = "Conectado com sucesso ({$elapsed}ms) — HTTP {$httpCode}";
        } else {
            $status  = 'error';
            $message = "HTTP {$httpCode} — {$elapsed}ms";
        }

        // Persistir resultado
        $this->configModel->updateHealthCheck($tenantId, $status, $message);
        $this->writeLog($tenantId, $status === 'ok' ? 'INFO' : 'WARN', "Health check: {$message}");

        return [
            'status'       => $status,
            'message'      => $message,
            'http_code'    => $httpCode,
            'elapsed_ms'   => $elapsed,
            'checked_at'   => date('d/m/Y H:i:s'),
        ];
    }

    // ============================================================
    // Retry de evento da DLQ
    // ============================================================

    /**
     * Reprocessa um evento da DLQ.
     */
    public function retryEvent(int $eventDbId, int $tenantId): array {
        $event = $this->eventModel->findById($eventDbId, $tenantId);
        if (!$event) {
            return ['success' => false, 'message' => 'Evento não encontrado.'];
        }

        $config = $this->configModel->getByTenant($tenantId);
        if (!$config) {
            return ['success' => false, 'message' => 'Configuração de webhook não encontrada.'];
        }

        // Resetar para pending
        $this->eventModel->resetForRetry($eventDbId);

        // Reenviar
        $payload = json_decode($event['payload'], true) ?: [];
        $result  = $this->doHttpPost($config, $event['event_type'], $event['event_id'], $payload);

        if ($result['success']) {
            $this->eventModel->updateAttempt($eventDbId, 'sent', $result['http_code'], null);
            return ['success' => true, 'message' => 'Evento reenviado com sucesso.'];
        }

        $this->eventModel->updateAttempt($eventDbId, 'failed', $result['http_code'] ?? 0, $result['error'] ?? '');
        return ['success' => false, 'message' => $result['error'] ?? 'Falha no reenvio.'];
    }

    // ============================================================
    // Utilitários
    // ============================================================

    private function generateUuid(): string {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Escreve log em arquivo para auditoria de qualquer eventualidade.
     */
    private function writeLog(int $tenantId, string $level, string $message): void {
        try {
            $logDir  = dirname(__DIR__, 2) . '/storage/logs/webhook';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            $logFile = $logDir . '/webhook_hub_' . date('Y-m-d') . '.log';
            $line    = sprintf("[%s] [%s] [tenant:%d] %s\n", date('Y-m-d H:i:s'), $level, $tenantId, $message);
            file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Silencioso — log não deve quebrar o fluxo principal
        }
    }
}
