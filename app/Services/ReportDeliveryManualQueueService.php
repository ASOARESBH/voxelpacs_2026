<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use Throwable;

/**
     * Reenfileira, por solicitação administrativa explícita, um laudo já liberado.
 *
 * Não executa entrega externa: apenas cria o evento e os jobs idempotentes da
 * outbox, aplicando exatamente a mesma regra de InstitutionName da liberação.
 */
final class ReportDeliveryManualQueueService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{created:bool,outbox_id:int|null,job_count:int,reason?:string,report_id:int} */
    public function queueReleasedReportByPublicToken(int $tenantId, string $publicToken, int $requestedBy): array
    {
        if ($tenantId <= 0 || !preg_match('/^[a-f0-9]{48}$/', $publicToken)) {
            throw new DomainException('Informe um link de laudo liberado válido.');
        }

        $stmt = $this->pdo->prepare(
            "SELECT r.*, e.*,
                    r.id AS report_id,
                    e.id AS source_estudo_id,
                    r.tenant_id AS report_tenant_id
             FROM reports r
             INNER JOIN bi_pacs_estudos e ON e.id = r.estudo_id
             WHERE r.public_token = :token
               AND r.tenant_id = :tenant_id
             LIMIT 1"
        );
        $stmt->execute([':token' => $publicToken, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);

        if (!$row) {
            throw new DomainException('Laudo não encontrado neste negócio.');
        }
        if ((string) ($row->situacao ?? '') !== 'liberado') {
            throw new DomainException('Somente laudos liberados podem ser enviados para homologação.');
        }

        $reportId = (int) $row->report_id;
        $estudoId = (int) $row->source_estudo_id;
        if ($reportId <= 0 || $estudoId <= 0) {
            throw new DomainException('O laudo não possui estudo válido para devolutiva.');
        }

        $reportVersion = $this->latestVersion($reportId);
        $sections = [
            'exame' => (string) ($row->secao_exame ?? ''),
            'tecnica' => (string) ($row->secao_tecnica ?? ''),
            'achados' => (string) ($row->secao_achados ?? ''),
            'conclusao' => (string) ($row->secao_conclusao ?? ''),
            'recomendacao' => (string) ($row->secao_recomendacao ?? ''),
        ];
        $reportHash = hash('sha256', json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $releasedBy = (int) ($row->liberado_por ?? 0) ?: $requestedBy;
        $releasedAt = trim((string) ($row->liberado_em ?? '')) ?: gmdate('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $result = (new ReportDeliveryOutboxService($this->pdo))->queueReleasedReport(
                $tenantId,
                $reportId,
                $estudoId,
                $reportVersion,
                $row,
                $row,
                $releasedBy,
                $releasedAt,
                $reportHash,
                true,
                'manual_homologation'
            );
            $this->pdo->commit();

            return $result + ['report_id' => $reportId];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Fallback operacional por ID do Report mostrado no Hub. A operação apenas
     * reenfileira jobs terminais; não cria jobs novos para laudos históricos.
     *
     * @return array{created:bool,outbox_id:int|null,job_count:int,reason?:string,report_id:int,retried_jobs:int}
     */
    public function queueReleasedReportById(int $tenantId, int $reportId, int $requestedBy): array
    {
        if ($tenantId <= 0 || $reportId <= 0) {
            throw new DomainException('Laudo inválido para reenvio.');
        }

        $repository = new \App\Repositories\ReportDeliveryRepository($this->pdo);
        $retried = $repository->retryTerminalJobsForReport($reportId, $tenantId, $requestedBy);
        if ($retried === 0) {
            throw new DomainException('Nenhuma falha terminal elegível foi encontrada para reenvio manual.');
        }
        return ['created' => false, 'outbox_id' => null, 'job_count' => $retried, 'report_id' => $reportId, 'retried_jobs' => $retried];
    }

    private function latestVersion(int $reportId): int
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(versao), 1) FROM report_versions WHERE report_id = :report_id');
            $stmt->execute([':report_id' => $reportId]);
            return max(1, (int) $stmt->fetchColumn());
        } catch (Throwable) {
            $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(versao_numero), 1) FROM report_versions WHERE report_id = :report_id');
            $stmt->execute([':report_id' => $reportId]);
            return max(1, (int) $stmt->fetchColumn());
        }
    }
}
