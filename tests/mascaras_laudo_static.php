<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$viewPath = $root . '/app/Views/medicos/form.php';
$controllerPath = $root . '/app/Controllers/TemplatesController.php';
$reportsViewPath = $root . '/app/Views/reports/index.php';
$failures = [];

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$view = (string) file_get_contents($viewPath);
$controller = (string) file_get_contents($controllerPath);
$reportsView = (string) file_get_contents($reportsViewPath);

$modalStart = strpos($view, '<div id="modalMascara"');
$modalEnd = $modalStart === false ? false : strpos($view, '<!-- ── MODAL IMPORTAR DOCX', $modalStart);
$modal = ($modalStart !== false && $modalEnd !== false) ? substr($view, $modalStart, $modalEnd - $modalStart) : '';

$expect($modal !== '', 'Modal Nova Máscara não localizado.');
$expect(!str_contains($modal, 'mEd-exame'), 'Campo Exame ainda está visível no modal.');
$expect(!str_contains($modal, 'mEd-recomendacao'), 'Campo Recomendação ainda está visível no modal.');
$expect(str_contains($modal, 'mEd-tecnica'), 'Editor Técnica ausente.');
$expect(str_contains($modal, 'mEd-achados'), 'Editor Achados ausente.');
$expect(str_contains($modal, 'mEd-conclusao'), 'Editor Impressão ausente.');
$expect(str_contains($modal, '>Impressão<'), 'Rótulo Impressão ausente ou incorreto.');
$expect(!str_contains($modal, '>Conclusão<'), 'Rótulo Conclusão ainda está exposto no modal.');
$expect(str_contains($view, 'quill@1.3.7/dist/quill.min.js'), 'Quill não foi carregado na tela de máscaras.');
$expect(str_contains($view, "modules: { toolbar: [['bold']] }"), 'Toolbar mínima de negrito não foi configurada.');
$expect(str_contains($view, "const MASCARA_SECOES_EDITAVEIS = ['tecnica', 'achados', 'conclusao']"), 'Lista de seções editáveis não corresponde ao novo formulário.');
$expect(str_contains($view, 'secao_exame:          _mascaraLegacySecoes.exame'), 'Seção Exame legada não é preservada no payload.');
$expect(str_contains($view, 'secao_recomendacao:   _mascaraLegacySecoes.recomendacao'), 'Seção Recomendação legada não é preservada no payload.');
$expect(str_contains($controller, 'private function sanitizeSectionHtml'), 'Sanitização de HTML das seções não foi implementada.');
$expect(str_contains($controller, "strip_tags(\$html, '<p><br><strong><b>')"), 'Whitelist de tags não preserva negrito com segurança.');
$expect(str_contains($controller, "SELECT secao_exame, secao_recomendacao"), 'Edição não busca seções legadas para preservá-las.');
$expect(str_contains($reportsView, 't.secao_conclusao') && str_contains($reportsView, "join('<br><br>')"), 'Laudário não integra a seção Impressão no corpo clínico contínuo.');

if ($failures) {
    fwrite(STDERR, "FALHOU:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: máscaras de laudo com Impressão e negrito validadas estaticamente.\n";
