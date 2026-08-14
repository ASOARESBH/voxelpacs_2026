<?php

namespace App\Services;

use RuntimeException;

/**
 * Cifra segredos de destino antes da persistência no banco.
 *
 * A chave é derivada de APP_SECRET, que deve existir apenas no .env de
 * produção. O valor retornado nunca é exibido nas telas de administração.
 */
class ReportDeliveryCryptoService
{
    private const CIPHER = 'aes-256-gcm';

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key = $this->key();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Não foi possível cifrar a configuração sensível do destino.');
        }

        return base64_encode(json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($ciphertext),
        ], JSON_UNESCAPED_SLASHES));
    }

    public function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        $decoded = json_decode(base64_decode($payload, true) ?: '', true);
        if (!is_array($decoded) || empty($decoded['iv']) || empty($decoded['tag']) || empty($decoded['data'])) {
            throw new RuntimeException('Configuração sensível do destino inválida.');
        }

        $plaintext = openssl_decrypt(
            base64_decode((string) $decoded['data'], true),
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            base64_decode((string) $decoded['iv'], true),
            base64_decode((string) $decoded['tag'], true)
        );
        if ($plaintext === false) {
            throw new RuntimeException('Não foi possível decifrar a configuração sensível do destino.');
        }

        return $plaintext;
    }

    private function key(): string
    {
        $secret = (string) getenv('APP_SECRET');
        if (strlen($secret) < 32) {
            throw new RuntimeException('APP_SECRET deve ter ao menos 32 caracteres para proteger destinos de entrega.');
        }

        return hash('sha256', $secret, true);
    }
}
