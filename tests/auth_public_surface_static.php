<?php
declare(strict_types=1);

function authPublicRequire(string $source, string $needle, string $message): void
{
    if (strpos($source, $needle) === false) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$login = (string) file_get_contents($root . '/app/Views/auth/login.php');
$auth = (string) file_get_contents($root . '/app/Controllers/AuthController.php');
$token = (string) file_get_contents($root . '/app/Controllers/Auth/AccessTokenController.php');
$resetView = (string) file_get_contents($root . '/app/Views/auth/criar_senha.php');
$testEndpoint = (string) file_get_contents($root . '/public/test.php');
$installEndpoint = (string) file_get_contents($root . '/public/install_tables.php');
$guard = (string) file_get_contents($root . '/app/Services/LoginAttemptGuard.php');
$migration = (string) file_get_contents($root . '/database/migrations/2026-09-03_auth_login_throttle_postgresql.sql');

if (strpos($login, 'admin@voxelpacs.com.br') !== false) throw new RuntimeException('Login ainda expõe identificador de conta no formulário público.');
if (strpos($login, "\$_POST['email']") !== false) throw new RuntimeException('Login preserva e-mail submetido no HTML público.');
if (strpos($resetView, "\$tokenData['user_") !== false) throw new RuntimeException('Página pública por token ainda expõe identificadores do titular.');
if (strpos($testEndpoint, 'phpversion') !== false || strpos($testEndpoint, 'DB_USERNAME') !== false) throw new RuntimeException('Endpoint de diagnóstico legado ainda expõe ambiente público.');
if (strpos($installEndpoint, 'DB_PASSWORD') !== false || strpos($installEndpoint, 'new PDO') !== false) throw new RuntimeException('Instalador legado ainda expõe ou utiliza ambiente público.');
authPublicRequire($auth, 'if (!$this->validCsrf())', 'Login não valida CSRF antes das credenciais.');
authPublicRequire($auth, 'new LoginAttemptGuard()', 'Login não aplica proteção contra tentativas repetidas.');
authPublicRequire($token, 'if (!$this->validCsrf() || empty($email)', 'Recuperação de senha não valida CSRF.');
authPublicRequire($token, 'private function publicBaseUrl()', 'Redefinição não usa base URL controlada.');
if (strpos($token, "\$_SERVER['HTTP_HOST']") !== false) throw new RuntimeException('Redefinição ainda confia no cabeçalho Host recebido.');
authPublicRequire($guard, 'hash_hmac', 'Tentativas de login não são protegidas por hash com segredo do servidor.');
authPublicRequire($migration, 'bi_auth_login_attempts', 'Migration da proteção de login não cria a tabela necessária.');
echo "AUTH_PUBLIC_SURFACE_STATIC_OK\n";
