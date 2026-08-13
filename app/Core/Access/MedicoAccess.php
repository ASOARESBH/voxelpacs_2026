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

    /** Limpa o cache estático; útil em testes e troca de contexto na mesma execução. */
    public static function reset(): void
    {
        self::$resolved = false;
        self::$restricted = false;
        self::$medicoId = null;
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
