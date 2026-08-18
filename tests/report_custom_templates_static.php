<?php
/**
 * Regressão estática — Template de Laudo Personalizado por Unidade.
 * Executar: php tests/report_custom_templates_static.php
 */
require_once __DIR__ . '/../app/Services/ReportCustomTemplateService.php';

use App\Services\ReportCustomTemplateService;

$root = dirname(__DIR__);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$read = static function (string $path): string {
    $content = file_get_contents($path);
    if ($content === false) throw new RuntimeException('Arquivo ausente: ' . $path);
    return $content;
};

$service = $read($root . '/app/Services/ReportCustomTemplateService.php');
$controller = $read($root . '/app/Controllers/ReportCustomTemplateController.php');
$reports = $read($root . '/app/Controllers/ReportsController.php');
$signer = $read($root . '/app/Services/ReportService.php');
$layout = $read($root . '/app/Services/ReportLayoutService.php');
$view = $read($root . '/app/Views/unidades/template_personalizado.php');
$pdfPartial = $read($root . '/app/Views/reports/pdf/templates/_personalizado.php');
$routes = $read($root . '/routes/web.php');
$migration = $read($root . '/database/migrations/2026-08-18_report_custom_templates.sql');

$assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `report_custom_templates`'), 'Migration deve criar a tabela versionada.');
$assert(str_contains($migration, 'report_custom_template_id'), 'Migration deve congelar a versão no report.');
$assert(!str_contains(strtoupper($migration), 'INFORMATION_SCHEMA'), 'Migration nova não pode usar INFORMATION_SCHEMA.');
$assert(!preg_match('/\b(PROCEDURE|TRIGGER|PREPARE)\b/i', $migration), 'Migration não pode usar procedures, triggers ou SQL dinâmico.');
$assert(str_contains($migration, "'personalizado'"), 'Migration deve semear o quinto layout Personalizado.');

$assert(str_contains($service, "SOURCE_INSTITUTION = 'institution_name'"), 'Service deve distinguir a origem real da Unidade.');
$assert(str_contains($service, "STATUS_DRAFT = 'rascunho'"), 'Service deve manter rascunho.');
$assert(str_contains($service, "STATUS_PUBLISHED = 'publicado'"), 'Service deve manter publicação versionada.');
$assert(str_contains($service, 'sanitizeHtml'), 'Service deve sanitizar HTML.');
$assert(str_contains($service, 'sanitizeCss'), 'Service deve sanitizar CSS.');
$assert(str_contains($service, "'laudo.corpo'"), 'Service deve oferecer corpo de laudo no catálogo de variáveis.');
$assert(str_contains($service, 'mockContext'), 'Preview deve depender de contexto fictício.');

$assert(str_contains($controller, 'guardAdmin'), 'Controller deve proteger o editor por perfil.');
$assert(str_contains($controller, 'guardCsrf'), 'Controller deve validar CSRF em POSTs.');
$assert(str_contains($controller, 'tenant_id = :tenant_id'), 'Controller deve validar Unidade pelo tenant.');
$assert(str_contains($controller, 'previewInstitution'), 'Controller deve ter preview do Sistema A.');
$assert(str_contains($controller, 'previewUnidade'), 'Controller deve ter preview do Sistema B.');

$assert(str_contains($routes, 'template-personalizado/publicar'), 'Rotas devem expor publicação por POST.');
$assert(str_contains($routes, 'template-personalizado/preview'), 'Rotas devem expor preview por POST.');
$assert(str_contains($layout, "'personalizado'"), 'Allowlist de layout deve aceitar Personalizado.');
$assert(str_contains($reports, 'ReportCustomTemplateService'), 'PDF deve resolver a versão publicada personalizada.');
$assert(str_contains($reports, 'layout personalizado sem versão publicada; aplicado fallback'), 'PDF deve manter fallback seguro quando não houver publicação.');
$assert(str_contains($signer, 'congelarTemplatePersonalizadoAssinado'), 'Assinatura deve congelar a versão publicada.');
$assert(str_contains($view, 'sandbox="allow-same-origin"'), 'Preview deve ser isolado em iframe sem scripts.');
$assert(str_contains($view, 'dados fictícios'), 'Preview deve declarar uso de dados fictícios.');
$assert(str_contains($pdfPartial, 'renderReport'), 'Partial personalizado deve reutilizar o serviço central.');

$clean = ReportCustomTemplateService::sanitizeHtml('<script>alert(1)</script><p onclick="x()">Seguro</p><style>@import url(https://x); .a{background:url(javascript:x)}</style>');
$assert(!str_contains(strtolower($clean), '<script'), 'Sanitização deve remover script.');
$assert(!str_contains(strtolower($clean), 'onclick'), 'Sanitização deve remover evento inline.');
$assert(!str_contains(strtolower($clean), '@import'), 'Sanitização deve remover import CSS.');
$assert(!str_contains(strtolower($clean), 'javascript:'), 'Sanitização deve remover javascript no CSS.');

$preview = (new ReportCustomTemplateService())->renderPreview([
    'header_mode' => 'html',
    'header_content' => '<h1>{{unidade.nome}}</h1>',
    'body_mode' => 'texto',
    'body_content' => '<p>{{paciente.nome}}</p><p>{{laudo.corpo}}</p>',
    'footer_mode' => 'texto',
    'footer_content' => '<p>{{medico.crm}}</p>',
]);
$assert(str_contains($preview, 'Clínica Exemplo VOXEL'), 'Preview deve substituir dados fictícios de Unidade.');
$assert(str_contains($preview, 'PACIENTE DE EXEMPLO'), 'Preview deve substituir dados fictícios de Paciente.');
$assert(!str_contains($preview, '{{paciente.nome}}'), 'Preview não pode deixar placeholder conhecido sem resolver.');

$rendered = (new ReportCustomTemplateService())->renderReport([
    'header_content' => '<p>{{paciente.nome}}</p>',
    'body_content' => '<div>{{laudo.corpo}}</div>',
    'footer_content' => '<p>{{qrcode}}</p>',
], [
    'patient_name' => '<script>dados-clinicos</script>',
    'tenant_nome' => 'Clínica',
    'assinatura_hash' => 'abcdef0123456789',
], '<p>Corpo clínico seguro.</p>', 'Título de Teste');
$assert(str_contains($rendered, '&lt;script&gt;dados-clinicos&lt;/script&gt;'), 'Dados clínicos devem ser escapados na substituição.');
$assert(!str_contains($rendered, '<script>dados-clinicos</script>'), 'Dados clínicos não podem executar como HTML.');
$assert(str_contains($rendered, 'position:fixed'), 'PDF personalizado deve repetir visualmente cabeçalho e rodapé na impressão.');
$assert(str_contains($rendered, 'VALIDAR:'), 'QR de rastreio deve ser resolvido pelo backend.');

if ($failures) {
    fwrite(STDERR, "FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "OK: regressão de Template Personalizado aprovada.\n";
