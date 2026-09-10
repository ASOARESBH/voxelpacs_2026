<?php
// Materialização de runtime inerte da Fase 1 Philips Non-DICOM; não ativa SMB, bridge, XML ou automação.

declare(strict_types=1);

namespace App\Services;

/** Teste de configuração SMB sem PDF, XML, outbox ou job clínico. */
final class PhilipsFolderSmbConnectivityService
{
    /** @param array<string,mixed> $configuration */
    public function test(int $tenantId, int $destinationId, array $configuration, string $encryptedSecret, int $timeout): string
    {
        if (!PhilipsFolderDeliveryService::testEnabled() || $tenantId <= 0 || $destinationId <= 0) {
            throw new PhilipsFolderDeliveryException('feature_disabled', 'feature_disabled');
        }
        $password = $this->password($encryptedSecret);
        try {
            $envelope = (new GatewaySmbSecretEnvelopeService())->seal($password, $tenantId, $destinationId);
        } catch (\Throwable) {
            throw new PhilipsFolderDeliveryException('gateway_secret_envelope_unavailable', 'gateway_unavailable');
        } finally {
            sodium_memzero($password);
        }
        return (new PhilipsFolderGatewayBridgeClient())->testSmbConnectivity(
            $tenantId,
            $destinationId,
            $configuration,
            $envelope,
            max(5, min(120, $timeout))
        );
    }

    private function password(string $encryptedSecret): string
    {
        try {
            $payload = json_decode((new ReportDeliveryCryptoService())->decrypt($encryptedSecret), true);
        } catch (\Throwable) {
            throw new PhilipsFolderDeliveryException('credentials_unavailable', 'credentials_unavailable');
        }
        $password = is_array($payload) ? (string) ($payload['smb_password'] ?? '') : '';
        if ($password === '') {
            throw new PhilipsFolderDeliveryException('credentials_unavailable', 'credentials_unavailable');
        }
        return $password;
    }
}
