<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$repositoryPath = $root . '/app/Repositories/ReportDeliveryRepository.php';
require_once $repositoryPath;
$repository = (string) file_get_contents($root . '/app/Repositories/ReportDeliveryRepository.php');
$controller = (string) file_get_contents($root . '/app/Controllers/Platform/ReportDeliveryController.php');
$view = (string) file_get_contents($root . '/app/Views/platform/negocios/report_delivery.php');
$routes = (string) file_get_contents($root . '/routes/platform.php');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$payloadGate = new ReflectionMethod('App\Repositories\ReportDeliveryRepository', 'isAutomaticProductionPayload');
$payloadGate->setAccessible(true);
$payloadIsAutomatic = static fn(mixed $payload): bool => (bool) $payloadGate->invoke(null, $payload);
$expect($payloadIsAutomatic(json_encode(['dispatch_mode' => 'automatic_production'])), 'Payload automático válido foi rejeitado.');
$expect(!$payloadIsAutomatic(json_encode(['dispatch_mode' => 'controlled_production'])), 'Payload controlado foi aceito pelo gate automático.');
$expect(!$payloadIsAutomatic(json_encode(['dispatch_mode' => 'automatic_production', 'task_site_id_alias' => 'alias'])), 'Payload com alias controlado foi aceito.');
$expect(!$payloadIsAutomatic('{invalid-json'), 'JSON inválido foi aceito pelo gate automático.');
$expect(!$payloadIsAutomatic(null), 'Payload nulo foi aceito pelo gate automático.');

$methodStart = strpos($repository, 'public function resendAutomaticProductionJob');
$methodEnd = strpos($repository, 'public function retryManualHomologationJob', $methodStart === false ? 0 : $methodStart);
$expect($methodStart !== false && $methodEnd !== false, 'Método de reenvio automático não localizado.');
$method = substr($repository, $methodStart, $methodEnd - $methodStart);

foreach ([
    ['beginTransaction()', 'transação explícita'],
    ['FOR UPDATE', 'lock pessimista do Job'],
    ['[\'queued\', \'failed\']', 'estados rearmáveis'],
    ['j.delivery_profile', 'perfil submission_document'],
    ["'producao'", 'ambiente de produção'],
    ['d.disparar_na_liberacao', 'disparo automático habilitado'],
    ['event_type', 'evento de liberação'],
    ['delivery_request_id', 'ausência de Delivery Request controlada'],
    ['worker_eligible_at = NOW()', 'elegibilidade temporal do Worker'],
    ['automatic_dispatch_date = CURRENT_DATE', 'data automática do banco'],
    ['next_attempt_at = NOW()', 'próxima tentativa imediata'],
    ['remote_reference IS NULL', 'proteção contra resultado remoto existente'],
    ['isAutomaticProductionPayload', 'validação do payload automático'],
    ["dispatch_mode'] ?? null) !== 'automatic_production'", 'dispatch_mode automático obrigatório'],
    ["task_site_id_alias", 'ausência de alias controlado'],
] as [$needle, $label]) {
    $expect(str_contains($method, $needle), "Contrato ausente: {$label}.");
}

foreach ([
    'report_versions',
    'patient_name_family',
    'patient_name_given',
    'patient_name_middle',
    'referring_physician_name',
] as $forbidden) {
    $expect(!str_contains($method, $forbidden), "O reenvio automático não pode alterar ou reconstruir o snapshot: {$forbidden}.");
}

foreach ([
    ['public function resendAutomaticProduction', 'handler administrativo'],
    ['if (!$this->isPlatformAdmin())', 'autorização de administrador'],
    ['if (!$this->validCsrf())', 'proteção CSRF'],
    ['confirm_automatic_resend', 'confirmação explícita'],
    ['resendAutomaticProductionJob($jobId, $tenantId)', 'chamada tenant-scoped'],
    ["erro_permissao_reenvio_automatico", 'erro de permissão i18n'],
    ["erro_sessao_reenvio_automatico", 'erro de sessão i18n'],
    ["erro_negocio_reenvio_automatico", 'erro de negócio i18n'],
    ["'clinical_snapshot_changed' => false", 'auditoria da imutabilidade clínica'],
    ["'transmission_started' => false", 'ausência de transmissão na tela'],
] as [$needle, $label]) {
    $expect(str_contains($controller, $needle), "Controller sem {$label}.");
}

$expect(str_contains($routes, '/jobs/{jobId}/resend-automatic'), 'Rota administrativa não localizada.');
$expect(str_contains($routes, 'ReportDeliveryController@resendAutomaticProduction'), 'Handler da rota não localizado.');
$expect(str_contains($view, 'automaticResendEligible'), 'Flag de elegibilidade não chega à view.');
$expect(str_contains($view, 'automatic-resend-form'), 'Formulário de reenvio automático ausente.');
$expect(str_contains($view, 'confirm_automatic_resend'), 'Confirmação do formulário ausente.');

foreach (['pt_BR', 'en', 'es'] as $locale) {
    $catalog = (string) file_get_contents($root . "/lang/{$locale}.php");
    foreach ([
        'delivery_hub.jobs.reenviar_automatico',
        'delivery_hub.jobs.reenviar_automatico_ajuda',
        'delivery_hub.jobs.confirmar_reenvio_automatico',
        'delivery_hub.jobs.reenvio_automatico_aceito',
        'delivery_hub.jobs.erro_confirmacao_reenvio_automatico',
        'delivery_hub.jobs.erro_reenvio_automatico',
        'delivery_hub.jobs.erro_permissao_reenvio_automatico',
        'delivery_hub.jobs.erro_sessao_reenvio_automatico',
        'delivery_hub.jobs.erro_negocio_reenvio_automatico',
        'delivery_hub.jobs.erro_job_invalido_reenvio_automatico',
        'delivery_hub.jobs.erro_job_nao_encontrado_reenvio_automatico',
        'delivery_hub.jobs.erro_job_inelegivel_reenvio_automatico',
        'delivery_hub.jobs.erro_job_conflito_reenvio_automatico',
    ] as $key) {
        $expect(str_contains($catalog, "'{$key}'"), "Chave i18n ausente em {$locale}: {$key}.");
    }
}

fwrite(STDOUT, "REPORT_DELIVERY_AUTOMATIC_RESEND_STATIC_OK\n");
