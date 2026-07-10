<?php
namespace App\Middlewares;

use App\Core\Auth;
use App\Core\Middleware;
use App\Core\TenantContext;
use App\Models\Tenant;

class TenantMiddleware extends Middleware {
    public function handle(): void {
        if (!Auth::check()) {
            header('Location: /login');
            exit;
        }

        // Platform admin não precisa de tenant
        if (Auth::isPlatformAdmin()) return;

        $tenantId = Auth::tenantId();
        if (!$tenantId) {
            header('Location: /selecionar-empresa');
            exit;
        }

        $tenant = (new Tenant())->findById($tenantId);
        if (!$tenant || $tenant->status !== 'ativo') {
            Auth::logout();
            header('Location: /login?error=tenant_inativo');
            exit;
        }

        TenantContext::set($tenant);
    }
}
