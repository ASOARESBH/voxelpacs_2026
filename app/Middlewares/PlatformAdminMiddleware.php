<?php
namespace App\Middlewares;

use App\Core\Auth;
use App\Core\Middleware;

class PlatformAdminMiddleware extends Middleware {
    public function handle(): void {
        if (!Auth::check() || !Auth::isPlatformAdmin()) {
            http_response_code(403);
            exit('Acesso restrito ao administrador da plataforma.');
        }
    }
}
