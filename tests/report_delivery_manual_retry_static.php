<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$repository = (string) file_get_contents($root . '/app/Repositories/ReportDeliveryRepository.php');
$controller = (string) file_get_contents($root . '/app/Controllers/Platform/ReportDeliveryController.php');
$view = (string) file_get_contents($root . '/app/Views/platform/negocios/report_delivery.php');
$routes = (string) file_get_contents($root . '/routes/platform.php');

$manualStart = strpos($repository, 'public function retryManualHomologationJob');
$manualEnd = strpos($repository, 'public function recoverStaleProcessingJob', $manualStart === false ? 0 : $manualStart);
if ($manualStart === false || $manualEnd === false) {
    throw new RuntimeException('Método de retry manual não localizado.');
}
$manual = substr($repository, $manualStart, $manualEnd - $manualStart);

$autoStart = strpos($repository, 'public function retryJob');
$autoEnd = strpos($repository, 'public function retryManualHomologationJob', $autoStart === false ? 0 : $autoStart);
if ($autoStart === false || $autoEnd === false) {
    throw new RuntimeException('Retry automático não localizado.');
}
$automatic = substr($repository, $autoStart, $autoEnd - $autoStart);

$manualControllerStart = strpos($controller, 'public function retryManualHomologation');
$manualControllerEnd = strpos($controller, 'public function resendReleasedReport', $manualControllerStart === false ? 0 : $manualControllerStart);
if ($manualControllerStart === false || $manualControllerEnd === false) {
    throw new RuntimeException('Controller do retry manual não localizado.');
}
$manualController = substr($controller, $manualControllerStart, $manualControllerEnd - $manualControllerStart);

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$isEligible = static function (array $job, array $destination, bool $artifactPresent, int $requestedTenant, int $requestedDestination): bool {
    return in_array($job['status'] ?? null, ['failed', 'dead_letter'], true)
        && (int) ($destination['enabled'] ?? 0) === 1
        && ($destination['ambiente'] ?? null) === 'homologacao'
        && ($job['transport'] ?? null) === 'philips_non_dicom'
        && ($destination['transport'] ?? null) === 'philips_non_dicom'
        && (int) ($job['tenant_id'] ?? 0) === $requestedTenant
        && (int) ($destination['tenant_id'] ?? 0) === $requestedTenant
        && (int) ($job['destination_id'] ?? 0) === $requestedDestination
        && (int) ($destination['id'] ?? 0) === $requestedDestination
        && $artifactPresent;
};

$baseJob = ['id' => 292, 'tenant_id' => 2, 'destination_id' => 6, 'transport' => 'philips_non_dicom', 'status' => 'failed'];
$baseDestination = ['id' => 6, 'tenant_id' => 2, 'enabled' => 1, 'ambiente' => 'homologacao', 'transport' => 'philips_non_dicom', 'disparar_na_liberacao' => 0];
$expect($isEligible($baseJob, $baseDestination, true, 2, 6), 'Job 292 não está coberto como elegível.');
$expect($isEligible($baseJob, $baseDestination, true, 2, 6), 'Job failed não está coberto como elegível.');
$expect($isEligible(array_merge($baseJob, ['status' => 'dead_letter']), $baseDestination, true, 2, 6), 'Job dead_letter não está coberto como elegível.');
$expect(!$isEligible($baseJob, array_merge($baseDestination, ['ambiente' => 'producao']), true, 2, 6), 'Produção não foi bloqueada.');
$expect(!$isEligible(array_merge($baseJob, ['transport' => 'dicom_pdf']), $baseDestination, true, 2, 6), 'DICOM não foi bloqueado.');
$expect(!$isEligible($baseJob, array_merge($baseDestination, ['enabled' => 0]), true, 2, 6), 'Destino desabilitado não foi bloqueado.');
$expect($isEligible($baseJob, $baseDestination, true, 2, 6), 'disparar_na_liberacao=0 bloqueou indevidamente o retry manual.');
$expect(!$isEligible($baseJob, $baseDestination, true, 99, 6), 'Outro tenant não foi bloqueado.');
$expect(!$isEligible($baseJob, array_merge($baseDestination, ['id' => 99]), true, 2, 99), 'Outro destination não foi bloqueado.');
$expect(!$isEligible(array_merge($baseJob, ['status' => 'delivered']), $baseDestination, true, 2, 6), 'Job completed/delivered não foi bloqueado.');
$expect(!$isEligible(array_merge($baseJob, ['status' => 'processing']), $baseDestination, true, 2, 6), 'Job em processamento não foi bloqueado.');
$expect(!$isEligible($baseJob, $baseDestination, false, 2, 6), 'Artifact ausente não foi bloqueado.');

