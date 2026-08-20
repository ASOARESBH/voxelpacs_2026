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
    'app/Controllers/Platform/PortalImageReviewController.php',
    'app/Views/platform/negocios/portal_images_review.php',
    'routes/portal.php',
    'routes/platform.php',
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
$preparation = file_get_contents($root . '/app/Services/PortalImagePreparationService.php') ?: '';
$orthancClient = file_get_contents($root . '/app/Services/PortalAnonymizedOrthancClient.php') ?: '';
$envExample = file_get_contents($root . '/.env.example') ?: '';
$routes = file_get_contents($root . '/routes/portal.php') ?: '';
$platformRoutes = file_get_contents($root . '/routes/platform.php') ?: '';
$review = file_get_contents($root . '/app/Controllers/Platform/PortalImageReviewController.php') ?: '';
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
    [$controller, "echo \$response['body'];\n            exit;", 'Gateway deve encerrar resposta DICOMweb sem layout HTML.'],
    [$controller, "'session_missing'", 'Gateway deve negar ausência de sessão.'],
    [$controller, 'private function clientIp()', 'Gateway deve obter o IP sem depender de helper ausente.'],
    [$portal, "PORTAL_IMAGES_ENABLED", 'Portal deve conservar flag explícita de ativação.'],
    [$portal, "PORTAL_IMAGES_ANONYMIZED", 'Portal deve exigir anonimização explícita.'],
    [$portal, "'httponly' => true", 'Cookie de sessão de imagens deve ser HttpOnly.'],
    [$reportService, 'PORTAL_IMAGES_PIPELINE_ENABLED', 'Pipeline deve exigir habilitação explícita.'],
    [$preparation, 'PORTAL_CLINICAL_ORTHANC_PRIVATE_URL', 'Pipeline deve exigir origem privada explícita do Orthanc clínico.'],
    [$preparation, 'Endpoint privado do Orthanc clínico não configurado.', 'Pipeline deve falhar fechado sem origem clínica privada.'],
    [$preparation, "'DicomVersion' => '2021b'", 'Perfil deve documentar a versão DICOM usada na anonimização.'],
    [$preparation, "'Force' => true", 'Perfil deve autorizar a substituição segura dos identificadores DICOM.'],
    [$preparation, 'UIDs de estudo/série/instância não podem ser preservados', 'Perfil deve exigir novos UIDs anonimizados.'],
    [$preparation, 'public static function documentedRemovedTags()', 'Perfil deve manter a lista auditável de tags removidas.'],
    [$preparation, 'instanceSanitizationProfile()', 'Transferência deve aplicar sanitização por instância.'],
    [$preparation, 'sanitizeInstance((string) $instanceId', 'Instância deve ser sanitizada antes do upload ao repositório.'],
    [$preparation, "'PatientName' => 'ANONYMOUS'", 'Perfil deve substituir o nome por valor neutro.'],
    [$preparation, "'PatientID' => 'PORTAL-ANON'", 'Perfil deve substituir o identificador do paciente por valor neutro.'],
    [$orthancClient, 'public function sanitizeInstance', 'Cliente privado deve suportar sanitização sem persistência.'],
    [$preparation, "'PatientName', 'PatientID'", 'Lista auditável deve incluir identificadores diretos do paciente.'],
    [$reportService, 'enqueueReleasedReport($reportId)', 'Cópia deve ser enfileirada após laudo liberado.'],
    [$envExample, 'PORTAL_IMAGES_PIPELINE_ENABLED=false', 'Pipeline deve iniciar desabilitado no ambiente.'],
    [$envExample, 'PORTAL_CLINICAL_ORTHANC_PRIVATE_URL=http://10.0.0.3:8042', 'Ambiente deve documentar a origem privada do Orthanc clínico.'],
    [$routes, "Router::get('/imagens/dicom-web/studies/{studyUid}/metadata'", 'Gateway deve expor apenas rota DICOMweb específica.'],
    [$platformRoutes, "Router::post('/platform/negocios/{id}/portal-imagens/{copyId}/revisar'", 'Rota de revisão administrativa ausente.'],
    [$review, 'Auth::isPlatformAdmin()', 'Revisão de pixels deve permanecer exclusiva de superadmin.'],
    [$review, 'public function preview', 'Revisão deve oferecer prévia protegida para inspeção de pixels.'],
    [$review, "header('Cache-Control: no-store, private')", 'Prévia de pixels não pode permanecer no cache do navegador.'],
    [$review, "AuditLogger::log('portal_images.pixel_preview'", 'Abertura de prévia administrativa deve ser auditada.'],
    [$platformRoutes, "portal-imagens/{copyId}/preview", 'Prévia deve ter rota administrativa dedicada.'],
    [$review, 'validCsrf()', 'Revisão de pixels deve exigir CSRF.'],
    [$review, "'approved', 'rejected'", 'Revisão deve aceitar somente decisões explícitas.'],
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
