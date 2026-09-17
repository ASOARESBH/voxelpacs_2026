<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function expect_profile(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$controller = file_get_contents($root . '/app/Controllers/Platform/ReportDeliveryController.php');
$view = file_get_contents($root . '/app/Views/platform/negocios/report_delivery.php');
$producer = file_get_contents($root . '/app/Services/PhilipsSubmissionPackageProducer.php');
$resolver = file_get_contents($root . '/app/Services/PhilipsSubmissionMetadataResolver.php');
$outbox = file_get_contents($root . '/app/Services/ReportDeliveryOutboxService.php');
$contract = file_get_contents($root . '/docs/PHILIPS_SUBMISSION_DOCUMENT_CONTRACT.md');
expect_profile(is_string($controller) && is_string($view) && is_string($producer) && is_string($resolver) && is_string($outbox) && is_string($contract), 'All Commit 4 sources must be readable');

foreach ([
    'PROFILE_PDF_ONLY',
    'PROFILE_SUBMISSION_DOCUMENT',
    'validatePhilipsSubmissionConfiguration',
    'task_file_path',
    'task_site_id',
    'task_document_name',
    'task_author_id',
    'task_author_humanname_family',
    'task_author_humanname_given',
    'task_author_humanname_middle',
    'task_delete_file',
    'task_document_type_applicable',
    '11502-2',
] as $marker) {
    expect_profile(str_contains($controller, $marker), "Controller must validate {$marker}");
}

foreach ([
    'data-field="delivery_profile"',
    'value="submission_document"',
    'data-submission-field',
    'data-field="task_file_path"',
    'data-field="task_site_id"',
    'data-field="task_document_name"',
    'data-field="task_author_id"',
    'data-field="task_author_humanname_family"',
    'data-field="task_author_humanname_given"',
    'data-field="task_author_humanname_middle"',
    'data-field="task_document_type_applicable"',
    'data-field="task_document_type"',
    'data-field="task_delete_file"',
    'config.philips_submission = philipsSubmission',
] as $marker) {
    expect_profile(str_contains($view, $marker), "View must expose {$marker}");
}

expect_profile(
    str_contains($view, "const value = input.hasAttribute('data-submission-field')")
        && !str_contains($view, "const value = input.dataset.submissionField"),
    'View must load submission fields by boolean attribute presence'
);
expect_profile(
    str_contains($view, "submissionProfile.value = currentConfig.delivery_profile === 'submission_document' ? 'submission_document' : 'pdf_only';"),
    'View must restore the persisted delivery profile when editing a destination'
);

expect_profile(str_contains($producer, 'new PhilipsSubmissionMetadataResolver'), 'Package producer must use the explicit metadata resolver');
expect_profile(str_contains($producer, "'task_document_name'"), 'Document name must be accepted as explicit configuration');
expect_profile(str_contains($producer, "'task_author_id'"), 'Author ID must be accepted as explicit configuration');
expect_profile(str_contains($outbox, "'patient_name_dicom'"), 'Snapshot must preserve raw DICOM PatientName separately from display text');
expect_profile(str_contains($resolver, "patient_name_dicom"), 'Resolver must consume the raw DICOM PatientName source');
expect_profile(str_contains($resolver, 'dicomPersonName'), 'Resolver must recognize structured DICOM PatientName');
expect_profile(str_contains($resolver, "strpos(\$value, '^')"), 'Resolver must require the DICOM component separator');
expect_profile(!str_contains($resolver, 'explode(\' \''), 'Resolver must not split names on spaces');
expect_profile(str_contains($contract, 'pdf_only'), 'Contract must document backward compatibility');
expect_profile(str_contains($contract, 'task_document_type'), 'Contract must document conditional document type');

foreach (['pt_BR', 'en', 'es'] as $locale) {
    $catalog = file_get_contents($root . '/lang/' . ($locale === 'pt_BR' ? 'pt_BR' : $locale) . '.php');
    expect_profile(is_string($catalog) && substr_count($catalog, 'philips_non_dicom.profile_submission_document') === 1, "{$locale} must contain the profile translation");
}

echo "REPORT_DELIVERY_SUBMISSION_PROFILE_STATIC_OK\n";
