<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$controllerPath = $root . '/app/Controllers/Platform/ReportPdfRevisionController.php';
$routesPath = $root . '/routes/platform.php';
$controller = (string) file_get_contents($controllerPath);
$routes = (string) file_get_contents($routesPath);

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    ['final class ReportPdfRevisionController', 'controller dedicado'],
    ['public function createVisualRendererCorrection(int $tenantId, int $reportId, int $version): void', 'ação nomeada'],
    ['Auth::isPlatformAdmin()', 'autorização de plataforma'],
    ['_csrf_token', 'proteção CSRF'],
    ['confirm_visual_renderer_correction', 'confirmação explícita'],
    ['new ReportAccessService()', 'autorização central do report'],
    ['findAuthorizedReport($reportId, false)', 'resolução autorizada do report'],
    ['(int) ($report->tenant_id ?? 0) !== $tenantId', 'escopo tenant/report'],
    ['createFromHistoricalReportVersion(', 'fonte histórica explícita'],
    ["'report.pdf_revision.created'", 'auditoria da criação'],
    ["'report.pdf_revision.denied'", 'auditoria de negação'],
    ["'report.pdf_revision.rejected'", 'auditoria de rejeição'],
    ['pdf_snapshot_sha256', 'hash do PDF na resposta/auditoria'],
    ['source_content_sha256', 'hash do conteúdo histórico'],
    ['publicMetadata', 'whitelist de metadados públicos'],
] as [$needle, $label]) {
    $expect(str_contains($controller, $needle), "Contrato ausente: {$label}.");
}

foreach ([
    'ReportRepository::findReportById',
    'INSERT INTO',
    'UPDATE ',
    'DELETE FROM',
    'Database::getInstance',
    'createOperationalReplacementFromCurrentReport(',
    "\$_POST['source_kind']",
    "\$_POST['corpo_laudo']",
] as $forbidden) {
    $expect(!str_contains($controller, $forbidden), "Caller contém operação ou entrada proibida: {$forbidden}.");
}

$routeNeedle = "'/platform/negocios/{id}/reports/{reportId}/versions/{version}/pdf-revisions/visual-renderer-correction'";
$routePosition = strpos($routes, $routeNeedle);
$expect($routePosition !== false, 'Rota de correção visual ausente.');
$lineStart = strrpos(substr($routes, 0, (int) $routePosition), "\n");
$line = substr($routes, $lineStart === false ? 0 : $lineStart + 1, 500);
$expect(str_contains($line, 'Router::post('), 'Rota não está restrita a POST.');
$expect(str_contains($line, 'ReportPdfRevisionController@createVisualRendererCorrection'), 'Handler da rota ausente.');

fwrite(STDOUT, "REPORT_PDF_REVISION_CALLER_STATIC_OK\n");
