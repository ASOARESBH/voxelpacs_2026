<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function expect_delivered_path(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$paths = [
    'worker_repository' => $root . '/app/Repositories/ReportDeliveryWorkerRepository.php',
    'worker_controller' => $root . '/app/Controllers/ReportDeliveryWorkerController.php',
    'worker_bin' => $root . '/bin/report_delivery_worker.php',
    'routes' => $root . '/routes/web.php',
];
$contents = [];
foreach ($paths as $name => $path) {
    $contents[$name] = file_get_contents($path);
    expect_delivered_path(is_string($contents[$name]), "Arquivo não legível: {$name}");
}

expect_delivered_path(
    substr_count($contents['worker_repository'], "SET status = 'delivered'") === 1,
    'A gravação de delivered deve ter um único ponto no Repository'
);
expect_delivered_path(
    substr_count($contents['worker_repository'], 'completeJob(') === 1,
    'O Repository deve expor um único método completeJob'
);
expect_delivered_path(str_contains($contents['worker_controller'], 'completeJob('), 'Controller deve usar completeJob');
expect_delivered_path(str_contains($contents['worker_bin'], 'completeJob('), 'Worker deve usar completeJob');
expect_delivered_path(str_contains($contents['worker_repository'], 'completionMetadataAllows'), 'Ponto de persistência deve aplicar o guard');
expect_delivered_path(
    !preg_match('/UPDATE\s+[^;]+SET\s+[^;]*status\s*=\s*[\'\"]delivered[\'\"]/is', $contents['worker_controller']),
    'Controller não pode gravar delivered diretamente'
);
expect_delivered_path(
    !preg_match('/UPDATE\s+[^;]+SET\s+[^;]*status\s*=\s*[\'\"]delivered[\'\"]/is', $contents['worker_bin']),
    'Worker binário não pode gravar delivered diretamente'
);
expect_delivered_path(str_contains($contents['routes'], "ReportDeliveryWorkerController@complete"), 'Rota de complete deve estar auditada');

fwrite(STDOUT, "REPORT_DELIVERY_DELIVERED_PATH_STATIC_OK\n");
