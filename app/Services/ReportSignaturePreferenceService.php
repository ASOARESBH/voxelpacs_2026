<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\SqlHelper;

/**
 * Preferência operacional do modo de finalização da assinatura.
 *
 * A preferência é tenant-scoped porque o mesmo usuário pode possuir vínculos
 * diferentes em tenants distintos. Ela não altera o conteúdo clínico, a
 * versão, o PatientName ou o transporte; somente restringe o modo permitido.
 */
final class ReportSignaturePreferenceService
{
    public const MODE_BOTH = 'ambos';
    public const MODE_SIGN_ONLY = 'somente';
    public const MODE_SIGN_AND_CLOSE = 'fechar';

    /** @var list<string> */
    public const MODES = [
        self::MODE_BOTH,
        self::MODE_SIGN_ONLY,
        self::MODE_SIGN_AND_CLOSE,
    ];

    /**
     * @return array{mode:string,source:string,available:bool,persisted:bool}
     */
    public function resolveForUser(int $userId, ?int $tenantId, bool $isMedical): array
    {
        $fallback = [
            'mode' => self::MODE_BOTH,
            'source' => $isMedical ? 'padrao' : 'nao_medico',
            'available' => false,
            'persisted' => false,
        ];

        if (!$isMedical || $userId <= 0 || !$tenantId) {
            return $fallback;
        }

        try {
            $pdo = Database::getInstance();
            if (!SqlHelper::hasTable($pdo, 'bi_user_report_signature_preferences')) {
                return $fallback;
            }

            $stmt = $pdo->prepare(
                'SELECT signature_mode
                   FROM bi_user_report_signature_preferences
                  WHERE tenant_id = ? AND user_id = ?
                  LIMIT 1'
            );
            $stmt->execute([$tenantId, $userId]);
            $stored = $stmt->fetchColumn();
            $mode = $this->normalize($stored);

            return [
                'mode' => $mode,
                'source' => $stored === false ? 'padrao' : 'usuario',
                'available' => true,
                'persisted' => $stored !== false,
            ];
        } catch (\Throwable) {
            // Ausência de migration ou schema incompatível não pode alterar o
            // comportamento atual nem impedir a abertura do laudário.
            return $fallback;
        }
    }

    /**
     * @return array{mode:string,source:string,available:bool,persisted:bool}
     */
    public function saveForUser(
        int $userId,
        int $tenantId,
        array $submitted,
        bool $isMedical,
        ?int $changedByUserId
    ): array {
        $mode = $isMedical
            ? $this->normalize($submitted['mode'] ?? $submitted['report_signature_mode'] ?? null)
            : self::MODE_BOTH;

        try {
            $pdo = Database::getInstance();
            if (!SqlHelper::hasTable($pdo, 'bi_user_report_signature_preferences')) {
                return [
                    'mode' => self::MODE_BOTH,
                    'source' => 'migration_pendente',
                    'available' => false,
                    'persisted' => false,
                ];
            }

            $values = [$tenantId, $userId, $mode, $changedByUserId];
            $sql = SqlHelper::isPostgres()
                ? 'INSERT INTO bi_user_report_signature_preferences (tenant_id, user_id, signature_mode, updated_by_user_id, updated_at)
                   VALUES (?,?,?,?,NOW())
                   ON CONFLICT (tenant_id, user_id) DO UPDATE SET
                       signature_mode = EXCLUDED.signature_mode,
                       updated_by_user_id = EXCLUDED.updated_by_user_id,
                       updated_at = NOW()'
                : 'INSERT INTO bi_user_report_signature_preferences (tenant_id, user_id, signature_mode, updated_by_user_id, updated_at)
                   VALUES (?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE
                       signature_mode = VALUES(signature_mode),
                       updated_by_user_id = VALUES(updated_by_user_id),
                       updated_at = NOW()';
            $pdo->prepare($sql)->execute($values);

            return [
                'mode' => $mode,
                'source' => 'usuario',
                'available' => true,
                'persisted' => true,
            ];
        } catch (\Throwable) {
            // O controller mantém o comportamento anterior se a migration ainda
            // não estiver aplicada; a ativação efetiva exige o schema disponível.
            return [
                'mode' => self::MODE_BOTH,
                'source' => 'indisponivel',
                'available' => false,
                'persisted' => false,
            ];
        }
    }

    /**
     * A preferência configurada no servidor prevalece sobre o modo enviado pelo
     * navegador. Com "ambos", o pedido original continua válido.
     */
    public function effectiveMode(
        string $requestedMode,
        int $userId,
        ?int $tenantId,
        bool $isMedical
    ): string {
        $requested = $this->normalize($requestedMode);
        $configured = $this->resolveForUser($userId, $tenantId, $isMedical)['mode'];
        return $configured === self::MODE_BOTH ? $requested : $configured;
    }

    public function normalize(mixed $mode): string
    {
        $mode = (string) $mode;
        return in_array($mode, self::MODES, true) ? $mode : self::MODE_BOTH;
    }
}
