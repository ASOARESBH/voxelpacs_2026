<?php
namespace App\Models;

use App\Core\Database;
use App\Core\Model;

class WebhookHubEvent extends Model {

    protected string $table = 'business_webhook_hub_events';
    protected bool   $hasTenant = false;

    /**
     * Verifica se evento já existe (idempotência).
     */
    public function existsByEventId(string $eventId, int $tenantId): bool {
        $stmt = $this->pdo->prepare("
            SELECT id FROM {$this->table}
            WHERE event_id = ? AND tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$eventId, $tenantId]);
        return (bool)$stmt->fetch();
    }

    /**
     * Registra novo evento como 'pending'.
     */
    public function create(array $data): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table}
                (tenant_id, webhook_config_id, event_id, event_type, event_timestamp, payload, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $data['tenant_id'],
            $data['webhook_config_id'],
            $data['event_id'],
            $data['event_type'],
            $data['event_timestamp'],
            is_string($data['payload']) ? $data['payload'] : json_encode($data['payload']),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Atualiza status após tentativa de envio.
     */
    public function updateAttempt(int $id, string $status, int $httpCode, ?string $error): void {
        $this->pdo->prepare("
            UPDATE {$this->table}
            SET status           = ?,
                attempt_count    = attempt_count + 1,
                last_attempt_at  = NOW(),
                http_status_code = ?,
                last_error       = ?
            WHERE id = ?
        ")->execute([$status, $httpCode, $error, $id]);
    }

    /**
     * Move evento para DLQ.
     */
    public function moveToDlq(int $id, string $reason): void {
        $this->pdo->prepare("
            UPDATE {$this->table}
            SET status     = 'dlq',
                last_error = ?
            WHERE id = ?
        ")->execute([$reason, $id]);
    }

    /**
     * Reseta evento para reprocessamento.
     */
    public function resetForRetry(int $id): void {
        $this->pdo->prepare("
            UPDATE {$this->table}
            SET status          = 'pending',
                attempt_count   = 0,
                last_attempt_at = NULL,
                last_error      = NULL
            WHERE id = ?
        ")->execute([$id]);
    }

    /**
     * Lista eventos de um tenant com filtros opcionais.
     */
    public function listByTenant(int $tenantId, array $filters = [], int $limit = 50, int $offset = 0): array {
        $where  = ['tenant_id = ?'];
        $params = [$tenantId];

        if (!empty($filters['status'])) {
            $where[]  = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['event_type'])) {
            $where[]  = 'event_type = ?';
            $params[] = $filters['event_type'];
        }
        if (!empty($filters['date_from'])) {
            $where[]  = 'created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]  = 'created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereStr = implode(' AND ', $where);
        $stmt = $this->pdo->prepare("
            SELECT id, event_id, event_type, event_timestamp, status,
                   attempt_count, last_attempt_at, last_error, http_status_code, created_at
            FROM {$this->table}
            WHERE {$whereStr}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Conta eventos de um tenant com filtros.
     */
    public function countByTenant(int $tenantId, array $filters = []): int {
        $where  = ['tenant_id = ?'];
        $params = [$tenantId];

        if (!empty($filters['status'])) {
            $where[]  = 'status = ?';
            $params[] = $filters['status'];
        }

        $whereStr = implode(' AND ', $where);
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM {$this->table} WHERE {$whereStr}
        ");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Busca evento por ID validando tenant.
     */
    public function findById(int $id, int $tenantId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM {$this->table}
            WHERE id = ? AND tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Estatísticas resumidas por tenant.
     */
    public function statsByTenant(int $tenantId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'sent'    THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'failed'  THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'dlq'     THEN 1 ELSE 0 END) AS dlq
            FROM {$this->table}
            WHERE tenant_id = ?
        ");
        $stmt->execute([$tenantId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['total'=>0,'sent'=>0,'pending'=>0,'failed'=>0,'dlq'=>0];
    }
}
