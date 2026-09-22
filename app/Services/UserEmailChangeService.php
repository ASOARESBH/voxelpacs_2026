<?php
namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Database;
use App\Core\Logger;
use PDO;

/** Regras de segurança para troca de e-mail efetivo somente após confirmação. */
final class UserEmailChangeService
{
    private const TTL_HOURS = 24;

    public function __construct(
        private readonly ?PDO $pdo = null,
        private readonly ?UserAccessMailService $mail = null,
    ) {
    }

    /** @return array{ok:bool,error?:string,pending?:bool} */
    public function request(int $userId, int $tenantId, string $newEmail, bool $platformAdmin = false): array
    {
        $email = strtolower(trim($newEmail));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            return ['ok' => false, 'error' => 'invalid_email'];
        }

        $pdo = $this->pdo();
        $token = '';
        $name = '';
        $oldEmail = '';
        $pendingTenantId = null;

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT u.id, u.name, u.email, u.email_pendente, u.email_pendente_tenant_id
                 FROM bi_users u
                 INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = ?
                 WHERE u.id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$tenantId, $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'target_not_found'];
            }

            $oldEmail = strtolower(trim((string) $user['email']));
            $name = (string) $user['name'];
            $pendingTenantId = $user['email_pendente_tenant_id'] === null ? null : (int) $user['email_pendente_tenant_id'];
            if ($email === $oldEmail) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'same_email'];
            }
            if ($pendingTenantId !== null && $pendingTenantId !== $tenantId && !$platformAdmin) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'pending_other_tenant'];
            }

            $collision = $pdo->prepare(
                'SELECT id FROM bi_users WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1'
            );
            $collision->execute([$email, $userId]);
            if ($collision->fetchColumn()) {
                $pdo->rollBack();
                AuditLogger::log('usuario.email_troca_recusada', 'bi_users', $userId, [
                    'tenant_id' => $tenantId,
                    'reason' => 'email_in_use',
                ], $tenantId, 'acesso');
                return ['ok' => false, 'error' => 'email_in_use'];
            }

            $pdo->prepare(
                "UPDATE bi_tenant_access_tokens SET usado = 1
                 WHERE user_id = ? AND tenant_id = ? AND tipo = 'confirmar_email' AND usado = 0"
            )->execute([$userId, $tenantId]);

            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $pdo->prepare(
                'UPDATE bi_users
                 SET email_pendente = ?, email_pendente_tenant_id = ?, email_pendente_solicitada_em = NOW()
                 WHERE id = ?'
            )->execute([$email, $tenantId, $userId]);
            $pdo->prepare(
                "INSERT INTO bi_tenant_access_tokens
                    (user_id, tenant_id, token, tipo, usado, expires_at, email_context_hash)
                 VALUES (?, ?, ?, 'confirmar_email', 0, ?, ?)"
            )->execute([
                $userId,
                $tenantId,
                $tokenHash,
                date('Y-m-d H:i:s', strtotime('+' . self::TTL_HOURS . ' hours')),
                hash('sha256', $email),
            ]);
            $pdo->commit();

            AuditLogger::log('usuario.email_troca_solicitada', 'bi_users', $userId, [
                'tenant_id' => $tenantId,
                'old_email_hash' => hash('sha256', $oldEmail),
                'new_email_hash' => hash('sha256', $email),
                'token_hash' => $tokenHash,
                'result' => 'pending',
            ], $tenantId, 'acesso');

            $delivery = $this->mail()->sendEmailChangeConfirmation($email, $name, $token);
            if (!$delivery['ok']) {
                AuditLogger::log('usuario.email_troca_envio_falhou', 'bi_users', $userId, [
                    'tenant_id' => $tenantId,
                    'result' => 'delivery_failed',
                ], $tenantId, 'acesso');
                Logger::warning('[UserEmailChangeService::request] confirmação pendente sem entrega', [
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                    'result' => 'delivery_failed',
                ]);
                return ['ok' => false, 'error' => 'delivery', 'pending' => true];
            }

            return ['ok' => true, 'pending' => true];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('[UserEmailChangeService::request] falha controlada', [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'error_class' => get_class($e),
            ]);
            return ['ok' => false, 'error' => $this->isUniqueViolation($e) ? 'email_in_use' : 'internal'];
        }
    }

    /** @return array{ok:bool,error?:string,notice_sent?:bool} */
    public function inspect(string $rawToken): array
    {
        $row = $this->findToken($rawToken);
        if (!$row) {
            return ['ok' => false, 'error' => 'invalid_token'];
        }
        if ((int) $row['usado'] === 1) {
            return ['ok' => false, 'error' => 'used_token'];
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return ['ok' => false, 'error' => 'expired_token'];
        }
        if ((string) $row['email_pendente'] === ''
                || !hash_equals((string) $row['email_context_hash'], hash('sha256', (string) $row['email_pendente']))) {
            return ['ok' => false, 'error' => 'stale_token'];
        }
        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string,notice_sent?:bool} */
    public function confirm(string $rawToken): array
    {
        $tokenHash = hash('sha256', $rawToken);
        $pdo = $this->pdo();
        $oldEmail = '';
        $newEmail = '';
        $name = '';
        $userId = 0;
        $tenantId = 0;

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "SELECT t.id AS token_id, t.user_id, t.tenant_id, t.usado, t.expires_at,
                        u.name, u.email, u.email_pendente, u.email_pendente_tenant_id, t.email_context_hash
                 FROM bi_tenant_access_tokens t
                 INNER JOIN bi_users u ON u.id = t.user_id
                 INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = t.tenant_id
                 WHERE t.token = ? AND t.tipo = 'confirmar_email'
                 FOR UPDATE"
            );
            $stmt->execute([$tokenHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'invalid_token'];
            }
            if ((int) $row['usado'] === 1) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'used_token'];
            }
            if (strtotime((string) $row['expires_at']) < time()) {
                $pdo->prepare('UPDATE bi_tenant_access_tokens SET usado = 1 WHERE id = ?')->execute([(int) $row['token_id']]);
                $pdo->commit();
                return ['ok' => false, 'error' => 'expired_token'];
            }

            $newEmail = strtolower(trim((string) $row['email_pendente']));
            $tenantId = (int) $row['tenant_id'];
            $userId = (int) $row['user_id'];
            if ($newEmail === '' || (int) $row['email_pendente_tenant_id'] !== $tenantId
                || !hash_equals((string) $row['email_context_hash'], hash('sha256', $newEmail))) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'stale_token'];
            }

            $collision = $pdo->prepare('SELECT id FROM bi_users WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1');
            $collision->execute([$newEmail, $userId]);
            if ($collision->fetchColumn()) {
                $pdo->prepare('UPDATE bi_tenant_access_tokens SET usado = 1 WHERE id = ?')->execute([(int) $row['token_id']]);
                $pdo->commit();
                AuditLogger::log('usuario.email_troca_recusada', 'bi_users', $userId, [
                    'tenant_id' => $tenantId,
                    'reason' => 'email_in_use_at_confirmation',
                ], $tenantId, 'acesso');
                return ['ok' => false, 'error' => 'email_in_use'];
            }

            $oldEmail = strtolower(trim((string) $row['email']));
            $name = (string) $row['name'];
            $pdo->prepare(
                'UPDATE bi_users
                 SET email = email_pendente, email_pendente = NULL, email_pendente_tenant_id = NULL,
                     email_pendente_solicitada_em = NULL
                 WHERE id = ?'
            )->execute([$userId]);
            $pdo->prepare('UPDATE bi_tenant_access_tokens SET usado = 1 WHERE id = ?')->execute([(int) $row['token_id']]);
            $pdo->prepare(
                "UPDATE bi_tenant_access_tokens SET usado = 1
                 WHERE user_id = ? AND tenant_id = ? AND tipo = 'confirmar_email' AND usado = 0"
            )->execute([$userId, $tenantId]);
            $pdo->commit();

            AuditLogger::log('usuario.email_troca_confirmada', 'bi_users', $userId, [
                'tenant_id' => $tenantId,
                'old_email_hash' => hash('sha256', $oldEmail),
                'new_email_hash' => hash('sha256', $newEmail),
                'result' => 'confirmed',
            ], $tenantId, 'acesso');

            $notice = $this->mail()->sendEmailChangeNotice($oldEmail, $name);
            if (!$notice['ok']) {
                AuditLogger::log('usuario.email_troca_aviso_falhou', 'bi_users', $userId, [
                    'tenant_id' => $tenantId,
                    'result' => 'notice_delivery_failed',
                ], $tenantId, 'acesso');
                Logger::warning('[UserEmailChangeService::confirm] troca concluída sem aviso ao e-mail anterior', [
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                    'result' => 'notice_delivery_failed',
                ]);
                return ['ok' => true, 'notice_sent' => false];
            }

            return ['ok' => true, 'notice_sent' => true];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('[UserEmailChangeService::confirm] falha controlada', [
                'error_class' => get_class($e),
            ]);
            return ['ok' => false, 'error' => $this->isUniqueViolation($e) ? 'email_in_use' : 'internal'];
        }
    }

    private function findToken(string $rawToken): ?array
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare(
            "SELECT t.usado, t.expires_at, t.email_context_hash, u.email_pendente
             FROM bi_tenant_access_tokens t
             INNER JOIN bi_users u ON u.id = t.user_id
             INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = t.tenant_id
             WHERE t.token = ? AND t.tipo = 'confirmar_email'
             LIMIT 1"
        );
        $stmt->execute([hash('sha256', $rawToken)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? Database::getInstance();
    }

    private function mail(): UserAccessMailService
    {
        return $this->mail ?? new UserAccessMailService();
    }

    private function isUniqueViolation(\Throwable $e): bool
    {
        return $e instanceof \PDOException
            && in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
