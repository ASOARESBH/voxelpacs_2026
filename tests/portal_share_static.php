<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'app/Services/PortalShareService.php',
    'app/Controllers/PatientPortalController.php',
    'app/Views/portal/results.php',
    'routes/portal.php',
    'public/assets/js/portal-share.js',
    'database/migrations/2026-08-20_portal_resultados_compartilhamento_postgresql.sql',
];
foreach ($files as $file) {
    if (!is_file($root . '/' . $file)) {
        fwrite(STDERR, "Arquivo ausente: {$file}\n");
        exit(1);
    }
}

$service = file_get_contents($root . '/app/Services/PortalShareService.php') ?: '';
$controller = file_get_contents($root . '/app/Controllers/PatientPortalController.php') ?: '';
$view = file_get_contents($root . '/app/Views/portal/results.php') ?: '';
$routes = file_get_contents($root . '/routes/portal.php') ?: '';
$migration = file_get_contents($root . '/database/migrations/2026-08-20_portal_resultados_compartilhamento_postgresql.sql') ?: '';

$expectations = [
    [$service, 'hash(\'sha256\', $rawToken)', 'Tokens de compartilhamento devem ser persistidos como hash.'],
    [$service, 'LINK_HOURS = 24', 'Links de compartilhamento devem expirar.'],
    [$service, 'recipient_hint', 'Destinatários devem ser mascarados para auditoria.'],
    [$controller, 'PORTAL_IMAGES_ANONYMIZED', 'Imagens do Portal devem exigir anonimização explícita.'],
    [$controller, 'validCsrf', 'Compartilhamento deve exigir CSRF.'],
    [$view, 'Ver imagens', 'Ação de imagens deve estar disponível na interface.'],
    [$view, 'Compartilhar', 'Ação de compartilhamento deve estar disponível na interface.'],
    [$routes, "Router::post('/compartilhar/{token}'", 'Rota POST de compartilhamento ausente.'],
    [$migration, 'bi_portal_share_links', 'Migration da trilha de compartilhamento ausente.'],
];
foreach ($expectations as [$content, $needle, $message]) {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}
echo "PORTAL_SHARE_STATIC_OK\n";
