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
expect_runtime_flag(ReportDeliveryRuntimeConfig::smbTestEnabled() === false, 'SMB test ausente deve permanecer OFF');
expect_runtime_flag(ReportDeliveryRuntimeConfig::smbReadOnlyTestEnabled() === false, 'SMB read-only ausente deve permanecer OFF');
expect_runtime_flag(ReportDeliveryRuntimeConfig::workerKillSwitchEnabled() === false, 'kill switch ausente deve permanecer OFF');
putenv(ReportDeliveryRuntimeConfig::PHILIPS_NON_DICOM_SMB_TEST_ENABLED . '=false');
expect_runtime_flag(ReportDeliveryRuntimeConfig::smbTestEnabled() === false, 'SMB test OFF deve permanecer OFF');
putenv(ReportDeliveryRuntimeConfig::PHILIPS_NON_DICOM_SMB_TEST_ENABLED . '=true');
expect_runtime_flag(ReportDeliveryRuntimeConfig::smbTestEnabled() === true, 'SMB test ON deve ser reconhecido');
putenv(ReportDeliveryRuntimeConfig::PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED . '=true');
expect_runtime_flag(ReportDeliveryRuntimeConfig::smbReadOnlyTestEnabled() === true, 'SMB read-only ON deve ser reconhecido');
putenv(ReportDeliveryRuntimeConfig::WORKER_KILL_SWITCH . '=true');
expect_runtime_flag(ReportDeliveryRuntimeConfig::workerKillSwitchEnabled() === true, 'kill switch ON deve bloquear o Worker');
putenv(ReportDeliveryRuntimeConfig::WORKER_KILL_SWITCH . '=invalid');
expect_runtime_flag(ReportDeliveryRuntimeConfig::workerKillSwitchEnabled() === true, 'kill switch inválido deve falhar fechado');
$worker = file_get_contents($root . '/bin/report_delivery_worker.php');
$scriptPath = $root . '/scripts/configure-report-delivery-runtime.sh';
$script = file_get_contents($scriptPath);
$config = file_get_contents($root . '/app/Config/ReportDeliveryRuntimeConfig.php');
expect_runtime_flag(is_string($worker) && is_string($script) && is_string($config), 'Runtime sources must be readable');
expect_runtime_flag(str_contains($worker, 'ReportDeliveryRuntimeConfig::workerKillSwitchEnabled()') && str_contains($worker, 'exit(78)'), 'Worker must check kill switch before processing');
expect_runtime_flag(str_contains($config, 'canonicalEnvironmentFile') && str_contains($config, "BASE_PATH . '/.env'"), 'Config must use canonical env');
expect_runtime_flag(str_contains($script, "readonly DEFAULT_ENV_FILE='/var/www/voxelpacs/app/.env'") && !str_contains($script, 'PHILIPS_AUTHOR_FALLBACK'), 'Flag applier must not expose author fallback keys');
echo "report_delivery_runtime_config_static: PASS\n";
