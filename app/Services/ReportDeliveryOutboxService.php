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
        string $reportHash,
        bool $reactivateDryRun = false,
        string $dispatchMode = 'automatic_production'
    ): array {
        if (!$this->enabled()) {
            return ['created' => false, 'outbox_id' => null, 'job_count' => 0, 'reason' => 'feature_disabled'];
        }

        if ($tenantId <= 0 || $reportId <= 0 || $estudoId <= 0 || $reportVersion < 1) {
            throw new \InvalidArgumentException('Dados insuficientes para registrar a devolutiva do laudo.');
        }
        $allowedEnvironments = match ($dispatchMode) {
            'automatic_production' => ['producao'],
            'manual_homologation' => ['homologacao'],
            default => throw new \InvalidArgumentException('Modo de despacho inválido para devolutiva.'),
        };
        $automaticDispatchDate = $dispatchMode === 'automatic_production'
            ? $this->clinicalDate($releasedAt)
            : null;

        $estabelecimentoId = (int) ($estudo->estabelecimento_id ?? $estudo->unidade_id ?? 0) ?: null;
        $rawInstitutionName = trim((string) ($estudo->institution_name ?? ''));
        $institutionName = InstitutionResolverService::canonicalForTenant($tenantId, $rawInstitutionName);
        $issuer = DicomIssuerService::sanitizeIssuer($estudo->issuer_of_patient_id ?? null);
        $issuerNormalized = DicomIssuerService::normalize($issuer);
        $routingBasis = $issuerNormalized !== null ? 'issuer' : ($institutionName !== null ? 'institution_name_fallback' : 'none');
        $eventType = 'report.released';
        $eventKey = hash('sha256', implode('|', [
            $tenantId,
            $reportId,
            $reportVersion,
            $eventType,
            $reportHash,
        ]));

        $payload = [
            'schema_version' => 2,
            'event_type' => $eventType,
            'tenant_id' => $tenantId,
            'estabelecimento_id' => $estabelecimentoId,
            'report_id' => $reportId,
            'report_version' => $reportVersion,
            'estudo_id' => $estudoId,
            'institution_name' => $institutionName,
            'institution_name_received' => $rawInstitutionName,
            'issuer_of_patient_id' => $issuer,
            'issuer_of_patient_id_normalized' => $issuerNormalized,
            'routing_basis' => $routingBasis,
            'dispatch_mode' => $dispatchMode,
            'automatic_dispatch_date' => $automaticDispatchDate,
            'study_instance_uid' => (string) ($estudo->study_instance_uid ?? $report->study_instance_uid ?? ''),
            'accession_number' => (string) ($estudo->accession_number ?? $estudo->numero_acesso ?? ''),
            'patient_id' => (string) ($estudo->patient_id ?? $estudo->paciente_id_externo ?? ''),
            'patient_name' => (string) ($estudo->patient_name_display ?? $estudo->patient_name ?? ''),
            'patient_birth_date' => (string) ($estudo->patient_birth_date ?? ''),
            'patient_sex' => (string) ($estudo->patient_sex ?? ''),
            'study_date' => (string) ($estudo->study_date ?? ''),
            'study_time' => (string) ($estudo->study_time ?? ''),
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
            $destinations = array_values(array_filter(
                $repository->findActiveDestinations($tenantId, $estabelecimentoId, $issuerNormalized, $institutionName),
                static fn(array $destination): bool => in_array((string) ($destination['ambiente'] ?? ''), $allowedEnvironments, true)
            ));
            $jobs = $repository->createJobs($outboxId, $tenantId, $estabelecimentoId, $eventKey, $destinations, $automaticDispatchDate);
            if ($jobs === 0 && $reactivateDryRun && !empty($destinations)) {
                $jobs = $repository->requeueDryRunJobs($outboxId, $tenantId);
            }

            if ($jobs > 0) {
                $repository->markOutboxQueued($outboxId);
            }

            if ($jobs === 0 && empty($destinations)) {
                $repository->markOutboxWithoutDestination($outboxId);
                Logger::warning('[ReportDeliveryOutbox] Nenhum destino associado à origem de devolução do estudo', [
                    'tenant_id' => $tenantId,
                    'estudo_id' => $estudoId,
                    'institution_name_received' => $rawInstitutionName,
                    'institution_name_canonical' => $institutionName,
                'issuer_of_patient_id_normalized' => $issuerNormalized,
                'routing_basis' => $routingBasis,
                'dispatch_mode' => $dispatchMode,
            ]);
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

    private function clinicalDate(string $releasedAt): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($releasedAt, 0, 19));
        if (!$date instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('Data de liberação inválida para a janela automática de devolutiva.');
        }
        return $date->format('Y-m-d');
    }
}
