<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$viewPath = $root . '/app/Views/medicos/form.php';
$cssPath = $root . '/public/assets/css/pacs.css';
$failures = [];

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$view = (string) file_get_contents($viewPath);
$css = (string) file_get_contents($cssPath);

$start = strpos($view, 'id="aba-mascaras"');
$end = $start === false ? false : strpos($view, 'id="aba-assinatura"', $start);
$mascaras = ($start !== false && $end !== false) ? substr($view, $start, $end - $start) : '';

$expect($mascaras !== '', 'Não foi possível localizar a aba Máscaras.');
$expect(str_contains($mascaras, 'class="medico-mascaras-toolbar"'), 'Toolbar dedicada das máscaras ausente.');
$expect(str_contains($mascaras, 'class="btn-pacs-outline"') && str_contains($mascaras, 'Importar DOCX'), 'Importar DOCX não usa o botão secundário padronizado.');
$expect(str_contains($mascaras, 'class="btn-pacs-primary"') && str_contains($mascaras, 'Nova Máscara'), 'Nova Máscara não usa o botão principal padronizado.');
$expect(!str_contains($mascaras, 'class="pacs-btn" onclick="abrirImportarMascara()"'), 'Importar DOCX ainda usa o botão compacto de ícone.');
$expect(str_contains($view, '.medico-mascaras-toolbar') && str_contains($view, 'white-space: nowrap'), 'Toolbar não impede quebra de texto dos botões.');
$expect(str_contains($view, '@media (max-width: 680px)') && str_contains($view, 'width: 100%;'), 'Toolbar não possui quebra responsiva em telas estreitas.');
$expect(str_contains($view, '@media (max-width: 420px)') && str_contains($view, 'flex: 1 1 100%;'), 'Toolbar não empilha os botões em telas muito estreitas.');
$expect(str_contains($css, '.btn-pacs-primary, .btn-primary') && str_contains($css, 'display: inline-flex'), 'Botão primário global não mantém comportamento textual.');
$expect(str_contains($css, '.btn-pacs-outline, .btn-secondary') && str_contains($css, 'display: inline-flex'), 'Botão secundário global não mantém comportamento textual.');

if ($failures) {
    fwrite(STDERR, "FALHOU:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: toolbar de máscaras validada estaticamente.\n";
