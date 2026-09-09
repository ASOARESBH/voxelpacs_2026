<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Transporte PDF-only para a pasta Philips remota.
 *
 * Não conhece SMB/SFTP, endpoints remotos ou segredos. Esses detalhes ficam na
 * bridge privada root-only, que é o único componente autorizado a atravessar a VPN.
 */
final class PhilipsFolderDeliveryService
{
    public const TRANSPORT = 'philips_folder';

    public static function enabled(): bool
    {
        return filter_var(getenv('PHILIPS_FOLDER_DELIVERY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $configuration @param array<string,mixed> $payload @param array<string,mixed> $artifact
     * @return array{reference:string,sha256:string,size:int,filename:string}
     */
    public function deliver(array $job, array $configuration, array $payload, array $artifact): array
    {
        if (!self::enabled()) {
            throw new PhilipsFolderDeliveryException('feature_disabled', 'feature_disabled');
        }
        if (!filter_var($configuration['gateway_bridge'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (string) ($configuration['delivery_profile'] ?? '') !== 'pdf_only') {
            throw new PhilipsFolderDeliveryException('invalid_configuration', 'invalid_configuration');
        }

        $jobId = (int) ($job['id'] ?? 0);
        $destinationId = (int) ($job['destination_id'] ?? 0);
        $reportId = (int) ($job['report_id'] ?? 0);
        $reportVersion = (int) ($job['report_version'] ?? 0);
        $pdfPath = (string) ($artifact['storage_path'] ?? '');
        $fileName = $this->fileName($payload, $reportId, $reportVersion);
        if ($jobId <= 0 || $destinationId <= 0 || $reportId <= 0 || $reportVersion <= 0 || !is_file($pdfPath)) {
            throw new PhilipsFolderDeliveryException('invalid_artifact', 'invalid_artifact');
        }

        $timeout = max(5, min(120, (int) ($job['timeout_seconds'] ?? 30)));
        $result = (new PhilipsFolderGatewayBridgeClient())->send($jobId, $destinationId, $fileName, $pdfPath, $timeout);

        return $result + ['filename' => $fileName];
    }

    /** @param array<string,mixed> $payload */
    public function fileName(array $payload, int $reportId, int $reportVersion): string
    {
        $accession = trim((string) ($payload['accession_number'] ?? ''));
        $accession = preg_replace('/[^A-Za-z0-9._-]+/', '_', $accession) ?: '';
        $accession = trim($accession, '._-');
        if ($accession === '') {
            $accession = 'REPORT';
        }
        return sprintf('VOXEL_%s_%d_V%d.pdf', mb_substr($accession, 0, 120), $reportId, $reportVersion);
    }
}
