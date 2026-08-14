<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$routes = file_get_contents($root . '/routes/web.php') ?: '';
$controller = file_get_contents($root . '/app/Controllers/ReportDeliveryWorkerController.php') ?: '';
$artifactService = file_get_contents($root . '/app/Services/ReportDeliveryArtifactService.php') ?: '';
$repository = file_get_contents($root . '/app/Repositories/ReportDeliveryWorkerRepository.php') ?: '';
$pdfService = file_get_contents($root . '/app/Services/ReportPdfService.php') ?: '';

$rules = [
    'rota de artefato é GET e aponta ao worker controller' => str_contains($routes, "Router::get('/api/report-delivery/jobs/{id}/artifact'"),
    'artefato exige job reservado' => str_contains($artifactService, 'findLeasedJobContext($jobId, $workerId)'),
    'artefato usa versão imutável do laudo' => str_contains($artifactService, 'loadVersionContent'),
    'artefato fica no storage privado' => str_contains($artifactService, 'storage/report_delivery'),
    'artefato registra hash SHA-256' => str_contains($artifactService, 'hash(\'sha256\', $binary)'),
    'repositório limita contexto ao worker que possui o lease' => str_contains($repository, "AND j.locked_by = :worker_id"),
    'PDF binário é reutilizável sem emitir resposta HTTP' => str_contains($pdfService, 'public function renderBinary'),
];

$failed = [];
foreach ($rules as $name => $passed) {
    echo sprintf("[%s] %s\n", $passed ? 'OK' : 'FALHOU', $name);
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
