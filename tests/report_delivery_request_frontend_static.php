<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = file_get_contents($root . '/app/Views/platform/negocios/report_delivery.php');

function expect_frontend(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

expect_frontend($view !== false, 'Report Delivery view must be readable');

$requestStart = strpos($view, "const requestForm = document.getElementById('delivery-request-form');");
$requestEnd = strpos($view, "manualForm.addEventListener('submit'", $requestStart === false ? 0 : $requestStart);
expect_frontend($requestStart !== false && $requestEnd !== false, 'Frontend Request executor block must be present');
$requestBlock = substr($view, $requestStart, $requestEnd - $requestStart);

foreach (['prepare', 'approve', 'materialize', 'arm'] as $stage) {
    expect_frontend(str_contains($view, "data-request-stage=\"{$stage}\""), "{$stage} button must be present");
    expect_frontend(str_contains($requestBlock, "confirm_{$stage}"), "{$stage} confirmation must be sent");
}

expect_frontend(str_contains($requestBlock, "requestBase + '/prepare'"), 'Prepare must use the existing prepare route');
expect_frontend(str_contains($requestBlock, "requestPaths = { approve: 'approve', materialize: 'materialize', arm: 'arm' }"), 'Transition paths must use the existing routes');
expect_frontend(substr_count($requestBlock, "method: 'POST'") === 1, 'Frontend executor must use POST for the request phases');
expect_frontend(str_contains($requestBlock, "credentials: 'same-origin'"), 'Frontend executor must preserve the authenticated session');
expect_frontend(str_contains($requestBlock, "requestForm.querySelector('[name=\"_csrf_token\"]').value"), 'Transitions must reuse the rendered session CSRF field');
expect_frontend(substr_count($requestBlock, "headers['Idempotency-Key'] = idempotencyKey") === 1, 'Idempotency-Key must be set by the frontend');
expect_frontend(str_contains($requestBlock, "if (stage === 'prepare')"), 'Idempotency-Key must be scoped to prepare');
expect_frontend(str_contains($requestBlock, 'window.crypto.randomUUID()') && str_contains($requestBlock, 'window.crypto.getRandomValues'), 'Prepare must generate UUID v4 with Web Crypto');
expect_frontend(str_contains($requestBlock, "value=\"6\"") || str_contains($view, "name=\"destination_id\" value=\"6\""), 'Frontend payload must keep Destination 6');
expect_frontend(str_contains($view, 'name="delivery_profile" value="submission_document"'), 'Frontend payload must keep submission_document');
expect_frontend(str_contains($view, 'name="dispatch_mode" value="manual_homologation"'), 'Frontend payload must keep manual_homologation');
expect_frontend(str_contains($requestBlock, "requestStageIndex += 1"), 'Frontend must enforce sequential phases');
expect_frontend(!str_contains($requestBlock, '/reports/enqueue'), 'Request executor must not use the legacy enqueue flow');
expect_frontend(!str_contains($requestBlock, 'test-smb'), 'Request executor must not call SMB test');

foreach (['pt_BR', 'en', 'es'] as $locale) {
    $translations = file_get_contents($root . "/lang/{$locale}.php");
    expect_frontend($translations !== false, "Translation file {$locale} must be readable");
    foreach (['delivery_hub.request.titulo', 'delivery_hub.request.prepare', 'delivery_hub.request.approve', 'delivery_hub.request.materialize', 'delivery_hub.request.arm', 'delivery_hub.request.confirm_prepare', 'delivery_hub.request.confirm_approve', 'delivery_hub.request.confirm_materialize', 'delivery_hub.request.confirm_arm'] as $key) {
        expect_frontend(str_contains($translations, "'{$key}'"), "Translation {$key} missing in {$locale}");
    }
}

echo "PASS: frontend Delivery Request executor contract\n";