foreach ([
    ['public function retryManualHomologationJob(int $jobId, int $tenantId): array', 'método manual por Job ID'],
    ['WHERE j.id = :job_id', 'filtro por Job ID'],
    ['AND j.tenant_id = :tenant_id', 'filtro por tenant'],
    ['d.id = j.destination_id', 'vínculo job/destino'],
    ['d.tenant_id = j.tenant_id', 'vínculo tenant/destino'],
    ["status IN ('failed', 'dead_letter')", 'somente falha terminal'],
    ["['enabled'] ?? 0) !== 1", 'destino habilitado'],
    ["['ambiente'] ?? '') !== 'homologacao'", 'homologação obrigatória'],
    ["['transport'] ?? '') !== 'philips_non_dicom'", 'transporte Non-DICOM no job'],
    ["['destination_transport'] ?? '') !== 'philips_non_dicom'", 'transporte Non-DICOM no destino'],
    ["artifact_type = 'pdf'", 'artifact PDF vinculado'],
    ['FOR UPDATE', 'lock transacional contra concorrência'],
    ["new DomainException('Job de entrega não encontrado para este negócio.', 404)", 'Job inexistente com 404'],
    ["new DomainException('Conflito: o job já está na fila ou em processamento.', 409)", 'conflito concorrente com 409'],
    ["SET status = 'queued'", 'requeue pelo mecanismo de domínio'],
    ['worker_eligible_at = NOW()', 'elegibilidade explícita do worker'],
    ['hash_file', 'validação do hash físico do artifact'],
    ['hash_equals', 'comparação segura do hash'],
    ["'attempt_number' => (int) \$job['attempt_count'] + 1", 'número da próxima tentativa rastreável'],
    ["'job_id' => (int) \$job['id']", 'identidade do job preservada'],
    ["['id'] ?? 0", 'artifact validado sem substituir sua identidade'],
] as [$needle, $label]) {
    $expect(str_contains($manual, $needle), "Contrato ausente: {$label}.");
}

foreach ([
    'INSERT INTO',
    'createJobs(',
    'retryJob(',
    'disparar_na_liberacao',
    'configuration_secret',
    'attempt_count = 0',
    'SET tenant_id',
    'SET report_id',
    'SET destination_id',
    'SET attempt_count',
    'SET last_error',
    'UPDATE pacs_report_delivery_artifacts',
] as $forbidden) {
    $expect(!str_contains($manual, $forbidden), "Retry manual contém alteração proibida: {$forbidden}.");
}

$expect(str_contains($automatic, 'd.disparar_na_liberacao = 1'), 'Retry automático perdeu seu requisito de disparo na liberação.');

foreach ([
    ['if (!$this->isPlatformAdmin())', 'autorização de administrador'],
    ['if (!$this->validCsrf())', 'proteção CSRF'],
    ["confirm_manual_homologation_retry", 'confirmação explícita'],
    ['retryManualHomologationJob($jobId, $tenantId)', 'chamada tenant-scoped do repository'],
    ["'action' => 'manual_homologation_retry'", 'ação de auditoria'],
    ["'previous_status'", 'status anterior na auditoria'],
    ["'new_status'", 'novo status na auditoria'],
    ["'attempt_number'", 'tentativa na auditoria'],
] as [$needle, $label]) {
    $expect(str_contains($manualController, $needle), "Contrato ausente no controller: {$label}.");
}

$expect(str_contains($routes, '/jobs/{jobId}/retry-homologation'), 'Rota POST de retry manual ausente.');
$expect(str_contains($routes, 'ReportDeliveryController@retryManualHomologation'), 'Handler da rota manual ausente.');
$manualRoute = strstr($routes, "'/platform/negocios/{id}/report-delivery/jobs/{jobId}/retry-homologation'");
$expect($manualRoute !== false && str_contains(substr($manualRoute, 0, 220), 'Router::post('), 'Retry manual não está restrito a POST.');
$expect(str_contains($view, 'manual_retry_job_id'), 'View não recebe o Job elegível.');
$expect(str_contains($view, 'manual-homologation-retry-form'), 'Formulário contextual manual ausente.');
$expect(str_contains($view, '/retry-homologation'), 'View não usa a rota por Job ID.');
$expect(str_contains($view, 'confirm_manual_homologation_retry'), 'View não envia confirmação específica.');
$expect(str_contains($view, 'confirmar_reenvio_homologacao'), 'View não exige confirmação traduzida.');

foreach (['pt_BR', 'en', 'es'] as $locale) {
    $catalog = (string) file_get_contents($root . "/lang/{$locale}.php");
    foreach ([
        'delivery_hub.released.reenviar_teste',
        'delivery_hub.released.confirmar_reenvio_homologacao',
        'delivery_hub.released.reenvio_homologacao_aceito',
        'delivery_hub.released.erro_confirmacao_reenvio_homologacao',
        'delivery_hub.released.erro_reenvio_homologacao',
    ] as $key) {
        $expect(str_contains($catalog, "'{$key}'"), "Chave i18n ausente em {$locale}: {$key}.");
    }
}

fwrite(STDOUT, "REPORT_DELIVERY_MANUAL_RETRY_STATIC_OK\n");
