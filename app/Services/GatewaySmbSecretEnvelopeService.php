<?php
// Materialização de runtime inerte da Fase 1 Philips Non-DICOM; não ativa SMB, bridge, XML ou automação.

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Sela a senha SMB somente para a chave pública X25519 root-only do gateway.
 * O PACS jamais chama SMB; ele só entrega o envelope autenticado à bridge.
 */
final class GatewaySmbSecretEnvelopeService
{
    private const CONTEXT = 'voxel-nondicom-smb-v1';

    /** @return array{envelope:string,sha256:string} */
    public function seal(string $password, int $tenantId, int $destinationId): array
    {
        if ($password === '' || $tenantId <= 0 || $destinationId <= 0 || !function_exists('sodium_crypto_scalarmult')) {
            throw new RuntimeException('gateway_secret_envelope_unavailable');
        }

        $publicKeyFile = trim((string) getenv('PHILIPS_NON_DICOM_GATEWAY_ENVELOPE_PUBLIC_KEY_FILE'));
        if ($publicKeyFile === '' || !is_file($publicKeyFile)) {
            throw new RuntimeException('gateway_secret_envelope_unavailable');
        }
        $encoded = trim((string) file_get_contents($publicKeyFile));
        $gatewayPublic = base64_decode($encoded, true);
        if (!is_string($gatewayPublic) || strlen($gatewayPublic) !== SODIUM_CRYPTO_SCALARMULT_BYTES) {
            throw new RuntimeException('gateway_secret_envelope_unavailable');
        }

        $ephemeralSecret = random_bytes(SODIUM_CRYPTO_SCALARMULT_SCALARBYTES);
        $ephemeralPublic = sodium_crypto_scalarmult_base($ephemeralSecret);
        $shared = sodium_crypto_scalarmult($ephemeralSecret, $gatewayPublic);
        $expiresAt = time() + 60;
        $context = self::CONTEXT . '|' . $tenantId . '|' . $destinationId . '|' . $expiresAt;
        $key = hash_hkdf('sha256', $shared, 32, $context, '');
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($password, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $context);
        sodium_memzero($ephemeralSecret);
        sodium_memzero($shared);
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('gateway_secret_envelope_unavailable');
        }

        $payload = json_encode([
            'v' => 1,
            'tenant_id' => $tenantId,
            'destination_id' => $destinationId,
            'expires_at' => $expiresAt,
            'ephemeral_public' => base64_encode($ephemeralPublic),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            throw new RuntimeException('gateway_secret_envelope_unavailable');
        }
        $envelope = base64_encode($payload);
        return ['envelope' => $envelope, 'sha256' => hash('sha256', $envelope)];
    }
}
