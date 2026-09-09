<?php
declare(strict_types=1);

$base = dirname(__DIR__);
$files = [
    'service' => $base . '/app/Services/PhilipsFolderDeliveryService.php',
    'client' => $base . '/app/Services/PhilipsFolderGatewayBridgeClient.php',
    'worker' => $base . '/bin/report_delivery_worker.php',
    'bridge' => $base . '/deploy/report-delivery-gateway-bridge/philips_folder_bridge.py',
    'controller' => $base . '/app/Controllers/Platform/ReportDeliveryController.php',
];
foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "MISSING: {$name}\n");
        exit(1);
    }
}
$service = file_get_contents($files['service']);
$client = file_get_contents($files['client']);
$worker = file_get_contents($files['worker']);
$bridge = file_get_contents($files['bridge']);
$controller = file_get_contents($files['controller']);

$required = [
    [$service, "getenv('PHILIPS_FOLDER_DELIVERY_ENABLED') ?: 'false'", 'feature flag segura'],
    [$service, "public const TRANSPORT = 'philips_folder'", 'transporte Philips'],
    [$client, 'CURLOPT_SSL_VERIFYPEER => true', 'mTLS peer verification'],
    [$client, 'X-VOXEL-Destination-ID', 'vínculo de destino'],
    [$worker, 'PHILIPS_FOLDER_REASON_CATEGORIES', 'categorias sanitizadas'],
    [$worker, 'PhilipsFolderDeliveryService::enabled()', 'claim condicionado à feature flag'],
    [$bridge, 'PHILIPS_FOLDER_MODE', 'modo de política root-only'],
    [$bridge, 'TARGET_ROOT = Path("/var/lib/voxelpacs/philips-folder-target")', 'raiz privada de destino'],
    [$bridge, 'single_test_requires_job', 'uso único de homologação'],
    [$bridge, 'os.replace(temporary, final_path)', 'gravação atômica'],
    [$controller, "'philips_folder'", 'opção de transporte'],
    [$controller, 'PhilipsFolderDeliveryService::enabled()', 'bloqueio de ativação'],
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
echo "PHILIPS_FOLDER_STAGE1_STATIC_OK\n";
