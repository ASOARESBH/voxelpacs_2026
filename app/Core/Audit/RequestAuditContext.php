<?php
declare(strict_types=1);

namespace App\Core\Audit;

final class RequestAuditContext
{
    public static function metadata(): array
    {
        $trustedProxy = filter_var($_ENV['AUDIT_TRUST_PROXY'] ?? false, FILTER_VALIDATE_BOOL);
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($trustedProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $candidate = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) $ip = $candidate;
        }

        $region = null;
        $source = null;
        foreach (['HTTP_CF_IPCOUNTRY' => 'cloudflare', 'HTTP_X_COUNTRY_CODE' => 'proxy'] as $header => $provider) {
            $value = strtoupper(trim((string) ($_SERVER[$header] ?? '')));
            if (preg_match('/^[A-Z]{2,3}$/', $value)) {
                $region = $value;
                $source = $provider;
                break;
            }
        }

        $requestId = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._-]{8,64}$/', $requestId)) $requestId = bin2hex(random_bytes(12));

        return [
            'ip' => $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            'region_code' => $region,
            'region_source' => $source,
            'request_id' => $requestId,
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
        ];
    }
}
