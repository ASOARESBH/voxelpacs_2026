<?php
namespace App\Services;

use App\Core\Logger;
use App\Core\Mailer;
use App\Core\PublicUrl;
use PDO;

/** Centraliza tokens, links e mensagens de acesso do módulo de usuários. */
final class UserAccessMailService
{
    public const INVITATION_TYPE = 'criar_senha';
    public const EMAIL_CHANGE_TYPE = 'confirmar_email';
    private const INVITATION_TTL_HOURS = 48;
    private const EMAIL_CHANGE_TTL_HOURS = 24;

    /** @return array{ok:bool,error?:string} */
    public function sendInvitation(PDO $pdo, int $userId, int $tenantId, string $email, string $name): array
    {
        try {
            $pdo->prepare(
                "UPDATE bi_tenant_access_tokens SET usado = 1
                 WHERE user_id = ? AND tenant_id = ? AND usado = 0 AND tipo = 'criar_senha'"
            )->execute([$userId, $tenantId]);

            $token = bin2hex(random_bytes(32));
            $pdo->prepare(
                "INSERT INTO bi_tenant_access_tokens
                    (user_id, tenant_id, token, tipo, usado, expires_at)
                 VALUES (?, ?, ?, 'criar_senha', 0, ?)"
            )->execute([
                $userId,
                $tenantId,
                $token,
                date('Y-m-d H:i:s', strtotime('+' . self::INVITATION_TTL_HOURS . ' hours')),
            ]);

            $link = PublicUrl::base() . '/acesso/criar-senha/' . $token;
            $html = $this->invitationHtml($name, $link);
            if (!Mailer::send($email, 'Acesso ao VOXEL PACS — Crie sua senha', $html)) {
                Logger::warning('[UserAccessMailService::sendInvitation] transporte recusou convite', [
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                    'mail_result' => 'rejected',
                ]);
                return ['ok' => false, 'error' => 'delivery'];
            }

            Logger::info('[UserAccessMailService::sendInvitation] convite aceito pelo transporte', [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'mail_result' => 'accepted',
            ]);
            return ['ok' => true];
        } catch (\Throwable $e) {
            Logger::error('[UserAccessMailService::sendInvitation] falha controlada', [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'error_class' => get_class($e),
            ]);
            return ['ok' => false, 'error' => 'internal'];
        }
    }

    /** @return array{ok:bool,error?:string} */
    public function sendEmailChangeConfirmation(string $email, string $name, string $token): array
    {
        try {
            $link = PublicUrl::base() . '/acesso/confirmar-email/' . $token;
            if (!Mailer::send($email, 'Confirme a alteração do seu e-mail — VOXEL PACS', $this->emailChangeHtml($name, $link))) {
                Logger::warning('[UserAccessMailService::sendEmailChangeConfirmation] transporte recusou confirmação', [
                    'mail_result' => 'rejected',
                ]);
                return ['ok' => false, 'error' => 'delivery'];
            }

            Logger::info('[UserAccessMailService::sendEmailChangeConfirmation] confirmação aceita pelo transporte', [
                'mail_result' => 'accepted',
            ]);
            return ['ok' => true];
        } catch (\Throwable $e) {
            Logger::error('[UserAccessMailService::sendEmailChangeConfirmation] falha controlada', [
                'error_class' => get_class($e),
            ]);
            return ['ok' => false, 'error' => 'internal'];
        }
    }

    /** @return array{ok:bool,error?:string} */
    public function sendEmailChangeNotice(string $email, string $name): array
    {
        try {
            $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
                . '<h2 style="color:#0a1628;">VOXEL PACS — Alteração de e-mail</h2>'
                . '<p>Olá, <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>!</p>'
                . '<p>O e-mail de login da sua conta foi alterado após confirmação de um novo endereço.</p>'
                . '<p>Se você não reconhece esta alteração, entre em contato imediatamente com o administrador da sua organização.</p>'
                . '</div>';

            if (!Mailer::send($email, 'E-mail de login alterado — VOXEL PACS', $html)) {
                return ['ok' => false, 'error' => 'delivery'];
            }

            return ['ok' => true];
        } catch (\Throwable $e) {
            Logger::error('[UserAccessMailService::sendEmailChangeNotice] falha controlada', [
                'error_class' => get_class($e),
            ]);
            return ['ok' => false, 'error' => 'internal'];
        }
    }

    private function invitationHtml(string $name, string $link): string
    {
        return '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
            . '<h2 style="color:#0a1628;">Bem-vindo ao VOXEL PACS</h2>'
            . '<p>Olá, <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>!</p>'
            . '<p>Sua conta foi criada no VOXEL PACS. Clique no botão abaixo para definir sua senha:</p>'
            . '<p style="text-align:center;margin:2rem 0;">'
            . '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="background:#4fc3f7;color:#0a1628;padding:.75rem 2rem;border-radius:8px;text-decoration:none;font-weight:700;">'
            . 'Criar minha senha</a></p>'
            . '<p style="color:#64748b;font-size:.85rem;">Link válido por ' . self::INVITATION_TTL_HOURS . ' horas. Use apenas uma vez.</p>'
            . '</div>';
    }

    private function emailChangeHtml(string $name, string $link): string
    {
        return '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
            . '<h2 style="color:#0a1628;">VOXEL PACS — Confirmação de e-mail</h2>'
            . '<p>Olá, <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>!</p>'
            . '<p>Foi solicitada uma alteração do e-mail de login da sua conta. Confirme o novo endereço pelo botão abaixo:</p>'
            . '<p style="text-align:center;margin:2rem 0;">'
            . '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="background:#4fc3f7;color:#0a1628;padding:.75rem 2rem;border-radius:8px;text-decoration:none;font-weight:700;">'
            . 'Confirmar novo e-mail</a></p>'
            . '<p style="color:#64748b;font-size:.85rem;">Este link expira em ' . self::EMAIL_CHANGE_TTL_HOURS . ' horas. O e-mail atual continua válido até a confirmação.</p>'
            . '</div>';
    }
}
