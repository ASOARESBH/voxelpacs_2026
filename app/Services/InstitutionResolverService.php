<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * VOXEL PACS — InstitutionResolverService
 * 
 * Centraliza a lógica de resolução, normalização e segurança multi-tenant
 * do InstitutionName (fonte única da verdade) para todos os módulos.
 */
class InstitutionResolverService
{
    /**
     * Retorna a lista de InstitutionNames (nomes exatos DICOM) vinculados a um tenant.
     * 
     * @param int $tenantId
     * @return array Lista de strings (institution_names)
     */
    public static function getInstitutionNamesByTenant(int $tenantId): array
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("
                SELECT institution_name 
                FROM bi_negocio_institution_names 
                WHERE tenant_id = :tenant_id AND ativo = 1
            ");
            $stmt->execute([':tenant_id' => $tenantId]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $ex) {
            Logger::error('[InstitutionResolverService::getInstitutionNamesByTenant] Erro: ' . $ex->getMessage());
            return [];
        }
    }

    /**
     * Normaliza um InstitutionName para comparação (remove espaços extras, acentos, uppercase).
     * 
     * @param string $name
     * @return string
     */
    public static function normalize(string $name): string
    {
        $name = trim($name);
        $name = mb_strtoupper($name, 'UTF-8');
        
        // Remove acentos
        $map = [
            'Á'=>'A', 'À'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A',
            'É'=>'E', 'È'=>'E', 'Ê'=>'E', 'Ë'=>'E',
            'Í'=>'I', 'Ì'=>'I', 'Î'=>'I', 'Ï'=>'I',
            'Ó'=>'O', 'Ò'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O',
            'Ú'=>'U', 'Ù'=>'U', 'Û'=>'U', 'Ü'=>'U',
            'Ç'=>'C', 'Ñ'=>'N'
        ];
        return strtr($name, $map);
    }

    /**
     * Retorna o InstitutionName canônico do tenant que corresponde a um valor
     * recebido por DICOM. A comparação usa a mesma normalização central do
     * roteamento de estudos, preservando no banco o nome cadastrado pelo negócio.
     */
    public static function canonicalForTenant(int $tenantId, string $institutionName): ?string
    {
        $candidate = trim($institutionName);
        if ($tenantId <= 0 || $candidate === '') {
            return null;
        }

        $normalized = self::normalize($candidate);
        foreach (self::getInstitutionNamesByTenant($tenantId) as $registered) {
            if (self::normalize((string) $registered) === $normalized) {
                return (string) $registered;
            }
        }

        return null;
    }

    /**
     * Resolve qual tenant é o dono de um estudo baseado no InstitutionName.
     * Usado no momento da importação do Orthanc.
     * 
     * @param string $institutionName
     * @return int|null tenant_id ou null se não encontrado
     */
    public static function resolveTenantByInstitutionName(string $institutionName): ?int
    {
        if (empty(trim($institutionName))) {
            return null;
        }

        try {
            $pdo = Database::getInstance();
            
            // 1. Busca exata
            $stmt = $pdo->prepare("
                SELECT tenant_id 
                FROM bi_negocio_institution_names 
                WHERE institution_name = :name AND ativo = 1 
                LIMIT 1
            ");
            $stmt->execute([':name' => trim($institutionName)]);
            $tenantId = $stmt->fetchColumn();
            
            if ($tenantId) {
                return (int)$tenantId;
            }
            
            // 2. Busca normalizada (case-insensitive e sem acentos)
            $normalized = self::normalize($institutionName);
            $all = $pdo->query("SELECT tenant_id, institution_name FROM bi_negocio_institution_names WHERE ativo = 1")->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($all as $row) {
                if (self::normalize($row['institution_name']) === $normalized) {
                    return (int)$row['tenant_id'];
                }
            }
            
            return null;
        } catch (\Throwable $ex) {
            Logger::error('[InstitutionResolverService::resolveTenantByInstitutionName] Erro: ' . $ex->getMessage());
            return null;
        }
    }
}
