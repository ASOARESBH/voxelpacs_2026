<?php

namespace App\Core\Access;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\SqlHelper;

/** Interseção entre trava global da plataforma e permissão individual legada. */
final class ModuleAccess
{
    /** @var array<string, bool>|null */
    private static ?array $globalCache = null;

    public static function canAccess(string $moduleKey): bool
    {
        if (!ModuleCatalog::has($moduleKey) || !Auth::check()) {
            return false;
        }
        if (!self::globalEnabled($moduleKey)) {
            return false;
        }

        $permission = ModuleCatalog::permissionKey($moduleKey);
        return $permission !== null && Auth::hasModule($permission);
    }

    public static function canAccessUri(string $uri): bool
    {
        $moduleKey = ModuleCatalog::moduleForUri($uri);
        return $moduleKey === null || self::canAccess($moduleKey);
    }

    public static function globalEnabled(string $moduleKey): bool
    {
        if (!ModuleCatalog::has($moduleKey)) {
            return false;
        }
        self::loadGlobalCache();
        return self::$globalCache[$moduleKey] ?? true;
    }

    /** @return array<string, array{global: bool, effective: bool}> */
    public static function states(): array
    {
        $states = [];
        foreach (ModuleCatalog::all() as $key => $_module) {
            $global = self::globalEnabled($key);
            $states[$key] = ['global' => $global, 'effective' => $global];
        }
        return $states;
    }

    private static function loadGlobalCache(): void
    {
        if (self::$globalCache !== null) {
            return;
        }
        self::$globalCache = [];
        try {
            $pdo = Database::getInstance();
            if (!SqlHelper::hasTable($pdo, 'bi_system_module_config')) {
                return;
            }
            $rows = $pdo->query('SELECT module_key, globally_enabled FROM bi_system_module_config')->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                self::$globalCache[(string) $row['module_key']] = (int) $row['globally_enabled'] === 1;
            }
        } catch (\Throwable $e) {
            Logger::warning('[ModuleAccess] Falha ao carregar travas globais de módulo');
            self::$globalCache = [];
        }
    }
}
