<?php
/**
 * Teste temporário de autenticação SMB somente leitura.
 * Não cria job, outbox, PDF, XML nem executa put/del/rename/get.
 */

declare(strict_types=1);

chdir(dirname(__DIR__));
require __DIR__ . '/../app/bootstrap.php';

$options = getopt('', ['tenant:', 'destination:', 'confirm:']);
$tenantId = (int) ($options['tenant'] ?? 0);
$destinationId = (int) ($options['destination'] ?? 0);
$confirmation = (string) ($options['confirm'] ?? '');

if ($tenantId !== 2 || $destinationId !== 6 || $confirmation !== 'pwd-only') {
    fwrite(STDERR, "usage: --tenant=2 --destination=6 --confirm=pwd-only\n");
    exit(2);
}

$print = static function (string $key, string $value): void {
    printf("%s=%s\n", $key, preg_replace('/[^A-Za-z0-9_.:-]/', '_', $value) ?? 'sanitized');
};

$print('TENANT_ID', (string) $tenantId);
$print('DESTINATION_ID', (string) $destinationId);
$print('JOB_CREATED', 'NO');
$print('OUTBOX_CREATED', 'NO');
$print('PDF_CREATED', 'NO');
$print('SMB_WRITE', 'NO');
$print('SMB_DELETE', 'NO');
$print('SMB_RENAME', 'NO');
$print('SMB_GET', 'NO');

try {
    $repository = new \App\Repositories\ReportDeliveryRepository(\App\Core\Database::getInstance());
    $destination = $repository->findDestination($destinationId, $tenantId, true);
    if (!$destination
        || (int) ($destination['tenant_id'] ?? 0) !== $tenantId
        || (string) ($destination['transport'] ?? '') !== \App\Services\PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT
        || (string) ($destination['ambiente'] ?? '') !== 'homologacao'
        || (int) ($destination['enabled'] ?? 0) !== 1
        || (int) ($destination['disparar_na_liberacao'] ?? 0) !== 0
    ) {
        $print('DESTINATION_VALID', 'NO');
        $print('RESULT', 'FAIL');
        $print('ERROR_CATEGORY', 'destination_precondition_failed');
        exit(3);
    }

    $configuration = json_decode((string) ($destination['configuration_json'] ?? '{}'), true);
    $encryptedSecret = (string) ($destination['configuration_secret'] ?? '');
    if (!is_array($configuration) || $encryptedSecret === '') {
        $print('DESTINATION_VALID', 'NO');
        $print('CREDENTIAL_CONFIGURED', $encryptedSecret === '' ? 'NO' : 'YES');
        $print('RESULT', 'FAIL');
        $print('ERROR_CATEGORY', 'credential_or_configuration_unavailable');
        exit(3);
    }

    $print('DESTINATION_VALID', 'YES');
    $print('CREDENTIAL_CONFIGURED', 'YES');
    $result = (new \App\Services\PhilipsFolderSmbConnectivityService())->testReadOnly(
        $tenantId,
        $destinationId,
        $configuration,
        $encryptedSecret,
        (int) ($destination['timeout_seconds'] ?? 30)
    );

    foreach ($result as $key => $value) {
        $print(strtoupper((string) $key), (string) $value);
    }
    $print('DECRYPTION', 'PASS');
    $print('ENVELOPE_SEALED', 'PASS');
    exit(($result['result'] ?? 'FAIL') === 'PASS' ? 0 : 4);
} catch (\App\Services\PhilipsFolderDeliveryException $error) {
    $print('DECRYPTION', $error->stage === 'credentials_unavailable' ? 'FAIL' : 'NOT_REACHED');
    $print('ENVELOPE_SEALED', 'NOT_REACHED');
    $print('RESULT', 'FAIL');
    $print('ERROR_STAGE', $error->stage);
    $print('ERROR_CATEGORY', (string) ($error->reasonCategory ?? 'sanitized_failure'));
    exit(4);
} catch (\Throwable $error) {
    $print('RESULT', 'FAIL');
    $print('ERROR_CATEGORY', 'internal_sanitized_error');
    exit(5);
}
