<?php

namespace App\Core\Access;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\SqlHelper;
use App\Services\DesktopViewerService;

/**
 * Interseção de disponibilidade do tenant e restrição individual de viewer.
 * Ausência de registro em bi_user_viewers significa acesso habilitado. A tabela é criada pela migration aditiva da mesma entrega.
 */
final class ViewerAccess
{
    /** @var array<string, array<string, bool>> */
    private static array $disabledCache = [];
    /** @var array<string, bool> */
    private static array $availabilityCache = [];

    public static function isUserEnabled(string $viewerKey, ?int $tenantId = null, ?int $userId = null): bool
    {
        if (!ViewerRegistry::has($viewerKey) || !Auth::check()) return false;
        if (Auth::isPlatformAdmin() || Auth::perfilAtual() === 'admin') return true;

        $tenantId ??= Auth::tenantId();
        $userId ??= Auth::userId();
        if (!$tenantId || !$userId) return false;

        return !isset(self::disabledForUser((int) $userId, (int) $tenantId)[$viewerKey]);
    }

    /** @return array<string, array{enabled: bool, tenant_available: bool, visible: bool}> */
    public static function statesForCurrentUser(int $tenantId, ?int $servidorId = null): array
    {
        $states = [];
        foreach (ViewerRegistry::all() as $key => $_viewer) {
            $enabled = self::isUserEnabled($key, $tenantId);
            $tenantAvailable = self::isTenantAvailable($key, $tenantId, $servidorId);
            $states[$key] = [
                'enabled' => $enabled,
                'tenant_available' => $tenantAvailable,
                'visible' => $enabled && $tenantAvailable,
            ];
        }
        return $states;
    }

    /** @return array<string, array{enabled: bool, tenant_available: bool, editable: bool}> */
    public static function statesForUser(int $userId, int $tenantId, string $perfil, ?string $role = null): array
    {
        $privileged = self::isPrivilegedTarget($perfil, $role);
        $disabled = $userId > 0 && $tenantId > 0 ? self::disabledForUser($userId, $tenantId) : [];
        $states = [];
        foreach (ViewerRegistry::all() as $key => $_viewer) {
            $tenantAvailable = self::isTenantAvailable($key, $tenantId);
            $enabled = $privileged || !isset($disabled[$key]);
            $states[$key] = [
                'enabled' => $enabled,
                'tenant_available' => $tenantAvailable,
                'editable' => !$privileged && $tenantAvailable,
            ];
        }
        return $states;
    }

    /** @return string[] */
    public static function disabledKeysForUser(int $userId, int $tenantId): array
    {
        return array_keys(self::disabledForUser($userId, $tenantId));
    }

    public static function isPrivilegedTarget(string $perfil, ?string $role = null): bool
    {
        return $perfil === 'admin' || $role === 'superadmin';
    }

    public static function isTenantAvailable(string $viewerKey, int $tenantId, ?int $servidorId = null): bool
    {
        if (!ViewerRegistry::has($viewerKey) || $tenantId <= 0) return false;
        if (in_array($viewerKey, ['voxel_view', 'voxel_desktop'], true)) return true;

        $cacheKey = $viewerKey . ':' . $tenantId . ':' . (int) $servidorId;
        if (array_key_exists($cacheKey, self::$availabilityCache)) {
            return self::$availabilityCache[$cacheKey];
        }

        try {
            $service = new DesktopViewerService();
            $config = $service->resolverConfig($tenantId, $viewerKey, $servidorId);
            return self::$availabilityCache[$cacheKey] = $service->validarConfig($config);
        } catch (\Throwable $e) {
            Logger::warning('[ViewerAccess] Configuração de visualizador indisponível', [
                'tenant_id' => $tenantId,
                'viewer_key' => $viewerKey,
            ]);
            return self::$availabilityCache[$cacheKey] = false;
        }
    }

    /** @return array<string, bool> */
    private static function disabledForUser(int $userId, int $tenantId): array
    {
        $cacheKey = $userId . ':' . $tenantId;
        if (isset(self::$disabledCache[$cacheKey])) return self::$disabledCache[$cacheKey];

        self::$disabledCache[$cacheKey] = [];
        try {
            $pdo = Database::getInstance();
            if (!SqlHelper::hasTable($pdo, 'bi_user_viewers')) return self::$disabledCache[$cacheKey];
            $stmt = $pdo->prepare(
                'SELECT viewer_key FROM bi_user_viewers WHERE user_id = ? AND tenant_id = ? AND habilitado = 0'
            );
            $stmt->execute([$userId, $tenantId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $viewerKey) {
                $viewerKey = (string) $viewerKey;
                if (ViewerRegistry::has($viewerKey)) self::$disabledCache[$cacheKey][$viewerKey] = true;
            }
        } catch (\Throwable $e) {
            // Modelo opt-out: sem tabela/migration, não altera o acesso legado.
            Logger::warning('[ViewerAccess] Restrições individuais indisponíveis', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ]);
        }
        return self::$disabledCache[$cacheKey];
    }
}
