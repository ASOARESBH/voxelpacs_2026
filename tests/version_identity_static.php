<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$view = (string) file_get_contents($root . '/app/Core/View.php');
$footer = (string) file_get_contents($root . '/app/Views/layout/pacs_footer.php');

$expect(str_contains($view, 'public static function version(): string'), 'View exposes a version reader');
$expect(str_contains($view, "VERSAO.txt"), 'version reader uses the explicit marker file');
$expect(str_contains($view, "preg_match('/^\\d+\\.\\d+(?:\\.\\d+)?$/'"), 'version reader validates the marker');
$expect(str_contains($view, "return '';"), 'missing or invalid marker fails closed');
$expect(str_contains($footer, 'View::version()'), 'layout reads the runtime version');
$expect(str_contains($footer, 'comum.versao_ambiente'), 'version label is translated');

$keys = [
    'comum.versao_ambiente',
];
$catalogs = [];
foreach (['pt_BR', 'en', 'es'] as $locale) {
    $catalogs[$locale] = require $root . '/lang/' . $locale . '.php';
    foreach ($keys as $key) {
        $expect(array_key_exists($key, $catalogs[$locale]), "{$key} exists in {$locale}");
    }
}

$expect(count(array_unique(array_map(static fn (array $catalog): string => (string) $catalog['comum.versao_ambiente'], $catalogs))) === 3, 'version labels are translated independently');

echo "VERSION_IDENTITY_STATIC=PASS\n";
