<?php

namespace App\Controllers\Platform;

use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Models\Tenant;
use App\Repositories\ImagiflowIntegrationRepository;
use Throwable;

final class ImagiflowIntegrationController extends Controller
{
    private ImagiflowIntegrationRepository $repository;
    private Tenant $tenants;

    public function __construct()
    {
        $this->repository = new ImagiflowIntegrationRepository(Database::getInstance());
        $this->tenants = new Tenant();
    }

    public function show(int $tenantId): void
    {
        if (!$this->platformAdmin()) return;
        $tenant = $this->tenants->find($tenantId);
        if (!$tenant) {
            http_response_code(404);
            echo 'Negócio não encontrado.';
            return;
        }
        $integration = $this->repository->findByTenant($tenantId);
        if ($integration) unset($integration['secret_ciphertext']);
        $this->view('platform/negocios/imagiflow', [
            'tenant' => $tenant,
            'integration' => $integration,
            'logs' => $this->repository->recentLogs($tenantId),
            'csrfToken' => $this->csrfToken(),
        ], 'platform');
    }

    public function generate(int $tenantId): void
    {
        if (!$this->platformAdmin(true) || !$this->validCsrf()) return;
        if (!$this->tenants->find($tenantId)) {
            $this->json(['success' => false, 'message' => 'Negócio não encontrado.'], 404);
            return;
        }
        try {
            $credential = $this->repository->regenerate($tenantId, (int) Auth::userId());
            AuditLogger::log('imagiflow.credential_generated', 'bi_imagiflow_integrations', $credential['id'], ['tenant_id' => $tenantId]);
            $this->json([
                'success' => true,
                'message' => 'Credencial gerada. Copie a chave agora; ela não será exibida novamente.',
                'integration_code' => $credential['code'],
                'secret' => $credential['secret'],
            ]);
        } catch (Throwable $e) {
            Logger::error('[ImagiflowIntegrationController::generate] Falha', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
            $this->json(['success' => false, 'message' => 'Não foi possível gerar a credencial.'], 500);
        }
    }

    public function revoke(int $tenantId): void
    {
        if (!$this->platformAdmin(true) || !$this->validCsrf()) return;
        try {
            $this->repository->revoke($tenantId);
            AuditLogger::log('imagiflow.credential_revoked', 'bi_imagiflow_integrations', null, ['tenant_id' => $tenantId]);
            $this->json(['success' => true, 'message' => 'Integração Imagiflow revogada.']);
        } catch (Throwable $e) {
            Logger::error('[ImagiflowIntegrationController::revoke] Falha', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
            $this->json(['success' => false, 'message' => 'Não foi possível revogar a integração.'], 500);
        }
    }

    private function platformAdmin(bool $json = false): bool
    {
        if (Auth::check() && Auth::isPlatformAdmin()) return true;
        if ($json) $this->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        else $this->redirect('/login');
        return false;
    }

    private function validCsrf(): bool
    {
        if (hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['_csrf_token'] ?? ''))) return true;
        $this->json(['success' => false, 'message' => 'Sessão expirada. Atualize a página e tente novamente.'], 419);
        return false;
    }
}
