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

if ($service === false || $controller === false || $orthanc === false || $routes === false || $router === false || $studies === false) {
    throw new RuntimeException('desktop_contract_files_unavailable');
}

$checks = [
    'worklist requires view permission' => str_contains($studies, "Auth::can('view_exames')"),
    'worklist scopes study by tenant' => str_contains($studies, 'AND tenant_id = :tid'),
    'launch remains temporary' => str_contains($service, 'LAUNCH_TTL_SECONDS = 120'),
    'launch stores tenant and source server' => str_contains($service, 'tenant_id, usuario_id, servidor_id, orthanc_study_id'),
    'primary URI uses voxel protocol' => str_contains($service, "'launch_uri' => 'voxel://?'"),
    'legacy URI is only compatibility metadata' => str_contains($service, "'compatibility_uri' => 'weasis://?'"),
    'manifest endpoint remains public' => str_contains($routes, "DesktopStudyLaunchController@manifest") && str_contains($router, "'/desktop-launch/'"),
    'manifest is single-use' => str_contains($service, 'manifesto_uses = 0'),
    'instance verifies its parent study' => str_contains($service, "ParentStudy'] ?? '') !== (string) \$launch['orthanc_study_id']"),
    'instance streams instead of returning full binary' => str_contains($controller, '->streamInstance(') && str_contains($orthanc, 'function streamInstanceFile('),
    'instance output never forwards Orthanc headers' => str_contains($controller, "header('Content-Type: application/dicom')") && str_contains($controller, 'X-Content-Type-Options'),
    'old generic viewer token table is not used' => !str_contains($service, 'pacs_viewer_tokens'),
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
