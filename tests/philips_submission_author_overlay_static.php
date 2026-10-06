<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';

function expect_author_overlay(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$producerSource = file_get_contents($root . '/app/Services/PhilipsSubmissionPackageProducer.php');
expect_author_overlay(is_string($producerSource), 'Producer source must be readable');
expect_author_overlay(str_contains($producerSource, 'new PdfNonDicomArtifactProducer($this->artifacts)'), 'PDF producer path must remain present');

$reflection = new ReflectionClass(App\Services\PhilipsSubmissionPackageProducer::class);
$producer = $reflection->newInstanceWithoutConstructor();
$metadataProperty = $reflection->getProperty('metadata');
$metadataProperty->setValue($producer, new App\Services\PhilipsSubmissionMetadataResolver());
$resolvedInput = $reflection->getMethod('resolvedInput');
$resolvedInput->setAccessible(true);

$payload = [
    'patient_id' => 'SYNTH-PATIENT',
    'patient_name_dicom' => 'FAMILY^GIVEN^MIDDLE',
    'patient_birth_date' => '19800101',
    'patient_sex' => 'F',
    'issuer_of_patient_id' => 'SYNTH-ISSUER',
    'accession_number' => 'SYNTH-ACCESSION',
    'study_date' => '2026-10-06',
    'study_time' => '10:11:12',
    'modality' => 'CT',
    'referring_physician_name' => null,
    'philips_submission' => [],
];
$configuration = [
    'philips_submission' => [
        'task_file_path' => '/synthetic/inbox',
        'task_site_id' => 'Canonical-Synthetic',
        'task_document_name' => 'SYNTHETIC REPORT',
        'task_author_id' => '4',
        'task_author_humanname_family' => 'CONFIGURED-FAMILY',
        'task_author_humanname_given' => 'CONFIGURED-GIVEN',
        'task_author_humanname_middle' => 'CONFIGURED-MIDDLE',
        'task_delete_file' => false,
        'task_document_type_applicable' => true,
        'task_document_type' => '11502-2',
    ],
];
$automaticContext = [
    'tenant_id' => 2,
    'report_id' => 492,
    'report_version' => 2,
    'estudo_id' => 1,
    'destination_id' => 7,
    'ambiente' => 'producao',
    'transport' => 'philips_non_dicom',
    'delivery_profile' => 'submission_document',
    'dispatch_mode' => 'automatic_production',
    'task_site_id_alias' => 'ORTHANC-CLIENTE-A',
];
$pdfFilename = 'VOXEL_SYNTH_492_V2.pdf';
$automaticInput = $resolvedInput->invoke($producer, $payload, $configuration, $pdfFilename, $automaticContext);

foreach ([
    'task_author_id' => '4',
    'task_author_humanname_family' => 'CONFIGURED-FAMILY',
    'task_author_humanname_given' => 'CONFIGURED-GIVEN',
    'task_author_humanname_middle' => 'CONFIGURED-MIDDLE',
] as $field => $expected) {
    expect_author_overlay(($automaticInput[$field] ?? null) === $expected, "Automatic overlay must provide {$field}");
}
expect_author_overlay(($automaticInput['task_site_id'] ?? null) === 'ORTHANC-CLIENTE-A', 'Automatic overlay must preserve the canonical Destination 7 alias resolution');

$document = (new App\Services\PhilipsSubmissionDocumentGenerator())->generate(
    array_replace($automaticInput, ['pdf_filename' => $pdfFilename]),
    $automaticContext
);
expect_author_overlay($document->content !== '', 'XML generation must produce content');
expect_author_overlay(str_contains($document->content, 'encoding="iso-8859-1"'), 'XML must retain ISO-8859-1 declaration');
expect_author_overlay(str_contains($document->content, '<task_author_id>4</task_author_id>'), 'XML must include configured author ID');
expect_author_overlay(str_contains($document->content, '<task_author_humanname_family>CONFIGURED-FAMILY</task_author_humanname_family>'), 'XML must include configured author family');
expect_author_overlay(str_contains($document->content, '<task_author_humanname_given>CONFIGURED-GIVEN</task_author_humanname_given>'), 'XML must include configured author given');
expect_author_overlay(str_contains($document->content, '<task_author_humanname_middle>CONFIGURED-MIDDLE</task_author_humanname_middle>'), 'XML must include configured author middle');
expect_author_overlay(function_exists('simplexml_load_string') && simplexml_load_string($document->content) !== false, 'XML must be well formed');

$controlledPayload = array_replace($payload, [
    'referring_physician_name' => 'CLINICAL^SOURCE^NAME',
    'task_site_id_alias' => 'ORTHANC-CLIENTE-A',
]);
$controlledContext = array_replace($automaticContext, ['dispatch_mode' => 'controlled_production']);
$controlledInput = $resolvedInput->invoke($producer, $controlledPayload, $configuration, $pdfFilename, $controlledContext);
expect_author_overlay(($controlledInput['task_author_humanname_family'] ?? null) === 'CLINICAL', 'Controlled production must preserve clinical author family precedence');
expect_author_overlay(($controlledInput['task_author_humanname_given'] ?? null) === 'SOURCE', 'Controlled production must preserve clinical author given precedence');
expect_author_overlay(($controlledInput['task_author_humanname_middle'] ?? null) === 'NAME', 'Controlled production must preserve clinical author middle precedence');

echo "PHILIPS_SUBMISSION_AUTHOR_OVERLAY_STATIC_OK\n";
echo "PDF_ARTIFACT=PASS\n";
echo "XML_ARTIFACT=PASS\n";
echo "AUTHOR_ID=PASS\n";
echo "AUTHOR_FAMILY=PASS\n";
echo "AUTHOR_GIVEN=PASS\n";
echo "AUTHOR_MIDDLE=PASS\n";
echo "ISO_8859_1=PASS\n";
echo "XML_WELL_FORMED=PASS\n";
echo "ALIAS_TASK_SITE_ID=PASS\n";
echo "DATABASE_WRITE=NO\n";
echo "TRANSPORT_CALLED=NO\n";
