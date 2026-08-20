<?php

declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use PDO;
use Throwable;

/**
 * Revisão humana obrigatória de dados queimados em pixels.
 * Rota inteira restrita ao superadmin da plataforma.
 */
final class PortalImageReviewController extends Controller
{
    public function index(int $tenantId): void
    {
        if (!$this->isPlatformAdmin()) {
            $this->redirect('/login');
        }
        $stmt = Database::getInstance()->prepare(
            "SELECT id, source_estudo_id, state, pixel_review_status, created_at, updated_at,
                    pixel_reviewed_at, pixel_reviewed_by, error_code
             FROM bi_portal_anonymized_studies
             WHERE tenant_id = :tenant_id
             ORDER BY updated_at DESC, id DESC
             LIMIT 100"
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        $this->view('platform/negocios/portal_images_review', [
            'tenantId' => $tenantId,
            'copies' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'csrfToken' => $this->csrfToken(),
        ], 'platform');
    }

    public function review(int $tenantId, int $copyId): void
    {
        if (!$this->isPlatformAdmin()) {
            $this->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }
        if (!$this->validCsrf()) {
            $this->json(['success' => false, 'message' => 'Sessão expirada. Atualize a página e tente novamente.'], 419);
        }
        $decision = strtolower(trim((string) ($_POST['decision'] ?? '')));
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            $this->json(['success' => false, 'message' => 'Decisão de revisão inválida.'], 422);
        }

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare(
                "UPDATE bi_portal_anonymized_studies
                 SET pixel_review_status = :decision,
                     pixel_reviewed_at = NOW(),
                     pixel_reviewed_by = :reviewer,
                     state = CASE WHEN :decision = 'rejected' THEN 'failed' ELSE state END,
                     error_code = CASE WHEN :decision = 'rejected' THEN 'pixel_review_rejected' ELSE NULL END,
                     updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant_id"
            );
            $stmt->execute([
                'decision' => $decision,
                'reviewer' => (int) Auth::userId(),
                'id' => $copyId,
                'tenant_id' => $tenantId,
            ]);
            if ($stmt->rowCount() !== 1) {
                $this->json(['success' => false, 'message' => 'Cópia não encontrada.'], 404);
            }
            AuditLogger::log('portal_images.pixel_review', 'bi_portal_anonymized_studies', $copyId, [
                'tenant_id' => $tenantId,
                'decision' => $decision,
            ]);
            $this->json(['success' => true, 'message' => $decision === 'approved' ? 'Cópia aprovada para homologação.' : 'Cópia rejeitada e bloqueada.']);
        } catch (Throwable $e) {
            Logger::error('PortalImageReviewController::review falhou', ['copy_id' => $copyId, 'error' => $e->getMessage()]);
            $this->json(['success' => false, 'message' => 'Não foi possível registrar a revisão.'], 500);
        }
    }

    private function isPlatformAdmin(): bool
    {
        return Auth::check() && Auth::isPlatformAdmin();
    }
}
