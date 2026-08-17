<?php
/**
 * Regressão estática — busca inline de Máscaras no painel lateral do Laudário.
 * Executar: php tests/reports_templates_inline_search_static.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$falhas = [];

function exigirBuscaInline(bool $condicao, string $mensagem): void
{
    global $falhas;
    if (!$condicao) {
        $falhas[] = $mensagem;
    }
}

function lerBuscaInline(string $caminho): string
{
    $conteudo = file_get_contents($caminho);
    if ($conteudo === false) {
        throw new RuntimeException('Arquivo não encontrado: ' . $caminho);
    }
    return $conteudo;
}

$show = lerBuscaInline($root . '/app/Views/reports/show.php');
$paciente = strpos($show, "partials/_paciente_card.php");
$busca = strpos($show, "partials/_mascara_search_card.php");
$exame = strpos($show, "partials/_exame_card.php");
exigirBuscaInline($paciente !== false && $busca !== false && $exame !== false && $paciente < $busca && $busca < $exame,
    'A busca de Máscaras não está entre os cards Paciente e Exame.');
exigirBuscaInline(strpos($show, '_modal_templates.php') === false,
    'A tela de Laudo ainda inclui o modal legado de Templates.');
exigirBuscaInline(!is_file($root . '/app/Views/reports/partials/_modal_templates.php'),
    'O partial legado do modal de Templates ainda existe.');

$header = lerBuscaInline($root . '/app/Views/layout/reports_header.php');
exigirBuscaInline(strpos($header, 'id="btn-template"') === false,
    'O botão legado de Template ainda está na barra superior.');

$partial = lerBuscaInline($root . '/app/Views/reports/partials/_mascara_search_card.php');
foreach (['mascara-search-input', 'mascara-search-dropdown', 'role="combobox"', 'aria-autocomplete="list"', 'role="listbox"'] as $contrato) {
    exigirBuscaInline(strpos($partial, $contrato) !== false, 'Contrato ausente no campo inline: ' . $contrato);
}

$templates = lerBuscaInline($root . '/public/assets/js/reports/reports-templates.js');
foreach ([
    'function normalizarBusca(value)',
    'function filtrarTemplates(consulta)',
    'function vincularBuscaInline()',
    'input.addEventListener(\'focus\', abrirBusca)',
    "event.key === 'ArrowDown'",
    "event.key === 'ArrowUp'",
    "event.key === 'Escape'",
    "event.key === 'Enter'",
    'document.addEventListener(\'mousedown\'',
    'editor.loadConteudoLivre(livre)',
    "editor.loadSecoes(parseSecoes(template), ['tecnica', 'achados', 'conclusao'])",
    'if (lastPayload) return lastPayload;',
] as $contrato) {
    exigirBuscaInline(strpos($templates, $contrato) !== false, 'Contrato de busca/aplicação ausente: ' . $contrato);
}
exigirBuscaInline(strpos($templates, 'new bootstrap.Modal') === false,
    'A lógica do modal Bootstrap ainda está ativa no seletor de Máscaras.');

$css = lerBuscaInline($root . '/public/assets/css/reports.css');
foreach (['.reports-mascara-search-dropdown', 'position: absolute;', 'overflow-y: auto;', '.reports-mascara-search-option.is-active'] as $contrato) {
    exigirBuscaInline(strpos($css, $contrato) !== false, 'Estilo ausente na busca inline: ' . $contrato);
}

$model = lerBuscaInline($root . '/app/Models/ReportTemplate.php');
$controller = lerBuscaInline($root . '/app/Controllers/TemplatesController.php');
exigirBuscaInline(strpos($model, "protected string \$table = 'report_templates'") !== false,
    'O model de Templates não aponta para report_templates.');
exigirBuscaInline(strpos($controller, 'Módulo de Máscaras/Templates de Laudo') !== false,
    'O controller de Máscaras não confirma a origem compartilhada da funcionalidade.');

if ($falhas !== []) {
    fwrite(STDERR, "FALHOU\n- " . implode("\n- ", $falhas) . "\n");
    exit(1);
}

echo "OK: busca inline de Máscaras, acessibilidade e remoção do modal legado validadas.\n";
