<?php
// Materialização de runtime inerte da Fase 1 Philips Non-DICOM; não ativa SMB, bridge, XML ou automação.

declare(strict_types=1);

// Materialização de runtime Philips Folder: transporte desativado por padrão.

namespace App\Services;

use App\Core\Logger;

/**
 * Transporte PDF-only para a pasta Philips remota.
 *
 * Não conhece SMB/SFTP, endpoints remotos ou segredos. Esses detalhes ficam na
 * bridge privada root-only, que é o único componente autorizado a atravessar a VPN.
 */
final class PhilipsFolderDeliveryService
{
    public const TRANSPORT = 'philips_folder';
    public const NON_DICOM_TRANSPORT = 'philips_non_dicom';
    public const PROFILE_PDF_ONLY = 'pdf_only';
    public const PROFILE_SUBMISSION_DOCUMENT = 'submission_document';
    public const PROFILE_MIXED = 'mixed';

    public static function enabled(): bool
    {
        return filter_var(getenv('PHILIPS_FOLDER_DELIVERY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    /** Fase 1: desligada por padrão; a autorização de teste é independente do worker geral. */
    public static function nonDicomEnabled(): bool
    {
        return filter_var(getenv('PHILIPS_NON_DICOM_DELIVERY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    /** Teste de conectividade não cria job e exige janela própria, desligada por padrão. */
    public static function testEnabled(): bool
    {
        return filter_var(getenv('PHILIPS_NON_DICOM_SMB_TEST_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    /** Diagnóstico temporário de autenticação SMB sem operação remota de escrita. */
    public static function readOnlyTestEnabled(): bool
    {
        return filter_var(getenv('PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
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

    /** @param array<string,mixed> $job @param array<string,mixed> $configuration @param array<string,mixed> $payload @param array<string,mixed> $artifact
     * @return array{reference:string,sha256:string,size:int,filename:string}
     */
    public function deliverNonDicomPdf(array $job, array $configuration, array $payload, array $artifact, string $encryptedSecret): array
    {
        if (!self::nonDicomEnabled()) {
            throw new PhilipsFolderDeliveryException('feature_disabled', 'feature_disabled');
        }
        if (!filter_var($configuration['gateway_bridge'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (string) ($configuration['delivery_profile'] ?? '') !== 'pdf_only'
            || (string) ($configuration['transport_protocol'] ?? '') !== 'smb') {
            throw new PhilipsFolderDeliveryException('invalid_configuration', 'invalid_configuration');
        }
        $jobId = (int) ($job['id'] ?? 0);
        $destinationId = (int) ($job['destination_id'] ?? 0);
        $reportId = (int) ($job['report_id'] ?? 0);
        $reportVersion = (int) ($job['report_version'] ?? 0);
        $pdfPath = (string) ($artifact['storage_path'] ?? '');
        if ($jobId <= 0 || $destinationId <= 0 || $reportId <= 0 || $reportVersion <= 0 || !is_file($pdfPath)) {
            throw new PhilipsFolderDeliveryException('invalid_artifact', 'invalid_artifact');
        }
        $password = $this->smbPassword($encryptedSecret);
        $envelope = (new GatewaySmbSecretEnvelopeService())->seal($password, (int) ($job['tenant_id'] ?? 0), $destinationId);
        sodium_memzero($password);
        $fileName = $this->fileName($payload, $reportId, $reportVersion);
        $timeout = max(5, min(120, (int) ($job['timeout_seconds'] ?? 30)));
        $result = (new PhilipsFolderGatewayBridgeClient())->send($jobId, $destinationId, $fileName, $pdfPath, $timeout, $envelope);
        return $result + ['filename' => $fileName];
    }

    /** @return array<string,mixed> */
    public function deliverNonDicomSubmissionPackage(
        array $job,
        array $configuration,
        array $payload,
        string $encryptedSecret,
        string $workerId
    ): array {
        if (!self::nonDicomEnabled()) {
            throw new PhilipsFolderDeliveryException('feature_disabled', 'feature_disabled');
        }
        if (!filter_var($configuration['gateway_bridge'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (string) ($configuration['delivery_profile'] ?? '') !== self::PROFILE_SUBMISSION_DOCUMENT
            || (string) ($configuration['transport_protocol'] ?? '') !== 'smb') {
            throw new PhilipsFolderDeliveryException('invalid_configuration', 'invalid_configuration');
        }
        $jobId = (int) ($job['id'] ?? 0);
        $destinationId = (int) ($job['destination_id'] ?? 0);
        $reportId = (int) ($job['report_id'] ?? 0);
        $reportVersion = (int) ($job['report_version'] ?? 0);
        if ($jobId <= 0 || $destinationId <= 0 || $reportId <= 0 || $reportVersion <= 0) {
            throw new PhilipsFolderDeliveryException('invalid_artifact', 'invalid_artifact');
        }

        $password = $this->smbPassword($encryptedSecret);
        try {
            $envelope = (new GatewaySmbSecretEnvelopeService())->seal($password, (int) ($job['tenant_id'] ?? 0), $destinationId);
            try {
                $package = (new PhilipsSubmissionPackageProducer())->produce($job, $configuration, $payload, $workerId);
            } catch (PhilipsXmlFieldUnresolvedException $error) {
                Logger::warning('[PhilipsNonDicomDelivery] PHILIPS_XML_FIELD_UNRESOLVED', [
                    'job_id' => $jobId,
                    'stage' => 'xml_generation',
                    'field' => $error->field,
                ]);
                throw new PhilipsFolderDeliveryException('xml_field_unresolved', 'xml_field_unresolved');
            }
            $pdfFileName = (string) ($package->pdfArtifact['filename'] ?? '');
            $xmlFileName = $package->xmlDocument->filename;
            $timeout = max(5, min(120, (int) ($job['timeout_seconds'] ?? 30)));
            $result = (new PhilipsFolderGatewayBridgeClient())->sendSubmissionPackage(
                $jobId,
                (int) ($job['tenant_id'] ?? 0),
                $destinationId,
                $pdfFileName,
                (string) ($package->pdfArtifact['storage_path'] ?? ''),
                $xmlFileName,
                $package->xmlStoragePath,
                $timeout,
                $envelope,
                $package->xmlDocument->taskFilePath,
                $package->xmlDocument->documentTypeApplicable,
                [
                    'patient_name_components_omitted' => $package->xmlDocument->patientNameComponentsOmitted,
                    'patient_name_as_family' => $package->xmlDocument->patientNameAsFamily,
                    'tenant_id' => (int) ($job['tenant_id'] ?? 0),
                    'report_id' => $reportId,
                    'report_version' => $reportVersion,
                    'estudo_id' => (int) ($job['estudo_id'] ?? 0),
                    'destination_id' => $destinationId,
                    'ambiente' => (string) ($job['ambiente'] ?? ''),
                    'delivery_profile' => (string) ($job['delivery_profile'] ?? $configuration['delivery_profile'] ?? ''),
                    'transport' => (string) ($job['transport'] ?? ''),
                ]
            );
            return $result + [
                'xml_filename' => $xmlFileName,
                'patient_name_components_omitted' => $package->xmlDocument->patientNameComponentsOmitted,
                'patient_name_as_family' => $package->xmlDocument->patientNameAsFamily,
                'pdf_artifact' => $package->pdfArtifact,
                'xml_artifact' => [
                    'type' => 'philips_submission_xml',
                    'filename' => $package->xmlDocument->filename,
                    'sha256' => $package->xmlDocument->sha256,
                    'size' => $package->xmlDocument->size,
                    'storage_path' => $package->xmlStoragePath,
                ],
            ];
        } finally {
            sodium_memzero($password);
        }
    }

    private function smbPassword(string $encryptedSecret): string
    {
        try {
            $secret = json_decode((new ReportDeliveryCryptoService())->decrypt($encryptedSecret), true);
        } catch (\Throwable) {
            throw new PhilipsFolderDeliveryException('credentials_unavailable', 'credentials_unavailable');
        }
        $password = is_array($secret) ? (string) ($secret['smb_password'] ?? '') : '';
        if ($password === '') {
            throw new PhilipsFolderDeliveryException('credentials_unavailable', 'credentials_unavailable');
        }
        return $password;
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
