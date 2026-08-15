<?php
namespace App\Core\Access;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;

/**
 * Resolve, uma única vez por requisição, se o usuário atual é um médico
 * vinculado que deve operar apenas sobre o próprio cadastro.
 *
 * Administradores de tenant e superadministradores nunca são restritos, mesmo
 * se houver algum vínculo histórico em bi_medicos.
 */
final class MedicoAccess
{
    private static bool $resolved = false;
    private static bool $restricted = false;
    private static ?int $medicoId = null;
    /** @var string[]|null */
    private static ?array $allowedInstitutionNames = null;

    private function __construct()
    {
    }

    public static function isRestricted(): bool
    {
        self::resolve();
        return self::$restricted;
    }

    /** ID de bi_medicos do usuário médico restrito; null para demais perfis. */
    public static function currentMedicoId(): ?int
    {
        self::resolve();
        return self::$medicoId;
    }

    /**
     * InstitutionNames DICOM permitidos ao médico restrito no tenant ativo.
     * Para perfis não restritos, retorna array vazio porque não há limitação adicional.
     *
     * @return string[]
     */
    public static function allowedInstitutionNames(): array
    {
        self::resolve();
        if (!self::$restricted || self::$medicoId === null || self::$medicoId <= 0) {
            return [];
        }
        if (self::$allowedInstitutionNames !== null) {
            return self::$allowedInstitutionNames;
        }

        $tenantId = TenantContext::id() ?? Auth::tenantId();
        if (!$tenantId) {
            return self::$allowedInstitutionNames = [];
        }

        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT DISTINCT institution_name
                 FROM bi_medico_unidades
                 WHERE tenant_id = :tenant_id
                   AND medico_id = :medico_id
                   AND institution_name IS NOT NULL
                   AND institution_name != \'\'
                 ORDER BY institution_name'
            );
            $stmt->execute([
                'tenant_id' => $tenantId,
                'medico_id' => self::$medicoId,
            ]);
            $unidades = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            self::$allowedInstitutionNames = array_values(array_unique(array_filter(
                array_map(static fn($unidade) => trim((string) $unidade), $unidades),
                static fn(string $unidade): bool => $unidade !== ''
            )));
        } catch (\Throwable $e) {
            self::$allowedInstitutionNames = [];
            Logger::error('[MedicoAccess::allowedInstitutionNames] Falha ao carregar Unidades permitidas', [
                'tenant_id' => $tenantId,
                'medico_id' => self::$medicoId,
                'erro' => $e->getMessage(),
            ]);
        }

        return self::$allowedInstitutionNames;
    }

    /**
     * Perfis não restritos podem usar qualquer Unidade do tenant; médico restrito
     * só pode aplicar filtros sobre os InstitutionNames explicitamente vinculados.
     */
    public static function isInstitutionAllowed(string $institutionName): bool
    {
        if (!self::isRestricted()) {
            return true;
        }

        $institutionName = trim($institutionName);
        if ($institutionName === '') {
            return false;
        }

        foreach (self::allowedInstitutionNames() as $unidade) {
            if (self::sameInstitutionName($unidade, $institutionName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mantém o vínculo explícito por InstitutionName, aceitando somente as
     * variações de caixa, acentuação e espaços que já são equivalentes nas
     * collations operacionais do MySQL. Não ignora termos, hífens ou Unidade.
     */
    private static function sameInstitutionName(string $left, string $right): bool
    {
        if (strcasecmp(trim($left), trim($right)) === 0) {
            return true;
        }

        return self::normalizeInstitutionName($left) === self::normalizeInstitutionName($right);
    }

    private static function normalizeInstitutionName(string $institutionName): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($institutionName)) ?? '';
        if (function_exists('iconv')) {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($transliterated !== false) {
                $value = $transliterated;
            }
        }
        return strtoupper($value);
    }

    /** Limpa o cache estático; útil em testes e troca de contexto na mesma execução. */
    public static function reset(): void
    {
        self::$resolved = false;
        self::$restricted = false;
        self::$medicoId = null;
        self::$allowedInstitutionNames = null;
    }

    private static function resolve(): void
    {
        if (self::$resolved) {
            return;
        }
        self::$resolved = true;

        if (!Auth::check() || Auth::isPlatformAdmin()) {
            return;
        }

        $perfil = strtolower((string) (Auth::perfilAtual() ?? ''));
        if (in_array($perfil, ['admin', 'administrador'], true)) {
            return;
        }
        if ($perfil !== 'medico') {
            return;
        }

        $tenantId = TenantContext::id() ?? Auth::tenantId();
        $userId = Auth::userId();
        if (!$tenantId || !$userId) {
            return;
        }

        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT id FROM bi_medicos WHERE tenant_id = :tenant_id AND usuario_id = :usuario_id LIMIT 1'
            );
            $stmt->execute([
                'tenant_id' => $tenantId,
                'usuario_id' => $userId,
            ]);
            $medicoId = (int) ($stmt->fetchColumn() ?: 0);
            if ($medicoId > 0) {
                self::$restricted = true;
                self::$medicoId = $medicoId;
            }
        } catch (\Throwable $e) {
            // Falha fechada: se não for possível resolver o vínculo de um perfil
            // médico, nenhum cadastro fica acessível até a infraestrutura normalizar.
            self::$restricted = true;
            self::$medicoId = 0;
            Logger::error('[MedicoAccess::resolve] Falha ao resolver vínculo médico', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'erro' => $e->getMessage(),
            ]);
        }
    }
}
