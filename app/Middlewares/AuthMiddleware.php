<?php
namespace App\Middlewares;

use App\Core\Auth;
use App\Core\Middleware;

class AuthMiddleware extends Middleware {
    public function handle(): void {
        if (!Auth::check()) {
            header('Location: /login');
            exit;
        }
    }
}
