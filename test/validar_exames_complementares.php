<?php
declare(strict_types=1);

$base = dirname(__DIR__);
$arquivos = [
    'service' => $base . '/app/Services/ExamesComplementaresService.php',
    'repository' => $base . '/app/Repositories/ExamesComplementaresRepository.php',
    'controller' => $base . '/app/Controllers/ExamesComplementaresController.php',
    'routes' => $base . '/routes/web.php',
    'report' => $base . '/app/Controllers/ReportsController.php',
    'worklist' => $base . '/app/Controllers/EstudosController.php',
    'report_view' => $base . '/app/Views/reports/partials/_exame_card.php',
    'worklist_view' => $base . '/app/Views/estudos/index.php',
];
foreach ($arquivos as $nome => $arquivo) {
    if (!is_file($arquivo)) {
        fwrite(STDERR, "ARQUIVO_AUSENTE={$nome}\n");
        exit(1);
    }
}

$conteudo = [];
foreach ($arquivos as $nome => $arquivo) $conteudo[$nome] = (string) file_get_contents($arquivo);

$requisitos = [
    'rota_anexar' => str_contains($conteudo['routes'], "'/api/gestao-exames/estudos/{id}/exames-complementares'") && str_contains($conteudo['routes'], 'ExamesComplementaresController@anexar'),
    'rota_remover' => str_contains($conteudo['routes'], "'/api/gestao-exames/estudos/{id}/exames-complementares/remover'") && str_contains($conteudo['routes'], 'ExamesComplementaresController@remover'),
    'rota_proxy_report' => str_contains($conteudo['routes'], "'/reports/r/{token}/exames-complementares'") && str_contains($conteudo['routes'], 'ReportsController@examesComplementaresByToken'),
    'tenant_repositorio' => str_contains($conteudo['repository'], 'tenant_id = :tenant_id') && str_contains($conteudo['repository'], 'ON CONFLICT (tenant_id, estudo_id)'),
    'arquivo_privado' => str_contains($conteudo['service'], '/storage/uploads/exames_complementares/') && str_contains($conteudo['service'], 'caminhoAbsolutoSeguro'),
    'limite_upload' => str_contains($conteudo['service'], '15 * 1024 * 1024') && str_contains($conteudo['service'], 'finfo_file'),
    'proxy_sem_cache' => str_contains($conteudo['controller'], 'Cache-Control: private, no-store, max-age=0') && str_contains($conteudo['report'], 'Cache-Control: private, no-store, max-age=0'),
    'gate_modalidade' => str_contains($conteudo['controller'], 'canAccessStudyModalities') && str_contains($conteudo['report'], 'findAuthorizedReportByPublicToken'),
    'indicador_worklist' => str_contains($conteudo['worklist'], 'exame_complementar_id') && str_contains($conteudo['worklist_view'], 'exame-complementar'),
    'acesso_report' => str_contains($conteudo['report_view'], '$examesComplementares') && str_contains($conteudo['report_view'], "['visualizar_url']"),
];

$keys = [
    'exames_complementares.titulo',
    'exames_complementares.status.anexado',
    'exames_complementares.modal.titulo',
    'exames_complementares.acao.salvar',
    'exames_complementares.erro.salvar',
];
foreach (['pt_BR.php', 'en.php', 'es.php'] as $catalogo) {
    $texto = (string) file_get_contents($base . '/lang/' . $catalogo);
    foreach ($keys as $key) $requisitos['i18n_' . $catalogo . '_' . $key] = str_contains($texto, "'{$key}'");
}

$falhas = array_keys(array_filter($requisitos, static fn(bool $ok): bool => !$ok));
if ($falhas) {
    fwrite(STDERR, 'FALHAS=' . implode(',', $falhas) . "\n");
    exit(1);
}
echo "VALIDACAO_EXAMES_COMPLEMENTARES=OK\n";
