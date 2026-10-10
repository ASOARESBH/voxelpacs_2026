<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/SqlHelper.php';
require_once dirname(__DIR__) . '/app/Repositories/ReportDeliveryRepository.php';

use App\Repositories\ReportDeliveryRepository;

final class ReportDeliveryRoutingTestStatement
{
    public function __construct(private ReportDeliveryRoutingTestPdo $pdo, private string $sql)
    {
    }

    public function bindValue(string|int $parameter, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->pdo->bindings[(string) $parameter] = [$value, $type];
        return true;
    }

    public function execute(?array $params = null): bool
    {
        $this->pdo->executeParams = $params ?? [];
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT): array
    {
        return [];
    }
}

final class ReportDeliveryRoutingTestPdo extends PDO
{
    public string $sql = '';
    /** @var array<string,array{mixed,int}> */
    public array $bindings = [];
    /** @var array<string,mixed> */
    public array $executeParams = [];

    public function __construct()
    {
    }

    public function prepare($query, $options = []): mixed
    {
        $this->sql = (string) $query;
        $this->bindings = [];
        $this->executeParams = [];
        return new ReportDeliveryRoutingTestStatement($this, $this->sql);
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pdo = new ReportDeliveryRoutingTestPdo();
$repository = new ReportDeliveryRepository($pdo);
$repository->findActiveDestinations(2, null, 'issuer-synthetic', null, null);
$assert(str_contains($pdo->sql, 'AND d.servidor_pacs_id IS NULL'), 'Origem sem servidor não usa IS NULL fail-closed.');
$assert(!array_key_exists(':source_server_id', $pdo->bindings), 'Origem sem servidor ainda cria bind nulo.');
$assert(!str_contains($pdo->sql, ':source_server_id_guard'), 'Placeholder nulo obsoleto ainda está na query.');
$assert(!str_contains($pdo->sql, ':source_server_id_value'), 'Placeholder duplicado obsoleto ainda está na query.');

$repository->findActiveDestinations(2, null, 'issuer-synthetic', null, 77);
$assert(str_contains($pdo->sql, 'AND d.servidor_pacs_id = :source_server_id'), 'Origem com servidor não usa igualdade tipada.');
$assert(($pdo->bindings[':source_server_id'][0] ?? null) === 77, 'Servidor PACS não foi bindado com o valor esperado.');
$assert(($pdo->bindings[':source_server_id'][1] ?? null) === PDO::PARAM_INT, 'Servidor PACS não foi bindado como inteiro.');

fwrite(STDOUT, "REPORT_DELIVERY_SOURCE_SERVER_ROUTING_STATIC_OK\n");
