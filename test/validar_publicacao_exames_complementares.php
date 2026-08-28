<?php
declare(strict_types=1);

use App\Core\Database;

$bootstrap = getenv('VOXEL_BOOTSTRAP') ?: '/var/www/voxelpacs/app/app/bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "BOOTSTRAP_INDISPONIVEL\n");
    exit(1);
}
require $bootstrap;

$schema = 'voxelpacs_mysql_source';
$pdo = Database::getInstance();
$columns = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = :schema AND table_name = :table');
$columns->execute(['schema' => $schema, 'table' => 'bi_pacs_estudos_exames_complementares']);
$columnCount = (int) $columns->fetchColumn();

$indexes = $pdo->prepare('SELECT COUNT(*) FROM pg_indexes WHERE schemaname = :schema AND tablename = :table AND indexdef ILIKE :signature');
$indexes->execute(['schema' => $schema, 'table' => 'bi_pacs_estudos_exames_complementares', 'signature' => '%(tenant_id, estudo_id)%']);
$hasTenantStudyIndex = (int) $indexes->fetchColumn() > 0;

$files = [
    '/var/www/voxelpacs/app/app/Repositories/ExamesComplementaresRepository.php',
    '/var/www/voxelpacs/app/app/Services/ExamesComplementaresService.php',
    '/var/www/voxelpacs/app/app/Controllers/ExamesComplementaresController.php',
    '/var/www/voxelpacs/app/app/Views/estudos/index.php',
    '/var/www/voxelpacs/app/app/Views/reports/partials/_exame_card.php',
];
$readable = true;
foreach ($files as $file) {
    if (!is_file($file) || !is_readable($file) || (fileperms($file) & 0004) === 0) $readable = false;
}

$checks = [
    'estrutura' => $columnCount >= 10,
    'indice_tenant_estudo' => $hasTenantStudyIndex,
    'arquivos_legiveis' => $readable,
];
$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed) {
    fwrite(STDERR, 'FALHAS=' . implode(',', $failed) . "\n");
    exit(1);
}
echo "PUBLICACAO_ESTRUTURAL_EXAMES_COMPLEMENTARES=OK\n";
