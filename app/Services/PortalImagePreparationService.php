<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
use App\Core\SqlHelper;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Processa uma cópia por vez. A cópia resultante é armazenada somente no
 * Orthanc anonimizado privado e permanece bloqueada até revisão explícita de
 * possível identificação queimada em pixels.
 */
final class PortalImagePreparationService
{
    private const REPOSITORY_KEY = PortalImageSessionService::REPOSITORY_KEY;
    private const COPY_RETENTION_HOURS = 168;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Enfileira a cópia somente para um report efetivamente liberado. A chave
     * única por estudo impede duplicações em reabertura ou reenvio de eventos.
     */
    public function enqueueReleasedReport(int $reportId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.id AS report_id, r.tenant_id, e.id AS estudo_id, e.orthanc_id, e.study_instance_uid
             FROM reports r
             INNER JOIN bi_pacs_estudos e ON e.id = r.estudo_id AND e.tenant_id = r.tenant_id
             WHERE r.id = :report_id AND r.situacao = 'liberado'
             LIMIT 1"
        );
        $stmt->execute(['report_id' => $reportId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row === null || trim((string) $row['orthanc_id']) === '' || trim((string) $row['study_instance_uid']) === '') {
            return;
        }
        try {
            $this->pdo->prepare(
                "INSERT INTO bi_portal_anonymized_studies
                    (tenant_id, source_estudo_id, source_orthanc_id, source_study_uid, repository_key, profile_version, state)
                 VALUES
                    (:tenant_id, :study_id, :source_orthanc_id, :source_study_uid, :repository_key, :profile_version, 'pending')"
            )->execute([
                'tenant_id' => (int) $row['tenant_id'],
                'study_id' => (int) $row['estudo_id'],
                'source_orthanc_id' => (string) $row['orthanc_id'],
                'source_study_uid' => (string) $row['study_instance_uid'],
                'repository_key' => self::REPOSITORY_KEY,
                'profile_version' => PortalImageSessionService::PROFILE_VERSION,
            ]);
            $this->audit(null, (int) $row['tenant_id'], (int) $row['estudo_id'], 'queued', 'info', 'released_report');
        } catch (\PDOException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate') && !str_contains(strtolower($e->getMessage()), 'unique')) {
                throw $e;
            }
        }
    }

    /** @return array{processed:int,ready:int,failed:int,skipped:int} */
    public function processPending(int $limit = 3): array
    {
        $result = ['processed' => 0, 'ready' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($this->pendingCopies(max(1, min($limit, 20))) as $copy) {
            $result['processed']++;
            try {
                $this->prepare($copy);
                $result['ready']++;
            } catch (Throwable $e) {
                $result['failed']++;
                $this->markFailure((int) $copy['id'], 'prepare_failed', $e->getMessage());
            }
        }
        return $result;
    }

    /** @return array{purged:int,expired_sessions:int} */
    public function purgeExpired(): array
    {
        $purged = 0;
        foreach ($this->expiredCopies() as $copy) {
            try {
                $target = $this->targetClient();
                if (!empty($copy['anonymized_orthanc_id'])) {
                    $target->deleteStudy((string) $copy['anonymized_orthanc_id']);
                }
                $this->pdo->prepare("UPDATE bi_portal_anonymized_studies SET state = 'purged', purged_at = NOW(), updated_at = NOW() WHERE id = :id")
                    ->execute(['id' => (int) $copy['id']]);
                $purged++;
            } catch (Throwable $e) {
                Logger::error('PortalImagePreparationService::purge falhou', ['copy_id' => (int) $copy['id'], 'error' => $e->getMessage()]);
            }
        }
        $expired = $this->pdo->exec("UPDATE bi_portal_image_sessions SET revoked_at = NOW() WHERE revoked_at IS NULL AND expires_at <= NOW()");
        return ['purged' => $purged, 'expired_sessions' => (int) $expired];
    }

    /** @param array<string,mixed> $copy */
    private function prepare(array $copy): void
    {
        $copyId = (int) $copy['id'];
        $this->pdo->prepare(
            "UPDATE bi_portal_anonymized_studies
             SET state = 'processing', processing_at = NOW(), retry_count = retry_count + 1, updated_at = NOW()
             WHERE id = :id AND state = 'pending'"
        )->execute(['id' => $copyId]);
        if ($this->pdo->query('SELECT state FROM bi_portal_anonymized_studies WHERE id = ' . $copyId)->fetchColumn() !== 'processing') {
            return;
        }

        $source = $this->sourceClient((int) $copy['tenant_id']);
        $target = $this->targetClient();
        $anonymized = $source->anonymizeStudy((string) $copy['source_orthanc_id'], self::anonymizationProfile());
        $temporarySourceCopyId = trim((string) ($anonymized['ID'] ?? ''));
        if ($temporarySourceCopyId === '') {
            throw new RuntimeException('O Orthanc não retornou identificador da cópia anonimizada.');
        }

        try {
            $targetStudyId = $this->transferAnonymizedStudy($source, $target, $temporarySourceCopyId);
            $tags = $target->sharedTags($targetStudyId);
            $anonUid = trim((string) ($tags['StudyInstanceUID'] ?? ''));
            if ($anonUid === '') {
                throw new RuntimeException('A cópia anonimizada não possui StudyInstanceUID.');
            }
            $expiresSql = SqlHelper::futureTimestamp('HOUR', self::COPY_RETENTION_HOURS);
            $stmt = $this->pdo->prepare(
                "UPDATE bi_portal_anonymized_studies
                 SET state = 'ready', anonymized_orthanc_id = :orthanc_id, anonymized_study_uid = :study_uid,
                     prepared_at = NOW(), expires_at = {$expiresSql}, failed_at = NULL,
                     failure_code = NULL, failure_detail = NULL, updated_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute(['orthanc_id' => $targetStudyId, 'study_uid' => $anonUid, 'id' => $copyId]);
            $this->audit(null, (int) $copy['tenant_id'], (int) $copy['source_estudo_id'], 'prepared', 'info', 'copy_ready');
        } finally {
            // A cópia temporária no Orthanc clínico nunca deve permanecer lá.
            try {
                $source->deleteStudy($temporarySourceCopyId);
            } catch (Throwable $cleanupError) {
                Logger::error('PortalImagePreparationService: não removeu cópia temporária da origem', ['copy_id' => $copyId, 'error' => $cleanupError->getMessage()]);
            }
        }
    }

    private function transferAnonymizedStudy(PortalAnonymizedOrthancClient $source, PortalAnonymizedOrthancClient $target, string $sourceStudyId): string
    {
        $study = $source->study($sourceStudyId);
        $seriesIds = $study['Series'] ?? [];
        if (!is_array($seriesIds) || $seriesIds === []) {
            throw new RuntimeException('A cópia anonimizada não contém séries.');
        }
        $targetStudyId = '';
        foreach ($seriesIds as $seriesId) {
            $series = $source->series((string) $seriesId);
            $instances = $series['Instances'] ?? [];
            if (!is_array($instances) || $instances === []) {
                throw new RuntimeException('Uma série anonimizada não contém instâncias.');
            }
            foreach ($instances as $instanceId) {
                $dicom = $source->downloadInstance((string) $instanceId);
                $uploaded = $target->uploadInstance($dicom);
                $parentStudy = trim((string) ($uploaded['ParentStudy'] ?? ''));
                if ($parentStudy === '') {
                    throw new RuntimeException('O repositório anonimizado não retornou ParentStudy.');
                }
                if ($targetStudyId !== '' && !hash_equals($targetStudyId, $parentStudy)) {
                    throw new RuntimeException('A cópia anonimizou instâncias em estudos divergentes.');
                }
                $targetStudyId = $parentStudy;
            }
        }
        if ($targetStudyId === '') {
            throw new RuntimeException('Nenhuma instância anonimizada foi armazenada.');
        }
        return $targetStudyId;
    }

    /** @return array<string,mixed> */
    public static function anonymizationProfile(): array
    {
        return [
            'DicomVersion' => '2021b',
            // Obrigatório no Orthanc ao remover/substituir PatientID e demais identificadores da hierarquia.
            'Force' => true,
            'KeepPrivateTags' => false,
            'Keep' => [
                'Modality', 'SOPClassUID',
                // UIDs de estudo/série/instância não podem ser preservados: Orthanc gera novos valores.
                'Rows', 'Columns', 'BitsAllocated', 'BitsStored', 'HighBit', 'PixelRepresentation',
                'PhotometricInterpretation', 'SamplesPerPixel', 'PlanarConfiguration', 'PixelSpacing',
                'ImageOrientationPatient', 'ImagePositionPatient', 'SliceThickness', 'SpacingBetweenSlices',
                'RescaleIntercept', 'RescaleSlope', 'WindowCenter', 'WindowWidth', 'ImageType',
                'SeriesNumber', 'InstanceNumber', 'BodyPartExamined', 'Laterality', 'ViewPosition',
            ],
        ];
    }

    /**
     * Tags removidas pelo perfil padrão de anonimização do Orthanc e verificadas
     * pela homologação. A lista permanece explícita para auditoria, mas não é
     * reenviada em `Remove`: versões do Orthanc podem recusar remoções redundantes
     * em níveis hierárquicos diferentes durante `/studies/{id}/anonymize`.
     *
     * @return array<int,string>
     */
    public static function documentedRemovedTags(): array
    {
        return [
            'PatientName', 'PatientID', 'PatientBirthDate', 'PatientSex', 'PatientAddress',
            'PatientTelephoneNumbers', 'OtherPatientIDs', 'OtherPatientNames', 'InstitutionName',
            'InstitutionAddress', 'InstitutionalDepartmentName', 'AccessionNumber', 'StudyID',
            'StudyDescription', 'SeriesDescription', 'RequestedProcedureDescription',
            'ScheduledProcedureStepDescription', 'ReferringPhysicianName', 'PerformingPhysicianName',
            'NameOfPhysiciansReadingStudy', 'OperatorsName', 'RequestingPhysician',
            'PatientComments', 'AdditionalPatientHistory', 'ResponsiblePerson', 'ResponsibleOrganization',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function pendingCopies(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM bi_portal_anonymized_studies
             WHERE state = 'pending' AND repository_key = :repository_key
             ORDER BY requested_at ASC LIMIT {$limit}"
        );
        $stmt->execute(['repository_key' => self::REPOSITORY_KEY]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    private function expiredCopies(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM bi_portal_anonymized_studies
             WHERE state = 'ready' AND expires_at IS NOT NULL AND expires_at <= NOW()
               AND repository_key = :repository_key"
        );
        $stmt->execute(['repository_key' => self::REPOSITORY_KEY]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function sourceClient(int $tenantId): PortalAnonymizedOrthancClient
    {
        $server = null;
        try {
            $stmt = $this->pdo->prepare('SELECT url, usuario, senha, timeout FROM bi_orthanc_servidores WHERE tenant_id = :tenant_id AND ativo = 1 ORDER BY id ASC LIMIT 1');
            $stmt->execute(['tenant_id' => $tenantId]);
            $server = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) {
            $server = null;
        }
        if ($server === null) {
            $server = $this->pdo->query('SELECT url, usuario, senha, timeout FROM bi_pacs_servidor WHERE ativo = 1 ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($server === null) {
            throw new RuntimeException('Servidor Orthanc clínico não configurado.');
        }
        // O pipeline não pode usar o hostname público cadastrado para Viewer/sincronização.
        // A origem clínica deve ser explicitamente privada (ex.: 10.0.0.3:8042).
        $privateUrl = trim((string) (getenv('PORTAL_CLINICAL_ORTHANC_PRIVATE_URL') ?: ''));
        if ($privateUrl === '') {
            throw new RuntimeException('Endpoint privado do Orthanc clínico não configurado.');
        }
        return new PortalAnonymizedOrthancClient(
            $privateUrl,
            empty($server['usuario']) ? null : (string) $server['usuario'],
            Crypto::decrypt(empty($server['senha']) ? null : (string) $server['senha']),
            max(30, (int) ($server['timeout'] ?? 90)),
        );
    }

    private function targetClient(): PortalAnonymizedOrthancClient
    {
        $url = trim((string) (getenv('PORTAL_ANONYMIZED_ORTHANC_URL') ?: ''));
        if ($url === '') {
            throw new RuntimeException('Repositório Orthanc anonimizado não configurado.');
        }
        return new PortalAnonymizedOrthancClient(
            $url,
            ($user = trim((string) (getenv('PORTAL_ANONYMIZED_ORTHANC_USERNAME') ?: ''))) === '' ? null : $user,
            getenv('PORTAL_ANONYMIZED_ORTHANC_PASSWORD') ?: null,
            120,
        );
    }

    private function markFailure(int $copyId, string $code, string $detail): void
    {
        $this->pdo->prepare(
            "UPDATE bi_portal_anonymized_studies
             SET state = 'failed', failed_at = NOW(), failure_code = :code, failure_detail = :detail, updated_at = NOW()
             WHERE id = :id"
        )->execute(['id' => $copyId, 'code' => $code, 'detail' => mb_substr($detail, 0, 255, 'UTF-8')]);
    }

    private function audit(?int $sessionId, int $tenantId, int $studyId, string $event, string $outcome, string $detail): void
    {
        try {
            $report = $this->pdo->prepare('SELECT id FROM reports WHERE estudo_id = :estudo_id AND tenant_id = :tenant_id AND situacao = :situacao ORDER BY id DESC LIMIT 1');
            $report->execute(['estudo_id' => $studyId, 'tenant_id' => $tenantId, 'situacao' => 'liberado']);
            $reportId = (int) ($report->fetchColumn() ?: 0);
            if ($reportId <= 0) return;
            $stmt = $this->pdo->prepare('INSERT INTO bi_portal_image_audit (image_session_id, tenant_id, report_id, event_type, outcome, detail_code) VALUES (:session_id, :tenant_id, :report_id, :event_type, :outcome, :detail_code)');
            $stmt->execute(['session_id' => $sessionId, 'tenant_id' => $tenantId, 'report_id' => $reportId, 'event_type' => $event, 'outcome' => $outcome, 'detail_code' => $detail]);
        } catch (Throwable $e) {
            Logger::error('PortalImagePreparationService::audit falhou', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
