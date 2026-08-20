<?php

declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Services\PortalAnonymizedOrthancClient;
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
                    pixel_reviewed_at, pixel_reviewed_by, failure_code
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
                     failure_code = CASE WHEN :decision = 'rejected' THEN 'pixel_review_rejected' ELSE NULL END,
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

    /**
     * Fornece uma amostra PNG exclusivamente ao superadmin para a conferência humana
     * de dados queimados. Nunca é rota do Portal, não recebe token e não expõe UID.
     */
    public function preview(int $tenantId, int $copyId): void
    {
        if (!$this->isPlatformAdmin()) {
            http_response_code(404);
            return;
        }
        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT anonymized_orthanc_id FROM bi_portal_anonymized_studies
                 WHERE id = :id AND tenant_id = :tenant_id AND state = 'ready' LIMIT 1"
            );
            $stmt->execute(['id' => $copyId, 'tenant_id' => $tenantId]);
            $orthancStudyId = trim((string) $stmt->fetchColumn());
            if ($orthancStudyId === '') {
                http_response_code(404);
                return;
            }
            $client = new PortalAnonymizedOrthancClient(
                (string) (getenv('PORTAL_ANONYMIZED_ORTHANC_URL') ?: ''),
                (string) (getenv('PORTAL_ANONYMIZED_ORTHANC_USERNAME') ?: ''),
                (string) (getenv('PORTAL_ANONYMIZED_ORTHANC_PASSWORD') ?: ''),
            );
            $study = $client->study($orthancStudyId);
            $seriesId = (string) (($study['Series'] ?? [])[0] ?? '');
            $series = $seriesId === '' ? [] : $client->series($seriesId);
            $instanceId = (string) (($series['Instances'] ?? [])[0] ?? '');
            if ($instanceId === '') {
                http_response_code(404);
                return;
            }
            $png = $client->previewInstance($instanceId);
            AuditLogger::log('portal_images.pixel_preview', 'bi_portal_anonymized_studies', $copyId, ['tenant_id' => $tenantId]);
            header('Content-Type: image/png');
            header('Cache-Control: no-store, private');
            header('X-Content-Type-Options: nosniff');
            echo $png;
        } catch (Throwable $e) {
            Logger::error('PortalImageReviewController::preview falhou', ['copy_id' => $copyId, 'error' => $e->getMessage()]);
            http_response_code(404);
        }
    }

    private function isPlatformAdmin(): bool
    {
        return Auth::check() && Auth::isPlatformAdmin();
    }
}
