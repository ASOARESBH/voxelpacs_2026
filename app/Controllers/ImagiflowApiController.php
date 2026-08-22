<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Repositories\ImagiflowIntegrationRepository;
use App\Services\ImagiflowApiAuthService;
use App\Services\ImagiflowApuracaoService;
use DomainException;
use PDO;
use Throwable;

final class ImagiflowApiController extends Controller
{
    private PDO $pdo;
    private ImagiflowIntegrationRepository $repository;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->repository = new ImagiflowIntegrationRepository($this->pdo);
    }

    public function medico(): void
    {
        $this->handle('/api/integracoes/imagiflow/v1/medicos/consultar', function (int $tenantId, array $payload): array {
            $crm = preg_replace('/\D+/', '', (string) ($payload['crm'] ?? '')) ?? '';
            $name = trim((string) ($payload['nome'] ?? ''));
            if ($crm === '' && $name === '') throw new DomainException('Informe crm ou nome do médico.');

            $doctors = $this->doctors($tenantId, $crm, $name);
            return [
                'found' => count($doctors) === 1,
                'matches' => $doctors,
            ];
        });
    }

    public function apuracao(): void
    {
        $this->handle('/api/integracoes/imagiflow/v1/apuracao/estudos', function (int $tenantId, array $payload): array {
            return (new ImagiflowApuracaoService($this->pdo))->listar($tenantId, $payload);
        });
    }

    /** @return array<int,array<string,mixed>> */
    private function doctors(int $tenantId, string $crm, string $name): array
    {
        if ($crm !== '') {
            $stmt = $this->pdo->prepare("SELECT id, nome, crm, crm_uf, especialidade FROM bi_medicos WHERE tenant_id = :tenant_id AND ativo = 1 AND REGEXP_REPLACE(COALESCE(crm, ''), '\\D', '', 'g') = :crm ORDER BY nome LIMIT 2");
            $stmt->execute([':tenant_id' => $tenantId, ':crm' => $crm]);
            return $this->publicDoctors($stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        $stmt = $this->pdo->prepare('SELECT id, nome, crm, crm_uf, especialidade FROM bi_medicos WHERE tenant_id = :tenant_id AND ativo = 1 ORDER BY nome');
        $stmt->execute([':tenant_id' => $tenantId]);
        $wanted = $this->normal($name);
        $terms = array_filter(explode(' ', $wanted), static fn (string $term): bool => mb_strlen($term) >= 3);
        $matches = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $doctor) {
            $candidate = $this->normal((string) $doctor['nome']);
            if ($candidate !== '' && $terms && !array_diff($terms, explode(' ', $candidate))) {
                $matches[] = $doctor;
            }
        }
        return $this->publicDoctors(array_slice($matches, 0, 5));
    }

    /** @param array<int,array<string,mixed>> $doctors @return array<int,array<string,mixed>> */
    private function publicDoctors(array $doctors): array
    {
        return array_map(static fn (array $doctor): array => [
            'medico_id' => (int) $doctor['id'],
            'nome' => $doctor['nome'],
            'crm' => $doctor['crm'] ?: null,
            'crm_uf' => $doctor['crm_uf'] ?: null,
            'especialidade' => $doctor['especialidade'] ?: null,
            'ativo' => true,
        ], $doctors);
    }

    private function normal(string $value): string
    {
        $value = preg_replace('/^(dr\.?|dra\.?|prof\.?|profa\.?)\s+/iu', '', trim($value)) ?? '';
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $ascii === false ? $value : $ascii;
        $value = preg_replace('/[^a-zA-Z0-9]+/', ' ', $value) ?? '';
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }

    /** @param callable(int,array<string,mixed>):array<string,mixed> $operation */
    private function handle(string $endpoint, callable $operation): void
    {
        $raw = (string) file_get_contents('php://input');
        $payload = $raw === '' ? [] : json_decode($raw, true);
        $headers = $this->headers();
        $requestId = (string) ($headers['x-request-id'] ?? 'req-' . bin2hex(random_bytes(8)));
        $integration = null;
        try {
            if (!is_array($payload)) throw new DomainException('JSON inválido.');
            $integration = (new ImagiflowApiAuthService($this->repository))->authenticate('POST', $endpoint, $raw, $headers);
            $result = $operation((int) $integration['tenant_id'], $payload);
            $this->repository->touchUsed((int) $integration['id']);
            $this->repository->log((int) $integration['id'], (int) $integration['tenant_id'], $requestId, 'POST', $endpoint, 200, true, (string) $integration['request_hash'], $this->remoteIp(), ['result' => 'ok']);
            $this->respond(['ok' => true, 'request_id' => $requestId, 'data' => $result], 200);
        } catch (DomainException $e) {
            $this->logFailure($integration, $requestId, $endpoint, 401, $e->getMessage(), hash('sha256', $raw));
            $this->respond(['ok' => false, 'request_id' => $requestId, 'error' => 'Não autorizado ou requisição inválida.'], 401);
        } catch (Throwable $e) {
            $this->logFailure($integration, $requestId, $endpoint, 500, 'internal_error', hash('sha256', $raw));
            $this->respond(['ok' => false, 'request_id' => $requestId, 'error' => 'Falha interna da integração.'], 500);
        }
    }

    /** @param array<string,mixed>|null $integration */
    private function logFailure(?array $integration, string $requestId, string $endpoint, int $status, string $reason, string $requestHash): void
    {
        $this->repository->log($integration ? (int) $integration['id'] : null, $integration ? (int) $integration['tenant_id'] : null, $requestId, 'POST', $endpoint, $status, false, $requestHash, $this->remoteIp(), ['reason' => $reason]);
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        $raw = function_exists('getallheaders') ? getallheaders() : [];
        $headers = [];
        foreach ($raw as $key => $value) $headers[strtolower((string) $key)] = (string) $value;
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
        }
        return $headers;
    }

    private function remoteIp(): ?string
    {
        return filter_var($_SERVER['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP) ?: null;
    }

    /** @param array<string,mixed> $payload */
    private function respond(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
