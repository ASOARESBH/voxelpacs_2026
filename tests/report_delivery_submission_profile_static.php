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
$snapshot = file_get_contents($root . '/app/Services/ReportDeliveryRequestSnapshotService.php');
$outbox = file_get_contents($root . '/app/Services/ReportDeliveryOutboxService.php');
$versionName = file_get_contents($root . '/app/Services/ReportVersionPatientNameService.php');
$versionMigration = file_get_contents($root . '/database/migrations/2026-09-19_report_versions_patient_name_structured_postgresql.sql');
$contract = file_get_contents($root . '/docs/PHILIPS_SUBMISSION_DOCUMENT_CONTRACT.md');
expect_profile(is_string($controller) && is_string($view) && is_string($producer) && is_string($resolver) && is_string($snapshot) && is_string($outbox) && is_string($versionName) && is_string($versionMigration) && is_string($contract), 'All submission sources must be readable');

foreach ([
    'PROFILE_PDF_ONLY',
    'PROFILE_SUBMISSION_DOCUMENT',
    'validatePhilipsSubmissionConfiguration',
    'task_file_path',
    'task_site_id',
    'task_document_name',
    'task_author_id',
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
    'task_author_source_help',
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
expect_profile(
    str_contains($view, "if (key.startsWith('task_')) delete config[key];"),
    'View must remove legacy task fields from the configuration root before serialization'
);

expect_profile(str_contains($producer, 'new PhilipsSubmissionMetadataResolver'), 'Package producer must use the explicit metadata resolver');
expect_profile(str_contains($producer, "'task_document_name'"), 'Document name must be accepted as explicit configuration');
expect_profile(str_contains($producer, "'task_author_id'"), 'Author ID must be accepted as explicit configuration');
expect_profile(!str_contains($producer, "'task_author_humanname_family'")
    && !str_contains($producer, "'task_author_humanname_given'")
    && !str_contains($producer, "'task_author_humanname_middle'"), 'Package producer must not accept configured human author names');
expect_profile(!str_contains($producer, "'task_patient_humanname_family'")
    && !str_contains($producer, "'task_patient_humanname_given'")
    && !str_contains($producer, "'task_patient_humanname_middle'"), 'Package producer must not accept administrative PatientName components');
expect_profile(str_contains($outbox, "'patient_name_dicom'"), 'Snapshot must preserve raw DICOM PatientName separately from display text');
expect_profile(str_contains($snapshot, 'e.tags_raw') && str_contains($snapshot, "'tags_raw'"), 'Delivery Request snapshot must preserve tags_raw for structured DICOM resolution');
expect_profile(str_contains($snapshot, 'e.referring_physician_name') && str_contains($snapshot, "'referring_physician_name'"), 'Delivery Request snapshot must preserve Referring Physician');
expect_profile(str_contains($snapshot, 'rv.patient_name_family') && str_contains($snapshot, "'patient_name_source'"), 'Delivery Request snapshot must preserve frozen version components');
expect_profile(str_contains($resolver, "patient_name_dicom"), 'Resolver must consume the raw DICOM PatientName source');
expect_profile(str_contains($resolver, 'patientNameFromTagsRaw'), 'Resolver must inspect tags_raw before normalized name fields');
expect_profile(str_contains($resolver, 'allowsPatientNameAsFamily') && str_contains($resolver, 'deliveryContext'), 'Resolver must require the scoped homologation context for flat PatientName family mode');
expect_profile(str_contains($resolver, 'patient_name_as_family'), 'Resolver must mark the scoped flat PatientName family mode');
expect_profile(str_contains($resolver, 'versionPatientName'), 'Resolver must prioritize report_version components');
expect_profile(str_contains($resolver, 'studyDocumentDate'), 'Resolver must derive document date from StudyDate/StudyTime');
expect_profile(str_contains($resolver, 'referring_physician_name'), 'Resolver must derive author names from Referring Physician');
expect_profile(str_contains($resolver, 'dicomPersonName'), 'Resolver must recognize structured DICOM PatientName');
expect_profile(str_contains($versionName, 'DicomPersonName::components') && str_contains($versionName, 'patient_name_confirmation_required'), 'Version service must parse DICOM PN and require explicit confirmation for flat names');
expect_profile(str_contains($versionMigration, 'patient_name_family') && str_contains($versionMigration, 'report_versions_patient_name_immutable'), 'Migration must add structured fields and immutability');
expect_profile(!str_contains($resolver, 'explode(\' \''), 'Resolver must not split names on spaces');
expect_profile(str_contains($contract, 'pdf_only'), 'Contract must document backward compatibility');
expect_profile(str_contains($contract, 'task_document_type'), 'Contract must document conditional document type');
expect_profile(str_contains($contract, 'PatientName-as-family'), 'Contract must document the scoped PatientName family exception');
expect_profile(str_contains($contract, 'ReferringPhysicianName') && str_contains($contract, 'StudyDate/StudyTime'), 'Contract must document clinical XML sources');

foreach (['pt_BR', 'en', 'es'] as $locale) {
    $catalog = file_get_contents($root . '/lang/' . ($locale === 'pt_BR' ? 'pt_BR' : $locale) . '.php');
    expect_profile(is_string($catalog) && substr_count($catalog, 'philips_non_dicom.profile_submission_document') === 1, "{$locale} must contain the profile translation");
}

echo "REPORT_DELIVERY_SUBMISSION_PROFILE_STATIC_OK\n";
