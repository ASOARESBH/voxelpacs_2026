<?php
declare(strict_types=1);

use App\Core\Database;

$base = dirname(__DIR__);
$bootstrap = getenv('VOXEL_BOOTSTRAP') ?: '/var/www/voxelpacs/app/app/bootstrap.php';
$migration = $base . '/database/migrations/2026-08-28_exames_complementares_postgresql.sql';
if (!is_file($bootstrap) || !is_file($migration)) {
    fwrite(STDERR, "ARQUIVO_OBRIGATORIO_AUSENTE\n");
    exit(1);
}
require $bootstrap;

$pdo = Database::getInstance();
$schema = 'voxelpacs_mysql_source';
$db = (string) $pdo->query('SELECT current_database()')->fetchColumn();
if ($db === '') {
    fwrite(STDERR, "BANCO_NAO_IDENTIFICADO\n");
    exit(1);
}

$backupDir = '/var/backups/voxelpacs';
if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "DIRETORIO_BACKUP_INDISPONIVEL\n");
    exit(1);
}
$backup = $backupDir . '/estrutura-before-exames-complementares-' . gmdate('Ymd-His') . '.json';
try {
    $snapshot = ['database' => $db, 'schema' => $schema, 'generated_at' => gmdate('c')];
    $tables = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = :schema AND table_name IN (:estudos, :complementares) ORDER BY table_name');
    $tables->execute(['schema' => $schema, 'estudos' => 'bi_pacs_estudos', 'complementares' => 'bi_pacs_estudos_exames_complementares']);
    $snapshot['tables'] = $tables->fetchAll(PDO::FETCH_ASSOC);
    $columns = $pdo->prepare('SELECT table_name, column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = :schema AND table_name IN (:estudos, :complementares) ORDER BY table_name, ordinal_position');
    $columns->execute(['schema' => $schema, 'estudos' => 'bi_pacs_estudos', 'complementares' => 'bi_pacs_estudos_exames_complementares']);
    $snapshot['columns'] = $columns->fetchAll(PDO::FETCH_ASSOC);
    $indexes = $pdo->prepare('SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = :schema AND tablename IN (:estudos, :complementares) ORDER BY tablename, indexname');
    $indexes->execute(['schema' => $schema, 'estudos' => 'bi_pacs_estudos', 'complementares' => 'bi_pacs_estudos_exames_complementares']);
    $snapshot['indexes'] = $indexes->fetchAll(PDO::FETCH_ASSOC);
    if (file_put_contents($backup, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Não foi possível gravar o snapshot.');
} catch (Throwable $e) {
    @unlink($backup);
    fwrite(STDERR, "BACKUP_SCHEMA_FALHOU\n");
    exit(1);
}

try {
    $pdo->beginTransaction();
    $pdo->exec((string) file_get_contents($migration));
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = :schema AND table_name = :table');
    $stmt->execute(['schema' => $schema, 'table' => 'bi_pacs_estudos_exames_complementares']);
    $columns = (int) $stmt->fetchColumn();
    if ($columns < 10) throw new RuntimeException('Estrutura de anexos complementares incompleta.');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "MIGRATION_EXAMES_COMPLEMENTARES_FALHOU\n");
    exit(1);
}

echo "MIGRATION_EXAMES_COMPLEMENTARES=OK\n";
