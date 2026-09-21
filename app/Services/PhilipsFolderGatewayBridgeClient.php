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
    private const CURL_DIAGNOSTICS_ENV = 'PHILIPS_FOLDER_CURL_DIAGNOSTICS';


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
            $startedAt = microtime(true);
            $body = curl_exec($curl);
            $errno = curl_errno($curl);
            $curlError = '';
            try {
                $curlError = curl_error($curl);
            } catch (\Throwable) {
                // Diagnóstico é best-effort e nunca pode alterar o resultado da entrega.
            }
            $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            try {
                $curlInfo = curl_getinfo($curl);
                $this->logCurlDiagnostics(
                    $jobId,
                    $destinationId,
                    $body,
                    $errno,
                    $curlError,
                    is_array($curlInfo) ? $curlInfo : [],
                    (int) round((microtime(true) - $startedAt) * 1000)
                );
            } catch (\Throwable) {
                // Diagnóstico é best-effort e nunca pode alterar o resultado da entrega.
            }
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

    /**
     * @param array<string,mixed>|null $homologationContext
     * @return array{reference:string,sha256:string,size:int,pdf_sha256:string,pdf_size:int,xml_sha256:string,xml_size:int,filename:string,package_identity:string,package_verified:string}
     */
    public function sendSubmissionPackage(
        int $jobId,
        int $tenantId,
        int $destinationId,
        string $pdfFileName,
        string $pdfPath,
        string $xmlFileName,
        string $xmlPath,
        int $timeout,
        ?array $secretEnvelope = null,
        ?string $taskFilePath = null,
        ?bool $taskDocumentTypeApplicable = null,
        ?array $homologationContext = null
    ): array {
        if ($jobId <= 0 || $tenantId <= 0 || $destinationId <= 0 || !$this->validFileName($pdfFileName) || !$this->validFileName($xmlFileName)
            || !is_file($pdfPath) || !is_file($xmlPath)) {
            throw new PhilipsFolderDeliveryException('invalid_artifact', 'invalid_artifact');
        }

        $pdfSize = (int) filesize($pdfPath);
        $xmlSize = (int) filesize($xmlPath);
        $pdfSha256 = hash_file('sha256', $pdfPath);
        $xmlSha256 = hash_file('sha256', $xmlPath);
        if (!is_string($pdfSha256) || !is_string($xmlSha256) || !is_string($taskFilePath) || trim($taskFilePath) === ''
            || !is_bool($taskDocumentTypeApplicable)
            || $pdfSize < 100 || $pdfSize > self::MAX_BYTES
            || $xmlSize < 32 || $xmlSize > 2 * 1024 * 1024) {
            throw new PhilipsFolderDeliveryException('invalid_artifact', 'invalid_artifact');
        }

        $packageFileName = preg_replace('/\.pdf$/', '.package', $pdfFileName);
        if (!is_string($packageFileName) || !$this->validPackageFileName($packageFileName)) {
            throw new PhilipsFolderDeliveryException('invalid_artifact', 'invalid_artifact');
        }
        try {
            $manifest = json_encode([
                'v' => 1,
                'pdf' => ['filename' => $pdfFileName, 'sha256' => $pdfSha256, 'size' => $pdfSize],
                'xml' => ['filename' => $xmlFileName, 'sha256' => $xmlSha256, 'size' => $xmlSize],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new PhilipsFolderDeliveryException('invalid_artifact', 'invalid_artifact');
        }

        $baseUrl = rtrim(trim((string) getenv('PHILIPS_FOLDER_BRIDGE_BASE_URL')), '/');
        $url = $baseUrl . '/v1/philips-folder/package/' . $jobId;
        if (!$this->allowedPackageUrl($baseUrl, $url, $jobId)) {
            throw new PhilipsFolderDeliveryException('gateway_policy_rejected', 'gateway_policy_rejected');
        }
        $secret = trim((string) getenv('PHILIPS_FOLDER_BRIDGE_HMAC'));
        $caFile = trim((string) getenv('PHILIPS_FOLDER_BRIDGE_CA_FILE'));
        $certFile = trim((string) getenv('PHILIPS_FOLDER_BRIDGE_CERT_FILE'));
        $keyFile = trim((string) getenv('PHILIPS_FOLDER_BRIDGE_KEY_FILE'));
        if ($secret === '' || !is_file($caFile) || !is_file($certFile) || !is_file($keyFile)) {
            throw new PhilipsFolderDeliveryException('gateway_credentials_unavailable', 'credentials_unavailable');
        }
        $envelope = $this->secretEnvelope($secretEnvelope);
        $timestamp = (string) time();
        $package = tmpfile();
        $pdfInput = fopen($pdfPath, 'rb');
        $xmlInput = fopen($xmlPath, 'rb');
        if (!is_resource($package) || !is_resource($pdfInput) || !is_resource($xmlInput)) {
            if (is_resource($package)) {
                fclose($package);
            }
            if (is_resource($pdfInput)) {
                fclose($pdfInput);
            }
            if (is_resource($xmlInput)) {
                fclose($xmlInput);
            }
            throw new PhilipsFolderDeliveryException('artifact_unreadable', 'artifact_unreadable');
        }
        try {
            if (fwrite($package, $manifest . "\n") !== strlen($manifest) + 1
                || stream_copy_to_stream($pdfInput, $package) !== $pdfSize
                || fwrite($package, "\n") !== 1
                || stream_copy_to_stream($xmlInput, $package) !== $xmlSize) {
                throw new PhilipsFolderDeliveryException('artifact_unreadable', 'artifact_unreadable');
            }
            fflush($package);
            $packageSize = (int) (fstat($package)['size'] ?? 0);
            $packagePath = (string) (stream_get_meta_data($package)['uri'] ?? '');
            $packageSha256 = hash_file('sha256', $packagePath);
            if (!is_string($packageSha256) || $packageSize < 1 || $packageSize > self::MAX_BYTES) {
                throw new PhilipsFolderDeliveryException('invalid_artifact', 'invalid_artifact');
            }
            rewind($package);
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
            $taskFilePathSha256 = hash('sha256', $taskFilePath);
            $taskDocumentTypeApplicableValue = $taskDocumentTypeApplicable ? '1' : '0';
            $patientNameException = is_array($homologationContext)
                && (($homologationContext['patient_name_components_omitted'] ?? false) === true);
            $patientNameAsFamily = is_array($homologationContext)
                && (($homologationContext['patient_name_as_family'] ?? false) === true);
            if ($patientNameException && !PhilipsSubmissionHomologationPolicy::allows($homologationContext)) {
                throw new PhilipsFolderDeliveryException('gateway_policy_rejected', 'gateway_policy_rejected');
            }
            if ($patientNameAsFamily && !PhilipsSubmissionHomologationPolicy::allowsPatientNameAsFamily($homologationContext)) {
                throw new PhilipsFolderDeliveryException('gateway_policy_rejected', 'gateway_policy_rejected');
            }
            if ($patientNameException && $patientNameAsFamily) {
                throw new PhilipsFolderDeliveryException('gateway_policy_rejected', 'gateway_policy_rejected');
            }
            $signatureParts = [
                'POST', $path, (string) $jobId, (string) $tenantId, (string) $destinationId,
                $packageFileName, $packageSha256, (string) $packageSize,
                $pdfFileName, $pdfSha256, (string) $pdfSize,
                $xmlFileName, $xmlSha256, (string) $xmlSize,
                $taskFilePathSha256, $taskDocumentTypeApplicableValue, $timestamp,
            ];
            if ($patientNameException) {
                $signatureParts = array_merge($signatureParts, [
                    'patient_name_components_omitted',
                    (string) ($homologationContext['report_id'] ?? 0),
                    (string) ($homologationContext['report_version'] ?? 0),
                    (string) ($homologationContext['estudo_id'] ?? 0),
                    (string) ($homologationContext['ambiente'] ?? ''),
                    (string) ($homologationContext['delivery_profile'] ?? ''),
                    (string) ($homologationContext['transport'] ?? ''),
                ]);
            }
            if ($patientNameAsFamily) {
                $signatureParts = array_merge($signatureParts, [
                    'patient_name_as_family',
                    (string) ($homologationContext['report_id'] ?? 0),
                    (string) ($homologationContext['report_version'] ?? 0),
                    (string) ($homologationContext['estudo_id'] ?? 0),
                    (string) ($homologationContext['ambiente'] ?? ''),
                    (string) ($homologationContext['delivery_profile'] ?? ''),
                    (string) ($homologationContext['transport'] ?? ''),
                ]);
            }
            $signatureParts[] = $envelope['sha256'];
            $signatureBase = implode("\n", $signatureParts);
            $signature = hash_hmac('sha256', $signatureBase, $secret);
            $curl = curl_init($url);
            if ($curl === false) {
                throw new PhilipsFolderDeliveryException('gateway_client_unavailable', 'gateway_unavailable');
            }
            try {
                curl_setopt_array($curl, [
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_UPLOAD => true,
                    CURLOPT_INFILE => $package,
                    CURLOPT_INFILESIZE => $packageSize,
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
                        'Content-Type: application/vnd.voxel.philips.package',
                        'Content-Length: ' . $packageSize,
                        'X-VOXEL-Job-ID: ' . $jobId,
                        'X-VOXEL-Tenant-ID: ' . $tenantId,
                        'X-VOXEL-Destination-ID: ' . $destinationId,
                        'X-VOXEL-Filename: ' . $packageFileName,
                        'X-VOXEL-SHA256: ' . $packageSha256,
                        'X-VOXEL-PDF-Filename: ' . $pdfFileName,
                        'X-VOXEL-PDF-SHA256: ' . $pdfSha256,
                        'X-VOXEL-PDF-Size: ' . $pdfSize,
                        'X-VOXEL-XML-Filename: ' . $xmlFileName,
                        'X-VOXEL-XML-SHA256: ' . $xmlSha256,
                        'X-VOXEL-XML-Size: ' . $xmlSize,
                        'X-VOXEL-XML-TASK-FILE-PATH-SHA256: ' . $taskFilePathSha256,
                        'X-VOXEL-XML-DOCUMENT-TYPE-APPLICABLE: ' . $taskDocumentTypeApplicableValue,
                        'X-VOXEL-Timestamp: ' . $timestamp,
                        ...($patientNameException ? [
                            'X-VOXEL-Patient-Name-Components-Omitted: 1',
                            'X-VOXEL-Report-ID: ' . (int) ($homologationContext['report_id'] ?? 0),
                            'X-VOXEL-Report-Version: ' . (int) ($homologationContext['report_version'] ?? 0),
                            'X-VOXEL-Estudo-ID: ' . (int) ($homologationContext['estudo_id'] ?? 0),
                            'X-VOXEL-Environment: ' . (string) ($homologationContext['ambiente'] ?? ''),
                            'X-VOXEL-Delivery-Profile: ' . (string) ($homologationContext['delivery_profile'] ?? ''),
                            'X-VOXEL-Transport: ' . (string) ($homologationContext['transport'] ?? ''),
                        ] : []),
                        ...($patientNameAsFamily ? [
                            'X-VOXEL-Patient-Name-As-Family: 1',
                            'X-VOXEL-Report-ID: ' . (int) ($homologationContext['report_id'] ?? 0),
                            'X-VOXEL-Report-Version: ' . (int) ($homologationContext['report_version'] ?? 0),
                            'X-VOXEL-Estudo-ID: ' . (int) ($homologationContext['estudo_id'] ?? 0),
                            'X-VOXEL-Environment: ' . (string) ($homologationContext['ambiente'] ?? ''),
                            'X-VOXEL-Delivery-Profile: ' . (string) ($homologationContext['delivery_profile'] ?? ''),
                            'X-VOXEL-Transport: ' . (string) ($homologationContext['transport'] ?? ''),
                        ] : []),
                        'X-VOXEL-Signature: ' . $signature,
                        ...($envelope['value'] === '' ? [] : ['X-VOXEL-Secret-Envelope: ' . $envelope['value']]),
                    ],
                ]);
                $startedAt = microtime(true);
                $body = curl_exec($curl);
                $errno = curl_errno($curl);
                $curlError = '';
                try {
                    $curlError = curl_error($curl);
                } catch (\Throwable) {
                }
                $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                try {
                    $curlInfo = curl_getinfo($curl);
                    $this->logCurlDiagnostics($jobId, $destinationId, $body, $errno, $curlError, is_array($curlInfo) ? $curlInfo : [], (int) round((microtime(true) - $startedAt) * 1000));
                } catch (\Throwable) {
                }
            } finally {
                curl_close($curl);
            }
            if ($errno !== 0 || !is_string($body) || $httpCode !== 201) {
                throw new PhilipsFolderDeliveryException('gateway_delivery_failed', $this->responseReasonCategory(is_string($body) ? $body : '') ?? 'gateway_delivery_failed');
            }
            $response = json_decode($body, true);
            $reference = is_array($response) ? (string) ($response['reference'] ?? '') : '';
            $remoteSha256 = is_array($response) ? (string) ($response['sha256'] ?? '') : '';
            $remoteIdentity = is_array($response) ? (string) ($response['package_identity'] ?? '') : '';
            $packageVerified = is_array($response) ? (string) ($response['package_verified'] ?? '') : '';
            if (preg_match('/^gateway-philips-folder:[a-f0-9]{16}$/', $reference) !== 1
                || !hash_equals($packageSha256, $remoteSha256)
                || !hash_equals($packageSha256, $remoteIdentity)
                || $packageVerified !== 'PASS') {
                throw new PhilipsFolderDeliveryException('gateway_invalid_response', 'remote_integrity_unconfirmed');
            }
            return [
                'reference' => $reference,
                'sha256' => $packageSha256,
                'size' => $packageSize,
                'pdf_sha256' => $pdfSha256,
                'pdf_size' => $pdfSize,
                'xml_sha256' => $xmlSha256,
                'xml_size' => $xmlSize,
                'filename' => $packageFileName,
                'package_identity' => $packageSha256,
                'package_verified' => $packageVerified,
            ];
        } finally {
            fclose($package);
            fclose($pdfInput);
            fclose($xmlInput);
        }
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

    /** @return array<string, string> Resultado sanitizado; não envia PDF e não executa escrita remota. */
    public function testSmbAuthenticationReadOnly(int $tenantId, int $destinationId, array $configuration, array $secretEnvelope, int $timeout): array
    {
        if ($tenantId <= 0 || $destinationId <= 0 || !PhilipsFolderDeliveryService::readOnlyTestEnabled()) {
            throw new PhilipsFolderDeliveryException('feature_disabled', 'feature_disabled');
        }
        $envelope = $this->secretEnvelope($secretEnvelope);
        if ($envelope['value'] === '') {
            throw new PhilipsFolderDeliveryException('credentials_unavailable', 'credentials_unavailable');
        }
        $baseUrl = rtrim(trim((string) getenv('PHILIPS_FOLDER_BRIDGE_BASE_URL')), '/');
        $url = $baseUrl . '/v1/philips-folder/smb-auth-test/' . $destinationId;
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        if (!$this->allowedTestUrl($baseUrl, $url, $destinationId, '/v1/philips-folder/smb-auth-test/')) {
            throw new PhilipsFolderDeliveryException('gateway_policy_rejected', 'gateway_policy_rejected');
        }
        $secret = trim((string) getenv('PHILIPS_FOLDER_BRIDGE_HMAC'));
        $caFile = trim((string) getenv('PHILIPS_FOLDER_BRIDGE_CA_FILE'));
        $certFile = trim((string) getenv('PHILIPS_FOLDER_BRIDGE_CERT_FILE'));
        $keyFile = trim((string) getenv('PHILIPS_FOLDER_BRIDGE_KEY_FILE'));
        if ($secret === '' || !is_file($caFile) || !is_file($certFile) || !is_file($keyFile)) {
            throw new PhilipsFolderDeliveryException('gateway_credentials_unavailable', 'credentials_unavailable');
        }
        $bridgeConfiguration = [
            'host' => (string) ($configuration['host'] ?? ''),
            'port' => 445,
            'share' => (string) ($configuration['smb_share'] ?? $configuration['share'] ?? ''),
            'username' => (string) ($configuration['smb_username'] ?? $configuration['username'] ?? ''),
        ];
        $configurationHash = hash('sha256', json_encode($bridgeConfiguration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
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
        $result = [
            'http_status' => (string) $httpCode,
            'curl_result' => $errno === 0 && is_string($body) ? 'RESPONSE' : 'ERROR',
            'curl_errno' => (string) $errno,
            'smb_return_code' => 'unknown',
            'smb_classification' => $errno === 0 && is_string($body)
                ? ($this->responseReasonCategory($body) ?? 'unknown')
                : $this->curlFailureCategory($errno, ''),
            'smb_auth' => 'FAIL',
            'smb_pwd' => 'FAIL',
            'nt_status_logon_failure' => 'UNKNOWN',
        ];
        $response = is_string($body) ? json_decode($body, true) : null;
        if (is_array($response)) {
            foreach (['smb_return_code', 'smb_classification', 'smb_auth', 'smb_pwd', 'nt_status_logon_failure'] as $key) {
                if (isset($response[$key]) && is_scalar($response[$key])) {
                    $result[$key] = (string) $response[$key];
                }
            }
        }
        $result['result'] = $errno === 0
            && is_string($body)
            && $httpCode === 200
            && is_array($response)
            && (string) ($response['status'] ?? '') === 'ok'
            && (string) ($response['pwd'] ?? '') === 'confirmed'
            ? 'PASS'
            : 'FAIL';
        return $result;
    }

    private function logCurlDiagnostics(
        int $jobId,
        int $destinationId,
        mixed $body,
        int $errno,
        string $curlError,
        array $curlInfo,
        int $durationMs
    ): void {
        if (getenv(self::CURL_DIAGNOSTICS_ENV) !== '1') {
            return;
        }

        $isResponse = is_string($body);
        $httpCode = (int) ($curlInfo['http_code'] ?? 0);
        $category = $isResponse ? $this->httpStatusCategory($httpCode) : $this->curlFailureCategory($errno, $curlError);
        \App\Core\Logger::info('[PhilipsFolderGatewayBridgeClient] CURL_DIAGNOSTIC_TEMP', [
            'job_id' => $jobId,
            'destination_id' => $destinationId,
            'duration_ms' => max(0, $durationMs),
            'curl_result' => $isResponse ? 'RESPONSE' : 'ERROR',
            'curl_errno' => $errno,
            'curl_error_category' => $isResponse ? 'none' : $category,
            'curl_error_detail_sanitized' => $isResponse ? 'none' : $this->sanitizedCurlError($curlError),
            'http_status' => $httpCode,
            'http_status_category' => $isResponse ? $category : 'none',
            'primary_ip' => $this->safeInfoValue($curlInfo, 'primary_ip'),
            'local_ip' => $this->safeInfoValue($curlInfo, 'local_ip'),
            'connect_time_ms' => $this->infoMilliseconds($curlInfo, 'connect_time'),
            'appconnect_time_ms' => $this->infoMilliseconds($curlInfo, 'appconnect_time'),
            'starttransfer_time_ms' => $this->infoMilliseconds($curlInfo, 'starttransfer_time'),
            'total_time_ms' => $this->infoMilliseconds($curlInfo, 'total_time'),
            'response_size_bytes' => $isResponse ? strlen($body) : 0,
        ]);
    }

    private function curlFailureCategory(int $errno, string $curlError): string
    {
        if (in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_RESOLVE_PROXY], true)) {
            return 'DNS_OR_ROUTE_FAILURE';
        }
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            return 'TIMEOUT';
        }
        if (in_array($errno, [CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CACERT, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CIPHER], true)) {
            return 'TLS_MTLS_FAILURE';
        }
        if ($errno === CURLE_COULDNT_CONNECT) {
            return 'TCP_CONNECT_FAILURE';
        }
        if (in_array($errno, [CURLE_GOT_NOTHING, CURLE_RECV_ERROR, CURLE_SEND_ERROR], true)) {
            return 'CONNECTION_CLOSED';
        }
        $message = strtolower($curlError);
        if (str_contains($message, 'certificate') || str_contains($message, 'ssl') || str_contains($message, 'tls')) {
            return 'TLS_MTLS_FAILURE';
        }
        return 'OTHER_CURL_FAILURE';
    }

    private function sanitizedCurlError(string $curlError): string
    {
        $message = strtolower(trim($curlError));
        if ($message === '') {
            return 'NO_ERROR_MESSAGE';
        }
        if (str_contains($message, 'certificate') || str_contains($message, 'ssl') || str_contains($message, 'tls')) {
            return 'TLS_ERROR_DETAIL';
        }
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'TIMEOUT_DETAIL';
        }
        if (str_contains($message, 'resolve') || str_contains($message, 'route')) {
            return 'DNS_OR_ROUTE_DETAIL';
        }
        if (str_contains($message, 'connect')) {
            return 'TCP_CONNECT_DETAIL';
        }
        if (str_contains($message, 'empty reply') || str_contains($message, 'reset')) {
            return 'CONNECTION_CLOSED_DETAIL';
        }
        return 'OTHER_CURL_DETAIL';
    }

    private function httpStatusCategory(int $httpCode): string
    {
        return match (true) {
            $httpCode >= 200 && $httpCode < 300 => 'HTTP_2XX',
            $httpCode >= 300 && $httpCode < 400 => 'HTTP_3XX',
            $httpCode >= 400 && $httpCode < 500 => 'HTTP_4XX',
            $httpCode >= 500 && $httpCode < 600 => 'HTTP_5XX',
            default => 'HTTP_OTHER',
        };
    }

    private function infoMilliseconds(array $curlInfo, string $key): int
    {
        return max(0, (int) round(((float) ($curlInfo[$key] ?? 0)) * 1000));
    }

    private function safeInfoValue(array $curlInfo, string $key): string
    {
        $value = trim((string) ($curlInfo[$key] ?? ''));
        return $value === '' ? 'absent' : $value;
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

    private function allowedPackageUrl(string $baseUrl, string $url, int $jobId): bool
    {
        $base = parse_url($baseUrl);
        $parts = parse_url($url);
        return is_array($base) && is_array($parts)
            && ($base['scheme'] ?? '') === 'https'
            && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === ($base['host'] ?? '')
            && (int) ($parts['port'] ?? 443) === (int) ($base['port'] ?? 443)
            && ($parts['path'] ?? '') === '/v1/philips-folder/package/' . $jobId
            && !isset($base['query'], $base['fragment'], $base['user'], $base['pass'])
            && !isset($parts['query'], $parts['fragment'], $parts['user'], $parts['pass']);
    }

    private function allowedTestUrl(string $baseUrl, string $url, int $destinationId, string $pathPrefix = '/v1/philips-folder/smb-test/'): bool
    {
        $base = parse_url($baseUrl);
        $parts = parse_url($url);
        return is_array($base) && is_array($parts)
            && ($base['scheme'] ?? '') === 'https'
            && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === ($base['host'] ?? '')
            && (int) ($parts['port'] ?? 443) === (int) ($base['port'] ?? 443)
            && ($parts['path'] ?? '') === $pathPrefix . $destinationId
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

    private function validPackageFileName(string $value): bool
    {
        return preg_match('/^VOXEL_[A-Za-z0-9._-]{1,120}_[1-9][0-9]*_V[1-9][0-9]*\.package$/', $value) === 1;
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
