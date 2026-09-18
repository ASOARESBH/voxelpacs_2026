<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Recupera somente metadata operacional do snapshot explícito no momento do package.
 * O conteúdo clínico permanece no ReportDeliveryArtifactService/versionamento.
 */
final class ReportDeliveryRequestSnapshotService
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::getInstance();
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $payload @return array<string,mixed> */
    public function hydratePayload(array $job, array $payload): array
    {
        $requestId = (int) ($job['delivery_request_id'] ?? 0);
        if ($requestId <= 0) {
            return $payload;
        }
        $tenantId = (int) ($job['tenant_id'] ?? 0);
        $reportId = (int) ($job['report_id'] ?? 0);
        $reportVersion = (int) ($job['report_version'] ?? 0);
        $studyId = (int) ($job['estudo_id'] ?? 0);
        if ($tenantId <= 0 || $reportId <= 0 || $reportVersion <= 0 || $studyId <= 0) {
            throw new RuntimeException('Snapshot da Delivery Request incompleto.');
        }

        $stmt = $this->pdo->prepare(
            "SELECT r.situacao, r.liberado_por, r.liberado_em,
                    e.study_instance_uid, e.accession_number, e.patient_id,
                    e.patient_name, e.patient_birth_date, e.patient_sex,
                    e.study_date, e.study_time, e.modalities,
                    e.institution_name, e.issuer_of_patient_id
               FROM reports r
               INNER JOIN bi_pacs_estudos e
                       ON e.id = r.estudo_id AND e.tenant_id = r.tenant_id
               INNER JOIN report_versions rv
                       ON rv.report_id = r.id AND rv.versao = :report_version
              WHERE r.tenant_id = :tenant_id
                AND r.id = :report_id
                AND e.id = :study_id
              LIMIT 2"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':report_id' => $reportId,
            ':report_version' => $reportVersion,
            ':study_id' => $studyId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw new RuntimeException('Snapshot da Delivery Request não é único.');
        }
        $snapshot = $rows[0];
        if ((string) ($snapshot['situacao'] ?? '') !== 'liberado') {
            throw new RuntimeException('Snapshot da Delivery Request não está liberado.');
        }

        return array_replace($payload, [
            'tenant_id' => $tenantId,
            'estabelecimento_id' => (int) ($job['estabelecimento_id'] ?? 0) ?: null,
            'report_id' => $reportId,
            'report_version' => $reportVersion,
            'estudo_id' => $studyId,
            'institution_name' => (string) ($snapshot['institution_name'] ?? ''),
            'issuer_of_patient_id' => (string) ($snapshot['issuer_of_patient_id'] ?? ''),
            'study_instance_uid' => (string) ($snapshot['study_instance_uid'] ?? ''),
            'accession_number' => (string) ($snapshot['accession_number'] ?? ''),
            'patient_id' => (string) ($snapshot['patient_id'] ?? ''),
            'patient_name' => (string) ($snapshot['patient_name'] ?? ''),
            'patient_name_dicom' => (string) ($snapshot['patient_name'] ?? ''),
            'patient_birth_date' => (string) ($snapshot['patient_birth_date'] ?? ''),
            'patient_sex' => (string) ($snapshot['patient_sex'] ?? ''),
            'study_date' => (string) ($snapshot['study_date'] ?? ''),
            'study_time' => (string) ($snapshot['study_time'] ?? ''),
            'modality' => (string) ($snapshot['modalities'] ?? ''),
            'released_by' => (int) ($snapshot['liberado_por'] ?? 0),
            'released_at' => (string) ($snapshot['liberado_em'] ?? ''),
        ]);
    }
}
