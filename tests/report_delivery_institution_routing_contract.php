<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$outbox = file_get_contents($root . '/app/Services/ReportDeliveryOutboxService.php') ?: '';
$repository = file_get_contents($root . '/app/Repositories/ReportDeliveryRepository.php') ?: '';
$controller = file_get_contents($root . '/app/Controllers/Platform/ReportDeliveryController.php') ?: '';
$view = file_get_contents($root . '/app/Views/platform/negocios/report_delivery.php') ?: '';
$migration = file_get_contents($root . '/database/migrations/2026-08-14_report_delivery_destination_institutions.sql') ?: '';

$rules = [
    'outbox resolve InstitutionName canônico do tenant' => str_contains($outbox, 'InstitutionResolverService::canonicalForTenant'),
    'outbox filtra destinos pela origem e servidor PACS do estudo' => str_contains($outbox, 'findActiveDestinations($tenantId, $estabelecimentoId, $issuerNormalized, $institutionName, $sourceServerId)'),
    'consulta une tabela de vínculo de destino e InstitutionName' => str_contains($repository, 'pacs_report_delivery_destination_institutions'),
    'destino exige ao menos uma origem' => str_contains($controller, 'Selecione ao menos um Issuer ou InstitutionName de fallback'),
    'controller valida InstitutionName ativo do tenant' => str_contains($controller, 'Selecione apenas InstitutionNames ativos deste negócio.'),
    'formulário apresenta seleção de PACS e origens' => str_contains($view, 'destination-server-pacs') && str_contains($view, 'institution_names[]'),
    'migration cria unicidade destino e InstitutionName' => str_contains($migration, 'uq_delivery_destination_institution'),
];

$failed = [];
foreach ($rules as $name => $passed) {
    echo sprintf("[%s] %s\n", $passed ? 'OK' : 'FALHOU', $name);
    if (!$passed) {
        $failed[] = $name;
    }
}

exit($failed ? 1 : 0);
