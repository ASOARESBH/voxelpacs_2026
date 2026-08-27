<?php
namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;

/** Control-plane transacional para células DICOM VPN-only. */
final class TenantDicomProvisioningService
{
    private \PDO $pdo;
    private TenantOperationsAgentClient $agent;

    public function __construct(?\PDO $pdo = null, ?TenantOperationsAgentClient $agent = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
        $this->agent = $agent ?? new TenantOperationsAgentClient();
    }

    /** @return array<string,mixed> */
    public function reserve(array $input, ?int $userId): array
    {
        $tenantId = (int) ($input['tenant_id'] ?? 0);
        $displayName = $this->requiredText($input['display_name'] ?? '', 160, 'Nome do servidor');
        $this->tenant($tenantId);
        $routeKey = $this->slug($input['route_key'] ?? '');
        $callingAe = $this->ae($input['calling_ae'] ?? '', 'Calling AE');
        $calledAe = $this->ae($input['called_ae'] ?? '', 'Called AE');
        $backendAe = $this->ae($input['backend_ae'] ?? '', 'AE do backend');
        $publicKey = $this->wireguardPublicKey($input['wireguard_public_key'] ?? '');
        if (($input['profile'] ?? 'vpn_only') !== 'vpn_only') {
            throw new \InvalidArgumentException('O perfil automatizado disponível é vpn_only.');
        }
        if ($callingAe === $calledAe || $backendAe === $calledAe) {
            throw new \InvalidArgumentException('Os AE Titles do emissor, gateway e backend devem ser distintos.');
        }

        $this->pdo->beginTransaction();
        try {
            $existingCell = $this->pdo->prepare('SELECT id FROM bi_tenant_orthanc_cells WHERE tenant_id = ? LIMIT 1');
            $existingCell->execute([$tenantId]);
            if ($existingCell->fetchColumn()) {
                throw new \RuntimeException('Este negócio já possui uma célula Orthanc exclusiva cadastrada.');
            }
            $existing = $this->pdo->prepare("SELECT id FROM bi_pacs_tenant_provisioning WHERE tenant_id = ? AND status NOT IN ('failed','suspended') LIMIT 1");
            $existing->execute([$tenantId]);
            if ($existing->fetchColumn()) {
                throw new \RuntimeException('Já existe uma solicitação operacional em andamento para este negócio.');
            }
            $this->assertUnique('route_key', $routeKey);
            $this->assertUnique('called_ae', $calledAe);
            $ports = $this->reservePorts();
            $vpnIp = $this->reserveVpnIp();
            $operationId = $this->uuidV4();
            $hash = hash('sha256', implode('|', [$tenantId, $routeKey, $callingAe, $calledAe, $backendAe, $ports['dicom'], $ports['dicomweb'], $vpnIp, $publicKey]));
            $sql = 'INSERT INTO bi_pacs_tenant_provisioning
                (operation_id, tenant_id, display_name, deployment_key, route_key, profile, calling_ae, called_ae, backend_ae, dicom_port, dicomweb_port, vpn_client_ip, wireguard_public_key, status, current_step, requested_by, operation_hash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS inet), ?, ?, ?, ?, ?)';
            $this->pdo->prepare($sql)->execute([
                $operationId, $tenantId, $displayName, $routeKey, $routeKey, 'vpn_only', $callingAe, $calledAe, $backendAe,
                $ports['dicom'], $ports['dicomweb'], $vpnIp, $publicKey, 'reserved', 'reserved', $userId, $hash,
            ]);
            $this->pdo->commit();
            return $this->getByOperation($operationId);
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function provision(string $operationId, ?int $userId): array
    {
        $row = $this->lockOperation($operationId);
        if ($row['status'] !== 'reserved') {
            throw new \RuntimeException('A solicitação não está disponível para provisionamento.');
        }
        $this->updateOperation($operationId, 'provisioning', 'hybrid_cell', null, null, $userId);
        $payload = $this->agentPayload($row);
        try {
            $hybrid = $this->agent->callHybrid('provision_cell', $operationId, $payload);
            $credential = $hybrid['dicomweb_credential'] ?? null;
            if (!is_array($credential) || empty($credential['username']) || empty($credential['password'])) {
                throw new \RuntimeException('O agente não retornou a credencial interna da célula.');
            }
            $this->updateOperation($operationId, 'provisioning', 'gateway_wireguard_echo', null, null, $userId);
            $gateway = $this->agent->callGateway('configure_wireguard_echo', $operationId, $payload);
            $gatewayPublicKey = $this->wireguardPublicKey($gateway['gateway_public_key'] ?? '');
            $this->pdo->beginTransaction();
            try {
                $privateUrl = 'http://10.0.0.3:' . (int) $row['dicomweb_port'];
                $insertServer = $this->pdo->prepare('INSERT INTO bi_pacs_servidor (nome, url, usuario, senha, timeout, ativo, dicom_aet, dicom_port, status_ping, observacoes, updated_at) VALUES (?, ?, ?, ?, 30, 1, ?, ?, ?, ?, NOW()) RETURNING id');
                $insertServer->execute([
                    $row['display_name'], $privateUrl, (string) $credential['username'], Crypto::encrypt((string) $credential['password']),
                    $row['backend_ae'], (int) $row['dicom_port'], 'pendente', 'Célula exclusiva VPN-only; sincronização automática desabilitada até homologação.',
                ]);
                $serverId = (int) $insertServer->fetchColumn();
                $insertCell = $this->pdo->prepare("INSERT INTO bi_tenant_orthanc_cells (tenant_id, servidor_id, profile, gateway_route_key, status) VALUES (?, ?, 'vpn_only', ?, 'provisioned') RETURNING id");
                $insertCell->execute([(int) $row['tenant_id'], $serverId, $row['route_key']]);
                $cellId = (int) $insertCell->fetchColumn();
                $pivot = $this->pdo->prepare("INSERT INTO bi_negocio_servidor_pacs (tenant_id, servidor_id, ativo, criado_por) VALUES (?, ?, 1, ?) ON CONFLICT (tenant_id, servidor_id) DO UPDATE SET ativo = EXCLUDED.ativo");
                $pivot->execute([(int) $row['tenant_id'], $serverId, $userId]);
                $finish = $this->pdo->prepare("UPDATE bi_pacs_tenant_provisioning SET servidor_id=?, cell_id=?, gateway_public_key=?, status='echo_ready', current_step='awaiting_echo', confirmed_by=?, confirmed_at=NOW(), echo_ready_at=NOW(), updated_at=NOW() WHERE operation_id=?");
                $finish->execute([$serverId, $cellId, $gatewayPublicKey, $userId, $operationId]);
                $this->pdo->commit();
            } catch (\Throwable $error) {
                $this->pdo->rollBack();
                throw $error;
            }
            unset($credential, $hybrid);
            return $this->getByOperation($operationId);
        } catch (\Throwable $error) {
            $this->updateOperation($operationId, 'failed', 'failed', 'agent_failed', 'A etapa operacional não foi concluída.', $userId);
            throw new \RuntimeException('Provisionamento não concluído; a rota permanece sem liberação de C-STORE.');
        }
    }

    /** @return array<string,mixed> */
    public function verifyEcho(int $serverId): array
    {
        $row = $this->getByServer($serverId);
        if (!in_array($row['status'], ['echo_ready', 'echo_validated'], true)) {
            throw new \RuntimeException('A célula não está aguardando uma validação C-ECHO.');
        }
        $payload = $this->agentPayload($row);
        $payload['since'] = strtotime((string) $row['echo_ready_at']) ?: time() - 86400;
        $result = $this->agent->callGateway('check_echo', (string) $row['operation_id'], $payload);
        if (($result['status'] ?? '') === 'echo_validated') {
            $this->pdo->prepare("UPDATE bi_pacs_tenant_provisioning SET status='echo_validated', current_step='echo_validated', echo_validated_at=COALESCE(echo_validated_at,NOW()), updated_at=NOW() WHERE id=?")->execute([$row['id']]);
            $this->pdo->prepare("UPDATE bi_pacs_servidor SET status_ping='online', ultimo_ping=NOW(), updated_at=NOW() WHERE id=?")->execute([$serverId]);
        }
        return ['status' => $result['status'] ?? 'pending', 'message' => $result['message'] ?? 'Aguardando C-ECHO.'];
    }

    /** @return array<string,mixed> */
    public function getByServer(int $serverId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bi_pacs_tenant_provisioning WHERE servidor_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$serverId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Control-plane da célula tenant não encontrado.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public function getByOperation(string $operationId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bi_pacs_tenant_provisioning WHERE operation_id = ?');
        $stmt->execute([$operationId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Solicitação operacional não encontrada.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function lockOperation(string $operationId): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM bi_pacs_tenant_provisioning WHERE operation_id = ? FOR UPDATE');
            $stmt->execute([$operationId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                throw new \RuntimeException('Solicitação operacional não encontrada.');
            }
            $this->pdo->commit();
            return $row;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function updateOperation(string $operationId, string $status, string $step, ?string $errorCode, ?string $message, ?int $userId): void
    {
        $this->pdo->prepare('UPDATE bi_pacs_tenant_provisioning SET status=?, current_step=?, last_error_code=?, last_error_message=?, confirmed_by=COALESCE(?,confirmed_by), updated_at=NOW() WHERE operation_id=?')
            ->execute([$status, $step, $errorCode, $message, $userId, $operationId]);
    }

    /** @return array<string,mixed> */
    private function tenant(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Negócio obrigatório.');
        }
        $stmt = $this->pdo->prepare("SELECT id, slug FROM bi_tenants WHERE id=? AND status <> 'cancelado'");
        $stmt->execute([$tenantId]);
        $tenant = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$tenant || !preg_match('/^[a-z][a-z0-9-]{1,30}$/', (string) $tenant['slug'])) {
            throw new \RuntimeException('Negócio não disponível para uma célula DICOM.');
        }
        return $tenant;
    }

    private function assertUnique(string $field, string $value): void
    {
        if (!in_array($field, ['route_key', 'called_ae'], true)) {
            throw new \LogicException('Campo de reserva inválido.');
        }
        $stmt = $this->pdo->prepare("SELECT 1 FROM bi_pacs_tenant_provisioning WHERE {$field}=? AND status NOT IN ('failed','suspended') LIMIT 1");
        $stmt->execute([$value]);
        if ($stmt->fetchColumn()) {
            throw new \RuntimeException('Identificador técnico já está reservado.');
        }
    }

    /** @return array{dicom:int,dicomweb:int} */
    private function reservePorts(): array
    {
        $used = [];
        $rows = $this->pdo->query('SELECT dicom_port, dicomweb_port FROM bi_pacs_tenant_provisioning WHERE status NOT IN (\'failed\',\'suspended\')')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $used[(int) $row['dicom_port']] = true;
            $used[(int) $row['dicomweb_port']] = true;
        }
        // A célula A já existia antes do control-plane. Sua porta DICOM deve
        // participar da reserva para que novos tenants nunca a reutilizem.
        $servers = $this->pdo->query('SELECT dicom_port, url FROM bi_pacs_servidor')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($servers as $server) {
            if ((int) ($server['dicom_port'] ?? 0) > 0) {
                $used[(int) $server['dicom_port']] = true;
            }
            if (preg_match('/:(\\d+)$/', (string) ($server['url'] ?? ''), $matches)) {
                $used[(int) $matches[1]] = true;
            }
        }
        for ($dicom = 4244; $dicom <= 4299; $dicom++) {
            $web = 8044 + ($dicom - 4244);
            if (!isset($used[$dicom], $used[$web])) {
                return ['dicom' => $dicom, 'dicomweb' => $web];
            }
        }
        throw new \RuntimeException('Não há portas privadas disponíveis para outra célula.');
    }

    private function reserveVpnIp(): string
    {
        $rows = $this->pdo->query('SELECT host(vpn_client_ip) AS ip FROM bi_pacs_tenant_provisioning WHERE status NOT IN (\'failed\',\'suspended\')')->fetchAll(\PDO::FETCH_ASSOC);
        $used = array_flip(array_column($rows, 'ip'));
        // O intervalo .2-.9 é reservado à integração já existente e à operação manual.
        // Novos peers criados pela interface iniciam em .10 para evitar colisões retroativas.
        for ($host = 10; $host <= 254; $host++) {
            $ip = '10.200.10.' . $host;
            if (!isset($used[$ip])) {
                return $ip;
            }
        }
        throw new \RuntimeException('Não há IPs VPN disponíveis.');
    }

    /** @return array<string,mixed> */
    private function agentPayload(array $row): array
    {
        return [
            'tenant' => $row['deployment_key'],
            'route_key' => $row['route_key'],
            'profile' => $row['profile'],
            'calling_ae' => $row['calling_ae'],
            'called_ae' => $row['called_ae'],
            'backend_ae' => $row['backend_ae'],
            'dicom_port' => (int) $row['dicom_port'],
            'dicomweb_port' => (int) $row['dicomweb_port'],
            'vpn_client_ip' => (string) $row['vpn_client_ip'],
            'wireguard_public_key' => $row['wireguard_public_key'],
        ];
    }

    private function requiredText(mixed $value, int $max, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new \InvalidArgumentException($field . ' inválido.');
        }
        return $value;
    }

    private function slug(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $value)) {
            throw new \InvalidArgumentException('Chave de rota inválida.');
        }
        return $value;
    }

    private function ae(mixed $value, string $label): string
    {
        $value = strtoupper(trim((string) $value));
        if (!preg_match('/^[A-Z0-9_-]{1,16}$/', $value)) {
            throw new \InvalidArgumentException($label . ' inválido.');
        }
        return $value;
    }

    private function wireguardPublicKey(mixed $value): string
    {
        $value = trim((string) $value);
        $decoded = base64_decode($value, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new \InvalidArgumentException('Chave pública WireGuard inválida.');
        }
        return $value;
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
