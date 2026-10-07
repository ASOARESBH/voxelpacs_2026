<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$partial = file_get_contents($root . '/app/Views/usuarios/_navigation.php');
$css = file_get_contents($root . '/public/assets/css/pacs.css');

$assert($partial !== false, 'partial de navegação deve existir');
$assert($css !== false, 'CSS global deve existir');

foreach (['/usuarios', '/usuarios/grupos', '/usuarios/notificacoes', '/usuarios/regras-acesso'] as $route) {
    $assert(str_contains((string) $partial, $route), "rota ausente no partial: {$route}");
}

foreach ([
    'app/Views/usuarios/index.php' => "require __DIR__ . '/_navigation.php';",
    'app/Views/usuarios/form.php' => "require __DIR__ . '/_navigation.php';",
    'app/Views/grupos/index.php' => "require __DIR__ . '/../usuarios/_navigation.php';",
    'app/Views/grupos/form.php' => "require __DIR__ . '/../usuarios/_navigation.php';",
    'app/Views/grupos/notificacoes.php' => "require __DIR__ . '/../usuarios/_navigation.php';",
    'app/Views/usuarios/regras_acesso_index.php' => "require __DIR__ . '/_navigation.php';",
    'app/Views/usuarios/regras_acesso_form.php' => "require __DIR__ . '/_navigation.php';",
] as $view => $include) {
    $contents = file_get_contents($root . '/' . $view);
    $assert($contents !== false && str_contains($contents, $include), "view sem navegação compartilhada: {$view}");
}

$assert(str_contains((string) $css, '.usuarios-tabs-bar'), 'CSS global não define .usuarios-tabs-bar');
$assert(str_contains((string) $css, '.usuarios-tab-btn.active'), 'CSS global não define o estado ativo');
$assert(!str_contains((string) file_get_contents($root . '/app/Views/usuarios/index.php'), '.usuarios-tabs-bar {'), 'CSS duplicado permaneceu na view de usuários');
$assert(!str_contains((string) file_get_contents($root . '/app/Views/grupos/index.php'), '.usuarios-tabs-bar {'), 'CSS duplicado permaneceu na view de grupos');

echo "USUARIOS_NAVIGATION_STATIC=PASS\n";
