<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';

use App\Config\ReportDeliveryRuntimeConfig;

function expect_runtime_flag(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$managed = [
    ReportDeliveryRuntimeConfig::HUB_ENABLED,
    ReportDeliveryRuntimeConfig::REQUESTS_ENABLED,
    ReportDeliveryRuntimeConfig::PHILIPS_FOLDER_ENABLED,
    ReportDeliveryRuntimeConfig::PHILIPS_NON_DICOM_ENABLED,
    ReportDeliveryRuntimeConfig::PHILIPS_NON_DICOM_SMB_TEST_ENABLED,
    ReportDeliveryRuntimeConfig::PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED,
    ReportDeliveryRuntimeConfig::WORKER_KILL_SWITCH,
];

foreach ($managed as $name) {
    unset($_ENV[$name], $_SERVER[$name]);
    putenv($name);
}
expect_runtime_flag(
    ReportDeliveryRuntimeConfig::smbTestEnabled() === false,
    'SMB test ausente deve permanecer OFF'
);
expect_runtime_flag(
    ReportDeliveryRuntimeConfig::smbReadOnlyTestEnabled() === false,
    'SMB read-only ausente deve permanecer OFF'
);
expect_runtime_flag(
    ReportDeliveryRuntimeConfig::workerKillSwitchEnabled() === false,
    'kill switch ausente deve permanecer OFF'
);

putenv(ReportDeliveryRuntimeConfig::PHILIPS_NON_DICOM_SMB_TEST_ENABLED . '=false');
expect_runtime_flag(
    ReportDeliveryRuntimeConfig::smbTestEnabled() === false,
    'SMB test explicitamente OFF deve permanecer OFF'
);
putenv(ReportDeliveryRuntimeConfig::PHILIPS_NON_DICOM_SMB_TEST_ENABLED . '=true');
expect_runtime_flag(
    ReportDeliveryRuntimeConfig::smbTestEnabled() === true,
    'SMB test explicitamente ON deve ser reconhecido como ON'
);

putenv(ReportDeliveryRuntimeConfig::PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED . '=false');
expect_runtime_flag(
    ReportDeliveryRuntimeConfig::smbReadOnlyTestEnabled() === false,
    'SMB read-only explicitamente OFF deve permanecer OFF'
);
putenv(ReportDeliveryRuntimeConfig::PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED . '=true');
expect_runtime_flag(
    ReportDeliveryRuntimeConfig::smbReadOnlyTestEnabled() === true,
    'SMB read-only explicitamente ON deve ser reconhecido como ON'
);

putenv(ReportDeliveryRuntimeConfig::WORKER_KILL_SWITCH . '=true');
expect_runtime_flag(
    ReportDeliveryRuntimeConfig::workerKillSwitchEnabled() === true,
    'kill switch explicitamente ON deve bloquear o Worker'
);
putenv(ReportDeliveryRuntimeConfig::WORKER_KILL_SWITCH . '=false');
expect_runtime_flag(
    ReportDeliveryRuntimeConfig::workerKillSwitchEnabled() === false,
    'kill switch explicitamente OFF deve liberar somente a leitura da flag'
);
putenv(ReportDeliveryRuntimeConfig::WORKER_KILL_SWITCH . '=invalid');
expect_runtime_flag(
    ReportDeliveryRuntimeConfig::workerKillSwitchEnabled() === true,
    'valor inválido do kill switch deve falhar fechado'
);

$worker = file_get_contents($root . '/bin/report_delivery_worker.php');
$scriptPath = $root . '/scripts/configure-report-delivery-runtime.sh';
$script = file_get_contents($scriptPath);
$config = file_get_contents($root . '/app/Config/ReportDeliveryRuntimeConfig.php');
expect_runtime_flag(is_string($worker), 'Worker não pode ser lido');
expect_runtime_flag(is_string($config), 'Configuração runtime não pode ser lida');
expect_runtime_flag(
    str_contains($worker, 'ReportDeliveryRuntimeConfig::workerKillSwitchEnabled()')
        && str_contains($worker, 'worker_kill_switch_enabled')
        && str_contains($worker, 'exit(78)'),
    'Worker deve consultar o kill switch antes de reclamar/processar jobs'
);
expect_runtime_flag(
    str_contains($config, "default => \$invalidValueDefault"),
    'configuração deve tratar valores inválidos conforme o modo fail-closed'
);
expect_runtime_flag(
    str_contains($config, 'canonicalEnvironmentFile')
        && str_contains($config, 'BASE_PATH . \'/.env\''),
    'configuração deve declarar o .env raiz como fonte canônica'
);
expect_runtime_flag(
    str_contains($script, "readonly DEFAULT_ENV_FILE='/var/www/voxelpacs/app/.env'")
        && !str_contains($script, "readonly DEFAULT_ENV_FILE='/var/www/voxelpacs/.env'")
        && !str_contains($script, 'VOXEL_RUNTIME_ENV_FILE'),
    'aplicador deve usar o .env da raiz efetiva do runtime sem override secundário'
);
expect_runtime_flag(is_string($script) && is_file($scriptPath), 'aplicador versionado de flags ausente');

fwrite(STDOUT, "report_delivery_runtime_config_static: PASS\n");
