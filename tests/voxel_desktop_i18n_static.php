<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$catalogs = [];
foreach (['pt_BR','en','es'] as $locale) {
    $catalog = require $root.'/lang/'.$locale.'.php';
    $catalogs[$locale] = array_values(array_filter(array_keys($catalog), static fn(string $key): bool => str_starts_with($key, 'voxel_desktop.')));
}
$reference = $catalogs['pt_BR'];
if ($reference === []) throw new RuntimeException('Chaves Voxel Desktop ausentes em pt_BR.');
foreach (['en','es'] as $locale) {
    $missing = array_values(array_diff($reference, $catalogs[$locale]));
    $extra = array_values(array_diff($catalogs[$locale], $reference));
    if ($missing || $extra) throw new RuntimeException('Paridade i18n Voxel Desktop falhou para '.$locale.'.');
}
echo "VOXEL_DESKTOP_I18N_STATIC_OK\n";
