<?php

namespace App\Repositories;

use App\Core\Crypto;
use PDO;

final class ImagiflowIntegrationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByTenant(int $tenantId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bi_imagiflow_integrations WHERE tenant_id = :tenant_id LIMIT 1');
        $stmt->execute([':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findActiveByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM bi_imagiflow_integrations WHERE integration_code = :code AND status = 'ativo' LIMIT 1");
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array{code:string,secret:string,id:int} */
    public function regenerate(int $tenantId, int $userId): array
    {
        $code = $this->newCode($tenantId);
        $secret = bin2hex(random_bytes(32));
        $ciphertext = Crypto::encrypt($secret);
        $existing = $this->findByTenant($tenantId);
        if ($existing) {
            $stmt = $this->pdo->prepare("UPDATE bi_imagiflow_integrations SET integration_code = :code, secret_ciphertext = :secret, status = 'ativo', created_by = :user_id, activated_at = NOW(), updated_at = NOW() WHERE tenant_id = :tenant_id RETURNING id");
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO bi_imagiflow_integrations (tenant_id, integration_code, secret_ciphertext, status, created_by, activated_at) VALUES (:tenant_id, :code, :secret, 'ativo', :user_id, NOW()) RETURNING id");
        }
        $stmt->execute([':tenant_id' => $tenantId, ':code' => $code, ':secret' => $ciphertext, ':user_id' => $userId]);
        return ['id' => (int) $stmt->fetchColumn(), 'code' => $code, 'secret' => $secret];
    }

    public function revoke(int $tenantId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE bi_imagiflow_integrations SET status = 'revogado', updated_at = NOW() WHERE tenant_id = :tenant_id AND status <> 'revogado'");
        return $stmt->execute([':tenant_id' => $tenantId]);
    }

    public function touchUsed(int $id): void
    {
        $this->pdo->prepare('UPDATE bi_imagiflow_integrations SET last_used_at = NOW(), updated_at = NOW() WHERE id = :id')->execute([':id' => $id]);
    }

    /** @param array<string,mixed> $details */
    public function log(?int $integrationId, ?int $tenantId, string $requestId, string $method, string $endpoint, int $httpStatus, bool $success, ?string $requestHash, ?string $remoteIp, array $details = []): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO bi_imagiflow_integration_logs (integration_id, tenant_id, request_id, method, endpoint, http_status, success, request_hash, remote_ip, details) VALUES (:integration_id, :tenant_id, :request_id, :method, :endpoint, :http_status, :success, :request_hash, :remote_ip, CAST(:details AS JSONB))');
        $stmt->bindValue(':integration_id', $integrationId, $integrationId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', $tenantId, $tenantId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':request_id', $requestId);
        $stmt->bindValue(':method', $method);
        $stmt->bindValue(':endpoint', $endpoint);
        $stmt->bindValue(':http_status', $httpStatus, PDO::PARAM_INT);
        $stmt->bindValue(':success', $success, PDO::PARAM_BOOL);
        $stmt->bindValue(':request_hash', $requestHash, $requestHash === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':remote_ip', $remoteIp ?: null, $remoteIp ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':details', json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->execute();
    }

    /** @return array<int,array<string,mixed>> */
    public function recentLogs(int $tenantId, int $limit = 30): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bi_imagiflow_integration_logs WHERE tenant_id = :tenant_id ORDER BY created_at DESC LIMIT :limit');
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function newCode(int $tenantId): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = 'IMGF-' . $tenantId . '-' . strtoupper(bin2hex(random_bytes(6)));
            $stmt = $this->pdo->prepare('SELECT 1 FROM bi_imagiflow_integrations WHERE integration_code = :code');
            $stmt->execute([':code' => $code]);
            if (!$stmt->fetchColumn()) return $code;
        }
        throw new \RuntimeException('Não foi possível gerar um código de integração único.');
    }
}
