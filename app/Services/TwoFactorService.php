<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Mailer;

final class TwoFactorService
{
    private const CODE_LENGTH = 6;
    private const TTL_SECONDS = 600;
    private const MAX_ATTEMPTS = 5;
    private const RESEND_COOLDOWN_SECONDS = 60;

    public function isEnabledForUser(int $userId): bool
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT 1 FROM bi_user_two_factor_settings WHERE user_id = ? AND email_enabled = TRUE LIMIT 1'
        );
        $stmt->execute([$userId]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return array{ok:bool,challenge_id?:int,error?:string} */
    public function issue(object $user, bool $respectCooldown = true): array
    {
        $userId = (int) ($user->id ?? 0);
        $email = trim((string) ($user->email ?? ''));
        if ($userId <= 0 || $email === '') return ['ok' => false, 'error' => 'invalid_user'];

        $pdo = Database::getInstance();
        $last = $pdo->prepare(
            'SELECT sent_at FROM bi_user_two_factor_challenges WHERE user_id = ? ORDER BY created_at DESC LIMIT 1'
        );
        $last->execute([$userId]);
        $lastSent = $last->fetchColumn();
        if ($respectCooldown && $lastSent && strtotime((string) $lastSent) > time() - self::RESEND_COOLDOWN_SECONDS) {
            return ['ok' => false, 'error' => 'cooldown'];
        }

        $code = str_pad((string) random_int(0, (10 ** self::CODE_LENGTH) - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE bi_user_two_factor_challenges SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL')
                ->execute([$userId]);
            $insert = $pdo->prepare(
                'INSERT INTO bi_user_two_factor_challenges (user_id, code_hash, max_attempts, expires_at) VALUES (?,?,?,NOW() + INTERVAL \'10 minutes\') RETURNING id'
            );
            $insert->execute([$userId, password_hash($code, PASSWORD_DEFAULT), self::MAX_ATTEMPTS]);
            $challengeId = (int) $insert->fetchColumn();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('[TwoFactorService::issue] Falha ao criar desafio', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'persistence'];
        }

        $name = htmlspecialchars((string) ($user->name ?? ''), ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
            . '<h2 style="color:#0a1628;">VOXEL PACS — Código de acesso</h2>'
            . '<p>Olá, <strong>' . $name . '</strong>.</p>'
            . '<p>Use o código abaixo para concluir seu login:</p>'
            . '<div style="letter-spacing:.35em;font-size:30px;font-weight:700;text-align:center;color:#0a1628;padding:20px;background:#f1f5f9;border-radius:8px;">' . $code . '</div>'
            . '<p style="color:#64748b;font-size:.85rem;">O código expira em 10 minutos e só pode ser usado uma vez. Se você não solicitou este acesso, ignore esta mensagem.</p>'
            . '</div>';

        if (!Mailer::send($email, 'VOXEL PACS — Código de verificação', $html)) {
            $pdo->prepare('UPDATE bi_user_two_factor_challenges SET consumed_at = NOW() WHERE id = ?')->execute([$challengeId]);
            Logger::warning('[TwoFactorService::issue] SMTP recusou desafio 2F', ['user_id' => $userId]);
            return ['ok' => false, 'error' => 'delivery'];
        }

        Logger::info('[TwoFactorService::issue] Desafio 2F enviado', ['user_id' => $userId, 'challenge_id' => $challengeId]);
        return ['ok' => true, 'challenge_id' => $challengeId];
    }

    /** @return array{ok:bool,error?:string} */
    public function verify(int $challengeId, int $userId, string $code): array
    {
        if (!preg_match('/^\d{6}$/', $code)) return ['ok' => false, 'error' => 'invalid'];

        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, code_hash, attempts, max_attempts, expires_at, consumed_at FROM bi_user_two_factor_challenges WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmt->execute([$challengeId, $userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || $row['consumed_at'] || strtotime((string) $row['expires_at']) < time()) {
                $pdo->commit();
                return ['ok' => false, 'error' => 'expired'];
            }
            if ((int) $row['attempts'] >= (int) $row['max_attempts']) {
                $pdo->prepare('UPDATE bi_user_two_factor_challenges SET consumed_at = NOW() WHERE id = ?')->execute([$challengeId]);
                $pdo->commit();
                return ['ok' => false, 'error' => 'locked'];
            }
            if (!password_verify($code, (string) $row['code_hash'])) {
                $newAttempts = (int) $row['attempts'] + 1;
                $pdo->prepare('UPDATE bi_user_two_factor_challenges SET attempts = ?, consumed_at = CASE WHEN ? >= max_attempts THEN NOW() ELSE NULL END WHERE id = ?')
                    ->execute([$newAttempts, $newAttempts, $challengeId]);
                $pdo->commit();
                return ['ok' => false, 'error' => $newAttempts >= (int) $row['max_attempts'] ? 'locked' : 'invalid'];
            }
            $pdo->prepare('UPDATE bi_user_two_factor_challenges SET consumed_at = NOW() WHERE id = ?')->execute([$challengeId]);
            $pdo->commit();
            Logger::info('[TwoFactorService::verify] Desafio 2F validado', ['user_id' => $userId, 'challenge_id' => $challengeId]);
            return ['ok' => true];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('[TwoFactorService::verify] Falha na validação', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'internal'];
        }
    }
}
