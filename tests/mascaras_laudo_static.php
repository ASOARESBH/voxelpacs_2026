<?php

declare(strict_types=1);

/**
 * Regressão de Máscaras — contrato do editor livre único.
 * Executar: php tests/mascaras_laudo_static.php
 */
$root = dirname(__DIR__);
$view = (string) file_get_contents($root . '/app/Views/medicos/form.php');
$controller = (string) file_get_contents($root . '/app/Controllers/TemplatesController.php');
$failures = [];

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$modalStart = strpos($view, '<div id="modalMascara"');
$modalEnd = $modalStart === false ? false : strpos($view, '<!-- ── MODAL IMPORTAR DOCX', $modalStart);
$modal = ($modalStart !== false && $modalEnd !== false) ? substr($view, $modalStart, $modalEnd - $modalStart) : '';

$expect($modal !== '', 'Modal Nova Máscara não localizado.');
$expect(str_contains($modal, 'mEd-conteudo'), 'Editor único de conteúdo livre ausente.');
$expect(str_contains($modal, 'mEd-conteudo-toolbar'), 'Toolbar completa do editor livre ausente.');
foreach (['mEd-exame', 'mEd-tecnica', 'mEd-achados', 'mEd-conclusao', 'mEd-recomendacao'] as $editorLegado) {
    $expect(!str_contains($modal, $editorLegado), 'Editor estruturado legado ainda está visível: ' . $editorLegado);
}
foreach (['ql-bold', 'ql-italic', 'ql-underline', 'ql-header', 'ql-list', 'ql-table', 'ql-undo', 'ql-redo', 'ql-clean'] as $ferramenta) {
    $expect(str_contains($modal, $ferramenta), 'Ferramenta ausente da toolbar: ' . $ferramenta);
}
$expect(str_contains($view, 'quill@1.3.7/dist/quill.min.js'), 'Quill não foi carregado na tela de Máscaras.');
$expect(str_contains($view, 'window.createVoxelQuillEditor'), 'Modal não reutiliza a fábrica Quill compartilhada.');
$expect(str_contains($view, 'conteudo_livre:       obterConteudoMascara()'), 'Payload não persiste o conteúdo livre.');
$expect(str_contains($view, 'conteudoLegadoMascara(template)'), 'Edição não mantém fallback de Máscaras legadas.');
$expect(str_contains($view, 'secao_exame:          _mascaraLegacySecoes.exame'), 'Seção Exame legada não é preservada no payload.');
$expect(str_contains($view, 'secao_recomendacao:   _mascaraLegacySecoes.recomendacao'), 'Seção Recomendação legada não é preservada no payload.');

$expect(str_contains($controller, 'private function sanitizeSectionHtml'), 'Sanitização de HTML não foi implementada.');
$expect(str_contains($controller, '<p><br><strong><b><em><i><u><h1>'), 'Allowlist não preserva a marcação rica do editor.');
$expect(str_contains($controller, 'conteudo_livre        = :conteudo_livre'), 'Atualização não persiste conteúdo livre.');
$expect(str_contains($controller, 'SELECT secao_exame, secao_recomendacao'), 'Edição não busca seções legadas para preservá-las.');

if ($failures) {
    fwrite(STDERR, "FALHOU:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: Máscaras livres, toolbar completa e compatibilidade legada validadas estaticamente.\n";
