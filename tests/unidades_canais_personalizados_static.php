<?php
/**
 * Regressão estática — QR, site e redes sociais institucionais por Unidade.
 * Executar: php tests/unidades_canais_personalizados_static.php
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

$migration = $read($root . '/database/migrations/2026-08-18_unidades_canais_personalizados.sql');
$controller = $read($root . '/app/Controllers/UnidadesController.php');
$editA = $read($root . '/app/Views/unidades/edit.php');
$editB = $read($root . '/app/Views/unidades/nova.php');
$service = $read($root . '/app/Services/ReportCustomTemplateService.php');
$reports = $read($root . '/app/Controllers/ReportsController.php');
$editor = $read($root . '/app/Views/unidades/template_personalizado.php');
$js = $read($root . '/public/assets/js/unidades/canais-personalizados.js');
$footer = $read($root . '/app/Views/layout/pacs_footer.php');

foreach (['bi_negocio_institution_names', 'bi_unidades'] as $table) {
    $assert(str_contains($migration, 'ALTER TABLE `' . $table . '`'), 'Migration deve contemplar ' . $table . '.');
}
foreach (['qrcode', 'site', 'instagram', 'facebook'] as $channel) {
    $assert(str_contains($migration, 'personalizado_' . $channel . '_habilitado'), 'Migration sem flag de ' . $channel . '.');
    $assert(str_contains($migration, 'personalizado_' . $channel . '_url'), 'Migration sem URL de ' . $channel . '.');
    $assert(str_contains($editA, "'" . $channel . "' =>") && str_contains($editA, '{{unidade.<?= htmlspecialchars($canal) ?>}}'), 'Cadastro ativo não documenta placeholder de ' . $channel . '.');
    $assert(str_contains($editB, "'" . $channel . "' =>") && str_contains($editB, '{{unidade.<?= htmlspecialchars($canal) ?>}}'), 'Cadastro compatível não documenta placeholder de ' . $channel . '.');
    $assert(str_contains($editor, '{{unidade.' . $channel . '}}'), 'Editor não oferece placeholder de ' . $channel . '.');
}
$assert(!str_contains(strtoupper($migration), 'INFORMATION_SCHEMA'), 'Migration não pode usar INFORMATION_SCHEMA.');
$assert(str_contains($controller, 'camposCanaisPersonalizados'), 'Controller deve validar os canais institucionalmente.');
$assert(str_contains($controller, "'https'"), 'Controller deve exigir HTTPS.');
$assert(str_contains($controller, "'instagram.com'"), 'Controller deve restringir Instagram ao domínio esperado.');
$assert(str_contains($controller, "'facebook.com'"), 'Controller deve restringir Facebook ao domínio esperado.');
$assert(str_contains($service, 'institutionalQrMarkup'), 'Serviço deve renderizar QR institucional internamente.');
$assert(str_contains($service, 'safeHttpsUrl'), 'Serviço deve revalidar URL antes de renderizar.');
$assert(str_contains($reports, 'canais institucionais indisponiveis'), 'PDF deve manter fallback se a migration não estiver aplicada.');
$assert(str_contains($js, 'custom-channel-toggle'), 'JavaScript deve sincronizar os toggles dos canais.');
$assert(str_contains($footer, 'canais-personalizados.js'), 'Layout deve carregar o comportamento dos canais somente nas telas elegíveis.');

$template = [
    'header_content' => '<p>{{unidade.site}} {{unidade.instagram}} {{unidade.facebook}}</p>',
    'body_content' => '<p>{{unidade.qrcode}}</p>',
    'footer_content' => '',
];
$report = [
    'tenant_nome' => 'Clínica Exemplo',
    'unidade_personalizado_site_habilitado' => 1,
    'unidade_personalizado_site_url' => 'https://clinica.example.br',
    'unidade_personalizado_instagram_habilitado' => 1,
    'unidade_personalizado_instagram_url' => 'https://instagram.com/clinica',
    'unidade_personalizado_facebook_habilitado' => 1,
    'unidade_personalizado_facebook_url' => 'https://facebook.com/clinica',
    'unidade_personalizado_qrcode_habilitado' => 1,
    'unidade_personalizado_qrcode_url' => 'https://clinica.example.br/resultado',
];
$rendered = (new ReportCustomTemplateService())->renderReport($template, $report, '<p>Laudo</p>', 'Título');
$assert(str_contains($rendered, 'https://clinica.example.br'), 'Site habilitado deve virar link controlado.');
$assert(str_contains($rendered, 'https://instagram.com/clinica'), 'Instagram habilitado deve virar link controlado.');
$assert(str_contains($rendered, 'https://facebook.com/clinica'), 'Facebook habilitado deve virar link controlado.');

$unsafe = (new ReportCustomTemplateService())->renderReport([
    'header_content' => '<p>{{unidade.site}}</p>', 'body_content' => '', 'footer_content' => '',
], [
    'unidade_personalizado_site_habilitado' => 1,
    'unidade_personalizado_site_url' => 'javascript:alert(1)',
], '', '');
$assert(!str_contains($unsafe, 'javascript:'), 'URL insegura não pode chegar ao HTML do PDF.');

if ($failures !== []) {
    fwrite(STDERR, "FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "OK: canais institucionais personalizados validados.\n";
