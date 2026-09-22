<?php
declare(strict_types=1);

$base = dirname(__DIR__);
$migration = $base . '/database/migrations/2026-08-28_exames_complementares_postgresql.sql';
if (!is_file($migration)) {
    fwrite(STDERR, "MIGRATION_AUSENTE\n");
    exit(1);
}
$sql = (string) file_get_contents($migration);
$checks = [
    'schema' => str_contains($sql, 'SET search_path TO voxelpacs_mysql_source, public'),
    'idempotente' => str_contains($sql, 'CREATE TABLE IF NOT EXISTS bi_pacs_estudos_exames_complementares'),
    'tenant' => str_contains($sql, 'tenant_id BIGINT NOT NULL'),
    'unico' => str_contains($sql, 'UNIQUE (tenant_id, estudo_id)'),
    'limite' => str_contains($sql, 'tamanho_bytes <= 15728640'),
    'indice' => str_contains($sql, 'idx_bi_pacs_estudos_exames_complementares_estudo'),
];
$falhas = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($falhas) {
    fwrite(STDERR, 'FALHAS_MIGRATION=' . implode(',', $falhas) . "\n");
    exit(1);
}
echo "CONTRATO_MIGRATION_EXAMES_COMPLEMENTARES=OK\n";
