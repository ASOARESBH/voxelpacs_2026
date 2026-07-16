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

        $tenantId = Auth::tenantId();

        // Platform admin sem impersonação ativa (tenant_id null na sessão): visão global, sem tenant.
        // Impersonando (tenant_id setado por TenantsController::impersonate), segue o fluxo normal abaixo.
        if (Auth::isPlatformAdmin() && !$tenantId) {
            return;
        }

        if (!$tenantId) {
            header('Location: /selecionar-empresa');
            exit;
        }

        $tenant = (new Tenant())->findById($tenantId);
        if (!$tenant || $tenant->status !== 'ativo') {
            if (Auth::isPlatformAdmin()) {
                // Negócio impersonado ficou inválido/inativo: encerra a impersonação, não desloga o superadmin.
                unset($_SESSION['impersonating_tenant_id'], $_SESSION['tenant_id'], $_SESSION['original_user']);
                header('Location: /platform/negocios');
                exit;
            }
            Auth::logout();
            header('Location: /login?error=tenant_inativo');
            exit;
        }

        TenantContext::set($tenant);
    }
}
