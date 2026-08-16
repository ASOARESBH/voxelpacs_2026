<?php

declare(strict_types=1);

namespace App\Core;

final class PatientPortalSession
{
    private const KEY = 'patient_portal';
    private const IDLE_SECONDS = 1800;

    /** @param array<string,mixed> $identity */
    public static function start(array $identity, int $tenantId, string $institutionName, string $databaseToken): void
    {
        session_regenerate_id(true);
        $_SESSION[self::KEY] = [
            'identity_hash' => (string) $identity['identity_hash'],
            'patient_name_normalized' => (string) $identity['patient_name_normalized'],
            'patient_birth_date' => (string) $identity['patient_birth_date'],
            'patient_sex' => (string) $identity['patient_sex'],
            'tenant_id' => $tenantId,
            'institution_name' => $institutionName,
            'database_token' => $databaseToken,
            'last_seen_at' => time(),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function current(): ?array
    {
        $scope = $_SESSION[self::KEY] ?? null;
        if (!is_array($scope) || empty($scope['database_token']) || empty($scope['identity_hash'])) return null;
        if ((int) ($scope['last_seen_at'] ?? 0) < (time() - self::IDLE_SECONDS)) {
            self::destroy();
            return null;
        }
        $_SESSION[self::KEY]['last_seen_at'] = time();
        return $_SESSION[self::KEY];
    }

    public static function destroy(): void
    {
        unset($_SESSION[self::KEY]);
    }
}
