<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Mailer;
use App\Core\PortalHost;
use App\Core\SqlHelper;
use PDO;

/**
 * Compartilhamento de laudos liberados originado no Portal do Paciente.
 *
 * Destinatários nunca são gravados em texto puro: apenas uma dica mascarada
 * suficiente para auditoria. Cada link é opaco, temporário e independente da
 * sessão original do paciente.
 */
final class PortalShareService
{
    private const LINK_HOURS = 24;

    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Database::getInstance();
    }

    /** @return array{url:string,expires_at:string} */
    public function createWhatsappLink(string $reportToken, array $scope, string $phone, string $ip): array
    {
        $phone = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($phone) < 10 || strlen($phone) > 15) {
            throw new \DomainException('Informe um número de WhatsApp válido com DDD.');
        }

        $share = $this->createShare($reportToken, $scope, 'whatsapp', $this->maskPhone($phone), $ip);
        $text = 'Olá! Foi compartilhado com você um laudo médico. Acesse o link seguro e temporário: ' . $share['url'];
        $share['whatsapp_url'] = 'https://wa.me/' . $phone . '?text=' . rawurlencode($text);
        return $share;
    }

    /** @return array{ok:bool,url:string,expires_at:string} */
    public function sendEmail(string $reportToken, array $scope, string $email, string $ip): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            throw new \DomainException('Informe um endereço de e-mail válido.');
        }

        $share = $this->createShare($reportToken, $scope, 'email', $this->maskEmail($email), $ip);
        $report = $this->sharedReportByToken($this->rawTokenFromUrl($share['url']));
        if ($report === null) {
            throw new \RuntimeException('Não foi possível preparar o laudo para envio.');
        }

        try {
            $pdf = (new ReportPdfService())->renderBinary((object) $report['study'], (object) $report['report']);
            $filename = 'laudo-voxel-' . ($report['study']['accession_number'] ?: $report['report']['id']) . '.pdf';
            $html = $this->emailBody($share['url'], $share['expires_at']);
            $sent = Mailer::sendWithAttachment(
                $email,
                'Laudo médico disponível — VOXEL PACS',
                $html,
                [['content' => $pdf, 'filename' => $filename, 'mime' => 'application/pdf']]
            );
        } catch (\Throwable $e) {
            Logger::error('PortalShareService::sendEmail falhou', [
                'report_id' => $report['report']['id'] ?? null,
                'recipient_hint' => $this->maskEmail($email),
                'error' => $e->getMessage(),
            ]);
            $this->audit('portal.compartilhamento_email_falhou', (int) ($report['report']['id'] ?? 0), $scope, [
                'recipient_hint' => $this->maskEmail($email),
            ]);
            throw new \RuntimeException('Não foi possível enviar o e-mail neste momento. Tente novamente mais tarde.');
        }

        if (!$sent) {
            $this->audit('portal.compartilhamento_email_falhou', (int) $report['report']['id'], $scope, [
                'recipient_hint' => $this->maskEmail($email),
            ]);
            throw new \RuntimeException('O serviço de e-mail não confirmou o envio. Tente novamente mais tarde.');
        }

        $this->audit('portal.compartilhamento_email_enviado', (int) $report['report']['id'], $scope, [
            'recipient_hint' => $this->maskEmail($email),
            'expires_at' => $share['expires_at'],
        ]);
        return ['ok' => true, 'url' => $share['url'], 'expires_at' => $share['expires_at']];
    }

    /** @return array{report:array<string,mixed>,study:array<string,mixed>}|null */
    public function sharedReportByToken(string $rawToken): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $rawToken)) {
            return null;
        }
        $situacaoLiberada = SqlHelper::isPostgres() ? 'CAST(r.situacao AS TEXT)' : 'r.situacao';
        $stmt = $this->pdo->prepare(
            "SELECT r.*, e.*,
                    r.id AS report_id, e.id AS estudo_id,
                    s.id AS share_id, s.access_count
             FROM bi_portal_share_links s
             INNER JOIN reports r ON r.id = s.report_id AND r.tenant_id = s.tenant_id
             INNER JOIN bi_pacs_estudos e ON e.id = r.estudo_id AND e.tenant_id = r.tenant_id
             WHERE s.token_hash = :token_hash
               AND s.revoked_at IS NULL
               AND s.expires_at > NOW()
               AND {$situacaoLiberada} = 'liberado'
             LIMIT 1"
        );
        $stmt->execute(['token_hash' => hash('sha256', $rawToken)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $this->pdo->prepare('UPDATE bi_portal_share_links SET access_count = access_count + 1, used_at = COALESCE(used_at, NOW()) WHERE id = :id')
            ->execute(['id' => (int) $row['share_id']]);

        $report = $row;
        $report['id'] = (int) $row['report_id'];
        $study = $row;
        $study['id'] = (int) $row['estudo_id'];
        return ['report' => $report, 'study' => $study];
    }

    /** @return array{url:string,expires_at:string} */
    private function createShare(string $reportToken, array $scope, string $channel, string $recipientHint, string $ip): array
    {
        $portal = new PatientPortalService($this->pdo);
        $report = $portal->releasedReportByToken($reportToken, $scope);
        if ($report === null) {
            throw new \DomainException('Laudo não encontrado ou não disponível para compartilhamento.');
        }

        $rawToken = bin2hex(random_bytes(32));
        $expiresSql = SqlHelper::futureTimestamp('HOUR', self::LINK_HOURS);
        $tokenHash = hash('sha256', $rawToken);
        $sql = 'INSERT INTO bi_portal_share_links
                (token_hash, report_id, tenant_id, channel, recipient_hint, creator_identity_hash, ip_address, expires_at)
             VALUES
                (:token_hash, :report_id, :tenant_id, :channel, :recipient_hint, :identity_hash, :ip_address, ' . $expiresSql . ')';
        if (SqlHelper::isPostgres()) {
            $sql .= ' RETURNING expires_at';
        }
        $insert = $this->pdo->prepare($sql);
        $params = [
            'token_hash' => $tokenHash,
            'report_id' => (int) $report['report_id'],
            'tenant_id' => (int) $scope['tenant_id'],
            'channel' => $channel,
            'recipient_hint' => $recipientHint,
            'identity_hash' => (string) $scope['identity_hash'],
            'ip_address' => $ip,
        ];
        $insert->execute($params);
        if (SqlHelper::isPostgres()) {
            $expiresAt = (string) $insert->fetchColumn();
        } else {
            $expiry = $this->pdo->prepare('SELECT expires_at FROM bi_portal_share_links WHERE token_hash = :token_hash LIMIT 1');
            $expiry->execute(['token_hash' => $tokenHash]);
            $expiresAt = (string) $expiry->fetchColumn();
        }
        $url = PortalHost::baseUrl() . '/compartilhado/' . $rawToken;

        $this->audit('portal.compartilhamento_criado', (int) $report['report_id'], $scope, [
            'channel' => $channel,
            'recipient_hint' => $recipientHint,
            'expires_at' => $expiresAt,
        ]);
        return ['url' => $url, 'expires_at' => $expiresAt];
    }

    private function emailBody(string $url, string $expiresAt): string
    {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $expiry = htmlspecialchars(date('d/m/Y H:i', strtotime($expiresAt)), ENT_QUOTES, 'UTF-8');
        return '<!doctype html><html lang="pt-BR"><body style="font-family:Arial,sans-serif;color:#0f172a">'
            . '<h2 style="color:#075a9e">VOXEL PACS — Laudo médico compartilhado</h2>'
            . '<p>Um laudo médico foi compartilhado com você. O PDF está anexado a esta mensagem.</p>'
            . '<p>Como alternativa, utilize o link seguro abaixo até <strong>' . $expiry . '</strong>:</p>'
            . '<p><a href="' . $safeUrl . '">Acessar laudo compartilhado</a></p>'
            . '<p style="font-size:12px;color:#475569">Não encaminhe esta mensagem ou o anexo sem autorização do paciente.</p>'
            . '</body></html>';
    }

    private function rawTokenFromUrl(string $url): string
    {
        return (string) basename(parse_url($url, PHP_URL_PATH) ?: '');
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 1) . '***@' . $domain;
    }

    private function maskPhone(string $phone): string
    {
        return str_repeat('*', max(0, strlen($phone) - 4)) . substr($phone, -4);
    }

    private function audit(string $action, int $reportId, array $scope, array $details): void
    {
        try {
            AuditLogger::log($action, 'patient_portal', $reportId, array_merge($details, [
                'tenant_id' => (int) ($scope['tenant_id'] ?? 0),
                'identity_hash' => (string) ($scope['identity_hash'] ?? ''),
            ]));
        } catch (\Throwable $e) {
            Logger::warning('PortalShareService::audit indisponível', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
