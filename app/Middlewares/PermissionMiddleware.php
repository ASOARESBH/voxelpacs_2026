<?php
namespace App\Middlewares;

use App\Core\Auth;
use App\Core\Middleware;

class PermissionMiddleware extends Middleware {
    private string $permission;

    public function __construct(string $permission) {
        $this->permission = $permission;
    }

    public function handle(): void {
        if (!Auth::can($this->permission)) {
            http_response_code(403);
            exit('Acesso negado: permissão insuficiente.');
        }
    }
}
