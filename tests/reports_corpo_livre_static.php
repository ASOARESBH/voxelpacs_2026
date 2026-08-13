<?php
/**
 * Teste estático — editor de laudo em corpo clínico livre.
 * Executar: php tests/reports_corpo_livre_static.php
 */

$root = dirname(__DIR__);
$falhas = [];

function exigir(bool $condicao, string $mensagem): void
{
    global $falhas;
    if (!$condicao) {
        $falhas[] = $mensagem;
    }
}

function lerArquivo(string $caminho): string
{
    $conteudo = file_get_contents($caminho);
    if ($conteudo === false) {
        throw new RuntimeException('Arquivo não encontrado: ' . $caminho);
    }
    return $conteudo;
}

$index = lerArquivo($root . '/app/Views/reports/index.php');
exigir(strpos($index, 'id="editor-corpo"') !== false, 'O editor único editor-corpo não foi encontrado.');
foreach (['editor-exame', 'editor-tecnica', 'editor-achados', 'editor-conclusao', 'editor-recomendacao'] as $editorLegado) {
    exigir(strpos($index, $editorLegado) === false, 'O editor fixo legado ainda está ativo: ' . $editorLegado);
}
exigir(strpos($index, 'corpo_laudo: getEditorContent(\'corpo\')') !== false, 'Autosave não envia corpo_laudo.');
exigir(strpos($index, 'corpo_laudo: getEditorContent(\'corpo\')') !== false, 'Liberação não envia o corpo clínico livre.');

$repository = lerArquivo($root . '/app/Repositories/ReportRepository.php');
exigir(strpos($repository, 'corpo_laudo = :corpo_laudo') !== false, 'Repositório não persiste corpo_laudo.');

$controller = lerArquivo($root . '/app/Controllers/ReportsController.php');
exigir(strpos($controller, "array_key_exists('corpo_laudo', \$input)") !== false, 'Controller não aceita corpo_laudo.');

$dispatcher = lerArquivo($root . '/app/Views/reports/pdf.php');
exigir(strpos($dispatcher, '$corpoLaudo') !== false, 'Dispatcher PDF não prepara corpoLaudo.');

$layouts = [
    '_classico_centralizado.php',
    '_moderno_lateral.php',
    '_corporativo_faixa.php',
    '_minimalista.php',
];
foreach ($layouts as $layout) {
    $conteudo = lerArquivo($root . '/app/Views/reports/pdf/templates/' . $layout);
    exigir(strpos($conteudo, '$corpoLaudo') !== false, 'Layout sem corpo livre: ' . $layout);
    foreach (['secao_exame', 'secao_tecnica', 'secao_achados', 'secao_conclusao', 'secao_recomendacao'] as $campoLegado) {
        exigir(strpos($conteudo, $campoLegado) === false, 'Layout ainda imprime campo clínico fixo: ' . $layout . ' / ' . $campoLegado);
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "FALHOU\n- " . implode("\n- ", $falhas) . "\n");
    exit(1);
}

echo "OK: editor livre, persistência e layouts institucionais validados.\n";
