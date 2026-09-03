<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$worker = file_get_contents($root . '/bin/report_delivery_worker.php') ?: '';
$repository = file_get_contents($root . '/app/Repositories/ReportDeliveryWorkerRepository.php') ?: '';

$rules = [
    'subprocessos possuem prazo máximo local' => str_contains($worker, 'int $timeoutSeconds = 60')
        && str_contains($worker, '$deadline = microtime(true) + $timeoutSeconds'),
    'saídas são drenadas de modo não bloqueante' => str_contains($worker, 'stream_set_blocking($pipe, false)')
        && str_contains($worker, 'proc_get_status($process)'),
    'watchdog encerra processo travado' => str_contains($worker, 'proc_terminate($process)')
        && str_contains($worker, 'proc_terminate($process, 9)'),
    'cstore recebe janela limitada' => str_contains($worker, "'cstore_failed', min(135, \$timeout + 15)"),
    'cstore classifica somente categorias permitidas' => str_contains($worker, 'CSTORE_REASON_CATEGORIES')
        && str_contains($worker, "'timeout'")
        && str_contains($worker, "'connect_failed'")
        && str_contains($worker, "'association_rejected'")
        && str_contains($worker, "'tls_required'")
        && str_contains($worker, "'command_failed'"),
    'stderr de cstore fica somente em memória limitada' => str_contains($worker, 'CSTORE_DIAGNOSTIC_MAX_BYTES = 8192')
        && str_contains($worker, 'private function drainCommandPipes')
        && str_contains($worker, '$cstoreStderr .= substr($output, 0, $remaining)'),
    'telemetria não persiste saída nem argumentos do subprocesso' => str_contains($worker, "\$metadata['reason_category'] = \$reasonCategory;")
        && !str_contains($worker, "'stderr' =>")
        && !str_contains($worker, "'stdout' =>")
        && !str_contains($worker, "'command' =>")
        && !str_contains($worker, "'arguments' =>"),
    'lease antigo não é reenfileirado pelo claim' => !str_contains($repository, "UPDATE pacs_report_delivery_jobs SET status = 'queued' WHERE status = 'processing'"),
];

$failed = [];
foreach ($rules as $name => $passed) {
    echo sprintf("[%s] %s\n", $passed ? 'OK' : 'FALHOU', $name);
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
