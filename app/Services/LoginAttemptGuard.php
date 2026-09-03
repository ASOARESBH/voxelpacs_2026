<?php
namespace App\Services;

use App\Core\Database;
use App\Core\SqlHelper;

/**
 * Segurança de autenticação pública: mantém somente hashes com segredo de
 * servidor e nunca persiste senha, e-mail ou IP em texto claro. Esta classe
 * é utilizada somente em tentativas novas de autenticação.
 */
final class LoginAttemptGuard
{
    private const WINDOW_SECONDS = 900;
    private const MAX_IDENTITY_IP_FAILURES = 5;
    private const MAX_IP_FAILURES = 25;

    public function allows(string $identity, ?string $ip = null): bool
    {
        try {
            $pdo = Database::getInstance();
            if (!$this->hasSecret() || !SqlHelper::hasTable($pdo, 'bi_auth_login_attempts')) return true;

            $cutoff = date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);
            $ipHash = $this->hashIp($ip);
            $identityHash = $this->hashIdentity($identity);

            $byIdentity = $pdo->prepare(
                'SELECT COUNT(*) FROM bi_auth_login_attempts
                  WHERE identity_hash = :identity_hash AND ip_hash = :ip_hash
                    AND sucesso = 0 AND attempted_at >= :cutoff'
            );
            $byIdentity->execute([':identity_hash' => $identityHash, ':ip_hash' => $ipHash, ':cutoff' => $cutoff]);

            $byIp = $pdo->prepare(
                'SELECT COUNT(*) FROM bi_auth_login_attempts
                  WHERE ip_hash = :ip_hash AND sucesso = 0 AND attempted_at >= :cutoff'
            );
            $byIp->execute([':ip_hash' => $ipHash, ':cutoff' => $cutoff]);

            return (int) $byIdentity->fetchColumn() < self::MAX_IDENTITY_IP_FAILURES
                && (int) $byIp->fetchColumn() < self::MAX_IP_FAILURES;
        } catch (\Throwable) {
            // Compatibilidade durante rollout: uma falha no controle auxiliar
            // não pode bloquear todo o login; a migration o torna efetivo.
            return true;
        }
    }

    public function recordFailure(string $identity, ?string $ip = null): void
    {
        try {
            $pdo = Database::getInstance();
            if (!$this->hasSecret() || !SqlHelper::hasTable($pdo, 'bi_auth_login_attempts')) return;
            $stmt = $pdo->prepare(
                'INSERT INTO bi_auth_login_attempts (identity_hash, ip_hash, sucesso, attempted_at)
                 VALUES (:identity_hash, :ip_hash, 0, NOW())'
            );
            $stmt->execute([':identity_hash' => $this->hashIdentity($identity), ':ip_hash' => $this->hashIp($ip)]);

            // Retenção curta: os hashes só são necessários para a janela de
            // proteção e uma margem operacional; evita crescimento ilimitado.
            $cleanup = $pdo->prepare('DELETE FROM bi_auth_login_attempts WHERE attempted_at < :cutoff');
            $cleanup->execute([':cutoff' => date('Y-m-d H:i:s', time() - 86400)]);
        } catch (\Throwable) {
            // Não registra dados sensíveis em caso de indisponibilidade auxiliar.
        }
    }

    public function clearFailures(string $identity, ?string $ip = null): void
    {
        try {
            $pdo = Database::getInstance();
            if (!$this->hasSecret() || !SqlHelper::hasTable($pdo, 'bi_auth_login_attempts')) return;
            $stmt = $pdo->prepare(
                'DELETE FROM bi_auth_login_attempts
                  WHERE identity_hash = :identity_hash AND ip_hash = :ip_hash AND sucesso = 0'
            );
            $stmt->execute([':identity_hash' => $this->hashIdentity($identity), ':ip_hash' => $this->hashIp($ip)]);
        } catch (\Throwable) {
            // Uma limpeza opcional nunca impede autenticação bem-sucedida.
        }
    }

    private function hashIdentity(string $identity): string
    {
        return $this->hash('identity|' . mb_strtolower(trim($identity), 'UTF-8'));
    }

    private function hashIp(?string $ip): string
    {
        return $this->hash('ip|' . substr((string) $ip, 0, 45));
    }

    private function hash(string $value): string
    {
        $secret = (string) ($_ENV['APP_SECRET'] ?? getenv('APP_SECRET') ?: '');
        return hash_hmac('sha256', $value, $secret);
    }

    private function hasSecret(): bool
    {
        return (string) ($_ENV['APP_SECRET'] ?? getenv('APP_SECRET') ?: '') !== '';
    }
}
