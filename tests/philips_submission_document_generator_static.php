<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';

use App\Services\PhilipsSubmissionDocumentGenerator;
use App\Services\PhilipsXmlFieldUnresolvedException;

function expect_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$input = [
    'pdf_filename' => 'VOXEL_SYNTHETIC_001.pdf',
    'task_patient_id' => 'SYNTH-001',
    'task_patient_humanname_family' => 'Silva & Costa',
    'task_patient_humanname_given' => 'João',
    'task_patient_humanname_middle' => 'D\'Ávila',
    'task_document_name' => 'Laudo & teste',
    'task_document_date' => '2026-09-14 17:00:01',
    'task_image_date' => '20260914170001',
    'task_file_path' => 'C:\\VOXEL\\PhilipsUpload',
    'task_accession_number' => 'ACC-SYNTH-001',
    'task_document_mimetype' => 'application/pdf',
    'task_patient_birthday' => '1980-01-02',
    'task_patient_gender' => 'f',
    'task_site_id' => 'SITE-SYNTH',
    'task_patient_issuer' => 'ISSUER-SYNTH',
    'task_author_id' => 'AUTH-SYNTH',
    'task_author_humanname_family' => 'Oliveira',
    'task_author_humanname_given' => 'Ana',
    'task_author_humanname_middle' => '',
    'task_modalities' => 'ct',
    'task_delete_file' => true,
    'task_document_type_applicable' => true,
    'task_document_type' => '11502-2',
];

$generator = new PhilipsSubmissionDocumentGenerator();
$first = $generator->generate($input);
$second = $generator->generate($input);

expect_true($first->filename === 'VOXEL_SYNTHETIC_001.xml', 'XML filename must derive from PDF filename');
expect_true($first->pdfFilename === $input['pdf_filename'], 'PDF filename must be preserved exactly');
expect_true($first->deleteFile === true, 'Explicit delete-file setting must be preserved');
expect_true($first->documentType === '11502-2', 'Explicit document type must be preserved');
expect_true($first->size === strlen($first->content), 'XML size must match encoded bytes');
expect_true(hash('sha256', $first->content) === $first->sha256, 'XML SHA-256 must match content');
expect_true($first->sha256 === $second->sha256 && $first->content === $second->content, 'Generation must be deterministic');
expect_true(str_starts_with($first->content, '<?xml version="1.0" encoding="iso-8859-1"?>'), 'XML declaration must use ISO-8859-1');
expect_true(str_contains($first->content, 'Silva &amp; Costa'), 'XML ampersand must be escaped');
expect_true(str_contains($first->content, 'Laudo &amp; teste'), 'XML document text must be escaped');
expect_true(str_contains($first->content, '<task_patient_gender>F</task_patient_gender>'), 'Gender must be normalized');
expect_true(str_contains($first->content, '<task_document_date>20260914170001</task_document_date>'), 'Date must be normalized');
expect_true(str_contains($first->content, '<task_patient_birthday>19800102</task_patient_birthday>'), 'Birthday must be normalized');
expect_true(str_contains($first->content, '<task_document_mimetype>application/pdf</task_document_mimetype>'), 'MIME must be PDF');
expect_true(str_contains($first->content, '<task_document_type>11502-2</task_document_type>'), 'Document type must be serialized');

if (function_exists('simplexml_load_string')) {
    $previous = libxml_use_internal_errors(true);
    $parsed = simplexml_load_string($first->content);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    expect_true($parsed !== false, 'XML must be well formed');
}

$missing = $input;
unset($missing['task_site_id']);
try {
    $generator->generate($missing);
    expect_true(false, 'Missing required field must fail closed');
} catch (PhilipsXmlFieldUnresolvedException $error) {
    expect_true($error->sanitizedCode() === 'PHILIPS_XML_FIELD_UNRESOLVED', 'Error code must be sanitized');
    expect_true($error->field === 'task_site_id', 'Error must identify only the technical field');
}

$heuristic = $input;
$heuristic['task_modalities'] = 'CT,MR';
try {
    $generator->generate($heuristic);
    expect_true(false, 'Ambiguous modality separator must fail closed');
} catch (PhilipsXmlFieldUnresolvedException $error) {
    expect_true($error->field === 'task_modalities', 'Ambiguous modality must identify its field');
}

echo "PHILIPS_SUBMISSION_DOCUMENT_GENERATOR_STATIC_OK\n";
