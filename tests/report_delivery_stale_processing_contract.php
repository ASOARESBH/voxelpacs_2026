<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$repository = file_get_contents($root . '/app/Repositories/ReportDeliveryRepository.php') ?: '';
$controller = file_get_contents($root . '/app/Controllers/Platform/ReportDeliveryController.php') ?: '';
$routes = file_get_contents($root . '/routes/platform.php') ?: '';
$view = file_get_contents($root . '/app/Views/platform/negocios/report_delivery.php') ?: '';

$rules = [
    'repositório restringe recuperação ao status processing' => str_contains($repository, "status = 'processing'"),
    'repositório exige lease registrado' => str_contains($repository, 'locked_at IS NOT NULL'),
    'repositório exige lease obsoleto de dez minutos' => str_contains($repository, 'DATE_SUB(NOW(), INTERVAL 10 MINUTE)'),
    'controller exige autenticação, CSRF e confirmação' => str_contains($controller, 'recoverStaleProcessing')
        && str_contains($controller, 'isPlatformAdmin()')
        && str_contains($controller, 'validCsrf()')
        && str_contains($controller, 'confirm_recover_stale'),
    'controller registra auditoria da recuperação' => str_contains($controller, 'report_delivery.stale_job_recovered'),
    'rota é administrativa e não pública' => str_contains($routes, "/report-delivery/jobs/{jobId}/recover-stale"),
    'repositório oferece quarentena terminal sem requeue' => str_contains($repository, 'quarantineStaleProcessingJob')
        && str_contains($repository, "status = 'failed'")
        && str_contains($repository, 'Lease stale colocado em quarentena administrativa'),
    'controller expõe quarentena separada e sem retry' => str_contains($controller, 'quarantineStaleProcessing')
        && str_contains($controller, 'confirm_quarantine_stale')
        && str_contains($controller, 'attempt_created\' => false'),
    'rota de quarentena é administrativa e não pública' => str_contains($routes, "/report-delivery/jobs/{jobId}/quarantine-stale"),
    'interface exibe ação somente para processing' => str_contains($view, "\$jobStatus === 'processing'")
        && str_contains($view, 'stale-action-form')
        && str_contains($view, '/quarantine-stale'),
];

$failed = [];
foreach ($rules as $name => $passed) {
    echo sprintf("[%s] %s\n", $passed ? 'OK' : 'FALHOU', $name);
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
