<?php
declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Services\ReportAccessService;
use App\Services\ReportVersionPdfRevisionService;
use DomainException;
use RuntimeException;
use Throwable;

/**
 * Control-plane para correção visual de uma versão de PDF já liberada.
 *
 * A rota não aceita conteúdo clínico nem fonte escolhida pelo cliente:
 * sempre reconstrói a revisão a partir de report_versions histórica.
 */
final class ReportPdfRevisionController extends Controller
{
    public function createVisualRendererCorrection(int $tenantId, int $reportId, int $version): void
    {
        $this->authorizePost($tenantId);

        if ($reportId <= 0 || $version <= 0) {
            $this->json(['success' => false, 'message' => 'Report e versão devem ser positivos.'], 422);
        }

        $report = (new ReportAccessService())->findAuthorizedReport($reportId, false);
        if (!$report || (int) ($report->tenant_id ?? 0) !== $tenantId) {
            AuditLogger::log('report.pdf_revision.denied', 'reports', $reportId, [
                'tenant_id' => $tenantId,
                'reason_code' => 'report_tenant_mismatch_or_not_found',
                'report_version' => $version,
            ], $tenantId, 'platform');
            $this->json(['success' => false, 'message' => 'Laudo não encontrado para este negócio.'], 404);
        }

        try {
            $result = (new ReportVersionPdfRevisionService())
                ->createFromHistoricalReportVersion(
                    $tenantId,
                    $reportId,
                    $version,
                    (int) Auth::userId()
                );

            AuditLogger::log('report.pdf_revision.created', 'pacs_report_version_pdf_revisions', (int) $result['id'], [
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'report_version' => $version,
                'revision_number' => (int) $result['revision_number'],
                'source_kind' => (string) $result['source_kind'],
                'reason_code' => (string) $result['reason_code'],
                'source_content_sha256' => $result['source_content_sha256'] ?? null,
                'pdf_snapshot_sha256' => (string) $result['pdf_snapshot_sha256'],
                'pdf_snapshot_size_bytes' => (int) $result['pdf_snapshot_size_bytes'],
            ], $tenantId, 'platform');

            $this->json([
                'success' => true,
                'message' => 'Revisão operacional criada ou localizada de forma idempotente.',
                'revision' => $this->publicMetadata($result),
            ]);
        } catch (DomainException $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], $this->statusFor($e));
        } catch (RuntimeException $e) {
            Logger::warning('[ReportPdfRevisionController::createVisualRendererCorrection] Revisão rejeitada', [
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'report_version' => $version,
                'error_class' => get_class($e),
            ]);
            AuditLogger::log('report.pdf_revision.rejected', 'reports', $reportId, [
                'tenant_id' => $tenantId,
                'report_version' => $version,
                'reason_code' => 'historical_revision_not_eligible',
                'error_class' => get_class($e),
            ], $tenantId, 'platform');
            $this->json([
                'success' => false,
                'message' => 'A versão histórica não está elegível para correção operacional.',
            ], 422);
        } catch (Throwable $e) {
            Logger::error('[ReportPdfRevisionController::createVisualRendererCorrection] Falha técnica', [
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'report_version' => $version,
                'error_class' => get_class($e),
            ]);
            AuditLogger::log('report.pdf_revision.failed', 'reports', $reportId, [
                'tenant_id' => $tenantId,
                'report_version' => $version,
                'reason_code' => 'technical_failure',
                'error_class' => get_class($e),
            ], $tenantId, 'platform');
            $this->json([
                'success' => false,
                'message' => 'Não foi possível materializar a revisão operacional.',
            ], 500);
        }
    }

    private function authorizePost(int $tenantId): void
    {
        if (!Auth::check() || !Auth::isPlatformAdmin()) {
            $this->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }

        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        $provided = (string) ($_POST['_csrf_token'] ?? '');
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            $this->json(['success' => false, 'message' => 'Sessão expirada. Atualize a página e tente novamente.'], 419);
        }

        if ((string) ($_POST['confirm_visual_renderer_correction'] ?? '') !== '1') {
            $this->json(['success' => false, 'message' => 'Confirmação explícita obrigatória.'], 422);
        }

        if ($tenantId <= 0) {
            $this->json(['success' => false, 'message' => 'Negócio inválido.'], 404);
        }
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function publicMetadata(array $result): array
    {
        return [
            'id' => (int) $result['id'],
            'tenant_id' => (int) $result['tenant_id'],
            'report_id' => (int) $result['report_id'],
            'report_version' => (int) $result['report_version'],
            'revision_number' => (int) $result['revision_number'],
            'source_kind' => (string) $result['source_kind'],
            'source_content_sha256' => $result['source_content_sha256'] ?? null,
            'pdf_snapshot_sha256' => (string) $result['pdf_snapshot_sha256'],
            'pdf_snapshot_size_bytes' => (int) $result['pdf_snapshot_size_bytes'],
            'pdf_snapshot_renderer' => (string) $result['pdf_snapshot_renderer'],
            'pdf_snapshot_schema_version' => (int) $result['pdf_snapshot_schema_version'],
            'reason_code' => (string) $result['reason_code'],
            'created_by' => $result['created_by'] !== null ? (int) $result['created_by'] : null,
        ];
    }

    private function statusFor(DomainException $exception): int
    {
        $code = (int) $exception->getCode();
        return in_array($code, [403, 404, 409, 422], true) ? $code : 422;
    }
}
