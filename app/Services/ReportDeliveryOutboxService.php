<?php

namespace App\Services;

use App\Core\Logger;
use App\Repositories\ReportDeliveryRepository;
use PDO;
use Throwable;

/**
 * Cria eventos de devolutiva dentro da transação clínica de liberação.
 *
 * Este serviço não gera PDF, não abre conexão DICOM, SFTP, HL7 ou HTTP. A
 * execução externa será feita exclusivamente pelo worker do Delivery Hub.
 */
class ReportDeliveryOutboxService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{created:bool,outbox_id:int|null,job_count:int,reason?:string}
     */
    public function queueReleasedReport(
        int $tenantId,
        int $reportId,
        int $estudoId,
        int $reportVersion,
        object $report,
        object $estudo,
        int $releasedBy,
        string $releasedAt,
        string $reportHash
    ): array {
        if (!$this->enabled()) {
            return ['created' => false, 'outbox_id' => null, 'job_count' => 0, 'reason' => 'feature_disabled'];
        }

        if ($tenantId <= 0 || $reportId <= 0 || $estudoId <= 0 || $reportVersion < 1) {
            throw new \InvalidArgumentException('Dados insuficientes para registrar a devolutiva do laudo.');
        }

        $estabelecimentoId = (int) ($estudo->estabelecimento_id ?? $estudo->unidade_id ?? 0) ?: null;
        $eventType = 'report.released';
        $eventKey = hash('sha256', implode('|', [
            $tenantId,
            $reportId,
            $reportVersion,
            $eventType,
            $reportHash,
        ]));

        $payload = [
            'schema_version' => 1,
            'event_type' => $eventType,
            'tenant_id' => $tenantId,
            'estabelecimento_id' => $estabelecimentoId,
            'report_id' => $reportId,
            'report_version' => $reportVersion,
            'estudo_id' => $estudoId,
            'study_instance_uid' => (string) ($estudo->study_instance_uid ?? $report->study_instance_uid ?? ''),
            'accession_number' => (string) ($estudo->accession_number ?? $estudo->numero_acesso ?? ''),
            'patient_id' => (string) ($estudo->patient_id ?? $estudo->paciente_id_externo ?? ''),
            'modality' => (string) ($estudo->modality ?? $estudo->modalidade ?? ''),
            'released_by' => $releasedBy,
            'released_at' => $releasedAt,
            'report_sha256' => $reportHash,
        ];

        try {
            $repository = new ReportDeliveryRepository($this->pdo);
            $outboxId = $repository->createOutboxIfAbsent(
                $tenantId,
                $estabelecimentoId,
                $reportId,
                $estudoId,
                $reportVersion,
                $eventType,
                $eventKey,
                $payload
            );
            $destinations = $repository->findActiveDestinations($tenantId, $estabelecimentoId);
            $jobs = $repository->createJobs($outboxId, $tenantId, $estabelecimentoId, $eventKey, $destinations);

            if ($jobs === 0 && empty($destinations)) {
                $repository->markOutboxWithoutDestination($outboxId);
            }

            return [
                'created' => true,
                'outbox_id' => $outboxId,
                'job_count' => $jobs,
            ];
        } catch (Throwable $e) {
            Logger::error('[ReportDeliveryOutbox] Falha ao criar evento transacional de devolutiva', [
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'estudo_id' => $estudoId,
                'report_version' => $reportVersion,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function enabled(): bool
    {
        return filter_var(
            getenv('VOXEL_REPORT_DELIVERY_HUB_ENABLED') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        );
    }
}
