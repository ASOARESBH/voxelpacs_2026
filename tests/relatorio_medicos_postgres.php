<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este teste é exclusivo do CLI.\n");
    exit(1);
}
foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $required) {
    if (getenv($required) === false || getenv($required) === '') {
        fwrite(STDERR, "Variável obrigatória ausente: {$required}\n");
        exit(2);
    }
}
foreach (['DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SCHEMA'] as $key) {
    $value = getenv($key);
    if ($value !== false && $value !== '') $_ENV[$key] = (string) $value;
}
$_ENV['DB_DRIVER'] = 'pgsql';
$_ENV['DB_SCHEMA'] = $_ENV['DB_SCHEMA'] ?? 'public';
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Repositories\RelatorioProdutividadeMedicosRepository;

$pdo = Database::getInstance();
$tenantId = (int) $pdo->query(
    "SELECT tenant_id FROM reports WHERE situacao::text IN ('assinado', 'liberado') GROUP BY tenant_id ORDER BY COUNT(*) DESC LIMIT 1"
)->fetchColumn();
if ($tenantId <= 0) {
    fwrite(STDERR, "Tenant de teste com laudos concluídos não encontrado.\n");
    exit(3);
}

$repo = new RelatorioProdutividadeMedicosRepository($pdo);
$base = [
    'tenant_id' => $tenantId,
    'data_de' => '2020-01-01',
    'data_ate' => '2030-12-31',
    'base_periodo' => 'assinatura',
    'unidade' => '',
    'modalidades' => [],
    'estudo' => '',
    'medico_id' => null,
    'medico_restrito_id' => null,
    'pagina' => 1,
    'por_pagina' => 25,
];

$result = $repo->buscar($base);
if ((int) $result['total'] <= 0) {
    throw new RuntimeException('Nenhum laudo concluído foi retornado para o tenant de teste.');
}
if ((int) $result['totalizadores']['laudos'] !== (int) $result['total']) {
    throw new RuntimeException('Totalizador de laudos diverge do conjunto detalhado.');
}
foreach ($result['linhas'] as $line) {
    if (!in_array($line['situacao_laudo'], ['assinado', 'liberado'], true)) {
        throw new RuntimeException('Relatório incluiu laudo não concluído.');
    }
    if ($line['tempo_assinatura_min'] !== null && (int) $line['tempo_assinatura_min'] < 0) {
        throw new RuntimeException('Tempo de assinatura negativo.');
    }
    if ($line['tempo_conclusao_min'] !== null && (int) $line['tempo_conclusao_min'] < 0) {
        throw new RuntimeException('Tempo de conclusão negativo.');
    }
}

$medicos = $repo->medicos($tenantId);
if ($medicos) {
    $byDoctor = $base;
    $byDoctor['medico_id'] = (int) $medicos[0]['id'];
    $filtered = $repo->buscar($byDoctor);
    foreach ($filtered['linhas'] as $line) {
        if ((int) ($line['medico_usuario_id'] ?? 0) <= 0) {
            throw new RuntimeException('Filtro médico retornou linha sem responsável vinculado.');
        }
    }
}

echo 'OK: relatório Médicos PostgreSQL validado; laudos=' . $result['total'] . ', peer_review=' . $result['totalizadores']['peer_reviews'] . PHP_EOL;
