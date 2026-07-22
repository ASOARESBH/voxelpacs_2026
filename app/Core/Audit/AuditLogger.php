<?php
namespace App\Core\Audit;

use App\Core\Database;
use App\Core\Auth;
use App\Core\TenantContext;

class AuditLogger {
    /**
     * @param int|null $tenantId Override explícito do tenant. Necessário em rotas
     *                           /platform/* (ex: NegociosController), onde não há
     *                           TenantContext ativo mas o tenant afetado é conhecido.
     *                           Se omitido, cai no comportamento original (TenantContext::id()).
     */
    public static function log(string $action, string $entity, ?int $entityId = null, array $details = [], ?int $tenantId = null): void {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("
                INSERT INTO bi_audit_logs (tenant_id, user_id, action, entity, entity_id, details, ip, created_at)
                VALUES (:tenant_id, :user_id, :action, :entity, :entity_id, :details, :ip, NOW())
            ");
            $stmt->execute([
                'tenant_id' => $tenantId ?? TenantContext::id(),
                'user_id'   => Auth::user()?->id,
                'action'    => $action,
                'entity'    => $entity,
                'entity_id' => $entityId,
                'details'   => json_encode($details),
                'ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Falha silenciosa no log de auditoria para não interromper o fluxo
            error_log('[AuditLogger] ' . $e->getMessage());
        }
    }
}
