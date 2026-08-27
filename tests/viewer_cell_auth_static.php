<?php
/**
 * Verificação estática da autorização DICOMweb para células Orthanc isoladas.
 * Não acessa banco, token, imagem ou dado de paciente.
 */
$root = dirname(__DIR__);
$controller = file_get_contents($root . '/app/Controllers/ViewerTokenController.php');
$routes = file_get_contents($root . '/routes/web.php');
$bootstrap = file_get_contents($root . '/public/index.php');

$required = [
    'authorizeDicomweb',
    "HTTP_X_VOXEL_VIEWER_ORIGIN",
    "HTTP_X_VOXEL_VIEWER_ORIGIN",
    "cell.gateway_route_key = :cell_key",
    "cell.viewer_url, '/') = :viewer_origin",
    'vt.tenant_id IS NOT NULL',
    "'httponly' => true",
    "'secure' => true",
];
foreach ($required as $needle) {
    if (strpos($controller, $needle) === false) {
        fwrite(STDERR, "FALHA: invariante ausente: {$needle}\n");
        exit(1);
    }
}
if (strpos($routes, "ViewerTokenController@authorizeDicomweb") === false) {
    fwrite(STDERR, "FALHA: rota interna de autorização ausente\n");
    exit(1);
}
if (strpos($bootstrap, "'/internal/viewer-auth/'") === false) {
    fwrite(STDERR, "FALHA: rota interna não foi liberada para subrequisição técnica\n");
    exit(1);
}
printf("VIEWER_CELL_AUTH_STATIC_OK\n");
