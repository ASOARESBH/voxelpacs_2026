<?php
namespace App\Models;

use App\Core\Database;
use App\Core\Model;

class WebhookHubConfig extends Model {

    protected string $table = 'business_webhook_hub_configs';
    protected bool   $hasTenant = false; // tenant_id gerenciado manualmente (plataforma)

    /**
     * Busca configuração pelo tenant_id.
     * Retorna array ou null.
     */
    public function getByTenant(int $tenantId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM {$this->table}
            WHERE tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Upsert: INSERT se não existe, UPDATE se já existe.
     * Retorna o ID do registro.
     */
    public function upsert(int $tenantId, array $data, int $userId): int {
        $existing = $this->getByTenant($tenantId);

        $eventsEnabled       = json_encode($data['events_enabled'] ?? ['study.received']);
        $retryBackoff        = json_encode($data['retry_backoff_seconds'] ?? [5, 15, 60, 300]);

        if ($existing) {
            $stmt = $this->pdo->prepare("
                UPDATE {$this->table} SET
                    hub_url                 = ?,
                    jwt_secret              = ?,
                    jwt_algorithm           = ?,
                    jwt_issuer              = ?,
                    jwt_audience            = ?,
                    jwt_expiry_seconds      = ?,
                    events_enabled          = ?,
                    retry_enabled           = ?,
                    retry_max_attempts      = ?,
                    retry_backoff_seconds   = ?,
                    retry_dlq_enabled       = ?,
                    request_timeout_seconds = ?,
                    rate_limit_per_minute   = ?,
                    status                  = ?,
                    updated_by              = ?,
                    updated_at              = NOW()
                WHERE tenant_id = ?
            ");
            $stmt->execute([
                $data['hub_url']                 ?? '',
                $data['jwt_secret']              ?? '',
                $data['jwt_algorithm']           ?? 'HS256',
                $data['jwt_issuer']              ?? 'voxel-pacs',
                $data['jwt_audience']            ?? 'voxel-hub',
                (int)($data['jwt_expiry_seconds']      ?? 3600),
                $eventsEnabled,
                (int)($data['retry_enabled']           ?? 1),
                (int)($data['retry_max_attempts']      ?? 5),
                $retryBackoff,
                (int)($data['retry_dlq_enabled']       ?? 1),
                (int)($data['request_timeout_seconds'] ?? 30),
                (int)($data['rate_limit_per_minute']   ?? 1000),
                $data['status']                  ?? 'disabled',
                $userId,
                $tenantId,
            ]);
            return (int)$existing['id'];
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table}
                (tenant_id, hub_url, jwt_secret, jwt_algorithm, jwt_issuer, jwt_audience,
                 jwt_expiry_seconds, events_enabled, retry_enabled, retry_max_attempts,
                 retry_backoff_seconds, retry_dlq_enabled, request_timeout_seconds,
                 rate_limit_per_minute, status, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $tenantId,
            $data['hub_url']                 ?? '',
            $data['jwt_secret']              ?? '',
            $data['jwt_algorithm']           ?? 'HS256',
            $data['jwt_issuer']              ?? 'voxel-pacs',
            $data['jwt_audience']            ?? 'voxel-hub',
            (int)($data['jwt_expiry_seconds']      ?? 3600),
            $eventsEnabled,
            (int)($data['retry_enabled']           ?? 1),
            (int)($data['retry_max_attempts']      ?? 5),
            $retryBackoff,
            (int)($data['retry_dlq_enabled']       ?? 1),
            (int)($data['request_timeout_seconds'] ?? 30),
            (int)($data['rate_limit_per_minute']   ?? 1000),
            $data['status']                  ?? 'disabled',
            $userId,
            $userId,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Atualiza somente o status de health check.
     */
    public function updateHealthCheck(int $tenantId, string $status, string $message): void {
        $this->pdo->prepare("
            UPDATE {$this->table}
            SET last_health_check   = NOW(),
                last_health_status  = ?,
                last_health_message = ?
            WHERE tenant_id = ?
        ")->execute([$status, $message, $tenantId]);
    }

    /**
     * Atualiza somente o campo status (enabled/disabled/testing).
     */
    public function updateStatus(int $tenantId, string $status): void {
        $this->pdo->prepare("
            UPDATE {$this->table} SET status = ? WHERE tenant_id = ?
        ")->execute([$status, $tenantId]);
    }
}
