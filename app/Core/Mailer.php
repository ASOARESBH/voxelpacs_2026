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
