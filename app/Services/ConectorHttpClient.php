<?php

namespace App\Services;

/** Cliente HTTP mínimo e restritivo para conectores administrativos globais. */
final class ConectorHttpClient
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $payload
     * @return array{ok:bool,http_code:int,response:string,json:array<string,mixed>|null,error:string|null}
     */
    public static function request(string $method, string $url, array $headers = [], ?array $payload = null): array
    {
        if (!function_exists('curl_init')) {
            return self::failure('Extensão cURL indisponível.');
        }

        $validatedUrl = self::validateUrl($url);
        if ($validatedUrl === null) {
            return self::failure('URL do conector inválida.');
        }

        $curl = curl_init($validatedUrl);
        if ($curl === false) {
            return self::failure('Não foi possível iniciar a conexão externa.');
        }

        $requestHeaders = array_merge(['Accept: application/json'], $headers);
        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $requestHeaders[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $requestHeaders;
        }
        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($response === false) {
            return self::failure($curlError !== '' ? $curlError : 'Falha de rede no conector.', $httpCode);
        }

        $body = self::sanitizeResponse((string) $response);
        $decoded = json_decode((string) $response, true);
        $json = is_array($decoded) ? $decoded : null;
        $ok = $httpCode >= 200 && $httpCode < 300;
        return [
            'ok' => $ok,
            'http_code' => $httpCode,
            'response' => $body,
            'json' => $json,
            'error' => $ok ? null : ('HTTP ' . $httpCode),
        ];
    }

    public static function validateUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user'], $parts['pass'])) {
            return null;
        }
        return $url;
    }

    public static function sanitizeResponse(string $response): string
    {
        $response = preg_replace('/("?(?:apikey|api[_-]?key|token|authorization)"?\s*[:=]\s*"?)[^",\s}]+/i', '$1[REDACTED]', $response) ?? '';
        return mb_substr(trim($response), 0, 2000);
    }

    /** @return array{ok:bool,http_code:int,response:string,json:null,error:string} */
    private static function failure(string $error, int $httpCode = 0): array
    {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'response' => '',
            'json' => null,
            'error' => mb_substr($error, 0, 500),
        ];
    }
}
