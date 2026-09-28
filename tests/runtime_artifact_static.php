<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function expect_runtime_artifact(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$builderPath = $root . '/scripts/build-runtime-artifact.sh';
$buildPath = $root . '/scripts/build.sh';
$deployPath = $root . '/scripts/deploy.sh';
$builder = file_get_contents($builderPath);
$build = file_get_contents($buildPath);
$deploy = file_get_contents($deployPath);

expect_runtime_artifact(is_string($builder), 'Builder de artefato runtime ausente');
expect_runtime_artifact(is_string($build), 'Wrapper build.sh ausente');
expect_runtime_artifact(is_string($deploy), 'Script deploy.sh ausente');

foreach ([
    'archive --format=tar HEAD',
    "'app/Config/ReportDeliveryRuntimeConfig.php'",
    "'app/bootstrap.php'",
    "'public/index.php'",
    "'bin/report_delivery_worker.php'",
    'storage',
    'uploads',
    'logs',
    'backups',
    'tests',
    'docs',
    'scripts',
    'database',
    'worktree_not_clean',
    'output_inside_worktree',
    'RUNTIME_CONFIG_IN_ARTIFACT=YES',
    'PRODUCTION_CHANGED=NO',
] as $marker) {
    expect_runtime_artifact(str_contains($builder, $marker), "Builder sem proteção: {$marker}");
}

expect_runtime_artifact(
    str_contains($builder, "'app/Config/ReportDeliveryRuntimeConfig.php'")
        && str_contains($builder, "legacy_flat_runtime_config_in_artifact"),
    'Builder deve validar a configuração aninhada e rejeitar cópia plana'
);
expect_runtime_artifact(
    str_contains($build, 'build-runtime-artifact.sh') && str_contains($build, 'exec bash'),
    'build.sh deve delegar ao builder oficial'
);
expect_runtime_artifact(
    str_contains($deploy, '/var/www/voxelpacs/app')
        && str_contains($deploy, 'PARENT_ROOT_IS_NOT_RUNTIME_ROOT')
        && str_contains($deploy, 'RUNTIME_ROOT=APP_ROOT'),
    'deploy.sh deve usar APP_ROOT e bloquear a raiz pai'
);
expect_runtime_artifact(
    !str_contains($deploy, 'composer install') && !str_contains($deploy, 'chmod -R 775'),
    'deploy.sh não pode executar Composer ou chmod recursivo no host'
);
expect_runtime_artifact(
    str_contains($deploy, 'ENV_PRESERVED=YES')
        && str_contains($deploy, 'STORAGE_PRESERVED=YES')
        && str_contains($deploy, 'UPLOADS_PRESERVED=YES'),
    'deploy.sh deve declarar a preservação dos dados persistentes'
);
expect_runtime_artifact(
    !str_contains($deploy, 'http://${REMOTE_HOST}/health'),
    'health check deve usar URL configurável e HTTPS por padrão'
);

fwrite(STDOUT, "runtime_artifact_static: PASS\n");
