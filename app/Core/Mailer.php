<?php

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Transporte central de mensagens do VOXEL PACS.
 *
 * A entrega usa SMTP autenticado; não há fallback para mail() porque a
 * instância de produção não possui MTA local. Todas as credenciais são lidas
 * somente de variáveis de ambiente carregadas fora do controle de versão.
 */
class Mailer
{
    /**
     * Envia uma mensagem HTML.
     */
    public static function send(string $to, string $subject, string $htmlBody): bool
    {
        return self::deliver($to, $subject, $htmlBody);
    }

    /**
     * Envia uma mensagem HTML com anexos binários em memória.
     *
     * @param array<int,array{content:string,filename:string,mime:string}> $attachments
     */
    public static function sendWithAttachment(string $to, string $subject, string $htmlBody, array $attachments): bool
    {
        if ($attachments === []) {
            Logger::warning('[Mailer::sendWithAttachment] anexo ausente', [
                'recipient_hint' => self::maskEmail($to),
            ]);

            return false;
        }

        return self::deliver($to, $subject, $htmlBody, $attachments);
    }

    /**
     * @param array<int,array{content:string,filename:string,mime:string}> $attachments
     */
    private static function deliver(string $to, string $subject, string $htmlBody, array $attachments = []): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Logger::warning('[Mailer] destinatário inválido');

            return false;
        }

        try {
            $mail = self::client();
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = trim(html_entity_decode(strip_tags($htmlBody), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            foreach ($attachments as $attachment) {
                $content = (string) ($attachment['content'] ?? '');
                if ($content === '') {
                    throw new \InvalidArgumentException('Anexo vazio não pode ser enviado.');
                }

                $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) ($attachment['filename'] ?? 'anexo.pdf')) ?: 'anexo.pdf';
                $mime = preg_match('#^[A-Za-z0-9.+-]+/[A-Za-z0-9.+-]+$#', (string) ($attachment['mime'] ?? ''))
                    ? (string) $attachment['mime']
                    : 'application/octet-stream';

                $mail->addStringAttachment($content, $filename, PHPMailer::ENCODING_BASE64, $mime);
            }

            $mail->send();

            Logger::info('[Mailer] mensagem SMTP aceita', [
                'recipient_hint' => self::maskEmail($to),
                'has_attachments' => $attachments !== [],
            ]);

            return true;
        } catch (\Throwable $e) {
            Logger::error('[Mailer] falha de entrega SMTP: ' . $e->getMessage(), [
                'recipient_hint' => self::maskEmail($to),
                'has_attachments' => $attachments !== [],
            ]);

            return false;
        }
    }

    private static function client(): PHPMailer
    {
        $host = trim(self::env('MAIL_SMTP_HOST'));
        $username = trim(self::env('MAIL_SMTP_USERNAME'));
        $password = self::env('MAIL_SMTP_PASSWORD');
        $from = trim(self::env('MAIL_FROM', $username));
        $fromName = trim(self::env('MAIL_FROM_NAME', 'VOXEL PACS'));
        $port = (int) self::env('MAIL_SMTP_PORT', '465');
        $encryption = strtolower(trim(self::env('MAIL_SMTP_ENCRYPTION', 'ssl')));

        if ($host === '' || $username === '' || $password === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('SMTP não configurado: defina MAIL_SMTP_HOST, MAIL_SMTP_USERNAME, MAIL_SMTP_PASSWORD e MAIL_FROM.');
        }

        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('Porta SMTP inválida.');
        }

        if (!in_array($encryption, ['ssl', 'tls'], true)) {
            throw new \RuntimeException('Criptografia SMTP inválida; use ssl ou tls.');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->SMTPSecure = $encryption === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
        $mail->Timeout = 20;
        $mail->SMTPDebug = 0;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($from, $fromName);
        $mail->addReplyTo($from, $fromName);

        return $mail;
    }

    private static function env(string $name, string $default = ''): string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            $value = $_ENV[$name] ?? $default;
        }

        return (string) $value;
    }

    private static function maskEmail(string $email): string
    {
        $parts = explode('@', strtolower(trim($email)), 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            return '[inválido]';
        }

        return substr($parts[0], 0, 1) . '***@' . $parts[1];
    }
}
