<?php
/**
 * Regressão estática — responsividade/PWA.
 * Executar: php tests/responsive_mobile_static.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$read = static function (string $relative) use ($root, &$failures): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        $failures[] = "Arquivo ausente: {$relative}";
        return '';
    }
    return (string) file_get_contents($path);
};

$css = $read('public/assets/css/mobile-responsive.css');
foreach ([
    '@media (max-width: 900px)',
    '@media (max-width: 575px)',
    '.pacs-mobile-backdrop',
    '.wl-mobile-filters-toggle',
    '.modal-content',
] as $needle) {
    if (!str_contains($css, $needle)) {
        $failures[] = "Camada responsiva sem regra esperada: {$needle}";
    }
}

foreach (['app/Views/layout/pacs_header.php', 'app/Views/layout/reports_header.php', 'app/Views/layout/auth_header.php'] as $header) {
    $content = $read($header);
    if (!str_contains($content, 'viewport-fit=cover')) {
        $failures[] = "Safe area ausente em {$header}";
    }
    if (!str_contains($content, '/assets/css/mobile-responsive.css')) {
        $failures[] = "CSS responsivo ausente em {$header}";
    }
}

$worklist = $read('app/Views/estudos/index.php');
foreach ([
    'wl-mobile-filters-toggle',
    'mobile-filters-open',
    'data-label="<?= htmlspecialchars(t(\'worklist.mobile.coluna.paciente\')',
    'window.matchMedia(\'(max-width: 575px)\')',
] as $needle) {
    if (!str_contains($worklist, $needle)) {
        $failures[] = "Worklist mobile sem contrato esperado: {$needle}";
    }
}
if (str_contains($worklist, '.col-unidade,.col-solicitante,.col-modalidades,.col-pedido,.col-medico-laudo{display:none;}')) {
    $failures[] = 'Worklist ainda oculta informações clínicas no breakpoint mobile.';
}

$manifest = json_decode($read('public/manifest.json'), true);
if (!is_array($manifest) || ($manifest['orientation'] ?? null) !== 'any') {
    $failures[] = 'Manifest PWA deve permitir orientação any.';
}

$sw = $read('public/sw.js');
if (!str_contains($sw, 'voxelpacs-static-v2') || !str_contains($sw, '/assets/css/mobile-responsive.css')) {
    $failures[] = 'Service worker não foi atualizado para a camada responsiva.';
}

$pt = include $root . '/lang/pt_BR.php';
$en = include $root . '/lang/en.php';
$es = include $root . '/lang/es.php';
foreach (['worklist.mobile.filtros_abrir', 'worklist.mobile.filtros_fechar', 'worklist.mobile.coluna.paciente'] as $key) {
    if (!isset($pt[$key], $en[$key], $es[$key])) {
        $failures[] = "Chave i18n mobile ausente: {$key}";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: camada responsiva, Worklist mobile, PWA e i18n validados.\n";
