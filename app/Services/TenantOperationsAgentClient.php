<?php
namespace App\Services;

/**
 * Canal da API para agentes operacionais internos.
 * A API continua sem acesso a root, Docker, WireGuard ou firewall; ela apenas
 * assina ordens de curta duração, emite mTLS e recebe resultado técnico mínimo.
 */
final class TenantOperationsAgentClient
{
    private const HMAC_FILE = '/etc/voxelpacs-tenant-agent/hmac.key';
    private const PKI_DIR = '/etc/voxelpacs-tenant-agent/pki';

    /** @return array<string,mixed> */
    public function callApi(string $action, string $operationId, array $payload): array
    {
        return $this->call((string) (getenv('TENANT_AGENT_API_URL') ?: 'https://10.0.0.2:8813'), $action, $operationId, $payload);
    }

    /** @return array<string,mixed> */
    public function callHybrid(string $action, string $operationId, array $payload): array
    {
        return $this->call((string) (getenv('TENANT_AGENT_HYBRID_URL') ?: 'https://10.0.0.3:8813'), $action, $operationId, $payload);
    }

    /** @return array<string,mixed> */
    public function callGateway(string $action, string $operationId, array $payload): array
    {
        return $this->call((string) (getenv('TENANT_AGENT_GATEWAY_URL') ?: 'https://10.0.0.4:8813'), $action, $operationId, $payload);
    }

    /** @return array<string,mixed> */
    private function call(string $endpoint, string $action, string $operationId, array $payload): array
    {
        if (!in_array($action, ['provision_cell', 'configure_wireguard_echo', 'register_control_plane', 'check_echo', 'enable_cstore', 'suspend_route'], true)) {
            throw new \RuntimeException('Ação operacional não permitida.');
        }
        if (!preg_match('#^https://10\\.0\\.0\\.(2|3|4):8813$#', $endpoint)) {
            throw new \RuntimeException('Endpoint privado do agente inválido.');
        }
        $secret = @file_get_contents(self::HMAC_FILE);
        if ($secret === false || strlen(trim($secret)) < 32) {
            throw new \RuntimeException('Credencial do agente indisponível.');
        }
        foreach (['ca.crt', 'api-client.crt', 'api-client.key'] as $file) {
            if (!is_file(self::PKI_DIR . '/' . $file)) {
                throw new \RuntimeException('Material mTLS do agente indisponível.');
            }
        }

        $body = json_encode([
            'action' => $action,
            'operation_id' => $operationId,
            'payload' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = time();
        $nonce = bin2hex(random_bytes(32));
        $signature = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, trim($secret));
        unset($secret);

        $curl = curl_init(rtrim($endpoint, '/') . '/v1/operations');
        if ($curl === false) {
            throw new \RuntimeException('Não foi possível iniciar a comunicação com o agente.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 320,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($body),
                'X-Voxel-Timestamp: ' . $timestamp,
                'X-Voxel-Nonce: ' . $nonce,
                'X-Voxel-Signature: ' . $signature,
            ],
            CURLOPT_CAINFO => self::PKI_DIR . '/ca.crt',
            CURLOPT_SSLCERT => self::PKI_DIR . '/api-client.crt',
            CURLOPT_SSLKEY => self::PKI_DIR . '/api-client.key',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($curl);
        $errno = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($response === false || $errno !== 0 || $status !== 200) {
            throw new \RuntimeException('O agente operacional não confirmou a ordem.');
        }
        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Resposta operacional inválida.');
        }
        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true || ($decoded['operation_id'] ?? '') !== $operationId) {
            throw new \RuntimeException('O agente recusou a ordem operacional.');
        }
        $result = $decoded['result'] ?? null;
        if (!is_array($result)) {
            throw new \RuntimeException('Resultado operacional incompleto.');
        }
        return $result;
    }
}
