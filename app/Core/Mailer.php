<?php
namespace App\Core;

/**
 * Mailer mínimo via mail() nativo do PHP — sem dependências externas,
 * compatível com hospedagem compartilhada (ver bootstrap.php: "Compatível
 * com HostGator Compartilhado"). Não existia nenhum mecanismo de envio de
 * e-mail no projeto antes desta classe, apesar das variáveis MAIL_* já
 * presentes em .env.example.
 *
 * Caso a entregabilidade via mail() nativo seja insuficiente em produção
 * (comum em hosts compartilhados), trocar a implementação de send() por
 * SMTP/PHPMailer é a única mudança necessária — nenhum chamador precisa
 * ser alterado.
 */
class Mailer {
    /**
     * Envia mensagem HTML com anexos binários (por exemplo, PDF de laudo).
     * Cada anexo usa as chaves content, filename e mime.
     *
     * @param array<int,array{content:string,filename:string,mime:string}> $attachments
     */
    public static function sendWithAttachment(string $to, string $subject, string $htmlBody, array $attachments): bool {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || $attachments === []) {
            return false;
        }
        $from     = $_ENV['MAIL_FROM']      ?? 'noreply@voxelpacs.com.br';
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'VOXEL PACS';
        $boundary = '=_Voxel_' . bin2hex(random_bytes(16));
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "From: {$fromName} <{$from}>\r\n";
        $headers .= "Reply-To: {$from}\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlBody . "\r\n";
        foreach ($attachments as $attachment) {
            $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) ($attachment['filename'] ?? 'anexo.pdf')) ?: 'anexo.pdf';
            $mime = preg_match('#^[A-Za-z0-9.+-]+/[A-Za-z0-9.+-]+$#', (string) ($attachment['mime'] ?? ''))
                ? (string) $attachment['mime']
                : 'application/octet-stream';
            $content = (string) ($attachment['content'] ?? '');
            if ($content === '') {
                throw new \InvalidArgumentException('Anexo vazio não pode ser enviado.');
            }
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: {$mime}; name=\"{$filename}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
            $body .= chunk_split(base64_encode($content)) . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";
        $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        try {
            $ok = mail($to, $subjectEncoded, $body, $headers);
            if (!$ok) {
                Logger::error('[Mailer::sendWithAttachment] mail() retornou false', ['to' => $to, 'subject' => $subject]);
            }
            return $ok;
        } catch (\Throwable $e) {
            Logger::error('[Mailer::sendWithAttachment] ' . $e->getMessage(), ['to' => $to]);
            return false;
        }
    }

    public static function send(string $to, string $subject, string $htmlBody): bool {
        $from     = $_ENV['MAIL_FROM']      ?? 'noreply@voxelpacs.com.br';
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'VOXEL PACS';

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$fromName} <{$from}>\r\n";
        $headers .= "Reply-To: {$from}\r\n";

        $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        try {
            $ok = mail($to, $subjectEncoded, $htmlBody, $headers);
            if (!$ok) {
                Logger::error('[Mailer::send] mail() retornou false', ['to' => $to, 'subject' => $subject]);
            }
            return $ok;
        } catch (\Throwable $e) {
            Logger::error('[Mailer::send] ' . $e->getMessage(), ['to' => $to]);
            return false;
        }
    }
}
