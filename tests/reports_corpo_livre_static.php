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

$editorPartial = lerArquivo($root . '/app/Views/reports/partials/_editor.php');
$templatesJs = lerArquivo($root . '/public/assets/js/reports/reports-templates.js');
exigir(strpos($editorPartial, "\$tituloLaudo = trim((string) (\$mascaraTitulo ?? ''));" ) !== false, 'Título inicial do Laudário não está restrito à máscara aplicada.');
exigir(strpos($editorPartial, "['study_description', 'requested_procedure_desc', 'body_part_examined', 'modalities']") === false, 'Metadados DICOM ainda preenchem o título do Laudário sem máscara.');
exigir(strpos($editorPartial, "\$tituloLaudo === '' ? ' hidden' : ''") !== false, 'Título vazio do Laudário não é ocultado antes de aplicar uma máscara.');
exigir(strpos($templatesJs, "getElementById('reports-editor-document-title')") !== false && strpos($templatesJs, "elemento.hidden = texto === ''") !== false, 'Aplicação de máscara não atualiza o título real do Laudário com segurança.');

$report = (object) ['conteudo' => '', 'corpo_laudo' => '', 'situacao' => 'rascunho'];
$estudo = (object) ['study_description' => '', 'requested_procedure_desc' => '', 'body_part_examined' => '', 'modalities' => 'CT'];
$readonly = false;
$reportLayoutCodigo = '';
$reportVisual = [];
$mascaraTitulo = '';
ob_start();
require $root . '/app/Views/reports/partials/_editor.php';
$editorVazio = (string) ob_get_clean();
exigir(strpos($editorVazio, '>CT</h1>') === false && strpos($editorVazio, 'Laudo Médico</h1>') === false, 'Estudo sem máscara preencheu indevidamente o título do Laudário.');
exigir(strpos($editorVazio, 'reports-editor-document-title" class="reports-editor-document-title" hidden') !== false && strpos($editorVazio, '<p><br></p>') !== false, 'Estudo sem máscara não abre com título oculto e corpo em branco.');

$mascaraTitulo = 'Tomografia Computadorizada do Tórax';
ob_start();
require $root . '/app/Views/reports/partials/_editor.php';
$editorComMascara = (string) ob_get_clean();
exigir(strpos($editorComMascara, '>Tomografia Computadorizada do Tórax</h1>') !== false && strpos($editorComMascara, 'reports-editor-document-title" class="reports-editor-document-title" hidden') === false, 'Título de máscara aplicada não é exibido corretamente no Laudário.');

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
