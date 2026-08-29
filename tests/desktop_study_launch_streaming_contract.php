<?php

declare(strict_types=1);

/**
 * Contrato estático do VOXEL Desktop. Não acessa banco, Orthanc, tokens
 * ou estudos: verifica somente os guardrails de código da API pública.
 */
$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/Services/DesktopStudyLaunchService.php');
$controller = file_get_contents($root . '/app/Controllers/DesktopStudyLaunchController.php');
$orthanc = file_get_contents($root . '/app/Services/OrthancService.php');
$routes = file_get_contents($root . '/routes/web.php');
$router = file_get_contents($root . '/app/Core/Router.php');
$studies = file_get_contents($root . '/app/Controllers/EstudosController.php');
$launchGrantsMigration = file_get_contents($root . '/database/migrations/2026-08-29_voxel_desktop_launch_grants_postgresql.sql');
$shortLaunchMigration = file_get_contents($root . '/database/migrations/2026-08-29_voxel_desktop_short_launch_reference_postgresql.sql');

if ($service === false || $controller === false || $orthanc === false || $routes === false || $router === false || $studies === false || $launchGrantsMigration === false || $shortLaunchMigration === false) {
    throw new RuntimeException('desktop_contract_files_unavailable');
}

$checks = [
    'worklist requires view permission' => str_contains($studies, "Auth::can('view_exames')"),
    'worklist scopes study by tenant' => str_contains($studies, 'AND tenant_id = :tid'),
    'launch remains temporary' => str_contains($service, 'LAUNCH_TTL_SECONDS = 120'),
    'launch stores tenant and source server' => str_contains($service, 'tenant_id, usuario_id, servidor_id, orthanc_study_id'),
    'primary URI uses voxel protocol' => str_contains($service, "'launch_uri' => 'voxel://?'"),
    'voxel launch uses local handoff page' => str_contains($studies, 'renderVoxelDesktopLauncher')
        && str_contains($studies, 'id="open-voxel-desktop"')
        && str_contains($studies, 'launch.click()'),
    'voxel launch avoids direct HTTP protocol redirect' => !str_contains($studies, "header('Location: ' . \$launch['launch_uri']"),
    'short launch reference remains opaque and random' => str_contains($service, 'bin2hex(random_bytes(16))')
        && str_contains($service, 'manifestByReference')
        && str_contains($service, 'resolveReference'),
    'short launch remains expiring and revocable' => str_contains($service, 'launch_ref = :launch_ref AND expires_at > NOW() AND revogado_em IS NULL'),
    'short manifest and instance routes are registered' => str_contains($routes, 'desktop-short-launch/{launchRef}/manifest')
        && str_contains($routes, 'desktop-short-launch/{launchRef}/instance/{instanceId}')
        && str_contains($controller, 'function shortManifest')
        && str_contains($controller, 'function shortInstance'),
    'short instance preserves HMAC and study ownership' => str_contains($service, 'streamInstanceByReference')
        && str_contains($service, "hash_equals((string) \$launch['signature'], \$signature)")
        && str_contains($service, 'assertInstanceBelongsToLaunch($orthanc, $instanceId, $launch)')
        && str_contains($controller, "(string) (\$_GET['sig'] ?? '')"),
    'short launch migration creates opaque reference indexes' => str_contains($shortLaunchMigration, 'ADD COLUMN IF NOT EXISTS launch_ref')
        && str_contains($shortLaunchMigration, 'uq_desktop_launches_launch_ref')
        && str_contains($shortLaunchMigration, 'idx_desktop_launches_ref_expiry'),
    'legacy URI is only compatibility metadata' => str_contains($service, "'compatibility_uri' => 'weasis://?'"),
    'manifest endpoint remains public' => str_contains($routes, "DesktopStudyLaunchController@manifest") && str_contains($router, "'/desktop-launch/'"),
    'manifest is single-use' => str_contains($service, 'manifesto_uses = 0'),
    'instance verifies its parent study' => str_contains($service, "ParentStudy'] ?? '') !== (string) \$launch['orthanc_study_id']"),
    'instance streams instead of returning full binary' => str_contains($controller, '->streamInstance(') && str_contains($orthanc, 'function streamInstanceFile('),
    'instance output never forwards Orthanc headers' => str_contains($controller, "header('Content-Type: application/dicom')") && str_contains($controller, 'X-Content-Type-Options'),
    'old generic viewer token table is not used' => !str_contains($service, 'pacs_viewer_tokens'),
    'launch table grants cover application and sequence' => str_contains($launchGrantsMigration, 'GRANT SELECT, INSERT, UPDATE, DELETE')
        && str_contains($launchGrantsMigration, 'bi_desktop_study_launches')
        && str_contains($launchGrantsMigration, 'GRANT USAGE, SELECT')
        && str_contains($launchGrantsMigration, 'bi_desktop_study_launches_id_seq')
        && str_contains($launchGrantsMigration, 'TO voxelpacs_homolog'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? '[OK] ' : '[FALHOU] ') . $label . PHP_EOL;
    if (!$passed) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    throw new RuntimeException('desktop_streaming_contract_failed:' . implode(',', $failed));
}

echo "desktop_study_launch_streaming_contract_ok\n";
