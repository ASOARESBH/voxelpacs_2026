<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string) file_get_contents($root . '/app/Views/platform/negocios/report_delivery.php');
$routes = (string) file_get_contents($root . '/routes/platform.php');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'id="pdf-revision-form"' => 'formulário de revisão PDF',
    'name="_csrf_token"' => 'CSRF no formulário',
    'name="confirm_visual_renderer_correction"' => 'confirmação explícita',
    'class="form-check-input ms-0 me-2" id="pdf-revision-confirm"' => 'checkbox visível no viewport do formulário',
    'id="pdf-revision-public-token"' => 'token/link público para abertura',
    'credentials: \'same-origin\'' => 'sessão autenticada same-origin',
    'fetch(endpoint' => 'chamada autenticada ao caller',
    "body.delete('report_public_token')" => 'token público não enviado ao endpoint',
    'revisionOpenLink.href' => 'link para abrir a revisão',
    'pdf-revisions/visual-renderer-correction' => 'endpoint de correção visual',
] as $needle => $label) {
    $expect(str_contains($view, $needle), "Contrato ausente: {$label}.");
}

$expect(str_contains(
    $routes,
    "'/platform/negocios/{id}/reports/{reportId}/versions/{version}/pdf-revisions/visual-renderer-correction'"
), 'Rota do caller não está registrada.');
$expect(!str_contains($view, 'Database::getInstance'), 'A view não pode acessar o banco diretamente.');
$expect(!str_contains($view, 'INSERT INTO'), 'A view não pode executar SQL de inserção.');
$expect(!str_contains($view, 'UPDATE '), 'A view não pode executar SQL de atualização.');
$expect(!str_contains($view, 'window.confirm(revisionMessages'), 'A revisão PDF não pode depender de diálogo nativo.');

$keys = [
    'delivery_hub.pdf_revision.titulo',
    'delivery_hub.pdf_revision.ajuda',
    'delivery_hub.pdf_revision.report_id',
    'delivery_hub.pdf_revision.report_version',
    'delivery_hub.pdf_revision.public_link',
    'delivery_hub.pdf_revision.public_link_placeholder',
    'delivery_hub.pdf_revision.public_link_help',
    'delivery_hub.pdf_revision.confirm_label',
    'delivery_hub.pdf_revision.submit',
    'delivery_hub.pdf_revision.open',
    'delivery_hub.pdf_revision.processing',
    'delivery_hub.pdf_revision.success',
    'delivery_hub.pdf_revision.error',
    'delivery_hub.pdf_revision.invalid_response',
    'delivery_hub.pdf_revision.invalid_report',
    'delivery_hub.pdf_revision.invalid_link',
];

foreach (['pt_BR', 'en', 'es'] as $locale) {
    $translations = require $root . '/lang/' . $locale . '.php';
    foreach ($keys as $key) {
        $expect(array_key_exists($key, $translations), "Chave {$key} ausente em {$locale}.");
    }
}

fwrite(STDOUT, "REPORT_PDF_REVISION_UI_STATIC_OK\n");
