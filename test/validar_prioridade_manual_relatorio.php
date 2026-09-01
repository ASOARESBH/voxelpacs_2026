<?php
declare(strict_types=1);

/** Validação estática: prioridade efetiva e confirmação dupla sem acesso a dados clínicos. */
$base = dirname(__DIR__);
$arquivos = [
    'controller' => $base . '/app/Controllers/GestaoExamesController.php',
    'service' => $base . '/app/Services/GestaoExamesService.php',
    'js' => $base . '/public/assets/js/gestao-exames-gerenciar.js',
    'worklist' => $base . '/app/Views/estudos/index.php',
    'produtividade' => $base . '/app/Repositories/RelatorioProdutividadeMedicosRepository.php',
    'laudario' => $base . '/app/Views/reports/partials/_exame_card.php',
    'exportacao' => $base . '/app/Services/RelatorioExportService.php',
    'pdf' => $base . '/app/Views/relatorios/pdf/medicos.php',
];

foreach ($arquivos as $nome => $arquivo) {
    if (!is_file($arquivo)) throw new RuntimeException("Arquivo ausente: {$nome}");
}

$controller = file_get_contents($arquivos['controller']);
$service = file_get_contents($arquivos['service']);
$js = file_get_contents($arquivos['js']);
$worklist = file_get_contents($arquivos['worklist']);
$produtividade = file_get_contents($arquivos['produtividade']);
$laudario = file_get_contents($arquivos['laudario']);
$exportacao = file_get_contents($arquivos['exportacao']);
$pdf = file_get_contents($arquivos['pdf']);

$checks = [
    'backend recebe confirmação explícita' => str_contains($controller, "confirmar_prioridade") && str_contains($service, 'bool $confirmed'),
    'backend bloqueia sem confirmação' => str_contains($service, "confirmacao_prioridade_obrigatoria"),
    'auditoria registra confirmação explícita' => str_contains($service, "confirmacao_explicita' => true"),
    'modal exige confirmação dupla' => str_contains($worklist, 'gerenciarPrioridadeConfirmacao') && str_contains($js, 'confirmar_prioridade: true'),
    'relatório usa prioridade efetiva' => str_contains($produtividade, 'prioridadeEfetivaSql') && str_contains($produtividade, 'dicom_priority_override'),
    'relatório marca alteração manual' => str_contains($produtividade, 'prioridade_manual') && str_contains($laudario, 'relatorios.prioridade.manual'),
    'exportação registra origem e indicador' => str_contains($exportacao, 'Prioridade DICOM original') && str_contains($exportacao, 'prioridade_origem'),
    'PDF registra origem e indicador' => str_contains($pdf, 'fmtPrioridade') && str_contains($pdf, 'Alterada manualmente'),
];

foreach ($checks as $descricao => $ok) {
    if (!$ok) throw new RuntimeException("Falha: {$descricao}");
}

foreach (['pt_BR.php', 'en.php', 'es.php'] as $idioma) {
    $catalogo = file_get_contents($base . '/lang/' . $idioma);
    foreach (['gestao_gerenciar.prioridade.confirmacao_dupla', 'gestao_gerenciar.erro.confirmacao_prioridade_obrigatoria', 'relatorios.prioridade.manual'] as $chave) {
        if (!str_contains($catalogo, $chave)) throw new RuntimeException("Tradução ausente em {$idioma}: {$chave}");
    }
}

echo "VALIDACAO_PRIORIDADE_MANUAL_RELATORIO=OK\n";
