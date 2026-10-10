<?php
// Materialização de runtime do roteamento manual: separa homologação explícita de automação por liberação.
// Materialização de runtime Philips Folder: não cria jobs enquanto a flag estiver desligada.
// Materialização de runtime inerte da Fase 1 Philips Non-DICOM; não ativa SMB, bridge, XML ou automação.
namespace App\Services;

use App\Config\ReportDeliveryRuntimeConfig;
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
     * Verifica a compatibilidade do PatientName congelado antes da transição
     * clínica para liberado. A assinatura pode continuar sendo persistida,
     * mas um destino submission_document sem Given deve impedir a liberação
     * e a criação de qualquer Outbox/Job incompatível.
     *
     * @return array{allowed:bool,reason:?string,destinations:array<int,array<string,mixed>>,submission_document_count:int}
     */
    public function assessReleaseCompatibility(
        int $tenantId,
        int $reportId,
        object $estudo,
        ?array $patientName,
        string $dispatchMode = 'automatic_production'
    ): array {
        if (!$this->enabled()) {
            return [
                'allowed' => true,
                'reason' => 'feature_disabled',
                'destinations' => [],
                'submission_document_count' => 0,
            ];
        }

        try {
            $destinations = $this->resolveEligibleDestinations($tenantId, $estudo, $dispatchMode);
            $repository = new ReportDeliveryRepository($this->pdo);
            $submissionDestinations = array_values(array_filter(
                $destinations,
                fn(array $destination): bool => $this->isSubmissionDocumentDestination($repository, $destination)
            ));
            $given = trim((string) ($patientName['given'] ?? ''));

            if ($submissionDestinations !== [] && $given === '') {
                Logger::warning('[ReportDeliveryCompatibility] Liberação bloqueada por Given ausente', [
                    'tenant_id' => $tenantId,
                    'report_id' => $reportId,
                    'dispatch_mode' => $dispatchMode,
                    'submission_document_count' => count($submissionDestinations),
                    'reason' => 'patient_name_given_required',
                ]);
                return [
                    'allowed' => false,
                    'reason' => 'patient_name_given_required',
                    'destinations' => $destinations,
                    'submission_document_count' => count($submissionDestinations),
                ];
            }

            return [
                'allowed' => true,
                'reason' => null,
                'destinations' => $destinations,
                'submission_document_count' => count($submissionDestinations),
            ];
        } catch (Throwable $e) {
            // A falha de leitura do roteamento não pode criar um despacho
            // desconhecido. O chamador mantém o ato de assinatura, mas não
            // libera o laudo nem cria Outbox/Job nesta requisição.
            Logger::error('[ReportDeliveryCompatibility] Não foi possível avaliar a liberação', [
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'dispatch_mode' => $dispatchMode,
                'reason' => 'release_compatibility_unavailable',
                'error_class' => get_class($e),
            ]);
            return [
                'allowed' => false,
                'reason' => 'release_compatibility_unavailable',
                'destinations' => [],
                'submission_document_count' => 0,
            ];
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function resolveEligibleDestinations(
        int $tenantId,
        object $estudo,
        string $dispatchMode = 'automatic_production'
    ): array {
        if (!$this->enabled()) {
            return [];
        }

        $allowedEnvironments = match ($dispatchMode) {
            'automatic_production' => ['producao'],
            'manual_homologation' => ['homologacao'],
            default => throw new \InvalidArgumentException('Modo de despacho inválido para devolutiva.'),
        };
        $estabelecimentoId = (int) ($estudo->estabelecimento_id ?? $estudo->unidade_id ?? 0) ?: null;
        $sourceServerId = (int) ($estudo->servidor_id ?? 0) ?: null;
        $rawInstitutionName = trim((string) ($estudo->institution_name ?? ''));
        $institutionName = InstitutionResolverService::canonicalForTenant($tenantId, $rawInstitutionName);
        $issuer = DicomIssuerService::sanitizeIssuer($estudo->issuer_of_patient_id ?? null);
        $issuerNormalized = DicomIssuerService::normalize($issuer);
        $repository = new ReportDeliveryRepository($this->pdo);
        $eligibleDestinations = $dispatchMode === 'manual_homologation'
            ? $repository->findManualHomologationDestinations($tenantId, $estabelecimentoId, $issuerNormalized, $institutionName, $sourceServerId)
            : $repository->findActiveDestinations($tenantId, $estabelecimentoId, $issuerNormalized, $institutionName, $sourceServerId);

        return array_values(array_filter(
            $eligibleDestinations,
            static fn(array $destination): bool => in_array((string) ($destination['ambiente'] ?? ''), $allowedEnvironments, true)
                && ((string) ($destination['transport'] ?? '') !== PhilipsFolderDeliveryService::TRANSPORT
                    || PhilipsFolderDeliveryService::enabled())
                && ((string) ($destination['transport'] ?? '') !== PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT
                    || PhilipsFolderDeliveryService::nonDicomEnabled())
        ));
    }

    /** @param array<string,mixed> $destination */
    private function isSubmissionDocumentDestination(ReportDeliveryRepository $repository, array $destination): bool
    {
        return $repository->deliveryProfileForDestination($destination)
            === PhilipsFolderDeliveryService::PROFILE_SUBMISSION_DOCUMENT;
    }

    /**
     * @return array{created:bool,outbox_id:int|null,job_count:int,reason?:string}
     * @param array<int,array<string,mixed>>|null $resolvedDestinations
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
        string $dispatchMode = 'automatic_production',
        ?array $resolvedDestinations = null
    ): array {
        if (!$this->enabled()) {
            return ['created' => false, 'outbox_id' => null, 'job_count' => 0, 'reason' => 'feature_disabled'];
        }

        if ($tenantId <= 0 || $reportId <= 0 || $estudoId <= 0 || $reportVersion < 1) {
            throw new \InvalidArgumentException('Dados insuficientes para registrar a devolutiva do laudo.');
        }
        if (!in_array($dispatchMode, ['automatic_production', 'manual_homologation'], true)) {
            throw new \InvalidArgumentException('Modo de despacho inválido para devolutiva.');
        }
        $automaticDispatchDate = $dispatchMode === 'automatic_production'
            ? $this->clinicalDate($releasedAt)
            : null;

        $estabelecimentoId = (int) ($estudo->estabelecimento_id ?? $estudo->unidade_id ?? 0) ?: null;
        $sourceServerId = (int) ($estudo->servidor_id ?? 0) ?: null;
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
            'source_server_id' => $sourceServerId,
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
            'patient_name_dicom' => PhilipsSubmissionMetadataResolver::patientNameFromTagsRaw($estudo->tags_raw ?? null),
            'patient_birth_date' => (string) ($estudo->patient_birth_date ?? ''),
            'patient_sex' => (string) ($estudo->patient_sex ?? ''),
            'study_date' => (string) ($estudo->study_date ?? ''),
            'study_time' => (string) ($estudo->study_time ?? ''),
            'modality' => (string) ($estudo->modalities ?? $estudo->modality ?? $estudo->modalidade ?? ''),
            'released_by' => $releasedBy,
            'released_at' => $releasedAt,
            'report_sha256' => $reportHash,
        ];
        if ($dispatchMode === 'automatic_production') {
            $frozenPatientName = $this->loadFrozenPatientName($tenantId, $reportId, $reportVersion);
            $payload['patient_name_family'] = $frozenPatientName['family'];
            $payload['patient_name_given'] = $frozenPatientName['given'];
            $payload['patient_name_middle'] = $frozenPatientName['middle'];
            $payload['patient_name_source'] = $frozenPatientName['source'];
            $payload['referring_physician_name'] = $estudo->referring_physician_name ?? null;
        }

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
            $destinations = $resolvedDestinations
                ?? $this->resolveEligibleDestinations($tenantId, $estudo, $dispatchMode);
            if ($destinations !== []) {
                $profiles = array_values(array_unique(array_map(
                    fn(array $destination): string => $repository->deliveryProfileForDestination($destination),
                    $destinations
                )));
                $repository->setOutboxDeliveryProfile(
                    $outboxId,
                    $tenantId,
                    count($profiles) === 1 ? $profiles[0] : 'mixed'
                );
            }
            $jobs = $repository->createJobs(
                $outboxId,
                $tenantId,
                $estabelecimentoId,
                $eventKey,
                $destinations,
                $automaticDispatchDate,
                $reportId,
                $reportVersion,
                $reportHash
            );
            if ($jobs === 0 && $reactivateDryRun && !empty($destinations)) {
                $jobs = $repository->requeueDryRunJobs($outboxId, $tenantId);
            }

            if ($jobs > 0) {
                $repository->markOutboxQueued($outboxId);
                if (array_filter($destinations, static fn(array $destination): bool => in_array((string) ($destination['transport'] ?? ''), [PhilipsFolderDeliveryService::TRANSPORT, PhilipsFolderDeliveryService::NON_DICOM_TRANSPORT], true))) {
                    Logger::info('[PhilipsNonDicomDelivery] PHILIPS_EXPORT_QUEUED', [
                        'tenant_id' => $tenantId,
                        'outbox_id' => $outboxId,
                        'job_count' => $jobs,
                    ]);
                }
            }

            if ($jobs === 0 && empty($destinations)) {
                $repository->markOutboxWithoutDestination($outboxId);
                Logger::warning('[ReportDeliveryOutbox] Nenhum destino associado à origem de devolução do estudo', [
                    'tenant_id' => $tenantId,
                    'outbox_id' => $outboxId,
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

    /** @return array{family:string,given:string,middle:string,source:string} */
    private function loadFrozenPatientName(int $tenantId, int $reportId, int $reportVersion): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT rv.patient_name_family,
                    rv.patient_name_given,
                    rv.patient_name_middle,
                    rv.patient_name_source
               FROM report_versions rv
               INNER JOIN reports r
                       ON r.id = rv.report_id
                      AND r.tenant_id = :tenant_id
              WHERE rv.report_id = :report_id
                AND rv.versao = :report_version
              LIMIT 2"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':report_id' => $reportId,
            ':report_version' => $reportVersion,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw new \InvalidArgumentException('report_version_patient_name_unavailable');
        }

        $row = $rows[0];
        $source = (string) ($row['patient_name_source'] ?? '');
        if (!in_array($source, ['dicom_pn', 'patient_name_fallback', 'manual_confirmation'], true)) {
            throw new \InvalidArgumentException('patient_name_source_invalid');
        }
        $family = trim((string) ($row['patient_name_family'] ?? ''));
        if ($family === '') {
            throw new \InvalidArgumentException('patient_name_family');
        }

        return [
            'family' => $family,
            'given' => trim((string) ($row['patient_name_given'] ?? '')),
            'middle' => trim((string) ($row['patient_name_middle'] ?? '')),
            'source' => $source,
        ];
    }

    private function enabled(): bool
    {
        return ReportDeliveryRuntimeConfig::hubEnabled();
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
