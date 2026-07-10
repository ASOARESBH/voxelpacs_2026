<?php
namespace App\Core;

class TenantContext {
    private static ?object $tenant = null;
    private static ?int $tenantId = null;

    public static function set(object $tenant): void {
        self::$tenant  = $tenant;
        self::$tenantId = (int) $tenant->id;
    }

    public static function get(): ?object { return self::$tenant; }
    public static function id(): ?int    { return self::$tenantId; }
    public static function name(): string { return self::$tenant->nome ?? ''; }
    public static function plan(): string { return self::$tenant->plano ?? 'basic'; }
    public static function isSet(): bool  { return self::$tenantId !== null; }

    public static function allows(string $feature): bool {
        if (!self::$tenant) return false;
        return match ($feature) {
            'benchmark'  => (bool) (self::$tenant->permite_benchmark ?? false),
            'preditivo'  => (bool) (self::$tenant->permite_preditivo ?? false),
            'api'        => (bool) (self::$tenant->permite_api       ?? false),
            default      => false,
        };
    }
}
