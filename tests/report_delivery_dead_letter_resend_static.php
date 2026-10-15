<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$repository = (string) file_get_contents($root . '/app/Repositories/ReportDeliveryRepository.php');
$controller = (string) file_get_contents($root . '/app/Controllers/Platform/ReportDeliveryController.php');
$view = (string) file_get_contents($root . '/app/Views/platform/negocios/report_delivery.php');
$routes = (string) file_get_contents($root . '/routes/platform.php');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$methodStart = strpos($repository, 'public function createAutomaticProductionJobFromDeadLetter');
$methodEnd = strpos($repository, 'private static function isAutomaticProductionPayload', $methodStart === false ? 0 : $methodStart);
$expect($methodStart !== false && $methodEnd !== false, 'Método de nova identidade dead_letter não localizado.');
$method = substr($repository, $methodStart, $methodEnd - $methodStart);

foreach ([
    ['beginTransaction()', 'transação explícita'],
    ['FOR UPDATE', 'lock pessimista do Job histórico'],
    ["['status'] ?? '') === 'dead_letter'", 'somente Job dead_letter'],
    ["['outbox_status'] ?? '') === 'dead_letter'", 'outbox terminal correspondente'],
    ["['transport'] ?? '') === 'philips_non_dicom'", 'transporte Non-DICOM'],
    ["['delivery_profile'] ?? '') === 'submission_document'", 'profile submission_document'],
    ["['ambiente'] ?? '') === 'producao'", 'destino de produção'],
    ['d.disparar_na_liberacao', 'disparo automático habilitado'],
    ['isAutomaticProductionPayload', 'payload automático'],
    ['delivery_request_id', 'ausência de Delivery Request'],
    ['artifact_type = \'pdf\'', 'artifact PDF vinculado'],
    ['hash_file', 'hash físico do artifact'],
    ['hash_equals', 'comparação segura do artifact'],
    ['ReportDeliveryClock::currentClinicalDate($this->pdo)', 'data clínica'],
    ['report-delivery-dead-letter-resend-v1', 'idempotência por Job de origem'],
    ['createOutboxIfAbsent(', 'nova outbox'],
    ['createJobs(', 'novo Job'],
    ["'resend_source_job_id'", 'rastreabilidade da origem'],
    ["\$payload['resend_reason'] = 'dead_letter'", 'motivo sanitizado'],
    ["'created' => \$created === 1", 'resultado idempotente'],
] as [$needle, $label]) {
    $expect(str_contains($method, $needle), "Contrato ausente: {$label}.");
}

foreach ([
    'UPDATE pacs_report_delivery_jobs',
    'attempt_count = 0',
    'remote_reference = NULL',
    'Bridge',
    'SMB',
] as $forbidden) {
    $expect(!str_contains($method, $forbidden), "Nova identidade contém mutação/transporte proibido: {$forbidden}.");
}

foreach ([
    'public function resendDeadLetter',
    'if (!$this->isPlatformAdmin())',
    'if (!$this->validCsrf())',
    'confirm_dead_letter_resend',
    'createAutomaticProductionJobFromDeadLetter($jobId, $tenantId)',
    "'source_job_id' => \$result['source_job_id']",
    "'new_job_id' => \$result['new_job_id']",
    "'transmission_started' => false",
] as $needle) {
    $expect(str_contains($controller, $needle), "Controller sem contrato: {$needle}.");
}

$expect(str_contains($routes, '/jobs/{jobId}/resend-dead-letter'), 'Rota dead_letter ausente.');
$expect(str_contains($routes, 'ReportDeliveryController@resendDeadLetter'), 'Handler da rota dead_letter ausente.');
$deadLetterRoute = strstr($routes, "'/platform/negocios/{id}/report-delivery/jobs/{jobId}/resend-dead-letter'");
$expect($deadLetterRoute !== false && str_contains(substr($deadLetterRoute, 0, 220), 'Router::post('), 'Rota dead_letter não está restrita a POST.');

foreach ([
    'dead_letter_resend_eligible',
    'dead-letter-resend-form',
    '/resend-dead-letter',
    'confirm_dead_letter_resend',
    'reenviar_dead_letter_ajuda',
    'confirmar_reenvio_dead_letter',
] as $needle) {
    $expect(str_contains($view, $needle), "View sem contrato: {$needle}.");
}

foreach (['pt_BR', 'en', 'es'] as $locale) {
    $catalog = (string) file_get_contents($root . "/lang/{$locale}.php");
    foreach ([
        'delivery_hub.jobs.reenviar_dead_letter_ajuda',
        'delivery_hub.jobs.confirmar_reenvio_dead_letter',
        'delivery_hub.jobs.reenvio_dead_letter_aceito',
        'delivery_hub.jobs.reenvio_dead_letter_ja_existente',
        'delivery_hub.jobs.erro_confirmacao_reenvio_dead_letter',
        'delivery_hub.jobs.erro_reenvio_dead_letter',
        'delivery_hub.jobs.erro_permissao_reenvio_dead_letter',
        'delivery_hub.jobs.erro_sessao_reenvio_dead_letter',
        'delivery_hub.jobs.erro_negocio_reenvio_dead_letter',
        'delivery_hub.jobs.erro_job_invalido_reenvio_dead_letter',
        'delivery_hub.jobs.erro_job_nao_encontrado_reenvio_dead_letter',
        'delivery_hub.jobs.erro_job_inelegivel_reenvio_dead_letter',
        'delivery_hub.jobs.erro_job_conflito_reenvio_dead_letter',
    ] as $key) {
        $expect(str_contains($catalog, "'{$key}'"), "Chave i18n ausente em {$locale}: {$key}.");
    }
}

fwrite(STDOUT, "REPORT_DELIVERY_DEAD_LETTER_RESEND_STATIC_OK\n");
