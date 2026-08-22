<?php

namespace App\Services;

use App\Core\Crypto;
use App\Repositories\ImagiflowIntegrationRepository;
use DomainException;

final class ImagiflowApiAuthService
{
    public function __construct(private ImagiflowIntegrationRepository $repository)
    {
    }

    /** @return array<string,mixed> */
    public function authenticate(string $method, string $path, string $rawBody, array $headers): array
    {
        $code = trim((string) ($headers['x-imagiflow-code'] ?? ''));
        $timestamp = trim((string) ($headers['x-imagiflow-timestamp'] ?? ''));
        $signature = strtolower(trim((string) ($headers['x-imagiflow-signature'] ?? '')));
        $requestId = trim((string) ($headers['x-request-id'] ?? ''));

        if ($code === '' || $timestamp === '' || $signature === '') {
            throw new DomainException('Cabeçalhos de autenticação Imagiflow ausentes.');
        }
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            throw new DomainException('Timestamp de integração inválido ou expirado.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $signature)) {
            throw new DomainException('Assinatura de integração inválida.');
        }
        if ($requestId !== '' && !preg_match('/^[A-Za-z0-9._-]{8,64}$/', $requestId)) {
            throw new DomainException('Request ID inválido.');
        }

        $integration = $this->repository->findActiveByCode($code);
        if (!$integration) {
            throw new DomainException('Integração não encontrada ou inativa.');
        }
        $secret = Crypto::decrypt((string) $integration['secret_ciphertext']);
        if ($secret === '') {
            throw new DomainException('Segredo de integração indisponível.');
        }
        $payloadHash = hash('sha256', $rawBody);
        $canonical = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $payloadHash;
        $expected = hash_hmac('sha256', $canonical, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new DomainException('Assinatura de integração não confere.');
        }
        $integration['request_id'] = $requestId !== '' ? $requestId : 'req-' . bin2hex(random_bytes(8));
        $integration['request_hash'] = $payloadHash;
        return $integration;
    }
}
