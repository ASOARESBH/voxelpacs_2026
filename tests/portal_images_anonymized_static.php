<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'app/Controllers/PatientPortalController.php',
    'app/Controllers/PortalImageGatewayController.php',
    'app/Services/PortalImageSessionService.php',
    'app/Services/PortalImagePreparationService.php',
    'app/Services/PortalImageGatewayService.php',
    'app/Services/PortalAnonymizedOrthancClient.php',
    'app/Views/portal/images_preparing.php',
    'app/Views/portal/images_viewer.php',
    'routes/portal.php',
    'database/migrations/2026-08-20_portal_imagens_anonimizadas_postgresql.sql',
    'database/migrations/2026-08-20_portal_imagens_anonimizadas_mysql.sql',
];
foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) {
        fwrite(STDERR, "Arquivo obrigatório ausente: {$file}\n");
        exit(1);
    }
}

$session = file_get_contents($root . '/app/Services/PortalImageSessionService.php') ?: '';
$gateway = file_get_contents($root . '/app/Services/PortalImageGatewayService.php') ?: '';
$controller = file_get_contents($root . '/app/Controllers/PortalImageGatewayController.php') ?: '';
$portal = file_get_contents($root . '/app/Controllers/PatientPortalController.php') ?: '';
$reportService = file_get_contents($root . '/app/Services/ReportService.php') ?: '';
$envExample = file_get_contents($root . '/.env.example') ?: '';
$routes = file_get_contents($root . '/routes/portal.php') ?: '';
$pg = file_get_contents($root . '/database/migrations/2026-08-20_portal_imagens_anonimizadas_postgresql.sql') ?: '';
$mysql = file_get_contents($root . '/database/migrations/2026-08-20_portal_imagens_anonimizadas_mysql.sql') ?: '';

$expectations = [
    [$session, 'hash(\'sha256\', $token)', 'Sessão de imagem deve persistir somente hash do token.'],
    [$session, "SESSION_MINUTES = 15", 'Sessão de imagem deve possuir expiração curta.'],
    [$session, "pixel_review_status", 'Sessão não pode abrir cópia sem revisão de pixels.'],
    [$gateway, 'isAllowedStudyPath', 'Gateway deve restringir requisição ao estudo autorizado.'],
    [$gateway, 'anonymized_study_uid', 'Gateway deve usar somente UID anonimizado.'],
    [$controller, 'voxel_portal_image_session', 'Gateway deve exigir cookie de sessão opaco.'],
    [$controller, "'Cache-Control: no-store, private'", 'Gateway deve impedir cache de resposta clínica.'],
    [$portal, "PORTAL_IMAGES_ENABLED", 'Portal deve conservar flag explícita de ativação.'],
    [$portal, "PORTAL_IMAGES_ANONYMIZED", 'Portal deve exigir anonimização explícita.'],
    [$portal, "'httponly' => true", 'Cookie de sessão de imagens deve ser HttpOnly.'],
    [$reportService, 'PORTAL_IMAGES_PIPELINE_ENABLED', 'Pipeline deve exigir habilitação explícita.'],
    [$reportService, 'enqueueReleasedReport($reportId)', 'Cópia deve ser enfileirada após laudo liberado.'],
    [$envExample, 'PORTAL_IMAGES_PIPELINE_ENABLED=false', 'Pipeline deve iniciar desabilitado no ambiente.'],
    [$routes, "Router::get('/imagens/dicom-web/studies/{studyUid}/metadata'", 'Gateway deve expor apenas rota DICOMweb específica.'],
    [$pg, 'bi_portal_anonymized_studies', 'Migration PostgreSQL das cópias anonimizadas ausente.'],
    [$pg, "pixel_review_status", 'Migration PostgreSQL deve bloquear cópia sem revisão de pixels.'],
    [$mysql, 'DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci', 'Migration MySQL deve manter compatibilidade HostGator.'],
    [$mysql, 'bi_portal_image_sessions', 'Migration MySQL de sessão temporária ausente.'],
];
foreach ($expectations as [$content, $needle, $message]) {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$forbidden = [
    [$routes, "Router::get('/imagens/dicom-web/studies'", 'Gateway não pode expor QIDO-RS genérico de estudos.'],
    [$routes, 'Router::post(\'/imagens/dicom-web', 'Gateway não pode expor STOW-RS.'],
];
foreach ($forbidden as [$content, $needle, $message]) {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo "PORTAL_IMAGES_ANONYMIZED_STATIC_OK\n";
