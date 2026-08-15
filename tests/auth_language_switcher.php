<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function languageSwitcherRead(string $relativePath): string
{
    global $root;
    $path = $root . '/' . $relativePath;
    if (!is_file($path)) {
        throw new RuntimeException("Arquivo ausente: {$relativePath}");
    }
    return (string) file_get_contents($path);
}

function languageSwitcherRequire(string $source, string $needle, string $message): void
{
    if (strpos($source, $needle) === false) {
        throw new RuntimeException($message);
    }
}

$controller = languageSwitcherRead('app/Controllers/AuthController.php');
$routes = languageSwitcherRead('routes/web.php');
$view = languageSwitcherRead('app/Views/auth/login.php');
$css = languageSwitcherRead('public/assets/css/auth.css');
$header = languageSwitcherRead('app/Views/layout/auth_header.php');

languageSwitcherRequire($routes, "Router::post('/login/idioma', 'AuthController@setLoginLocale')", 'Rota POST de idioma ausente.');
languageSwitcherRequire($controller, 'public function setLoginLocale(): void', 'Handler de seleção de idioma ausente.');
languageSwitcherRequire($controller, 'hash_equals($sessionCsrf, $csrf)', 'Seleção de idioma não protege a sessão com CSRF.');
languageSwitcherRequire($controller, 'Translator::SUPPORTED', 'Locale não é validado contra os idiomas permitidos.');
languageSwitcherRequire($controller, "\$_SESSION['login_locale'] = \$locale", 'Idioma selecionado não é persistido na sessão.');
languageSwitcherRequire($controller, 'applyLoginLocale()', 'Login não aplica o idioma persistido antes de renderizar.');
languageSwitcherRequire($view, 'action="/login/idioma"', 'Seletor não envia para a rota de idioma.');
languageSwitcherRequire($view, 'name="locale" value="pt_BR"', 'Opção PT ausente.');
languageSwitcherRequire($view, 'name="locale" value="en"', 'Opção EN ausente.');
languageSwitcherRequire($view, 'name="locale" value="es"', 'Opção ES ausente.');
languageSwitcherRequire($view, 'auth-language-switcher__button', 'View não usa a classe do seletor.');
languageSwitcherRequire($view, 'aria-pressed', 'Seletor não informa o idioma ativo à tecnologia assistiva.');
languageSwitcherRequire($css, '.auth-language-switcher', 'Estilo do seletor de idioma ausente.');
languageSwitcherRequire($css, '.auth-language-switcher__button.is-active', 'Estado visual do idioma ativo ausente.');
languageSwitcherRequire($header, '$documentLang', 'Idioma escolhido não é refletido no atributo lang do documento.');

$requiredKeys = [
    'auth.login.titulo',
    'auth.login.subtitulo',
    'auth.login.email',
    'auth.login.senha',
    'auth.login.esqueceu_senha',
    'auth.login.entrar',
    'auth.login.autenticando',
    'auth.login.mostrar_senha',
    'auth.login.idioma_aria',
    'auth.login.direitos_reservados',
    'auth.login.erro_campos_obrigatorios',
    'auth.login.erro_credenciais',
];
$catalogs = [
    'pt_BR' => require $root . '/lang/pt_BR.php',
    'en' => require $root . '/lang/en.php',
    'es' => require $root . '/lang/es.php',
];
foreach ($catalogs as $locale => $catalog) {
    foreach ($requiredKeys as $key) {
        if (!isset($catalog[$key]) || trim((string) $catalog[$key]) === '') {
            throw new RuntimeException("Chave {$key} ausente ou vazia em {$locale}.");
        }
    }
}

fwrite(STDOUT, "AUTH_LANGUAGE_SWITCHER_OK\n");
