<?php

declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Services\ReportDeliveryRequestService;
use DomainException;
use Throwable;

final class ReportDeliveryRequestController extends Controller
{
    public function get(int $tenantId, int $requestId): void
    {
        $this->authorizeTenant($tenantId);
        try {
            $result = $this->service()->get($tenantId, $requestId);
            $this->json(['success' => true, 'feature_enabled' => ReportDeliveryRequestService::isEnabled(), 'request' => $result]);
        } catch (DomainException $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        } catch (Throwable $e) {
            $this->logFailure('get', $tenantId, $requestId, $e);
            $this->json(['success' => false, 'message' => 'Não foi possível consultar a Delivery Request.'], 500);
        }
    }

    public function prepare(int $tenantId): void
    {
        $this->authorizePost($tenantId, 'confirm_prepare');
        try {
            $input = $_POST;
            $input['request_uuid'] = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
            $result = $this->service()->prepare($tenantId, $input, (int) Auth::userId());
            AuditLogger::log('report_delivery.request_prepared', 'pacs_report_delivery_requests', (int) $result['id'], [
                'tenant_id' => $tenantId,
                'report_id' => (int) $result['report_id'],
                'report_version' => (int) $result['report_version'],
                'destination_id' => (int) $result['destination_id'],
                'delivery_profile' => (string) $result['delivery_profile'],
            ], $tenantId);
            $this->json(['success' => true, 'request' => $result], 201);
        } catch (DomainException $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        } catch (Throwable $e) {
            $this->logFailure('prepare', $tenantId, null, $e);
            $this->json(['success' => false, 'message' => 'Não foi possível preparar a Delivery Request.'], 500);
        }
    }

    public function approve(int $tenantId, int $requestId): void
    {
        $this->authorizePost($tenantId, 'confirm_approve');
        try {
            $result = $this->service()->approve($tenantId, $requestId, (int) Auth::userId());
            $this->auditTransition('approved', $tenantId, $requestId, $result);
            $this->json(['success' => true, 'request' => $result]);
        } catch (DomainException $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        } catch (Throwable $e) {
            $this->logFailure('approve', $tenantId, $requestId, $e);
            $this->json(['success' => false, 'message' => 'Não foi possível aprovar a Delivery Request.'], 500);
        }
    }

    public function materialize(int $tenantId, int $requestId): void
    {
        $this->authorizePost($tenantId, 'confirm_materialize');
        try {
            $result = $this->service()->materialize($tenantId, $requestId, (int) Auth::userId());
            $this->auditTransition('materialized', $tenantId, $requestId, $result);
            $this->json(['success' => true, 'request' => $result]);
        } catch (DomainException $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        } catch (Throwable $e) {
            $this->logFailure('materialize', $tenantId, $requestId, $e);
            $this->json(['success' => false, 'message' => 'Não foi possível materializar a Delivery Request.'], 500);
        }
    }

    public function arm(int $tenantId, int $requestId): void
    {
        $this->authorizePost($tenantId, 'confirm_arm');
        try {
            $result = $this->service()->arm($tenantId, $requestId, (int) Auth::userId());
            $this->auditTransition('armed', $tenantId, $requestId, $result);
            $this->json(['success' => true, 'request' => $result]);
        } catch (DomainException $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        } catch (Throwable $e) {
            $this->logFailure('arm', $tenantId, $requestId, $e);
            $this->json(['success' => false, 'message' => 'Não foi possível armar a Delivery Request.'], 500);
        }
    }

    public function cancel(int $tenantId, int $requestId): void
    {
        $this->authorizePost($tenantId, 'confirm_cancel');
        try {
            $result = $this->service()->cancel($tenantId, $requestId, (int) Auth::userId());
            $this->auditTransition('cancelled', $tenantId, $requestId, $result);
            $this->json(['success' => true, 'request' => $result]);
        } catch (DomainException $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        } catch (Throwable $e) {
            $this->logFailure('cancel', $tenantId, $requestId, $e);
            $this->json(['success' => false, 'message' => 'Não foi possível cancelar a Delivery Request.'], 500);
        }
    }

    public function expire(int $tenantId, int $requestId): void
    {
        $this->authorizePost($tenantId, 'confirm_expire');
        try {
            $result = $this->service()->expire($tenantId, $requestId, (int) Auth::userId());
            $this->auditTransition('expired', $tenantId, $requestId, $result);
            $this->json(['success' => true, 'request' => $result]);
        } catch (DomainException $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        } catch (Throwable $e) {
            $this->logFailure('expire', $tenantId, $requestId, $e);
            $this->json(['success' => false, 'message' => 'Não foi possível expirar a Delivery Request.'], 500);
        }
    }

    private function service(): ReportDeliveryRequestService
    {
        return new ReportDeliveryRequestService(Database::getInstance());
    }

    private function authorizeTenant(int $tenantId): void
    {
        if (!Auth::check()) {
            $this->json(['success' => false, 'message' => 'Não autenticado.'], 401);
        }
        if (Auth::isPlatformAdmin()) {
            return;
        }
        if ((int) Auth::tenantId() !== $tenantId || Auth::perfilAtual() !== 'admin') {
            $this->json(['success' => false, 'message' => 'Sem permissão para este tenant.'], 403);
        }
    }

    private function authorizePost(int $tenantId, string $confirmation = ''): void
    {
        $this->authorizeTenant($tenantId);
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        $provided = (string) ($_POST['_csrf_token'] ?? '');
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            $this->json(['success' => false, 'message' => 'Sessão expirada. Atualize a página e tente novamente.'], 419);
        }
        if ($confirmation !== '' && (string) ($_POST[$confirmation] ?? '') !== '1') {
            $this->json(['success' => false, 'message' => 'Confirmação explícita obrigatória.'], 422);
        }
    }

    private function statusFor(DomainException $e): int
    {
        $code = (int) $e->getCode();
        return in_array($code, [403, 404, 409, 422, 503], true) ? $code : 422;
    }

    /** @param array<string,mixed> $result */
    private function auditTransition(string $transition, int $tenantId, int $requestId, array $result): void
    {
        AuditLogger::log('report_delivery.request_' . $transition, 'pacs_report_delivery_requests', $requestId, [
            'tenant_id' => $tenantId,
            'status' => (string) ($result['status'] ?? $transition),
            'outbox_id' => isset($result['outbox_id']) ? (int) $result['outbox_id'] : null,
            'job_id' => isset($result['job_id']) ? (int) $result['job_id'] : null,
        ], $tenantId);
    }

    private function logFailure(string $operation, int $tenantId, ?int $requestId, Throwable $e): void
    {
        Logger::error('[DeliveryRequestController] falha sanitizada', [
            'operation' => $operation,
            'tenant_id' => $tenantId,
            'request_id' => $requestId,
            'error_class' => get_class($e),
        ]);
    }
}
