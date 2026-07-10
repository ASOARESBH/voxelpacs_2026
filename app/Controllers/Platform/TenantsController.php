<?php
namespace App\Controllers\Platform;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Audit\AuditLogger;
use App\Models\Tenant;
use App\Models\TenantPlan;

class TenantsController extends Controller {

    /**
     * Redireciona URLs antigas /platform/tenants para o novo módulo /platform/negocios
     */
    public function redirectToNegocios(): void {
        $this->redirect('/platform/negocios');
    }

    public function suspend(int $id): void {
        (new Tenant())->update($id, ['status' => 'suspenso']);
        AuditLogger::log('suspend_negocio', 'tenant', $id, ['by' => Auth::user()?->id]);
        $this->redirect('/platform/negocios');
    }

    public function impersonate(int $id): void {
        $_SESSION['impersonating_tenant_id'] = $id;
        $_SESSION['original_user']           = $_SESSION['user'];
        $_SESSION['tenant_id']               = $id;
        AuditLogger::log('impersonate', 'tenant', $id, ['impersonated_by' => Auth::user()?->id]);
        $this->redirect('/dashboard');
    }

    public function exitImpersonate(): void {
        unset($_SESSION['impersonating_tenant_id'], $_SESSION['tenant_id']);
        $this->redirect('/platform/negocios');
    }
}
