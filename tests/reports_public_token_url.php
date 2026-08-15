<?php
/**
 * Regressão estática: URL pública do Laudário deve usar somente token opaco.
 * Executar: php tests/reports_public_token_url.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'migration' => $root . '/database/migrations/2026-08-14_reports_public_token_url_segura.sql',
    'routes' => $root . '/routes/web.php',
    'repository' => $root . '/app/Repositories/ReportRepository.php',
    'access' => $root . '/app/Services/ReportAccessService.php',
    'service' => $root . '/app/Services/ReportService.php',
    'reports_controller' => $root . '/app/Controllers/ReportsController.php',
    'worklist_controller' => $root . '/app/Controllers/EstudosController.php',
    'worklist_view' => $root . '/app/Views/estudos/index.php',
    'header' => $root . '/app/Views/layout/reports_header.php',
    'reports_js' => $root . '/public/assets/js/reports/reports-main.js',
    'reports_footer' => $root . '/app/Views/layout/reports_footer.php',
    'view_core' => $root . '/app/Core/View.php',
    'chat_service' => $root . '/app/Services/ReportChatService.php',
];

foreach ($checks as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FALHOU: arquivo ausente [$name]: $path\n");
        exit(1);
    }
    $contents[$name] = (string) file_get_contents($path);
}

$mustContain = [
    ['migration', 'public_token'],
    ['migration', 'RANDOM_BYTES(24)'],
    ['migration', 'idx_reports_public_token'],
    ['routes', "/reports/r/{token}"],
    ['routes', 'ReportsController@showByToken'],
    ['routes', 'ReportsController@pdfByToken'],
    ['routes', 'ReportsController@assinaturaImagemByToken'],
    ['repository', 'findReportByPublicToken'],
    ['repository', 'bin2hex(random_bytes(24))'],
    ['access', 'findAuthorizedReportByPublicToken'],
    ['reports_controller', 'showByToken'],
    ['reports_controller', 'pdfByToken'],
    ['reports_controller', 'findAuthorizedReportByPublicToken($token)'],
    ['reports_controller', '$this->pdf();'],
    ['worklist_controller', 'report_public_token'],
    ['worklist_view', '/reports/r/'],
    ['header', 'data-report-token'],
    ['reports_js', 'config.reportToken'],
    ['reports_js', 'function openSecurePdf(pdfUrl)'],
    ['reports_js', '`/reports/r/${encodeURIComponent(config.reportToken)}/pdf`'],
    ['reports_footer', 'reports-main.js?v=<?= $v ?>'],
    ['view_core', "ASSET_VERSION = '2.1.5'"],
    ['chat_service', "'/reports/r/'"],
];

foreach ($mustContain as [$file, $needle]) {
    if (strpos($contents[$file], $needle) === false) {
        fwrite(STDERR, "FALHOU: [$file] não contém $needle\n");
        exit(1);
    }
}

$forbidden = [
    $root . '/routes/web.php',
    $root . '/app/Views/estudos/index.php',
    $root . '/public/assets/js/reports/reports-main.js',
    $root . '/app/Views/reports/pdf/templates/_classico_centralizado.php',
    $root . '/app/Views/reports/pdf/templates/_corporativo_faixa.php',
    $root . '/app/Views/reports/pdf/templates/_minimalista.php',
    $root . '/app/Views/reports/pdf/templates/_moderno_lateral.php',
];

foreach ($forbidden as $path) {
    $text = (string) file_get_contents($path);
    if (preg_match('#/reports/pdf\\?report_id=|/reports/assinatura-imagem\\?report_id=#', $text)) {
        fwrite(STDERR, "FALHOU: URL pública legada encontrada em $path\n");
        exit(1);
    }
}

echo "OK: tokens públicos opacos de URL do Laudário validados.\n";
