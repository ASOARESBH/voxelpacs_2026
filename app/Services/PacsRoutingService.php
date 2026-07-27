<?php
namespace App\Services;

use App\Core\Database;

/**
 * VOXEL PACS — Motor de roteamento de estudos por InstitutionName (0008,0080).
 *
 * Um servidor Orthanc pode ser compartilhado por N negócios (bi_negocio_servidor_pacs).
 * A fonte única de verdade para decidir a QUAL negócio um estudo pertence é a
 * Unidade (bi_tenant_unidades_dicom) cadastrada em cada negócio — não uma tabela
 * de roteamento paralela. Ver docs/PACS_MULTISERVIDOR_ROTEAMENTO.md.
 */
class PacsRoutingService
{
    public const STATUS_ROTEADO          = 'roteado';
    public const STATUS_NAO_IDENTIFICADO = 'nao_identificado';
    public const STATUS_CONFLITO         = 'conflito';

    /**
     * @return array{status:string, tenant_id:?int, candidatos:array}
     */
    public static function resolveTenant(int $servidorId, ?string $institutionName): array
    {
        $institutionName = trim((string) $institutionName);

        if ($institutionName === '') {
            return ['status' => self::STATUS_NAO_IDENTIFICADO, 'tenant_id' => null, 'candidatos' => []];
        }

        $pdo = Database::getInstance();

        // Negócios ativos associados a este servidor (pivot N:N)
        $tenantsStmt = $pdo->prepare("
            SELECT tenant_id FROM bi_negocio_servidor_pacs WHERE servidor_id = ? AND ativo = 1
        ");
        $tenantsStmt->execute([$servidorId]);
        $tenantIds = $tenantsStmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($tenantIds)) {
            return ['status' => self::STATUS_NAO_IDENTIFICADO, 'tenant_id' => null, 'candidatos' => []];
        }

        // Unidades (InstitutionName) cadastradas nesses negócios
        $placeholders = implode(',', array_fill(0, count($tenantIds), '?'));
        $unidadesStmt = $pdo->prepare("
            SELECT tenant_id, institution_name
            FROM bi_tenant_unidades_dicom
            WHERE tenant_id IN ($placeholders) AND status = 'ativo'
        ");
        $unidadesStmt->execute($tenantIds);
        $unidades = $unidadesStmt->fetchAll(\PDO::FETCH_ASSOC);

        $alvo = InstitutionResolverService::normalize($institutionName);
        $matchedTenantIds = [];
        foreach ($unidades as $u) {
            if (InstitutionResolverService::normalize($u['institution_name']) === $alvo) {
                $matchedTenantIds[(int) $u['tenant_id']] = true;
            }
        }
        $matchedTenantIds = array_keys($matchedTenantIds);

        if (count($matchedTenantIds) === 0) {
            return ['status' => self::STATUS_NAO_IDENTIFICADO, 'tenant_id' => null, 'candidatos' => []];
        }

        if (count($matchedTenantIds) === 1) {
            return ['status' => self::STATUS_ROTEADO, 'tenant_id' => $matchedTenantIds[0], 'candidatos' => []];
        }

        // Conflito: mais de 1 negócio com a mesma InstitutionName associada ao mesmo servidor
        $nomesStmt = $pdo->prepare("
            SELECT id, nome FROM bi_tenants WHERE id IN (" . implode(',', array_fill(0, count($matchedTenantIds), '?')) . ")
        ");
        $nomesStmt->execute($matchedTenantIds);
        $candidatos = array_map(
            fn($row) => ['tenant_id' => (int) $row['id'], 'nome' => $row['nome']],
            $nomesStmt->fetchAll(\PDO::FETCH_ASSOC)
        );

        return ['status' => self::STATUS_CONFLITO, 'tenant_id' => null, 'candidatos' => $candidatos];
    }
}
