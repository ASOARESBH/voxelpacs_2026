<?php
namespace App\Core;

/** Resolve a origem pública segura para links enviados por e-mail. */
final class PublicUrl
{
    public static function base(): string
    {
        $configured = rtrim(trim(self::value('AUTH_PUBLIC_BASE_URL')), '/');
        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_URL)) {
            $parts = parse_url($configured);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if ($scheme === 'https' && !isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
                return $configured;
            }
        }

        return 'https://server.voxelpacs.com.br';
    }

    private static function value(string $name): string
    {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }

        $value = $_ENV[$name] ?? $_SERVER[$name] ?? '';
        return is_scalar($value) ? (string) $value : '';
    }
}
