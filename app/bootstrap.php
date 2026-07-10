<?php
/**
 * VOXEL B.I - Bootstrap para Hospedagem Compartilhada (HostGator)
 * ORDEM OBRIGATÓRIA: ini_set → session → headers → autoload → env
 */

// Define constantes de diretório absolutas baseadas no local do arquivo
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');

// Configurações de erro (antes de tudo)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', STORAGE_PATH . '/logs/php_errors.log');
error_reporting(E_ALL);

// ─── SESSÃO: deve ser configurada ANTES de qualquer header() ─────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');

$sessionPath = STORAGE_PATH . '/sessions';
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0755, true);
}
if (is_dir($sessionPath) && is_writable($sessionPath)) {
    session_save_path($sessionPath);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// ─────────────────────────────────────────────────────────────────────────────

// Headers de segurança (APÓS session_start)
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Carrega o autoloader customizado (não depende do composer no servidor)
require_once APP_PATH . '/autoload.php';

// ─── HANDLER GLOBAL DE ERROS: nenhum \Throwable não tratado deve virar uma
// tela 500 crua e muda. Loga via Logger e mostra uma página amigável. ────────
set_exception_handler(function (\Throwable $e): void {
    try {
        \App\Core\Logger::error('Erro não tratado (' . get_class($e) . ')', [
            'msg'  => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'uri'  => $_SERVER['REQUEST_URI'] ?? null,
        ]);
    } catch (\Throwable $loggingError) {
        // Se nem o log funcionar, segue para exibir a tela de erro mesmo assim.
    }

    if (!headers_sent()) {
        http_response_code(500);
    }

    $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
        . '<title>Erro — VOXEL PACS</title></head>'
        . '<body style="font-family:sans-serif;background:#1a1d29;color:#e6e6e6;'
        . 'display:flex;align-items:center;justify-content:center;height:100vh;margin:0;">'
        . '<div style="max-width:520px;text-align:center;">'
        . '<h2 style="color:#e05252;">Ocorreu um erro inesperado</h2>'
        . '<p>Nossa equipe já foi notificada. Tente novamente em instantes.</p>'
        . ($debug ? '<pre style="text-align:left;white-space:pre-wrap;color:#f0a0a0;">'
            . htmlspecialchars($e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine())
            . '</pre>' : '')
        . '<p><a href="/estudos" style="color:#6ea8fe;">Voltar para a Worklist</a></p>'
        . '</div></body></html>';
});

// Função simples para ler .env sem dependência externa
if (!function_exists('loadEnv')) {
    function loadEnv(string $path): void {
        if (!file_exists($path)) return;

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) continue;

            $name  = trim($parts[0]);
            $value = trim($parts[1]);

            // Remove aspas se houver
            if (preg_match('/^"(.*)"$/s', $value, $m)) {
                $value = $m[1];
            } elseif (preg_match("/^'(.*)'$/s", $value, $m)) {
                $value = $m[1];
            }

            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name]    = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

// Carrega variáveis de ambiente
loadEnv(BASE_PATH . '/.env');

// Configura timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo');

// Se debug estiver ativo, mostra erros
if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
    ini_set('display_errors', 1);
}
