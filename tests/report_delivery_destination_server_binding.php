<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/SqlHelper.php';
require_once dirname(__DIR__) . '/app/Repositories/ReportDeliveryRepository.php';

use App\Repositories\ReportDeliveryRepository;
final class ReportDeliveryDestinationBindingStatement
{
    public function __construct(private ReportDeliveryDestinationBindingPdo $pdo, private string $sql)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->pdo->executed[] = ['sql' => $this->sql, 'params' => $params ?? []];
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (str_contains($this->sql, 'FROM bi_pacs_servidor')) {
            return $this->pdo->serverAuthorized ? 77 : false;
        }
        if (str_contains($this->sql, 'SELECT id FROM pacs_report_delivery_destinations')) {
            return false;
        }
        return false;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->sql, 'FROM pacs_report_delivery_destinations d')) {
            return [
                'id' => 42,
                'tenant_id' => 2,
                'configuration_secret' => '',
            ];
        }
        return false;
    }
}

final class ReportDeliveryDestinationBindingPdo extends PDO
{
    public bool $serverAuthorized = true;
    /** @var list<array{sql:string,params:array<string,mixed>}> */
    public array $executed = [];
    private bool $transaction = false;

    public function __construct()
    {
    }

    public function prepare($query, $options = []): mixed
    {
        return new ReportDeliveryDestinationBindingStatement($this, (string) $query);
    }

    public function beginTransaction(): bool
    {
        $this->transaction = true;
        return true;
    }

    public function commit(): bool
    {
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->transaction = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function lastInsertId($name = null): string
    {
        return '42';
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$data = [
    'nome' => 'Philips produção sintética',
    'servidor_pacs_id' => '77',
    'transport' => 'philips_non_dicom',
    'ambiente' => 'producao',
    'enabled' => 0,
    'disparar_na_liberacao' => 0,
    'configuration_json' => '{}',
    'configuration_secret' => '',
    'timeout_seconds' => 30,
    'max_attempts' => 5,
    'institution_names' => ['INSTITUTION_SYNTHETIC'],
    'issuers' => [],
];

$pdo = new ReportDeliveryDestinationBindingPdo();
$repository = new ReportDeliveryRepository($pdo);
$createdId = $repository->saveDestination(2, null, $data, 9001);
$assert($createdId === 42, 'Criação sintética não retornou o identificador esperado.');
$assert(count(array_filter($pdo->executed, static fn(array $query): bool => str_contains($query['sql'], 'servidor_pacs_id'))) >= 1, 'Criação não persistiu o vínculo servidor_pacs_id.');

$editedId = $repository->saveDestination(2, 42, $data, 9001);
$assert($editedId === 42, 'Edição sintética não preservou o identificador do destino.');
$assert(count(array_filter($pdo->executed, static fn(array $query): bool => str_contains($query['sql'], 'servidor_pacs_id = :servidor_pacs_id'))) === 1, 'Edição não atualizou o vínculo servidor_pacs_id.');

$missingServer = $data;
$missingServer['servidor_pacs_id'] = null;
try {
    $repository->saveDestination(2, null, $missingServer, 9001);
    throw new RuntimeException('Destino Philips de produção sem servidor deveria ser bloqueado.');
} catch (DomainException $exception) {
    $assert(str_contains($exception->getMessage(), 'servidor PACS'), 'Erro de servidor PACS não foi sanitizado.');
}

$pdo->serverAuthorized = false;
try {
    $repository->saveDestination(2, null, $data, 9001);
    throw new RuntimeException('Servidor inativo ou de outro tenant deveria ser bloqueado.');
} catch (DomainException $exception) {
    $assert(str_contains($exception->getMessage(), 'autorizado'), 'Bloqueio de servidor não autorizado não foi aplicado.');
}

foreach (['pt_BR', 'en', 'es'] as $locale) {
    $catalog = (string) file_get_contents(dirname(__DIR__) . "/lang/{$locale}.php");
    foreach (['delivery_hub.destination.servidor_pacs', 'delivery_hub.destination.servidor_pacs_ajuda'] as $key) {
        $assert(str_contains($catalog, "'{$key}'"), "Chave i18n ausente em {$locale}: {$key}");
    }
}

fwrite(STDOUT, "REPORT_DELIVERY_DESTINATION_SERVER_BINDING_OK\n");
