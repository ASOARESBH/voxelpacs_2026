<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/Platform/ReportDeliveryController.php');
$repository = (string) file_get_contents($root . '/app/Repositories/ReportDeliveryRepository.php');
$crypto = (string) file_get_contents($root . '/app/Services/ReportDeliveryCryptoService.php');
$view = (string) file_get_contents($root . '/app/Views/platform/negocios/report_delivery.php');

$required = [
    [$view, 'id="destination-secret" name="configuration_secret"', 'hidden POST field configuration_secret'],
    [$view, "secretInput.value = Object.keys(secret).length ? JSON.stringify(secret) : '';", 'empty secret preservation signal'],
    [$view, "group.querySelectorAll('[data-secret-field]').forEach", 'secret field serialization'],
    [$controller, '$_POST[\'configuration_secret\'] ?? \'\'', 'controller POST reception'],
    [$controller, '$this->crypto->encrypt($secret)', 'controller encryption'],
    [$controller, '\'secret_update\' => $secretUpdate', 'sanitized audit state'],
    [$controller, '\'secret_update\' => $secretUpdate,', 'sanitized response state'],
    [$controller, "t('philips_non_dicom.senha_alterada_sucesso')", 'updated-password success message'],
    [$controller, "t('philips_non_dicom.configuracao_salva_senha_mantida')", 'preserved-password success message'],
    [$repository, "configuration_secret = CASE WHEN :configuration_secret_check = ''", 'empty secret preservation SQL'],
    [$repository, 'ELSE :configuration_secret_value END', 'secret replacement SQL'],
    [$crypto, "private const CIPHER = 'aes-256-gcm';", 'AES-256-GCM encryption'],
];

foreach ($required as [$source, $needle, $label]) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException("MISSING_SECRET_FLOW_CONTRACT: {$label}");
    }
}

foreach (['password=', 'configuration_secret=', 'APP_SECRET=', 'secret_plaintext', 'ciphertext='] as $forbidden) {
    if (str_contains($controller, $forbidden) || str_contains($view, $forbidden)) {
        throw new RuntimeException("FORBIDDEN_SECRET_EXPOSURE_PATTERN: {$forbidden}");
    }
}

if (!str_contains($controller, '\'secret_update\' => $secretUpdate')) {
    throw new RuntimeException('MISSING_SANITIZED_SECRET_UPDATE_STATE');
}

fwrite(STDOUT, "REPORT_DELIVERY_SECRET_FLOW_STATIC_OK\n");
