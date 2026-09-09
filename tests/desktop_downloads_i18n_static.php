<?php
$root = dirname(__DIR__);
$keys = ['downloads.nav','downloads.title','downloads.new','downloads.version','downloads.package_zip','downloads.save_draft','downloads.publish','downloads.archive','downloads.worklist_cta','downloads.error.invalid_upload'];
foreach (['pt_BR','en','es'] as $locale) {
    $catalog = require $root . '/lang/' . $locale . '.php';
    foreach ($keys as $key) if (!array_key_exists($key, $catalog)) throw new RuntimeException("Chave {$key} ausente em {$locale}");
}
echo "desktop_downloads_i18n_static: OK\n";
