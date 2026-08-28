<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Canal autenticado API -> gateway para um artefato DICOM de devolutiva.
 *
 * O gateway aplica uma segunda allowlist independente antes de abrir a
 * associação DICOM para o receptor externo. Esta classe nunca aceita
 * redirecionamentos, URLs públicas ou credenciais vindas do navegador.
 */
final class ReportDeliveryGatewayBridgeClient
{
    /** @param array<string,mixed> $job @param array<string,mixed> $configuration @return array{reference:string,sha256:string,size:int} */
    public function send(array $job, array $configuration, string $dicomPath, int $timeout): array
    {
        $jobId = (int) ($job['id'] ?? 0);
        $tenantId = (int) ($job['tenant_id'] ?? 0);
        $destinationId = (int) ($job['destination_id'] ?? 0);
        if ($jobId <= 0 || $tenantId <= 0 || $destinationId <= 0 || !is_file($dicomPath)) {
            throw new RuntimeException('gateway_bridge_invalid_artifact');
        }

        $bridgeMode = trim((string) ($configuration['gateway_bridge_mode'] ?? 'controlled_job'));
        $bridgeUrl = trim((string) ($configuration['bridge_url'] ?? ''));
        if (!$this->allowedBridgeUrl($bridgeUrl, $bridgeMode, $jobId, $tenantId, $destinationId, $configuration)) {
            throw new RuntimeException('gateway_bridge_policy_rejected');
        }

        $sha256 = hash_file('sha256', $dicomPath);
        $size = (int) filesize($dicomPath);
        if (!is_string($sha256) || $size < 256 || $size > 20 * 1024 * 1024) {
            throw new RuntimeException('gateway_bridge_invalid_artifact');
        }

        $secret = trim((string) getenv('VOXEL_REPORT_DELIVERY_BRIDGE_HMAC'));
        $caFile = trim((string) getenv('VOXEL_REPORT_DELIVERY_BRIDGE_CA_FILE'));
        $certFile = trim((string) getenv('VOXEL_REPORT_DELIVERY_BRIDGE_CERT_FILE'));
        $keyFile = trim((string) getenv('VOXEL_REPORT_DELIVERY_BRIDGE_KEY_FILE'));
        if ($secret === '' || !is_file($caFile) || !is_file($certFile) || !is_file($keyFile)) {
            throw new RuntimeException('gateway_bridge_credentials_unavailable');
        }

        $parts = parse_url($bridgeUrl);
        $path = (string) ($parts['path'] ?? '');
        $timestamp = (string) time();
        $signatureBase = implode("\n", ['POST', $path, (string) $jobId, (string) $tenantId, (string) $destinationId, $sha256, (string) $size, $timestamp]);
        $signature = hash_hmac('sha256', $signatureBase, $secret);
        $input = fopen($dicomPath, 'rb');
        if (!is_resource($input)) {
            throw new RuntimeException('gateway_bridge_artifact_unreadable');
        }

        $curl = curl_init($bridgeUrl);
        if ($curl === false) {
            fclose($input);
            throw new RuntimeException('gateway_bridge_client_unavailable');
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
                    'Content-Type: application/dicom',
                    'Content-Length: ' . $size,
                    'X-VOXEL-Job-ID: ' . $jobId,
                    'X-VOXEL-Tenant-ID: ' . $tenantId,
                    'X-VOXEL-Destination-ID: ' . $destinationId,
                    'X-VOXEL-Timestamp: ' . $timestamp,
                    'X-VOXEL-SHA256: ' . $sha256,
                    'X-VOXEL-Signature: ' . $signature,
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
            throw new RuntimeException('gateway_bridge_delivery_failed');
        }
        $response = json_decode($body, true);
        $reference = is_array($response) ? (string) ($response['reference'] ?? '') : '';
        if (preg_match('/^gateway-cstore:[a-f0-9]{16}$/', $reference) !== 1) {
            throw new RuntimeException('gateway_bridge_invalid_response');
        }

        return ['reference' => $reference, 'sha256' => $sha256, 'size' => $size];
    }

    /** @param array<string,mixed> $configuration */
    private function allowedBridgeUrl(string $url, string $mode, int $jobId, int $tenantId, int $destinationId, array $configuration): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== '10.0.0.4'
            || (int) ($parts['port'] ?? 443) !== 9443
            || isset($parts['query'], $parts['fragment'], $parts['user'], $parts['pass'])) {
            return false;
        }
        if ($mode === 'controlled_job') {
            return (int) ($configuration['bridge_job_id'] ?? 0) === $jobId
                && ($parts['path'] ?? '') === '/v1/report-delivery/' . $jobId;
        }
        if ($mode === 'tenant_destination') {
            return (int) ($configuration['bridge_tenant_id'] ?? 0) === $tenantId
                && (int) ($configuration['bridge_destination_id'] ?? 0) === $destinationId
                && ($parts['path'] ?? '') === '/v1/report-delivery/tenant/' . $tenantId . '/destination/' . $destinationId;
        }
        return false;
    }
}
