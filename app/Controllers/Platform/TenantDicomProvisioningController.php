<?php
namespace App\Controllers\Platform;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Services\TenantDicomProvisioningService;
use App\Services\TenantVpnKitPdfService;

/** Endpoints de onboarding operacional de células DICOM VPN-only. */
final class TenantDicomProvisioningController extends Controller
{
    public function create(): void
    {
        $pdo = Database::getInstance();
        $tenants = $pdo->query("SELECT t.id, t.nome, t.slug FROM bi_tenants t WHERE t.status <> 'cancelado' AND NOT EXISTS (SELECT 1 FROM bi_tenant_orthanc_cells c WHERE c.tenant_id=t.id) ORDER BY t.nome")
            ->fetchAll(\PDO::FETCH_ASSOC);
        $this->view('platform/servidor_pacs/novo_tenant', [
            'tenants' => $tenants,
            'csrfToken' => $this->csrfToken(),
        ], 'platform');
    }

    public function createAndProvision(): void
    {
        $this->requireCsrf();
        if (($_POST['confirm_provision'] ?? '') !== '1') {
            $_SESSION['error'] = 'Confirme o provisionamento controlado antes de continuar.';
            $this->redirect('/platform/servidor-pacs/novo');
        }
        try {
            $service = new TenantDicomProvisioningService();
            $reservation = $service->reserve($_POST, Auth::userId());
            $provisioned = $service->provision((string) $reservation['operation_id'], Auth::userId());
            $_SESSION['success'] = 'Célula criada com rota restrita a C-ECHO. Baixe o kit VPN-only e configure o cliente antes de validar a comunicação.';
            $this->redirect('/platform/servidor-pacs/' . (int) $provisioned['servidor_id'] . '/configurar');
        } catch (\Throwable $error) {
            Logger::error('Provisionamento DICOM tenant não concluído', [
                'user_id' => Auth::userId(),
                'error_type' => get_class($error),
            ]);
            $_SESSION['error'] = $this->safeMessage($error);
            $this->redirect('/platform/servidor-pacs/novo');
        }
    }

    public function status(int $serverId): void
    {
        $this->respondJson(function () use ($serverId): array {
            $row = (new TenantDicomProvisioningService())->getByServer($serverId);
            return [
                'success' => true,
                'status' => $row['status'],
                'step' => $row['current_step'],
                'last_error_code' => $row['last_error_code'],
                'last_error_message' => $row['last_error_message'],
                'echo_ready_at' => $row['echo_ready_at'],
                'echo_validated_at' => $row['echo_validated_at'],
            ];
        });
    }

    public function verifyEcho(int $serverId): void
    {
        $this->requireCsrfJson();
        $this->respondJson(function () use ($serverId): array {
            $result = (new TenantDicomProvisioningService())->verifyEcho($serverId);
            return [
                'success' => ($result['status'] ?? 'pending') === 'echo_validated',
                'status' => $result['status'] ?? 'pending',
                'message' => $result['message'] ?? 'Aguardando C-ECHO.',
            ];
        });
    }

    public function kit(int $serverId): void
    {
        try {
            $pdo = Database::getInstance();
            $operation = (new TenantDicomProvisioningService($pdo))->getByServer($serverId);
            if (!in_array($operation['status'], ['echo_ready', 'echo_validated', 'active'], true) || empty($operation['gateway_public_key'])) {
                throw new \RuntimeException('O kit VPN-only estará disponível depois que o peer WireGuard for criado.');
            }
            $tenant = $pdo->prepare('SELECT nome FROM bi_tenants WHERE id=?');
            $tenant->execute([(int) $operation['tenant_id']]);
            $tenantName = (string) ($tenant->fetchColumn() ?: 'Tenant');
            (new TenantVpnKitPdfService())->stream($operation, $tenantName);
        } catch (\Throwable $error) {
            $_SESSION['error'] = $this->safeMessage($error);
            $this->redirect('/platform/servidor-pacs/' . $serverId . '/configurar');
        }
    }

    public function activateCstore(int $serverId): void
    {
        $this->requireCsrfJson();
        $this->respondJson(function () use ($serverId): array {
            $confirm = trim((string) ($_POST['confirm'] ?? ''));
            if ($confirm !== 'LIBERAR C-STORE') {
                throw new \RuntimeException('Digite LIBERAR C-STORE para confirmar a recepção de dados DICOM.');
            }
            $operation = (new TenantDicomProvisioningService())->activateCstore($serverId);
            return ['success' => true, 'status' => $operation['status'], 'message' => 'C-STORE liberado para esta rota após confirmação explícita.'];
        });
    }

    private function requireCsrf(): void
    {
        $provided = (string) ($_POST['_csrf_token'] ?? '');
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
            http_response_code(403);
            exit('Token CSRF inválido.');
        }
    }

    private function requireCsrfJson(): void
    {
        $provided = (string) ($_POST['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.']);
            exit;
        }
    }

    /** @param callable():array<string,mixed> $callback */
    private function respondJson(callable $callback): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            echo json_encode($callback(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            Logger::warning('Ação operacional tenant recusada', ['user_id' => Auth::userId(), 'error_type' => get_class($error)]);
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $this->safeMessage($error)], JSON_UNESCAPED_UNICODE);
        }
    }

    private function safeMessage(\Throwable $error): string
    {
        $message = trim($error->getMessage());
        return $message !== '' && strlen($message) <= 220 ? $message : 'A operação não foi concluída; revise o status técnico seguro.';
    }
}
