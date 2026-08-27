<?php

namespace App\Services;

use App\Core\Database;
use App\Core\SqlHelper;

/**
 * Roteia estudos por dois controles independentes:
 * InstitutionName identifica a origem institucional e Issuer é autorizado por
 * modalidade. A decisão final é sempre um tenant; nenhum filtro clínico usa
 * InstitutionName ou Issuer como substituto da barreira tenant_id.
 */
class PacsRoutingService
{
    public const STATUS_ROTEADO = 'roteado';
    public const STATUS_NAO_IDENTIFICADO = 'nao_identificado';
    public const STATUS_CONFLITO = 'conflito';

    /**
     * @param string|array<int,string>|null $modalities ModalitiesInStudy, normalmente separado por "\\".
     * @return array{status:string,tenant_id:?int,candidatos:array,criterio:string,unidade_id:?int}
     */
    public static function resolveTenant(int $servidorId, ?string $institutionName, ?string $issuerOfPatientId = null, string|array|null $modalities = null): array
    {
        $institutionName = trim((string) $institutionName);
        $issuerOfPatientId = DicomIssuerService::sanitizeIssuer($issuerOfPatientId);
        $modalityCodes = DicomIssuerModalidadeService::fromStudy($modalities);
        $pdo = Database::getInstance();

        // Uma célula Orthanc exclusiva é uma fronteira de tenant mais forte que
        // metadados enviados pela modalidade. Enquanto estiver provisionada ou
        // ativa, nenhum estudo dessa célula pode ser roteado para outro tenant.
        $tenantDaCelula = self::exclusiveCellTenant($pdo, $servidorId);
        if ($tenantDaCelula !== null) {
            return self::result(self::STATUS_ROTEADO, $tenantDaCelula, [], 'celula_orthanc_exclusiva');
        }

        $tenantIds = self::activeTenantIds($pdo, $servidorId);

        if ($tenantIds === []) {
            return self::result(self::STATUS_NAO_IDENTIFICADO, null, [], 'servidor_sem_negocios');
        }

        $institutionCandidates = self::institutionCandidates($pdo, $tenantIds, $institutionName);
        $issuerPoliciesExist = $modalityCodes !== [] && self::issuerPoliciesExist($pdo, $tenantIds, $modalityCodes);

        // Sem Issuer: InstitutionName continua como caminho de compatibilidade
        // apenas nas modalidades que ainda não receberam uma política de Issuer.
        if ($issuerOfPatientId === null) {
            if ($issuerPoliciesExist) {
                return self::result(self::STATUS_NAO_IDENTIFICADO, null, [], 'issuer_ausente_modalidade_controlada');
            }
            return self::resolveInstitutionCandidates($pdo, $institutionCandidates, 'institution_sem_politica_issuer_modalidade');
        }

        $issuerCandidates = $modalityCodes === []
            ? []
            : self::issuerCandidates($pdo, $tenantIds, DicomIssuerService::normalize($issuerOfPatientId), $modalityCodes);

        if ($issuerCandidates !== []) {
            // Se ambos controles resolvem origem, eles devem convergir. Uma
            // divergência é conflito operacional, jamais escolha automática.
            if ($institutionCandidates !== []) {
                $institutionTenantIds = self::tenantIdsFromRows($institutionCandidates);
                $intersection = array_values(array_intersect($issuerCandidates, $institutionTenantIds));
                if ($intersection === []) {
                    return self::conflict($pdo, array_values(array_unique(array_merge($issuerCandidates, $institutionTenantIds))), 'issuer_institution_divergentes');
                }
                $unitId = self::unitIdForTenant($institutionCandidates, (int) $intersection[0]);
                return self::resolveTenantIds($pdo, $intersection, 'issuer_modalidade_e_institution', $unitId);
            }

            // InstitutionName não é pré-requisito para um Issuer autorizado por
            // modalidade. Isso atende fluxos de modalidade/Worklist sem a tag 0008,0080.
            return self::resolveTenantIds($pdo, $issuerCandidates, 'issuer_modalidade', null);
        }

        // Depois que uma modalidade recebe política de Issuer, um valor não
        // autorizado não pode recair em InstitutionName de forma silenciosa.
        if ($issuerPoliciesExist) {
            return self::result(self::STATUS_NAO_IDENTIFICADO, null, [], 'issuer_nao_autorizado_modalidade');
        }

        // Transição retrocompatível: modalidades ainda sem regras de Issuer usam
        // InstitutionName como hoje, sem gravar qualquer vínculo entre os dois.
        return self::resolveInstitutionCandidates($pdo, $institutionCandidates, 'institution_sem_politica_issuer_modalidade');
    }

    /**
     * Retorna o tenant de uma célula Orthanc isolada. A verificação de existência
     * preserva compatibilidade temporária com bancos que ainda não receberam a
     * migration; nesses bancos, mantém-se o roteamento legível existente.
     */
    private static function exclusiveCellTenant(\PDO $pdo, int $servidorId): ?int
    {
        if (!SqlHelper::hasTable($pdo, 'bi_tenant_orthanc_cells')) {
            return null;
        }

        $stmt = $pdo->prepare("\n            SELECT tenant_id\n            FROM bi_tenant_orthanc_cells\n            WHERE servidor_id = :servidor_id\n              AND status IN ('provisioned', 'active')\n            LIMIT 1\n        ");
        $stmt->execute([':servidor_id' => $servidorId]);
        $tenantId = $stmt->fetchColumn();
        return $tenantId === false ? null : (int) $tenantId;
    }

