<?php

declare(strict_types=1);

namespace App\Core;

final class PortalHost
{
    public static function isPortal(): bool
    {
        $host = strtolower(trim(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]));
        $configured = strtolower(trim((string) (getenv('PORTAL_HOST') ?: 'portal.voxelpacs.com.br')));
        return $host !== '' && hash_equals($configured, $host);
    }

    public static function baseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? 'portal.voxelpacs.com.br'))[0]);
        return $scheme . '://' . $host;
    }
}
