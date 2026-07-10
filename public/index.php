<?php
/**
 * VOXEL B.I - Entry Point
 * Compatível com HostGator Compartilhado (LiteSpeed + PHP 8.x)
 */

// Carrega o bootstrap: paths, sessão, headers, autoload, env
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Router;
use App\Core\Auth;
use App\Middlewares\TenantMiddleware;

// Rotas que NÃO precisam de autenticação nem de tenant
$rotasPublicas = ['/login', '/logout', '/selecionar-empresa', '/test.php', '/api/orthanc/ping', '/open/'];
$uriAtual = strtok($_SERVER['REQUEST_URI'], '?');

// Carrega rotas
require_once BASE_PATH . '/routes/web.php';
require_once BASE_PATH . '/routes/platform.php';

// Determina se a rota atual é pública ou de plataforma
$ehPublica = false;
foreach ($rotasPublicas as $pub) {
    if ($uriAtual === $pub || strpos($uriAtual, $pub) === 0) {
        $ehPublica = true;
        break;
    }
}
$ehPlataforma = strpos($uriAtual, '/platform') === 0;

// Aplica TenantMiddleware apenas em rotas protegidas de tenant
// Isso carrega o TenantContext a partir da sessão antes do controller ser chamado
if (!$ehPublica && !$ehPlataforma && Auth::check()) {
    (new TenantMiddleware())->handle();
}

// Despacha a requisição
Router::dispatch();
