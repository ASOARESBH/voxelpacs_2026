<?php
declare(strict_types=1);

$base = dirname(__DIR__);
$files = [
    'service' => $base . '/app/Services/PhilipsFolderDeliveryService.php',
    'connectivity' => $base . '/app/Services/PhilipsFolderSmbConnectivityService.php',
    'client' => $base . '/app/Services/PhilipsFolderGatewayBridgeClient.php',
    'worker' => $base . '/bin/report_delivery_worker.php',
    'bridge' => $base . '/deploy/report-delivery-gateway-bridge/philips_folder_bridge.py',
    'controller' => $base . '/app/Controllers/Platform/ReportDeliveryController.php',
    'repository' => $base . '/app/Repositories/ReportDeliveryRepository.php',
    'view' => $base . '/app/Views/platform/negocios/report_delivery.php',
];
foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "MISSING: {$name}\n");
        exit(1);
    }
}
require_once $base . '/app/autoload.php';
if (!class_exists('App\\Services\\PhilipsFolderSmbConnectivityService')) {
    fwrite(STDERR, "SMB_CONNECTIVITY_SERVICE_NOT_AUTOLOADABLE\n");
    exit(1);
}
$service = file_get_contents($files['service']);
$connectivity = file_get_contents($files['connectivity']);
$client = file_get_contents($files['client']);
$worker = file_get_contents($files['worker']);
$bridge = file_get_contents($files['bridge']);
$controller = file_get_contents($files['controller']);
$repository = file_get_contents($files['repository']);
$view = file_get_contents($files['view']);
$requiredI18n = [
    'philips_non_dicom.confirmar_teste_smb',
    'philips_non_dicom.resposta_invalida',
    'philips_non_dicom.teste_indisponivel',
];
foreach (['pt_BR', 'en', 'es'] as $locale) {
    $catalog = require $base . '/lang/' . $locale . '.php';
    foreach ($requiredI18n as $key) {
        if (!isset($catalog[$key]) || !is_string($catalog[$key]) || $catalog[$key] === '') {
            fwrite(STDERR, "MISSING_I18N: {$locale}:{$key}\n");
            exit(1);
        }
    }
}

$required = [
    [$service, "getenv('PHILIPS_FOLDER_DELIVERY_ENABLED') ?: 'false'", 'feature flag segura'],
    [$service, "public const TRANSPORT = 'philips_folder'", 'transporte Philips'],
    [$connectivity, 'namespace App\\Services;', 'namespace do serviço de conectividade SMB'],
    [$connectivity, "'gateway_unavailable'", 'falha local do envelope sanitizada'],
    [$connectivity, 'sodium_memzero($password)', 'limpeza da senha temporária'],
    [$client, 'CURLOPT_SSL_VERIFYPEER => true', 'mTLS peer verification'],
    [$client, 'X-VOXEL-Destination-ID', 'vínculo de destino'],
    [$worker, 'PHILIPS_FOLDER_REASON_CATEGORIES', 'categorias sanitizadas'],
    [$worker, 'PhilipsFolderDeliveryService::enabled()', 'claim condicionado à feature flag'],
    [$bridge, 'PHILIPS_FOLDER_MODE', 'modo de política root-only'],
    [$bridge, 'TARGET_ROOT = Path("/var/lib/voxelpacs/philips-folder-target")', 'raiz privada de destino'],
    [$bridge, 'single_test_requires_job', 'uso único de homologação'],
    [$bridge, 'os.replace(temporary, final_path)', 'gravação atômica'],
    [$bridge, 'StrictHostKeyChecking=yes', 'verificação obrigatória da host key SFTP'],
    [$bridge, 'UserKnownHostsFile=', 'known_hosts root-only do SFTP'],
    [$bridge, 'PHILIPS_FOLDER_TRANSPORT', 'seleção de transporte root-only'],
    [$bridge, 'TRANSIENT_TRANSPORT_FAILURES', 'fallback somente para falha transitória'],
    [$bridge, 'TRANSIENT_TRANSPORT_FAILURES = {"connectivity", "timeout"}', 'fallback limitado a conectividade e timeout'],
    [$bridge, 'smbclient', 'SMB pela bridge root-only'],
    [$bridge, 'temporary_smb_credentials', 'credencial SMB efêmera no gateway'],
    [$bridge, 'smb_write_probe', 'teste SMB sem artefato clínico'],
    [$controller, "'philips_folder'", 'opção de transporte'],
    [$controller, "'philips_non_dicom'", 'opção Non-DICOM PDF-only'],
    [$controller, 'testSmb', 'ação separada de teste SMB'],
    [$controller, 'PhilipsFolderDeliveryService::enabled()', 'bloqueio de ativação'],
    [$controller, 'PhilipsFolderDeliveryService::nonDicomEnabled()', 'bloqueio Non-DICOM por feature flag'],
    [$view, "t('philips_non_dicom.nome_transporte')", 'interface de destino Non-DICOM localizada'],
    [$view, "t('philips_non_dicom.testar_smb')", 'ação visual de teste SMB localizada'],
    [$view, 'smb-test-form', 'formulário SMB assíncrono'],
    [$view, 'smb-test-feedback', 'alerta local do teste SMB'],
    [$view, 'fetch(smbTestForm.action', 'requisição SMB sem navegação de página'],
    [$view, 'smb_password', 'campo de senha cifrada'],
    [$view, "t('philips_non_dicom.credencial_configurada')", 'indicador sanitizado de credencial localizado'],
    [$repository, 'credential_configured', 'booleano de presença de credencial'],
];
foreach ($required as [$content, $needle, $label]) {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "MISSING_CONTRACT: {$label}\n");
        exit(1);
    }
}
foreach (['smb://', 'ftp://', 'file://'] as $forbidden) {
    if (str_contains(strtolower($client), $forbidden) || str_contains(strtolower($service), $forbidden)) {
        fwrite(STDERR, "FORBIDDEN_PACS_TRANSPORT: {$forbidden}\n");
        exit(1);
    }
}
if (str_contains($worker, "'file_name' =>")) {
    fwrite(STDERR, "FORBIDDEN_TELEMETRY: file_name\n");
    exit(1);
}
if (str_contains($bridge, 'StrictHostKeyChecking=no')) {
    fwrite(STDERR, "FORBIDDEN_SFTP_HOST_KEY_BYPASS\n");
    exit(1);
}
if (str_contains($bridge, '0.0.0.0/0')) {
    fwrite(STDERR, "FORBIDDEN_VPN_DEFAULT_ROUTE\n");
    exit(1);
}
if (str_contains($bridge, 'mount.cifs') || str_contains($bridge, 'umount')) {
    fwrite(STDERR, "FORBIDDEN_SMB_MOUNT\n");
    exit(1);
}
if (str_contains($service, 'smbclient') || str_contains($connectivity, 'smbclient') || str_contains($client, 'smbclient')) {
    fwrite(STDERR, "FORBIDDEN_PACS_SMB_EXECUTION\n");
    exit(1);
}
echo "PHILIPS_FOLDER_STAGE1_STATIC_OK\n";
