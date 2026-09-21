<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';

use App\Services\PhilipsSubmissionDocumentGenerator;
use App\Services\PhilipsSubmissionHomologationPolicy;
use App\Services\PhilipsXmlFieldUnresolvedException;

function expect_homologation(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$input = [
    'pdf_filename' => 'VOXEL_SYNTHETIC_001.pdf',
    'task_patient_id' => 'SYNTH-001',
    'task_document_name' => 'Laudo sintetico',
    'task_document_date' => '20260914170001',
    'task_image_date' => '20260914170001',
    'task_file_path' => 'C:\\AutoIngest\\PDF\\VOXEL_SYNTHETIC_001.pdf',
    'task_accession_number' => 'ACC-SYNTH-001',
    'task_document_mimetype' => 'application/pdf',
    'task_patient_birthday' => '19800102',
    'task_patient_gender' => 'O',
    'task_site_id' => '2',
    'task_patient_issuer' => 'ISSUER-SYNTH',
    'task_author_id' => 'AUTH-SYNTH',
    'task_author_humanname_family' => 'Autor',
    'task_author_humanname_given' => 'Teste',
    'task_author_humanname_middle' => '',
    'task_modalities' => 'OT',
    'task_delete_file' => false,
    'task_document_type_applicable' => true,
    'task_document_type' => '11502-2',
];

$context = [
    'tenant_id' => 2,
    'report_id' => 74,
    'report_version' => 11,
    'estudo_id' => 1704,
    'destination_id' => 6,
    'ambiente' => 'homologacao',
    'delivery_profile' => 'submission_document',
    'transport' => 'philips_non_dicom',
];

$generator = new PhilipsSubmissionDocumentGenerator();
putenv(PhilipsSubmissionHomologationPolicy::FLAG . '=0');
$missing = $input;
try {
    $generator->generate($missing, $context);
    expect_homologation(false, 'default-off must remain fail-closed');
} catch (PhilipsXmlFieldUnresolvedException $error) {
    expect_homologation($error->field === 'task_patient_humanname_family', 'default-off field must be family');
}

putenv(PhilipsSubmissionHomologationPolicy::FLAG . '=1');
expect_homologation(PhilipsSubmissionHomologationPolicy::allows($context), 'exact context must be allowed when flag is on');
$document = $generator->generate($missing, $context);
expect_homologation($document->patientNameComponentsOmitted === true, 'document must mark omission');
expect_homologation(!str_contains($document->content, 'task_patient_humanname_family'), 'family node must be omitted');
expect_homologation(!str_contains($document->content, 'task_patient_humanname_given'), 'given node must be omitted');
expect_homologation(!str_contains($document->content, 'task_patient_humanname_middle'), 'middle node must be omitted');
expect_homologation(str_contains($document->content, '<task_patient_id>SYNTH-001</task_patient_id>'), 'patient id must remain present');
expect_homologation(str_contains($document->content, '<task_document_type>11502-2</task_document_type>'), 'document type must remain present');
expect_homologation(str_starts_with($document->content, '<?xml version="1.0" encoding="iso-8859-1"?>'), 'encoding must remain unchanged');
$xml = new DOMDocument();
expect_homologation($xml->loadXML($document->content), 'XML must remain well formed');
foreach ([
    'task_patient_id',
    'task_document_name',
    'task_document_date',
    'task_image_date',
    'task_file_path',
    'task_file_name',
    'task_accession_number',
    'task_document_mimetype',
    'task_patient_birthday',
    'task_patient_gender',
    'task_site_id',
    'task_patient_issuer',
    'task_author_id',
    'task_author_humanname_family',
    'task_author_humanname_given',
    'task_modalities',
    'task_delete_file',
    'task_document_type',
] as $field) {
    expect_homologation($xml->getElementsByTagName($field)->length === 1, "required node must remain: {$field}");
}
expect_homologation(basename(str_replace('\\', '/', $document->taskFilePath)) === $document->pdfFilename, 'PDF/XML basename must match');

$otherRequiredFieldMissing = $missing;
unset($otherRequiredFieldMissing['task_document_name']);
try {
    $generator->generate($otherRequiredFieldMissing, $context);
    expect_homologation(false, 'other required fields must remain fail-closed');
} catch (PhilipsXmlFieldUnresolvedException $error) {
    expect_homologation($error->field === 'task_document_name', 'other required field must be reported');
}

$wrongContext = $context;
$wrongContext['tenant_id'] = 3;
expect_homologation(!PhilipsSubmissionHomologationPolicy::allows($wrongContext), 'wrong tenant must be rejected');
try {
    $generator->generate($missing, $wrongContext);
    expect_homologation(false, 'wrong tenant must fail closed');
} catch (PhilipsXmlFieldUnresolvedException $error) {
    expect_homologation($error->field === 'task_patient_humanname_family', 'wrong tenant must require family');
}

$partial = $missing;
$partial['task_patient_humanname_family'] = 'PARTIAL';
try {
    $generator->generate($partial, $context);
    expect_homologation(false, 'partial components must not be silently omitted');
} catch (PhilipsXmlFieldUnresolvedException $error) {
    expect_homologation($error->field === 'task_patient_humanname_given', 'partial components must fail on given');
}

$empty = $missing;
$empty['task_patient_humanname_family'] = '';
$empty['task_patient_humanname_given'] = '';
$empty['task_patient_humanname_middle'] = '';
try {
    $generator->generate($empty, $context);
    expect_homologation(false, 'empty components must not be treated as omitted');
} catch (PhilipsXmlFieldUnresolvedException $error) {
    expect_homologation($error->field === 'task_patient_humanname_family', 'empty components must fail on family');
}

$normal = $input;
$normal['task_patient_humanname_family'] = 'Family';
$normal['task_patient_humanname_given'] = 'Given';
$normalDocument = $generator->generate($normal);
expect_homologation($normalDocument->patientNameComponentsOmitted === false, 'normal path must not mark omission');
expect_homologation(str_contains($normalDocument->content, '<task_patient_humanname_family>Family</task_patient_humanname_family>'), 'normal family node must remain');
expect_homologation(str_contains($normalDocument->content, '<task_patient_humanname_given>Given</task_patient_humanname_given>'), 'normal given node must remain');

putenv(PhilipsSubmissionHomologationPolicy::PATIENT_NAME_AS_FAMILY_FLAG . '=1');
$familyMode = $input;
$familyMode['task_patient_humanname_family'] = 'Flat Patient Name';
$familyMode['task_patient_humanname_given'] = '';
$familyMode['task_patient_humanname_middle'] = '';
$familyMode['patient_name_as_family'] = true;
$familyDocument = $generator->generate($familyMode, $context);
expect_homologation($familyDocument->patientNameAsFamily === true, 'family mode must be marked');
expect_homologation(str_contains($familyDocument->content, '<task_patient_humanname_family>Flat Patient Name</task_patient_humanname_family>'), 'family mode must preserve full PatientName');
expect_homologation(str_contains($familyDocument->content, '<task_patient_humanname_given></task_patient_humanname_given>'), 'family mode must emit empty given');
expect_homologation(str_contains($familyDocument->content, '<task_patient_humanname_middle></task_patient_humanname_middle>'), 'family mode must emit empty middle');
putenv(PhilipsSubmissionHomologationPolicy::PATIENT_NAME_AS_FAMILY_FLAG . '=0');

putenv(PhilipsSubmissionHomologationPolicy::FLAG . '=0');
echo "PHILIPS_SUBMISSION_HOMOLOGATION_EXCEPTION_STATIC_OK\n";
