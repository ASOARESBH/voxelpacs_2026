<?php
/**
 * Regressão estática — editor livre único de Máscaras.
 * Executar: php tests/mascara_editor_livre_static.php
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

$factory = lerArquivo($root . '/public/assets/js/shared/voxel-quill-factory.js');
exigir(strpos($factory, 'window.VoxelQuill.factory.create') !== false, 'A fábrica compartilhada do Quill não está disponível.');
exigir(strpos($factory, 'function insertBasicTable(quill)') !== false, 'A fábrica Quill não oferece a tabela 2x2.');
exigir(strpos($factory, 'table: () => insertBasicTable(quill)') !== false, 'A toolbar não delega a inserção da tabela 2x2 à fábrica Quill.');

$form = lerArquivo($root . '/app/Views/medicos/form.php');
exigir(strpos($form, 'id="mEd-conteudo"') !== false, 'O editor livre de Máscara não foi encontrado no modal.');
exigir(strpos($form, 'id="mEd-conteudo-toolbar"') !== false, 'A toolbar completa do editor de Máscara não foi encontrada.');
exigir(strpos($form, 'ql-underline') !== false, 'A toolbar de Máscara não oferece sublinhado.');
foreach (['mEd-tecnica', 'mEd-achados', 'mEd-conclusao'] as $editorLegado) {
    exigir(strpos($form, $editorLegado) === false, 'O editor estruturado legado ainda está presente: ' . $editorLegado);
}
exigir(strpos($form, 'conteudo_livre:') !== false && strpos($form, 'obterConteudoMascara()') !== false, 'O modal não envia conteudo_livre ao salvar.');

$controller = lerArquivo($root . '/app/Controllers/TemplatesController.php');
foreach (['<u>', '<ul>', '<ol>', '<table>', '<th>', '<td>'] as $tagPermitida) {
    exigir(strpos($controller, $tagPermitida) !== false, 'A sanitização não permite HTML rico: ' . $tagPermitida);
}
exigir(strpos($controller, 'conteudo_livre        = :conteudo_livre') !== false, 'Atualização de Máscara não persiste conteudo_livre.');
exigir(strpos($controller, ':conteudo_livre') !== false, 'Criação/importação de Máscara não persiste conteudo_livre.');

$migration = lerArquivo($root . '/database/migrations/2026-08-16_report_templates_conteudo_livre.sql');
exigir(strpos($migration, 'ADD COLUMN `conteudo_livre` MEDIUMTEXT NULL') !== false, 'Migration não cria conteudo_livre como MEDIUMTEXT anulável.');
exigir(stripos($migration, 'information_schema') === false, 'Migration não pode consultar INFORMATION_SCHEMA no HostGator.');

$reportsController = lerArquivo($root . '/app/Controllers/ReportsController.php');
exigir(strpos($reportsController, "'conteudo_livre' => \$conteudoLivre") !== false, 'normalizarTemplate não expõe conteudo_livre.');
exigir(strpos($reportsController, "json_encode(['corpo' => \$conteudoLivre]") !== false, 'Template livre não é transformado em corpo do Laudário.');

$templatesJs = lerArquivo($root . '/public/assets/js/reports/reports-templates.js');
exigir(strpos($templatesJs, 'function conteudoLivre(template)') !== false, 'Aplicador de Templates não identifica conteudo_livre.');
exigir(strpos($templatesJs, 'editor.loadConteudoLivre(livre)') !== false, 'Aplicador de Templates não carrega Máscaras livres diretamente.');

$repository = lerArquivo($root . '/app/Repositories/ReportRepository.php');
exigir(strpos($repository, 'corpo_laudo = :corpo_laudo') !== false, 'Laudário não persiste o corpo livre da Máscara.');
exigir(strpos($repository, 'secao_tecnica = \'\'') !== false, 'Persistência livre não limpa seções legadas para evitar duplicação.');

$pdfDispatcher = lerArquivo($root . '/app/Views/reports/pdf.php');
exigir(strpos($pdfDispatcher, "empty(\$r['mascara_conteudo_livre'])") !== false, 'Dispatcher PDF não diferencia Máscaras livres das legadas.');

$pdfModerno = lerArquivo($root . '/app/Views/reports/pdf/templates/_moderno_lateral.php');
foreach (['.pdf-report-content table', '.pdf-report-content th, .pdf-report-content td', '.pdf-report-content u'] as $cssEsperado) {
    exigir(strpos($pdfModerno, $cssEsperado) !== false, 'PDF Moderno Lateral não suporta conteúdo rico: ' . $cssEsperado);
}

if ($falhas !== []) {
    fwrite(STDERR, "FALHOU\n- " . implode("\n- ", $falhas) . "\n");
    exit(1);
}

echo "OK: editor livre de Máscaras, persistência, compatibilidade e PDF validados.\n";
