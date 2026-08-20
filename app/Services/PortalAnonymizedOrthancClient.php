<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use RuntimeException;

/**
 * Cliente HTTP exclusivamente para o pipeline privado de cópias anonimizadas.
 * Nunca é usado por rotas públicas nem repassa URL, identificadores ou headers
 * do Orthanc clínico ao navegador.
 */
final class PortalAnonymizedOrthancClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $username,
        private readonly ?string $password,
        private readonly int $timeout = 90,
    ) {
        if (!$this->isPrivateOrigin($baseUrl)) {
            throw new RuntimeException('O repositório de imagens deve estar em rede privada.');
        }
    }

    /** @return array<string,mixed> */
    public function anonymizeStudy(string $studyId, array $profile): array
    {
        return $this->json('/studies/' . rawurlencode($studyId) . '/anonymize', 'POST', $profile);
    }

    /** @return array<string,mixed> */
    public function study(string $studyId): array
    {
        return $this->json('/studies/' . rawurlencode($studyId));
    }

    /** @return array<string,mixed> */
    public function series(string $seriesId): array
    {
        return $this->json('/series/' . rawurlencode($seriesId));
    }

    public function downloadInstance(string $instanceId): string
    {
        return $this->raw('/instances/' . rawurlencode($instanceId) . '/file', 'GET', null, ['Accept: application/dicom']);
    }

    /**
     * Aplica o perfil de remoção explícita a uma instância já anonimizada e
     * devolve o arquivo DICOM resultante sem persistir uma nova cópia no Orthanc.
     *
     * @param array<string,mixed> $profile
     */
    public function sanitizeInstance(string $instanceId, array $profile): string
    {
        return $this->raw(
            '/instances/' . rawurlencode($instanceId) . '/anonymize',
            'POST',
            $profile,
            ['Content-Type: application/json', 'Accept: application/dicom']
        );
    }

    /** @return array<string,mixed> */
    public function uploadInstance(string $dicom): array
    {
        return $this->json('/instances', 'POST', $dicom, ['Content-Type: application/dicom', 'Accept: application/json'], true);
    }

    /** @return array<string,mixed> */
    public function sharedTags(string $studyId): array
    {
        return $this->json('/studies/' . rawurlencode($studyId) . '/shared-tags?simplify');
    }

    public function deleteStudy(string $studyId): void
    {
        $this->raw('/studies/' . rawurlencode($studyId), 'DELETE');
    }

    /**
     * Proxy restrito para o gateway. Só aceita URI já validada pelo controlador.
     * @return array{body:string,content_type:string,status:int}
     */
    public function dicomWeb(string $path, string $accept): array
    {
        $response = $this->rawWithHeaders('/dicom-web' . $path, 'GET', null, [
            'Accept: ' . $this->safeAccept($accept),
        ]);
        return [
            'body' => $response['body'],
            'content_type' => $response['content_type'],
            'status' => $response['status'],
        ];
    }

    /** @return array<string,mixed> */
    private function json(string $path, string $method = 'GET', mixed $payload = null, array $headers = [], bool $rawPayload = false): array
    {
        $body = $this->raw($path, $method, $payload, $headers, $rawPayload);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta inválida do repositório de imagens.');
        }
        return $decoded;
    }

    private function raw(string $path, string $method = 'GET', mixed $payload = null, array $headers = [], bool $rawPayload = false): string
    {
        return $this->rawWithHeaders($path, $method, $payload, $headers, $rawPayload)['body'];
    }

    /** @return array{body:string,content_type:string,status:int} */
    private function rawWithHeaders(string $path, string $method, mixed $payload = null, array $headers = [], bool $rawPayload = false): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $contentType = '';
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $this->timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$contentType): int {
                if (stripos($header, 'Content-Type:') === 0) {
                    $contentType = trim(substr($header, strlen('Content-Type:')));
                }
                return strlen($header);
            },
        ];
        if (str_starts_with($url, 'http://')) {
            // A rede privada não usa TLS neste estágio; nunca aceita origem pública.
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        if ($this->username !== null && $this->username !== '') {
            $options[CURLOPT_USERPWD] = $this->username . ':' . ($this->password ?? '');
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        }
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = $rawPayload ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (!$rawPayload && $headers === []) {
                $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Accept: application/json'];
            }
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            Logger::error('PortalAnonymizedOrthancClient: falha privada', ['method' => $method, 'path' => $path, 'error' => $error]);
            throw new RuntimeException('Falha no repositório de imagens anonimizadas.');
        }
        if ($status < 200 || $status >= 300) {
            Logger::warning('PortalAnonymizedOrthancClient: resposta recusada', ['method' => $method, 'path' => $path, 'status' => $status]);
            throw new RuntimeException('O repositório de imagens recusou a operação.');
        }
        return ['body' => $body, 'content_type' => $contentType, 'status' => $status];
    }

    private function isPrivateOrigin(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return false;
        }
        $host = strtolower((string) $parts['host']);
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }
        return preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $host) === 1;
    }

    private function safeAccept(string $accept): string
    {
        $accept = strtolower(trim(explode(',', $accept)[0] ?? ''));
        if (str_starts_with($accept, 'application/dicom+json')) {
            return 'application/dicom+json';
        }
        if (str_starts_with($accept, 'multipart/related')) {
            return 'multipart/related; type="application/octet-stream"; transfer-syntax=*';
        }
        if (in_array($accept, ['image/jpeg', 'image/png'], true)) {
            return $accept;
        }
        return 'application/dicom+json';
    }
}
