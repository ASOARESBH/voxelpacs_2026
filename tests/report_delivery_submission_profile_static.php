<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';

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
$generator = file_get_contents($root . '/app/Services/PhilipsSubmissionDocumentGenerator.php');
$dicomPersonName = file_get_contents($root . '/app/Helpers/DicomPersonName.php');
$snapshot = file_get_contents($root . '/app/Services/ReportDeliveryRequestSnapshotService.php');
$outbox = file_get_contents($root . '/app/Services/ReportDeliveryOutboxService.php');
$versionName = file_get_contents($root . '/app/Services/ReportVersionPatientNameService.php');
$versionMigration = file_get_contents($root . '/database/migrations/2026-09-19_report_versions_patient_name_structured_postgresql.sql');
$contract = file_get_contents($root . '/docs/PHILIPS_SUBMISSION_DOCUMENT_CONTRACT.md');
expect_profile(is_string($controller) && is_string($view) && is_string($producer) && is_string($resolver) && is_string($generator) && is_string($dicomPersonName) && is_string($snapshot) && is_string($outbox) && is_string($versionName) && is_string($versionMigration) && is_string($contract), 'All submission sources must be readable');

foreach ([
    'PROFILE_PDF_ONLY',
    'PROFILE_SUBMISSION_DOCUMENT',
    'validatePhilipsSubmissionConfiguration',
    'task_file_path',
    'task_site_id',
    'task_site_id_alias',
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
expect_profile(
    str_contains($view, "body.set('task_site_id_alias', taskSiteAliasField.value.trim())"),
    'View must submit the technical alias explicitly, including when the browser omits a disabled control'
);
expect_profile(
    str_contains($view, "task_site_id_alias: taskSiteAliasField ? taskSiteAliasField.value.trim() : ''"),
    'View capture diagnostics must expose only the sanitized alias field'
);

expect_profile(str_contains($producer, 'new PhilipsSubmissionMetadataResolver'), 'Package producer must use the explicit metadata resolver');
expect_profile(str_contains($producer, 'resolveTaskSiteId'), 'Package producer must resolve the controlled production alias explicitly');
expect_profile(str_contains($producer, 'dispatch_mode') && str_contains($producer, 'task_site_id_alias'), 'Alias selection must be scoped to the controlled payload or automatic destination context');
expect_profile(str_contains($producer, "'task_site_id_alias' => (string) (\$job['task_site_id_alias'] ?? '')"), 'Automatic production must pass the resolved destination alias into the delivery context');
expect_profile(str_contains($producer, "'task_document_name'"), 'Document name must be accepted as explicit configuration');
expect_profile(str_contains($producer, "'task_author_id'"), 'Author ID must be accepted as explicit configuration');
expect_profile(str_contains($producer, "'automatic_production'"), 'Configured author overlay must be scoped to automatic production');
foreach (['task_author_humanname_family', 'task_author_humanname_given', 'task_author_humanname_middle'] as $field) {
    expect_profile(str_contains($producer, "'{$field}'"), "Automatic production must accept configured {$field}");
}
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
expect_profile(str_contains($resolver, 'author_humanname_flat') && str_contains($resolver, 'referringPhysicianRaw'), 'Resolver must mark flat Referring Physician names without reusing PatientName-as-family');
expect_profile(str_contains($generator, 'author_humanname_flat') && str_contains($generator, 'optionalText($input, \'task_author_humanname_given\')'), 'Generator must allow empty author given only for the explicit flat-author context');
expect_profile(!str_contains($dicomPersonName, 'PhilipsSubmission') && !str_contains($dicomPersonName, 'author_humanname_flat'), 'Generic DICOM PN helper must not contain Philips-specific author rules');
expect_profile(str_contains($versionName, 'DicomPersonName::components') && str_contains($versionName, 'patient_name_fallback'), 'Version service must parse DICOM PN and persist flat names automatically as the fallback source');
expect_profile(str_contains($versionMigration, 'patient_name_family') && str_contains($versionMigration, 'report_versions_patient_name_immutable'), 'Migration must add structured fields and immutability');
expect_profile(!str_contains($resolver, 'explode(\' \''), 'Resolver must not split names on spaces');
expect_profile(str_contains($contract, 'pdf_only'), 'Contract must document backward compatibility');
expect_profile(str_contains($contract, 'task_document_type'), 'Contract must document conditional document type');
expect_profile(str_contains($contract, 'PatientName-as-family'), 'Contract must document the scoped PatientName family exception');
expect_profile(str_contains($contract, 'ReferringPhysicianName') && str_contains($contract, 'StudyDate/StudyTime'), 'Contract must document clinical XML sources');
expect_profile(str_contains($contract, 'Quando o valor é plano') && str_contains($contract, 'não reutiliza `patient_name_as_family`'), 'Contract must distinguish flat Referring Physician names from PatientName-as-family');

foreach (['pt_BR', 'en', 'es'] as $locale) {
    $catalog = file_get_contents($root . '/lang/' . ($locale === 'pt_BR' ? 'pt_BR' : $locale) . '.php');
    expect_profile(is_string($catalog) && substr_count($catalog, 'philips_non_dicom.profile_submission_document') === 1, "{$locale} must contain the profile translation");
}

foreach ([
    "\$result['smb_auth']",
    "\$result['smb_pwd']",
    "\$result['smb_return_code']",
    "\$result['smb_classification']",
    "\$result['nt_status_logon_failure']",
    "\$result['result']",
    "'SMB_WRITE' => 'NOT_EXECUTED'",
] as $marker) {
    expect_profile(str_contains($controller, $marker), "SMB read-only controller must map {$marker}");
}
expect_profile(
    str_contains($controller, "'success' => \$probePassed")
        && str_contains($controller, "\$probePassed ? 200 : 422"),
    'SMB read-only controller must not report an unconfirmed probe as success'
);
expect_profile(
    !str_contains($controller, "\$result['SMB_AUTH']")
        && !str_contains($controller, "\$result['SMB_TARGET']")
        && !str_contains($controller, "\$result['SMB_READONLY_LIST']"),
    'SMB read-only controller must not read nonexistent uppercase client keys'
);

$d6Context = [
    'transport' => 'philips_non_dicom',
    'destination_id' => 6,
    'ambiente' => 'homologacao',
    'delivery_profile' => 'submission_document',
    'dispatch_mode' => 'manual_homologation',
];
expect_profile(
    \App\Services\PhilipsSubmissionPackageProducer::resolveTaskSiteId(['task_site_id_alias' => 'MALICIOUS'], '2', $d6Context) === '2',
    'D6 must retain its canonical task_site_id and ignore aliases'
);
$d7Context = [
    'transport' => 'philips_non_dicom',
    'destination_id' => 7,
    'ambiente' => 'producao',
    'delivery_profile' => 'submission_document',
    'dispatch_mode' => 'controlled_production',
];
expect_profile(
    \App\Services\PhilipsSubmissionPackageProducer::resolveTaskSiteId(['task_site_id_alias' => 'ORTHANC-CLIENTE-A'], 'Unicode — canonical', $d7Context) === 'ORTHANC-CLIENTE-A',
    'D7 production must use only the frozen ASCII alias'
);
$d7AutomaticContext = [
    'transport' => 'philips_non_dicom',
    'destination_id' => 7,
    'ambiente' => 'producao',
    'delivery_profile' => 'submission_document',
    'dispatch_mode' => 'automatic_production',
    'task_site_id_alias' => 'ORTHANC-CLIENTE-A',
];
expect_profile(
    \App\Services\PhilipsSubmissionPackageProducer::resolveTaskSiteId([], 'Unicode — canonical', $d7AutomaticContext) === 'ORTHANC-CLIENTE-A',
    'D7 automatic production must use the resolved ASCII destination alias'
);
try {
    \App\Services\PhilipsSubmissionPackageProducer::resolveTaskSiteId(
        [],
        'Unicode — canonical',
        array_replace($d7AutomaticContext, ['task_site_id_alias' => ''])
    );
    expect_profile(false, 'D7 automatic production must fail closed without a valid destination alias');
} catch (\App\Services\PhilipsXmlFieldUnresolvedException) {
    // Expected fail-closed behavior.
}
expect_profile(
    \App\Services\PhilipsSubmissionPackageProducer::resolveTaskSiteId(
        ['task_site_id_alias' => 'MALICIOUS'],
        'Unicode — canonical',
        [
            'transport' => 'philips_non_dicom',
            'destination_id' => 6,
            'ambiente' => 'producao',
            'delivery_profile' => 'submission_document',
            'dispatch_mode' => 'automatic_production',
            'task_site_id_alias' => 'OTHER-DESTINATION',
        ]
    ) === 'Unicode — canonical',
    'Non-D7 automatic production must retain its canonical task_site_id'
);
foreach ([[], ['task_site_id_alias' => ''], ['task_site_id_alias' => 'não-ascii']] as $invalidPayload) {
    try {
        \App\Services\PhilipsSubmissionPackageProducer::resolveTaskSiteId($invalidPayload, 'Unicode — canonical', $d7Context);
        expect_profile(false, 'D7 production must fail closed without a valid alias');
    } catch (\App\Services\PhilipsXmlFieldUnresolvedException) {
        // Expected fail-closed behavior.
    }
}

echo "REPORT_DELIVERY_SUBMISSION_PROFILE_STATIC_OK\n";
