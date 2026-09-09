<?php
declare(strict_types=1);

/** Regressão estática: control-plane Voxel Desktop permanece opt-in, tenant-scoped e sem caminho local do receptor. */
$root = dirname(__DIR__);
$files = [
    'migration' => $root.'/database/migrations/2026-09-08_voxel_desktop_non_dicom_postgresql.sql',
    'manual_migration' => $root.'/database/migrations/2026-09-09_voxel_desktop_manual_tests_postgresql.sql',
    'repository' => $root.'/app/Repositories/VoxelDesktopRepository.php',
    'service' => $root.'/app/Services/VoxelDesktopOutboxService.php',
    'manual_service' => $root.'/app/Services/VoxelDesktopManualTestService.php',
    'artifact_service' => $root.'/app/Services/VoxelDesktopArtifactService.php',
    'router' => $root.'/app/Controllers/VoxelDesktopRouterController.php',
    'routes' => $root.'/routes/web.php',
    'platform' => $root.'/app/Controllers/Platform/VoxelDesktopController.php',
    'view' => $root.'/app/Views/platform/negocios/voxel_desktop.php',
    'report' => $root.'/app/Services/ReportService.php',
];
foreach ($files as $name => $path) if (!is_file($path)) throw new RuntimeException("Arquivo ausente: {$name}");
$migration = file_get_contents($files['migration']);
$manualMigration = file_get_contents($files['manual_migration']);
$repository = file_get_contents($files['repository']);
$service = file_get_contents($files['service']);
$manualService = file_get_contents($files['manual_service']);
$artifactService = file_get_contents($files['artifact_service']);
$router = file_get_contents($files['router']);
$routes = file_get_contents($files['routes']);
$platform = file_get_contents($files['platform']);
$view = file_get_contents($files['view']);
$report = file_get_contents($files['report']);
foreach (['pacs_voxel_desktop_destinations','pacs_voxel_desktop_outbox','pacs_voxel_desktop_jobs','pacs_voxel_desktop_artifacts','pacs_voxel_desktop_attempts'] as $table) if (!str_contains($migration,$table)) throw new RuntimeException("Tabela ausente: {$table}");
foreach (['pacs_voxel_desktop_manual_tests','tenant_id','destination_id','report_id','expires_at','lease_token','artifact_path'] as $guard) if (!str_contains($manualMigration,$guard)) throw new RuntimeException("Contrato do teste manual ausente: {$guard}");
if (!str_contains($manualMigration, 'disparar_na_liberacao SET DEFAULT FALSE')) throw new RuntimeException('Migration deve manter disparo automático desativado por padrão.');
foreach (['tenant_id','idempotency_key','configuration_secret','enabled'] as $guard) if (!str_contains($migration,$guard)) throw new RuntimeException("Proteção ausente: {$guard}");
if (!str_contains($service,"if (\$destinations === []) return ['created'=>false,'jobs'=>0,'reason'=>'no_eligible_destination'];")) throw new RuntimeException('Outbox deve permanecer inerte sem destino elegível.');
if (!str_contains($service,"'reason'=>'feature_disabled'")) throw new RuntimeException('Outbox deve permanecer inerte enquanto a feature estiver desativada.');
if (!str_contains($platform,"'enabled'=>0")) throw new RuntimeException('Piloto não pode habilitar destino pelo formulário.');
foreach (['activate(', 'deactivate(', 'prepareManualTest(', 'activation_confirmation_required', 'production_confirmation_required', 'manual_test_confirmation_required'] as $guard) if (!str_contains($platform, $guard)) throw new RuntimeException("Control-plane de ativação ausente: {$guard}");
if (str_contains($platform, "'error'=>\$e->getMessage()")) throw new RuntimeException('Control-plane não pode registrar mensagens brutas de exceção.');
if (!str_contains($repository, 'disparar_na_liberacao=0')) throw new RuntimeException('Ativação não pode ligar disparo automático.');
if (!str_contains($repository, 'destination_pair_conflict') || !str_contains($repository, 'router_id=:router_id AND site_id=:site_id AND enabled=1')) throw new RuntimeException('Ativação deve bloquear destino duplicado para o mesmo Router/Site.');
foreach (['prepareManualTest', 'claimManualTest', 'findLeasedManualTest', 'manualTestMetadata', 'recordManualTestArtifact', 'markManualTestStatus'] as $guard) if (!str_contains($repository, $guard)) throw new RuntimeException("Ciclo do teste manual ausente: {$guard}");
if (str_contains($manualService, 'VoxelDesktopOutboxService') || str_contains($manualService, 'pacs_voxel_desktop_jobs')) throw new RuntimeException('Teste manual não pode criar outbox ou job automático.');
foreach (['buildForManualTest', 'manual-tests', 'recordManualTestArtifact'] as $guard) if (!str_contains($artifactService, $guard)) throw new RuntimeException("Artefato privado de teste manual ausente: {$guard}");
foreach (['destination_incomplete','identifier_too_long','invalid_router_token','voxel_desktop.destination.rejected','technicalEvents'] as $guard) if (!str_contains($platform.$repository, $guard)) throw new RuntimeException("Diagnóstico sanitizado ausente: {$guard}");
if (str_contains($platform, 'A-Za-z0-9._-')) throw new RuntimeException('Router ID e Site ID não podem restringir o formato administrativo local.');
foreach (['pacs_voxel_desktop_attempts','j.tenant_id = :tenant_id','configuration_secret','payload_json','storage_path'] as $guard) if (!str_contains($repository, $guard)) throw new RuntimeException("Contrato de log técnico ausente: {$guard}");
if (str_contains($repository, 'SELECT * FROM pacs_voxel_desktop_attempts')) throw new RuntimeException('Log técnico não pode expor tentativas brutas.');
if (!str_contains($view, 'voxel_desktop.logs_title') || !str_contains($view, 'technicalLogs')) throw new RuntimeException('Painel de log técnico ausente.');
if (!str_contains($router,'hash_equals') || !str_contains($router,'router_destination_disabled')) throw new RuntimeException('API do Router exige token e destino habilitado.');
if (!str_contains($routes, "Router::get('/api/voxel-desktop/v1/status'") || !str_contains($router, 'connectionStatus') || !str_contains($router, 'routerContext(false)')) throw new RuntimeException('Teste de conexão autenticado e sem entrega ausente.');
foreach (["Router::post('/api/voxel-desktop/v1/manual-tests/claim'", "Router::get('/api/voxel-desktop/v1/manual-tests/{id}/document'", "Router::post('/api/voxel-desktop/v1/manual-tests/{id}/status'"] as $guard) if (!str_contains($routes, $guard)) throw new RuntimeException("Rota de teste manual ausente: {$guard}");
foreach (['claimManualTest', 'manualTestDocument', 'manualTestStatus', 'routerContext()', 'buildForManualTest'] as $guard) if (!str_contains($router, $guard)) throw new RuntimeException("API de teste manual sem fila ausente: {$guard}");
if (!str_contains($report,'new VoxelDesktopOutboxService($pdo)')) throw new RuntimeException('Liberação deve registrar a outbox Voxel Desktop na transação clínica.');
foreach (['C:\\','ProgramData','files'] as $forbidden) if (str_contains($migration.$repository.$service.$router.$platform,$forbidden)) throw new RuntimeException('PACS não pode persistir caminho local do receptor.');
echo "VOXEL_DESKTOP_NON_DICOM_STATIC_OK\n";
