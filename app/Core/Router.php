<?php
namespace App\Core;

class Router {
    private static array $routes = [];

    private static array $publicRoutes = [
        '/login',
        '/logout',
        '/selecionar-empresa',
        '/open/',
    ];

    public static function get(string $path, $handler): void {
        self::$routes[] = ['method' => 'GET', 'path' => $path, 'handler' => $handler];
    }

    public static function post(string $path, $handler): void {
        self::$routes[] = ['method' => 'POST', 'path' => $path, 'handler' => $handler];
    }

    public static function group(array $options, callable $callback): void {
        $callback();
    }

    private static function isPublicRoute(string $uri): bool {
        foreach (self::$publicRoutes as $pub) {
            if ($uri === $pub || strpos($uri, $pub) === 0) return true;
        }
        return false;
    }

    public static function dispatch(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = strtok($_SERVER['REQUEST_URI'], '?');

        if (!self::isPublicRoute($uri) && !Auth::check()) {
            header('Location: /login');
            exit;
        }

        foreach (self::$routes as $route) {
            $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';

            if ($route['method'] === $method && preg_match($pattern, $uri, $matches)) {
                array_shift($matches);

                if (is_callable($route['handler'])) {
                    try { call_user_func_array($route['handler'], $matches); }
                    catch (\Throwable $e) { self::handleError($e); }
                    return;
                }

                [$controllerName, $action] = explode('@', $route['handler']);
                $class = "App\\Controllers\\{$controllerName}";

                if (!class_exists($class)) {
                    Logger::error("Controller não encontrado: {$class}");
                    self::renderErrorPage(500, "Controller não encontrado",
                        "O controlador <code>{$class}</code> não foi encontrado no sistema.");
                    return;
                }

                try {
                    $controller = new $class();
                    call_user_func_array([$controller, $action], $matches);
                } catch (\Throwable $e) {
                    self::handleError($e);
                }
                return;
            }
        }

        self::renderErrorPage(404, "Página não encontrada",
            "A página que você está procurando não existe ou foi movida.");
    }

    private static function handleError(\Throwable $e): void {
        http_response_code(500);
        Logger::error('Erro não tratado na rota', [
            'exception' => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);

        if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
            self::renderErrorPage(500, "Erro Interno do Servidor",
                "<strong>" . htmlspecialchars($e->getMessage()) . "</strong><br><small>" .
                htmlspecialchars($e->getFile()) . " na linha " . $e->getLine() . "</small>",
                $e->getTraceAsString());
        } else {
            self::renderErrorPage(500, "Erro Interno do Servidor",
                "Ocorreu um erro ao processar sua requisição. Tente novamente mais tarde.");
        }
    }

    /**
     * Página de erro estilizada com CSS inline blindado.
     * Não depende de nenhum arquivo externo — funciona mesmo se CSS/JS estiver indisponível.
     */
    private static function renderErrorPage(int $code, string $title, string $message, string $trace = ''): void {
        http_response_code($code);
        $icon    = $code === 404 ? '&#128269;' : '&#9888;&#65039;';
        $backUrl = '/estudos';
        $uri     = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        if (strpos($uri, '/platform') === 0) $backUrl = '/platform/dashboard';
        echo '<!DOCTYPE html><html lang="pt-BR"><head>';
        echo '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">';
        echo '<title>Erro ' . $code . ' — VOXEL PACS</title>';
        echo '<style>';
        echo '*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}';
        echo 'body{font-family:system-ui,-apple-system,sans-serif;background:#0a1628;color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}';
        echo '.ec{background:#0d1b2a;border:1px solid #1e3a5f;border-radius:12px;padding:2.5rem;max-width:560px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.5)}';
        echo '.ei{font-size:3rem;margin-bottom:1rem;display:block}';
        echo '.ecode{font-size:4rem;font-weight:800;color:#4fc3f7;line-height:1;margin-bottom:.5rem}';
        echo '.etitle{font-size:1.2rem;font-weight:600;color:#e2e8f0;margin-bottom:.75rem}';
        echo '.emsg{font-size:.9rem;color:#8fa3bf;line-height:1.6;margin-bottom:1.5rem}';
        echo '.emsg code{background:#1e3a5f;padding:.1em .35em;border-radius:4px;font-size:.85em;color:#4fc3f7}';
        echo '.etrace{background:#060f1a;border:1px solid #1e3a5f;border-radius:8px;padding:1rem;text-align:left;font-size:.72rem;color:#64748b;overflow:auto;max-height:200px;margin-bottom:1.5rem;font-family:monospace;white-space:pre-wrap;word-break:break-all}';
        echo '.btn{display:inline-flex;align-items:center;gap:.5rem;background:#4fc3f7;color:#0a1628;padding:.65rem 1.5rem;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem}';
        echo '.brand{margin-top:1.5rem;font-size:.75rem;color:#3d5a7a;letter-spacing:.05em}';
        echo '</style></head><body>';
        echo '<div class="ec">';
        echo '<span class="ei">' . $icon . '</span>';
        echo '<div class="ecode">' . $code . '</div>';
        echo '<div class="etitle">' . htmlspecialchars($title) . '</div>';
        echo '<div class="emsg">' . $message . '</div>';
        if ($trace) echo '<div class="etrace">' . htmlspecialchars($trace) . '</div>';
        echo '<a href="' . $backUrl . '" class="btn">&#8592; Voltar</a>';
        echo '<div class="brand">VOXEL PACS</div>';
        echo '</div></body></html>';
        exit;
    }
}
