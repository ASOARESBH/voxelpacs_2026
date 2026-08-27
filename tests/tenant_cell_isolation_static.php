<?php
/**
 * Verificação estática de invariantes para células Orthanc segregadas.
 * Não requer banco, Orthanc, rede ou objetos DICOM.
 */
$root = dirname(__DIR__);
$files = [
    'routing' => $root . '/app/Services/PacsRoutingService.php',
    'sync' => $root . '/app/Services/PacsSyncService.php',
    'desktop' => $root . '/app/Services/DesktopViewerService.php',
    'estudos' => $root . '/app/Controllers/EstudosController.php',
    'viewer_token' => $root . '/app/Controllers/ViewerTokenController.php',
    'migration' => $root . '/database/migrations/2026-08-27_tenant_orthanc_cells_postgresql.sql',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Arquivo obrigatório ausente: {$name}\n");
        exit(1);
    }
    $files[$name] = file_get_contents($path);
}

$checks = [
    'migration cria controle de célula por tenant' => str_contains($files['migration'], 'bi_tenant_orthanc_cells'),
    'migration torna tenant único por célula' => str_contains($files['migration'], 'CONSTRAINT uq_cell_tenant UNIQUE (tenant_id)'),
    'migration torna servidor único por célula' => str_contains($files['migration'], 'CONSTRAINT uq_cell_servidor UNIQUE (servidor_id)'),
    'migration usa identidade composta de estudo' => str_contains($files['migration'], 'uq_estudo_servidor_orthanc')
        && str_contains($files['migration'], 'ON bi_pacs_estudos (servidor_id, orthanc_id)'),
    'roteamento prioriza célula exclusiva' => str_contains($files['routing'], "'celula_orthanc_exclusiva'"),
    'roteamento testa tabela de célula' => str_contains($files['routing'], "hasTable(\$pdo, 'bi_tenant_orthanc_cells')"),
    'sync identifica estudo por servidor e Orthanc' => str_contains($files['sync'], 'WHERE servidor_id = ? AND orthanc_id = ?'),
    'viewer web exige tenant ativo' => substr_count($files['estudos'], 'Selecione uma empresa antes de abrir imagens clínicas.') >= 1,
    'viewer web não faz fallback direto por UID' => !str_contains($files['estudos'], 'Fallback: redireciona direto se token falhar'),
    'token salva tenant sem nulo' => str_contains($files['estudos'], "':tenant_id'  => \$estudoTenantId,")
        && !str_contains($files['estudos'], "':tenant_id'  => Auth::tenantId() ?: null,"),
    'desktop exige configuração de célula explícita' => str_contains($files['desktop'], 'requiresTenantSpecificConfig'),
    'desktop não herda servidor global em célula' => str_contains($files['desktop'], 'Células exclusivas não podem herdar endpoint/AE do servidor global.'),
    'resolver público exige token com tenant' => str_contains($files['viewer_token'], 'vt.tenant_id IS NOT NULL'),
    'resolver público exige viewer exclusivo da célula' => str_contains($files['viewer_token'], 'Célula sem viewer_url para token autorizado.'),
];

$failed = false;
foreach ($checks as $description => $passed) {
    echo ($passed ? 'OK   ' : 'FAIL ') . $description . PHP_EOL;
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
