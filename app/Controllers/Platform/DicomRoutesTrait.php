<?php

namespace App\Controllers\Platform;

use App\Core\SqlHelper;
use App\Services\DicomIssuerModalidadeService;
use App\Services\DicomIssuerService;

trait DicomRoutesTrait
{
    /** @return list<array{issuer_of_patient_id:string,issuer_of_patient_id_normalized:string,modalidade:string}> */
    private function regrasIssuerModalidadeDaRequisicao(): array
    {
        $rawRules = $_POST['issuer_modalidades'] ?? [];
        if (!is_array($rawRules)) {
            return [];
        }

        $rules = [];
        foreach ($rawRules as $rawRule) {
            if (!is_array($rawRule)) continue;
            $issuer = DicomIssuerService::sanitizeIssuer($rawRule['issuer_of_patient_id'] ?? null);
            $modalities = DicomIssuerModalidadeService::fromStudy($rawRule['modalidades'] ?? '');

            if ($issuer === null && $modalities === []) continue;
            if ($issuer === null || $modalities === []) {
                throw new \RuntimeException('Cada regra de Issuer deve informar o Issuer e ao menos uma modalidade DICOM.');
            }

            $issuerKey = DicomIssuerService::normalize($issuer);
            foreach ($modalities as $modality) {
                $rules[$issuerKey . '|' . $modality] = [
                    'issuer_of_patient_id' => $issuer,
                    'issuer_of_patient_id_normalized' => $issuerKey,
                    'modalidade' => $modality,
                ];
            }
        }

        return array_values($rules);
    }

    /** @param list<array{issuer_of_patient_id:string,issuer_of_patient_id_normalized:string,modalidade:string}> $rules */
    private function sincronizarRegrasIssuerModalidade(\PDO $pdo, int $tenantId, array $rules): void
    {
        $pdo->prepare("UPDATE bi_tenant_issuer_modalidades SET status='inativo', atualizado_em=NOW() WHERE tenant_id=?")
            ->execute([$tenantId]);

        if ($rules === []) return;

        $sql = SqlHelper::isPostgres()
            ? "INSERT INTO bi_tenant_issuer_modalidades
                    (tenant_id, issuer_of_patient_id, issuer_of_patient_id_normalized, modalidade, status, criado_por, atualizado_em)
                VALUES (?, ?, ?, ?, 'ativo', ?, NOW())
                ON CONFLICT (tenant_id, issuer_of_patient_id_normalized, modalidade) DO UPDATE SET
                    issuer_of_patient_id=EXCLUDED.issuer_of_patient_id,
                    status='ativo', atualizado_em=NOW()"
            : "INSERT INTO bi_tenant_issuer_modalidades
                    (tenant_id, issuer_of_patient_id, issuer_of_patient_id_normalized, modalidade, status, criado_por, atualizado_em)
                VALUES (?, ?, ?, ?, 'ativo', ?, NOW())
                ON DUPLICATE KEY UPDATE issuer_of_patient_id=VALUES(issuer_of_patient_id), status='ativo', atualizado_em=NOW()";
        $stmt = $pdo->prepare($sql);
        foreach ($rules as $rule) {
            $stmt->execute([$tenantId, $rule['issuer_of_patient_id'], $rule['issuer_of_patient_id_normalized'], $rule['modalidade'], \App\Core\Auth::userId()]);
        }
    }
}