    /** @return list<int> */
    private static function activeTenantIds(\PDO $pdo, int $servidorId): array
    {
        $stmt = $pdo->prepare('SELECT tenant_id FROM bi_negocio_servidor_pacs WHERE servidor_id=? AND ativo=1');
        $stmt->execute([$servidorId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @param list<int> $tenantIds @return list<array<string,mixed>> */
    private static function institutionCandidates(\PDO $pdo, array $tenantIds, string $institutionName): array
    {
        if ($institutionName === '') return [];
        $placeholders = implode(',', array_fill(0, count($tenantIds), '?'));
        $stmt = $pdo->prepare("SELECT tenant_id, institution_name FROM bi_negocio_institution_names WHERE tenant_id IN ($placeholders) AND ativo=1 AND (excluido_manualmente=0 OR excluido_manualmente IS NULL)");
        $stmt->execute($tenantIds);
        $institutionKey = InstitutionResolverService::normalize($institutionName);
        return array_values(array_filter($stmt->fetchAll(\PDO::FETCH_ASSOC), static function (array $row) use ($institutionKey): bool {
            return InstitutionResolverService::normalize((string) ($row['institution_name'] ?? '')) === $institutionKey;
        }));
    }

    /** @param list<int> $tenantIds @param list<string> $modalities */
    private static function issuerPoliciesExist(\PDO $pdo, array $tenantIds, array $modalities): bool
    {
        $tenants = implode(',', array_fill(0, count($tenantIds), '?'));
        $mods = implode(',', array_fill(0, count($modalities), '?'));
        $stmt = $pdo->prepare("SELECT 1 FROM bi_tenant_issuer_modalidades WHERE tenant_id IN ($tenants) AND status='ativo' AND (modalidade IN ($mods) OR modalidade='*') LIMIT 1");
        $stmt->execute(array_merge($tenantIds, $modalities));
        return (bool) $stmt->fetchColumn();
    }

    /** @param list<int> $tenantIds @param list<string> $modalities @return list<int> */
    private static function issuerCandidates(\PDO $pdo, array $tenantIds, ?string $issuerKey, array $modalities): array
    {
        if ($issuerKey === null) return [];
        $tenants = implode(',', array_fill(0, count($tenantIds), '?'));
        $mods = implode(',', array_fill(0, count($modalities), '?'));
        $stmt = $pdo->prepare("SELECT DISTINCT tenant_id FROM bi_tenant_issuer_modalidades WHERE tenant_id IN ($tenants) AND status='ativo' AND issuer_of_patient_id_normalized=? AND (modalidade IN ($mods) OR modalidade='*')");
        $stmt->execute(array_merge($tenantIds, [$issuerKey], $modalities));
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @param list<array<string,mixed>> $rows */
    private static function resolveInstitutionCandidates(\PDO $pdo, array $rows, string $criterion): array
    {
        if ($rows === []) return self::result(self::STATUS_NAO_IDENTIFICADO, null, [], 'institution_desconhecida');
        $tenantIds = self::tenantIdsFromRows($rows);
        return self::resolveTenantIds($pdo, $tenantIds, $criterion, null);
    }

    /** @param list<array<string,mixed>> $rows @return list<int> */
    private static function tenantIdsFromRows(array $rows): array
    {
        return array_values(array_unique(array_map(static fn(array $row): int => (int) $row['tenant_id'], $rows)));
    }

    /** @param list<int> $tenantIds */
    private static function resolveTenantIds(\PDO $pdo, array $tenantIds, string $criterion, ?int $unitId): array
    {
        $tenantIds = array_values(array_unique($tenantIds));
        if ($tenantIds === []) return self::result(self::STATUS_NAO_IDENTIFICADO, null, [], $criterion);
        if (count($tenantIds) === 1) {
            $result = self::result(self::STATUS_ROTEADO, $tenantIds[0], [], $criterion);
            $result['unidade_id'] = $unitId;
            return $result;
        }
        return self::conflict($pdo, $tenantIds, $criterion . '_conflito');
    }

    /** @param list<int> $tenantIds */
    private static function conflict(\PDO $pdo, array $tenantIds, string $criterion): array
    {
        $placeholders = implode(',', array_fill(0, count($tenantIds), '?'));
        $stmt = $pdo->prepare("SELECT id, nome FROM bi_tenants WHERE id IN ($placeholders)");
        $stmt->execute($tenantIds);
        $candidates = array_map(static fn(array $row): array => ['tenant_id' => (int) $row['id'], 'nome' => $row['nome']], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        return self::result(self::STATUS_CONFLITO, null, $candidates, $criterion);
    }

    private static function result(string $status, ?int $tenantId, array $candidates, string $criterion): array
    {
        return ['status' => $status, 'tenant_id' => $tenantId, 'candidatos' => $candidates, 'criterio' => $criterion, 'unidade_id' => null];
    }
}
