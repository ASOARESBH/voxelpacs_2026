<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$pt = require $root . '/lang/pt_BR.php';
$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
$keys = array_keys(array_filter($pt, static fn($_, $key): bool => str_starts_with((string) $key, 'platform_notifications.'), ARRAY_FILTER_USE_BOTH));
if (!$keys) throw new RuntimeException('Chaves de notificações ausentes em pt_BR.');
foreach ($keys as $key) {
    if (!array_key_exists($key, $en) || !array_key_exists($key, $es)) throw new RuntimeException("Chave sem paridade: {$key}");
}
echo "platform_notifications_i18n_static=ok\n";
