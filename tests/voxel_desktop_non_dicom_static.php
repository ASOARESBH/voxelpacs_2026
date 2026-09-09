<?php
declare(strict_types=1);

/** Regressão estática: control-plane Voxel Desktop permanece opt-in, tenant-scoped e sem caminho local do receptor. */
$root = dirname(__DIR__);
$files = [
    'migration' => $root.'/database/migrations/2026-09-08_voxel_desktop_non_dicom_postgresql.sql',
    'repository' => $root.'/app/Repositories/VoxelDesktopRepository.php',
    'service' => $root.'/app/Services/VoxelDesktopOutboxService.php',
    'router' => $root.'/app/Controllers/VoxelDesktopRouterController.php',
    'platform' => $root.'/app/Controllers/Platform/VoxelDesktopController.php',
    'view' => $root.'/app/Views/platform/negocios/voxel_desktop.php',
    'report' => $root.'/app/Services/ReportService.php',
];
foreach ($files as $name => $path) if (!is_file($path)) throw new RuntimeException("Arquivo ausente: {$name}");
$migration = file_get_contents($files['migration']);
$repository = file_get_contents($files['repository']);
$service = file_get_contents($files['service']);
$router = file_get_contents($files['router']);
$platform = file_get_contents($files['platform']);
$view = file_get_contents($files['view']);
$report = file_get_contents($files['report']);
foreach (['pacs_voxel_desktop_destinations','pacs_voxel_desktop_outbox','pacs_voxel_desktop_jobs','pacs_voxel_desktop_artifacts','pacs_voxel_desktop_attempts'] as $table) if (!str_contains($migration,$table)) throw new RuntimeException("Tabela ausente: {$table}");
foreach (['tenant_id','idempotency_key','configuration_secret','enabled'] as $guard) if (!str_contains($migration,$guard)) throw new RuntimeException("Proteção ausente: {$guard}");
if (!str_contains($service,"if (\$destinations === []) return ['created'=>false,'jobs'=>0,'reason'=>'no_eligible_destination'];")) throw new RuntimeException('Outbox deve permanecer inerte sem destino elegível.');
if (!str_contains($service,"'reason'=>'feature_disabled'")) throw new RuntimeException('Outbox deve permanecer inerte enquanto a feature estiver desativada.');
if (!str_contains($platform,"'enabled'=>0")) throw new RuntimeException('Piloto não pode habilitar destino pelo formulário.');
foreach (['destination_incomplete','invalid_identifier','invalid_router_token','voxel_desktop.destination.rejected','technicalEvents'] as $guard) if (!str_contains($platform.$repository, $guard)) throw new RuntimeException("Diagnóstico sanitizado ausente: {$guard}");
foreach (['pacs_voxel_desktop_attempts','j.tenant_id = :tenant_id','configuration_secret','payload_json','storage_path'] as $guard) if (!str_contains($repository, $guard)) throw new RuntimeException("Contrato de log técnico ausente: {$guard}");
if (str_contains($repository, 'SELECT * FROM pacs_voxel_desktop_attempts')) throw new RuntimeException('Log técnico não pode expor tentativas brutas.');
if (!str_contains($view, 'voxel_desktop.logs_title') || !str_contains($view, 'technicalLogs')) throw new RuntimeException('Painel de log técnico ausente.');
if (!str_contains($router,'hash_equals') || !str_contains($router,'router_destination_disabled')) throw new RuntimeException('API do Router exige token e destino habilitado.');
if (!str_contains($report,'new VoxelDesktopOutboxService($pdo)')) throw new RuntimeException('Liberação deve registrar a outbox Voxel Desktop na transação clínica.');
foreach (['C:\\','ProgramData','files'] as $forbidden) if (str_contains($migration.$repository.$service.$router.$platform,$forbidden)) throw new RuntimeException('PACS não pode persistir caminho local do receptor.');
echo "VOXEL_DESKTOP_NON_DICOM_STATIC_OK\n";
