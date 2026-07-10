<?php
namespace App\Middlewares;

use App\Core\Middleware;

class SessionTimeoutMiddleware extends Middleware {
    public function handle(): void {
        $timeout = (int) ($_ENV['SESSION_TIMEOUT'] ?? 3600);

        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
            session_unset();
            session_destroy();
            header('Location: /login?error=sessao_expirada');
            exit;
        }

        $_SESSION['last_activity'] = time();
    }
}
