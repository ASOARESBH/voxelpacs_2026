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
    public static function log(string $action, string $entity, ?int $entityId = null, array $details = [], ?int $tenantId = null, ?string $category = null): void {
        try {
            $pdo = Database::getInstance();
            $context = RequestAuditContext::metadata();
            $stmt = $pdo->prepare("
                INSERT INTO bi_audit_logs (tenant_id, user_id, action, entity, entity_id, details, ip, category, request_id, region_code, region_source, user_agent, created_at)
                VALUES (:tenant_id, :user_id, :action, :entity, :entity_id, :details, :ip, :category, :request_id, :region_code, :region_source, :user_agent, NOW())
            ");
            $stmt->execute([
                'tenant_id' => $tenantId ?? TenantContext::id(),
                'user_id'   => Auth::user()?->id,
                'action'    => $action,
                'entity'    => $entity,
                'entity_id' => $entityId,
                'details'   => json_encode(self::sanitize(array_merge($details, self::actorContext($tenantId))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip'        => $context['ip'],
                'category'  => $category ?? self::categoryFor($action),
                'request_id'=> $context['request_id'],
                'region_code' => $context['region_code'],
                'region_source' => $context['region_source'],
                'user_agent' => $context['user_agent'],
            ]);
        } catch (\Throwable $e) {
            // Falha silenciosa no log de auditoria para não interromper o fluxo
            error_log('[AuditLogger] ' . $e->getMessage());
        }
    }

    public static function logChange(string $action, string $entity, ?int $entityId, array $before, array $after, ?int $tenantId = null, ?string $category = 'gestao_estudos'): void
    {
        self::log($action, $entity, $entityId, ['before' => $before, 'after' => $after], $tenantId, $category);
    }

    private static function categoryFor(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'login'), str_starts_with($action, 'logout'), str_starts_with($action, 'auth.') => 'acesso',
            $action === 'estudo.assumir' => 'clinica',
            str_starts_with($action, 'report.'), str_starts_with($action, 'laudo.') => 'clinica',
            str_starts_with($action, 'estudo.'), str_starts_with($action, 'pedido.'), str_starts_with($action, 'prioridade.') => 'gestao_estudos',
            default => 'sistema',
        };
    }

    private static function sanitize(array $details): array
    {
        $blocked = ['password','senha','token','email','cpf','cnpj','patient','paciente','nome_paciente'];
        $clean = [];
        foreach ($details as $key => $value) {
            $keyText = strtolower((string) $key);
            if (in_array($keyText, $blocked, true)) {
                $clean[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $clean[$key] = self::sanitize($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = is_string($value) ? mb_substr($value, 0, 300) : $value;
            }
        }
        return $clean;
    }

    /** Contexto administrativo mínimo, sem nomes ou conteúdo clínico, para auditorias rastreáveis. */
    private static function actorContext(?int $tenantId): array
    {
        $userId = (int) (Auth::userId() ?? 0);
        $context = [
            'perfil_efetivo' => strtolower(trim((string) Auth::perfilAtual())),
            'admin_plataforma' => Auth::isPlatformAdmin() && !Auth::isImpersonating(),
        ];
        if ($userId <= 0 || !$tenantId) return $context;
        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT grupo_id FROM bi_grupo_usuarios WHERE tenant_id = :tenant_id AND usuario_id = :user_id ORDER BY grupo_id ASC LIMIT 25'
            );
            $stmt->execute(['tenant_id' => $tenantId, 'user_id' => $userId]);
            $context['grupo_ids_efetivos'] = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        } catch (\Throwable) {
            $context['grupo_ids_efetivos'] = [];
        }
        return $context;
    }
}
