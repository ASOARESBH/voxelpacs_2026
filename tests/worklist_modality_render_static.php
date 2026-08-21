<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/public/assets/css/pacs.css');
$header = (string) file_get_contents($root . '/app/Views/layout/pacs_header.php');
$view = (string) file_get_contents($root . '/app/Core/View.php');

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require($css !== '', 'O CSS principal deve estar disponível.');
$require($header !== '', 'O cabeçalho PACS deve estar disponível.');
$require($view !== '', 'A classe de assets deve estar disponível.');

$require(
    !str_contains($css, '.pacs-content > *, #pacs-page > * { animation: fadeInUp .2s ease; }'),
    'A animação global que repinta a Worklist não pode retornar.'
);
$require(
    str_contains($css, '#pacs-page > .wl-page-header,')
    && str_contains($css, '#pacs-page > .wl-worklist-body {')
    && str_contains($css, 'animation: none;'),
    'Os blocos da Worklist devem renderizar diretamente no estado final.'
);
$require(
    str_contains($css, '.mod-badge {') && str_contains($css, 'display: inline-flex;'),
    'O badge de modalidade deve manter sua forma estável no CSS principal.'
);
$require(
    str_contains($header, 'rel="preload" href="/assets/css/pacs.css?v=')
    && str_contains($header, 'as="style" fetchpriority="high"')
    && str_contains($header, 'rel="stylesheet" href="/assets/css/pacs.css?v='),
    'O CSS principal deve ser priorizado antes da pintura da Worklist.'
);
$require(
    str_contains($view, "ASSET_VERSION = '2.3.12'"),
    'A versão de assets deve invalidar o cache do CSS corrigido.'
);

if ($failures !== []) {
    fwrite(STDERR, "WORKLIST_MODALITY_RENDER_STATIC_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "WORKLIST_MODALITY_RENDER_STATIC_OK\n");
