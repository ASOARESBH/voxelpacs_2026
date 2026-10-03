<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$repository = (string) file_get_contents($root . '/app/Repositories/ReportDeliveryRepository.php');
$view = (string) file_get_contents($root . '/app/Views/platform/negocios/report_delivery.php');
$routes = (string) file_get_contents($root . '/routes/platform.php');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$listStart = strpos($repository, 'public function listJobs');
$listEnd = strpos($repository, 'public function listReleasedDeliveries', $listStart === false ? 0 : $listStart);
$expect($listStart !== false && $listEnd !== false, 'Projeção listJobs não localizada.');
$listJobs = substr($repository, $listStart, $listEnd - $listStart);

foreach ([
    ['e.patient_name', 'nome clínico da fonte do estudo'],
    ['e.patient_name_display', 'nome normalizado da fonte do estudo'],
    ['e.tags_raw', 'fallback DICOM da fonte do estudo'],
    ['e.accession_number', 'accession number da fonte do estudo'],
    ['DicomPersonName::displayFromStudy', 'formatação DICOM na camada de apresentação'],
    ['e.tenant_id = j.tenant_id', 'isolamento tenant-scoped do estudo'],
    ['d.tenant_id = j.tenant_id', 'isolamento tenant-scoped do destino'],
    ['o.tenant_id = j.tenant_id', 'isolamento tenant-scoped da outbox'],
    ['manual_retry_eligible', 'elegibilidade contextual do botão'],
    ["j.status IN ('failed', 'dead_letter')", 'falha terminal obrigatória'],
    ["d.ambiente = 'homologacao'", 'homologação obrigatória'],
    ["d.transport = 'philips_non_dicom'", 'transporte Non-DICOM obrigatório'],
    ["a.artifact_type = 'pdf'", 'artifact PDF obrigatório'],
] as [$needle, $label]) {
    $expect(str_contains($listJobs, $needle), "Contrato ausente: {$label}.");
}

foreach ([
    ['delivery_hub.jobs.coluna_paciente', 'coluna paciente'],
    ['delivery_hub.jobs.coluna_accession', 'coluna accession'],
    ['delivery_hub.jobs.coluna_acoes', 'coluna de ações'],
    ['manual-homologation-retry-form', 'formulário de reenvio contextual'],
    ['/retry-homologation', 'rota de reenvio existente'],
    ['confirm_manual_homologation_retry', 'confirmação explícita do reenvio'],
    ['manual_retry_eligible', 'flag de elegibilidade'],
] as [$needle, $label]) {
    $expect(str_contains($view, $needle), "View sem {$label}.");
}

$expect(str_contains($routes, '/jobs/{jobId}/retry-homologation'), 'Rota manual por Job não localizada.');

foreach (['pt_BR', 'en', 'es'] as $locale) {
    $catalog = (string) file_get_contents($root . "/lang/{$locale}.php");
    foreach ([
        'delivery_hub.jobs.coluna_paciente',
        'delivery_hub.jobs.coluna_accession',
        'delivery_hub.jobs.coluna_acoes',
        'delivery_hub.jobs.vazio',
        'delivery_hub.jobs.sem_accession',
        'delivery_hub.jobs.sem_acao',
        'delivery_hub.jobs.reenviar_homologacao',
        'delivery_hub.jobs.reenviar_homologacao_ajuda',
    ] as $key) {
        $expect(str_contains($catalog, "'{$key}'"), "Chave i18n ausente em {$locale}: {$key}.");
    }
}

foreach (['INSERT INTO', 'CREATE TABLE', 'ALTER TABLE', 'DELETE FROM', 'UPDATE pacs_report_delivery_jobs'] as $forbidden) {
    $expect(!str_contains($listJobs, $forbidden), "listJobs contém mutação proibida: {$forbidden}.");
}

fwrite(STDOUT, "REPORT_DELIVERY_JOBS_CONTEXT_STATIC_OK\n");
