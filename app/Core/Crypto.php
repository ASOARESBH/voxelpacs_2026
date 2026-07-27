<?php
namespace App\Core;

/**
 * Criptografia simétrica (AES-256-GCM) para segredos gravados no banco
 * (ex: senha/token de acesso a servidores Orthanc). Chave derivada via HKDF
 * do APP_SECRET (.env) — não introduz variável de ambiente nova.
 *
 * Migração suave: valores gravados por esta classe levam o prefixo ENC_PREFIX.
 * decrypt() só tenta decriptar quando o prefixo está presente; caso contrário
 * devolve o valor original (texto legado, gravado antes desta classe existir).
 */
class Crypto
{
    private const CIPHER     = 'aes-256-gcm';
    private const ENC_PREFIX = 'enc:v1:';

    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain;
        }

        $iv  = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';
        $cipherText = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipherText === false) {
            Logger::error('[Crypto::encrypt] Falha ao criptografar valor.');
            return $plain;
        }

        return self::ENC_PREFIX . base64_encode($iv . $tag . $cipherText);
    }

    public static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || !str_starts_with($value, self::ENC_PREFIX)) {
            return $value;
        }

        $raw = base64_decode(substr($value, strlen(self::ENC_PREFIX)));
        if ($raw === false || strlen($raw) < 28) {
            Logger::error('[Crypto::decrypt] Valor criptografado malformado.');
            return null;
        }

        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        $iv         = substr($raw, 0, $ivLen);
        $tag        = substr($raw, $ivLen, 16);
        $cipherText = substr($raw, $ivLen + 16);

        $plain = openssl_decrypt($cipherText, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($plain === false) {
            Logger::error('[Crypto::decrypt] Falha ao decriptar valor (chave incorreta ou dado corrompido).');
            return null;
        }

        return $plain;
    }

    /** Diz se um valor já está no formato criptografado desta classe. */
    public static function isEncrypted(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::ENC_PREFIX);
    }

    private static function key(): string
    {
        $secret = getenv('APP_SECRET') ?: ($_ENV['APP_SECRET'] ?? '');
        return hash_hkdf('sha256', (string) $secret, 32, 'voxelpacs-orthanc-credentials');
    }
}
