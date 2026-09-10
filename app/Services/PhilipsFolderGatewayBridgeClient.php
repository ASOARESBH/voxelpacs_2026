<?php
// Materialização de runtime inerte da Fase 1 Philips Non-DICOM; não ativa SMB, bridge, XML ou automação.

declare(strict_types=1);

// Materialização de runtime Philips Folder: bridge privada com mTLS, HMAC e categorias sanitizadas de transporte.

namespace App\Services;

/**
 * Canal privado API -> gateway para entrega Philips Folder.
 *
 * O destino Windows, o método SMB/SFTP e as credenciais pertencem exclusivamente
 * à política root-only do gateway. Esta classe aceita somente a URL privada
 * allowlisted por variável de ambiente, mTLS e HMAC de curta duração.
 */
final class PhilipsFolderGatewayBridgeClient
{
    private const MAX_BYTES = 50 * 1024 * 1024;

    /** @return array{reference:string,sha256:string,size:int} */
    public function send(int $jobId, int $destinationId, string $fileName, string $pdfPath, int $timeout, ?array $secretEnvelope = null): array
    {
        if ($jobId <= 0 || $destinationId <= 0 || !$this->validFileName($fileName) || !is_file($pdfPath)) {
            throw new PhilipsFolderDeliveryException('invalid_artifact', 'invalid_artifact');
        }

        $size = (int) filesize($pdfPath);
        $sha256 = hash_file('sha256', $pdfPath);
        if (!is_string($sha256) || $size < 100 || $size > self::MAX_BYTES) {
            throw new PhilipsFolderDeliveryException('invalid_artifact', 'invalid_artifact');
        }

        $baseUrl = rtrim(trim((string) getenv('PHILIPS_FOLDER_BRIDGE_BASE_URL')), '/');
        $url = $baseUrl . '/v1/philips-folder/' . $jobId;
        if (!$this->allowedBridgeUrl($baseUrl, $url, $jobId)) {
            throw new PhilipsFolderDeliveryException('gateway_policy_rejected', 'gateway_policy_rejected');
        }

        $secret = trim((string) getenv('PHILIPS_FOLDER_BRIDGE_HMAC'));
        $caFile = trim((string) getenv('PHILIPS_FOLDER_BRIDGE_CA_FILE'));
        $certFile = trim((string) getenv('PHILIPS_FOLDER_BRIDGE_CERT_FILE'));
        $keyFile = trim((string) getenv('PHILIPS_FOLDER_BRIDGE_KEY_FILE'));
        if ($secret === '' || !is_file($caFile) || !is_file($certFile) || !is_file($keyFile)) {
            throw new PhilipsFolderDeliveryException('gateway_credentials_unavailable', 'credentials_unavailable');
        }

        $timestamp = (string) time();
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $envelope = $this->secretEnvelope($secretEnvelope);
        $signatureBase = implode("\n", ['POST', $path, (string) $jobId, (string) $destinationId, $fileName, $sha256, (string) $size, $timestamp, $envelope['sha256']]);
        $signature = hash_hmac('sha256', $signatureBase, $secret);
        $input = fopen($pdfPath, 'rb');
        if (!is_resource($input)) {
            throw new PhilipsFolderDeliveryException('artifact_unreadable', 'artifact_unreadable');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            fclose($input);
            throw new PhilipsFolderDeliveryException('gateway_client_unavailable', 'gateway_unavailable');
        }

        try {
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $input,
                CURLOPT_INFILESIZE => $size,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CAINFO => $caFile,
                CURLOPT_SSLCERT => $certFile,
                CURLOPT_SSLKEY => $keyFile,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/pdf',
                    'Content-Length: ' . $size,
                    'X-VOXEL-Job-ID: ' . $jobId,
                    'X-VOXEL-Destination-ID: ' . $destinationId,
                    'X-VOXEL-Filename: ' . $fileName,
                    'X-VOXEL-Timestamp: ' . $timestamp,
                    'X-VOXEL-SHA256: ' . $sha256,
                    'X-VOXEL-Signature: ' . $signature,
                    ...($envelope['value'] === '' ? [] : ['X-VOXEL-Secret-Envelope: ' . $envelope['value']]),
                ],
            ]);
            $body = curl_exec($curl);
            $errno = curl_errno($curl);
            $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curl);
            fclose($input);
        }

        if ($errno !== 0 || !is_string($body) || $httpCode !== 201) {
            $reasonCategory = $this->responseReasonCategory(is_string($body) ? $body : '');
            throw new PhilipsFolderDeliveryException('gateway_delivery_failed', $reasonCategory ?? 'gateway_delivery_failed');
        }
        $response = json_decode($body, true);
        $reference = is_array($response) ? (string) ($response['reference'] ?? '') : '';
        $remoteSha256 = is_array($response) ? (string) ($response['sha256'] ?? '') : '';
        if (preg_match('/^gateway-philips-folder:[a-f0-9]{16}$/', $reference) !== 1
            || !hash_equals($sha256, $remoteSha256)) {
            throw new PhilipsFolderDeliveryException('gateway_invalid_response', 'remote_integrity_unconfirmed');
        }

        return ['reference' => $reference, 'sha256' => $sha256, 'size' => $size];
    }

    /** @param array<string,mixed> $configuration @param array{envelope:string,sha256:string} $secretEnvelope */
    public function testSmbConnectivity(int $tenantId, int $destinationId, array $configuration, array $secretEnvelope, int $timeout): string
    {
        if ($tenantId <= 0 || $destinationId <= 0 || !PhilipsFolderDeliveryService::testEnabled()) {
            throw new PhilipsFolderDeliveryException('feature_disabled', 'feature_disabled');
        }
        $envelope = $this->secretEnvelope($secretEnvelope);
        if ($envelope['value'] === '') {
            throw new PhilipsFolderDeliveryException('credentials_unavailable', 'credentials_unavailable');
        }
        $baseUrl = rtrim(trim((string) getenv('PHILIPS_FOLDER_BRIDGE_BASE_URL')), '/');
        $url = $baseUrl . '/v1/philips-folder/smb-test/' . $destinationId;
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        if (!$this->allowedTestUrl($baseUrl, $url, $destinationId)) {
            throw new PhilipsFolderDeliveryException('gateway_policy_rejected', 'gateway_policy_rejected');
        }
        $secret = trim((string) getenv('PHILIPS_FOLDER_BRIDGE_HMAC'));
        $caFile = trim((string) getenv('PHILIPS_FOLDER_BRIDGE_CA_FILE'));
        $certFile = trim((string) getenv('PHILIPS_FOLDER_BRIDGE_CERT_FILE'));
        $keyFile = trim((string) getenv('PHILIPS_FOLDER_BRIDGE_KEY_FILE'));
        if ($secret === '' || !is_file($caFile) || !is_file($certFile) || !is_file($keyFile)) {
            throw new PhilipsFolderDeliveryException('gateway_credentials_unavailable', 'credentials_unavailable');
        }
        $configurationHash = hash('sha256', json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        $timestamp = (string) time();
        $signatureBase = implode("\n", ['POST', $path, (string) $tenantId, (string) $destinationId, $configurationHash, $envelope['sha256'], $timestamp]);
        $signature = hash_hmac('sha256', $signatureBase, $secret);
        $curl = curl_init($url);
        if ($curl === false) {
            throw new PhilipsFolderDeliveryException('gateway_client_unavailable', 'gateway_unavailable');
        }
        try {
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => '{}',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CAINFO => $caFile,
                CURLOPT_SSLCERT => $certFile,
                CURLOPT_SSLKEY => $keyFile,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Content-Length: 2',
                    'X-VOXEL-Tenant-ID: ' . $tenantId,
                    'X-VOXEL-Destination-ID: ' . $destinationId,
                    'X-VOXEL-Configuration-SHA256: ' . $configurationHash,
                    'X-VOXEL-Secret-Envelope: ' . $envelope['value'],
                    'X-VOXEL-Timestamp: ' . $timestamp,
                    'X-VOXEL-Signature: ' . $signature,
                ],
            ]);
            $body = curl_exec($curl);
            $errno = curl_errno($curl);
            $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curl);
        }
        if ($errno !== 0 || !is_string($body) || $httpCode !== 200) {
            throw new PhilipsFolderDeliveryException('gateway_smb_test_failed', $this->responseReasonCategory(is_string($body) ? $body : '') ?? 'gateway_delivery_failed');
        }
        $response = json_decode($body, true);
        if (!is_array($response) || (string) ($response['status'] ?? '') !== 'ok') {
            throw new PhilipsFolderDeliveryException('gateway_smb_test_failed', 'remote_integrity_unconfirmed');
        }
        return 'smb_connection_ok';
    }

    private function allowedBridgeUrl(string $baseUrl, string $url, int $jobId): bool
    {
        $base = parse_url($baseUrl);
        $parts = parse_url($url);
        if (!is_array($base) || !is_array($parts)) {
            return false;
        }
        return ($base['scheme'] ?? '') === 'https'
            && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === ($base['host'] ?? '')
            && (int) ($parts['port'] ?? 443) === (int) ($base['port'] ?? 443)
            && ($parts['path'] ?? '') === '/v1/philips-folder/' . $jobId
            && !isset($base['query'], $base['fragment'], $base['user'], $base['pass'])
            && !isset($parts['query'], $parts['fragment'], $parts['user'], $parts['pass']);
    }

    private function allowedTestUrl(string $baseUrl, string $url, int $destinationId): bool
    {
        $base = parse_url($baseUrl);
        $parts = parse_url($url);
        return is_array($base) && is_array($parts)
            && ($base['scheme'] ?? '') === 'https'
            && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === ($base['host'] ?? '')
            && (int) ($parts['port'] ?? 443) === (int) ($base['port'] ?? 443)
            && ($parts['path'] ?? '') === '/v1/philips-folder/smb-test/' . $destinationId
            && !isset($base['query'], $base['fragment'], $base['user'], $base['pass'])
            && !isset($parts['query'], $parts['fragment'], $parts['user'], $parts['pass']);
    }

    /** @param array{envelope?:string,sha256?:string}|null $secretEnvelope @return array{value:string,sha256:string} */
    private function secretEnvelope(?array $secretEnvelope): array
    {
        if ($secretEnvelope === null) {
            return ['value' => '', 'sha256' => ''];
        }
        $value = (string) ($secretEnvelope['envelope'] ?? '');
        $sha256 = (string) ($secretEnvelope['sha256'] ?? '');
        if ($value === '' || strlen($value) > 16384 || preg_match('/^[A-Za-z0-9+\/=]+$/', $value) !== 1 || !hash_equals(hash('sha256', $value), $sha256)) {
            throw new PhilipsFolderDeliveryException('credentials_unavailable', 'credentials_unavailable');
        }
        return ['value' => $value, 'sha256' => $sha256];
    }

    private function validFileName(string $value): bool
    {
        return preg_match('/^VOXEL_[A-Za-z0-9._-]{1,120}_[1-9][0-9]*_V[1-9][0-9]*\.(?:pdf|xml)$/', $value) === 1;
    }

    private function responseReasonCategory(string $body): ?string
    {
        $response = json_decode($body, true);
        $reason = is_array($response) ? (string) ($response['reason_category'] ?? '') : '';
        $allowed = [
            'connectivity',
            'timeout',
            'authentication',
            'host_key',
            'permission',
            'remote_io',
            'invalid_artifact',
            'configuration',
        ];
        return in_array($reason, $allowed, true) ? $reason : null;
    }
}
