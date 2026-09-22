<?php
declare(strict_types=1);

$root = $argv[1] ?? '';
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "CANDIDATE_ROOT_INVALID\n");
    exit(2);
}
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    require $root . '/app/autoload.php';
}

use App\Services\PhilipsSubmissionDocument;
use App\Services\PhilipsSubmissionDocumentGenerator;
use App\Services\PhilipsSubmissionPackageProducer;
use App\Services\ReportDeliveryPackage;
use App\Repositories\ReportDeliveryRepository;

$results = [];
$failures = [];
function check_case(string $name, bool $ok, array &$results, array &$failures): void
{
    $results[$name] = $ok ? 'PASS' : 'FAIL';
    if (!$ok) {
        $failures[] = $name;
    }
}
function expect_throw(callable $callback): bool
{
    try {
        $callback();
    } catch (Throwable) {
        return true;
    }
    return false;
}

$generator = new PhilipsSubmissionDocumentGenerator();
$resolvedWindows = PhilipsSubmissionPackageProducer::resolveTaskFilePath('C:\\AutoIngest\\PDF', 'VOXEL_SYNTHETIC_001.pdf');
$resolvedPosix = PhilipsSubmissionPackageProducer::resolveTaskFilePath('/var/lib/auto-ingest/pdf', 'VOXEL_SYNTHETIC_001.pdf');
check_case('TASK_FILE_PATH_WINDOWS_BASENAME', $resolvedWindows === 'C:\\AutoIngest\\PDF\\VOXEL_SYNTHETIC_001.pdf', $results, $failures);
check_case('TASK_FILE_PATH_POSIX_BASENAME', $resolvedPosix === '/var/lib/auto-ingest/pdf/VOXEL_SYNTHETIC_001.pdf', $results, $failures);
check_case('TASK_FILE_PATH_CONTROL_CHAR_FAIL_CLOSED', expect_throw(fn() => PhilipsSubmissionPackageProducer::resolveTaskFilePath("C:\\AutoIngest\nPDF", 'VOXEL_SYNTHETIC_001.pdf')), $results, $failures);
$input = [
    'pdf_filename' => 'VOXEL_SYNTHETIC_001.pdf',
    'task_patient_id' => 'PATIENT-SYNTH-001',
    'task_patient_humanname_family' => 'João & Maria',
    'task_patient_humanname_given' => 'São José',
    'task_patient_humanname_middle' => 'Álvaro',
    'task_document_name' => 'Clínica "Central" <teste> A > B',
    'task_document_date' => '20260915120000',
    'task_image_date' => '20260915113000',
    'task_file_path' => 'logical/synthetic',
    'task_accession_number' => 'ACC-SYNTH-001',
    'task_document_mimetype' => 'application/pdf',
    'task_patient_birthday' => '19800102',
    'task_patient_gender' => 'O',
    'task_site_id' => 'SITE-SYNTH',
    'task_patient_issuer' => 'ISSUER-SYNTH',
    'task_author_id' => 'AUTHOR-SYNTH',
    'task_author_humanname_family' => 'Autor',
    'task_author_humanname_given' => 'Sintético',
    'task_author_humanname_middle' => '',
    'task_modalities' => 'OT',
    'task_document_type_applicable' => true,
    'task_document_type' => '11502-2',
    'task_delete_file' => true,
];

$doc1 = $generator->generate($input);
$doc2 = $generator->generate($input);
check_case('XML_DETERMINISTIC_BYTES', $doc1->content === $doc2->content, $results, $failures);
check_case('XML_DETERMINISTIC_SHA256', $doc1->sha256 === $doc2->sha256, $results, $failures);
check_case('XML_SIZE_MATCH', $doc1->size === strlen($doc1->content), $results, $failures);
check_case('XML_SHA256_MATCH', hash('sha256', $doc1->content) === $doc1->sha256, $results, $failures);
check_case('XML_ISO88591_DECLARATION', str_contains($doc1->content, 'encoding="iso-8859-1"'), $results, $failures);
$xmlUtf8 = function_exists('iconv') ? iconv('ISO-8859-1', 'UTF-8', $doc1->content) : false;
check_case('XML_ISO88591_BYTES', is_string($xmlUtf8) && str_contains($xmlUtf8, 'João &amp; Maria'), $results, $failures);
check_case('XML_ESCAPING_AMPERSAND', is_string($xmlUtf8) && str_contains($xmlUtf8, '&amp;'), $results, $failures);
check_case('XML_ESCAPING_LT_GT', is_string($xmlUtf8) && str_contains($xmlUtf8, '&lt;teste&gt;') && str_contains($xmlUtf8, 'A &gt; B'), $results, $failures);
check_case('XML_ESCAPING_QUOTES', is_string($xmlUtf8) && str_contains($xmlUtf8, '&quot;Central&quot;'), $results, $failures);
libxml_use_internal_errors(true);
$xml = simplexml_load_string($doc1->content);
check_case('XML_WELL_FORMED', $xml !== false, $results, $failures);
check_case('XML_STRUCTURE', $xml !== false && isset($xml->document), $results, $failures);
check_case('XML_TASK_FILE_NAME', $xml !== false && (string) $xml->document->task_file_name === 'VOXEL_SYNTHETIC_001.pdf', $results, $failures);
check_case('XML_TASK_DOCUMENT_TYPE', $xml !== false && (string) $xml->document->task_document_type === '11502-2', $results, $failures);

$testDir = $root . '/tests/phase71_synthetic_runtime';
if (!is_dir($testDir)) {
    mkdir($testDir, 0700, true);
}
$pdfPath = $testDir . '/VOXEL_SYNTHETIC_001.pdf';
$xmlPath = $testDir . '/' . $doc1->filename;
$pdfBytes = "%PDF-1.4\n" . str_repeat('SYNTHETIC-PDF-', 16) . "\n%%EOF\n";
file_put_contents($pdfPath, $pdfBytes);
file_put_contents($xmlPath, $doc1->content);
$pdfSha = hash_file('sha256', $pdfPath);
$pdfSize = filesize($pdfPath);
$pdfArtifact = [
    'type' => 'pdf',
    'storage_path' => $pdfPath,
    'filename' => 'VOXEL_SYNTHETIC_001.pdf',
    'sha256' => $pdfSha,
    'size' => $pdfSize,
];
$package = new ReportDeliveryPackage($pdfArtifact, $doc1, $xmlPath);
$metadata = $package->artifactMetadata();
check_case('PACKAGE_PDF_PRESENT', isset($metadata['pdf']['storage_path']) && is_file($metadata['pdf']['storage_path']), $results, $failures);
check_case('PACKAGE_XML_PRESENT', isset($metadata['xml']['storage_path']) && is_file($metadata['xml']['storage_path']), $results, $failures);
check_case('PACKAGE_PDF_HASH', $metadata['pdf']['sha256'] === $pdfSha, $results, $failures);
check_case('PACKAGE_XML_HASH', $metadata['xml']['sha256'] === $doc1->sha256, $results, $failures);
check_case('PACKAGE_PDF_SIZE', $metadata['pdf']['size'] === $pdfSize, $results, $failures);
check_case('PACKAGE_XML_SIZE', $metadata['xml']['size'] === $doc1->size, $results, $failures);
check_case('PACKAGE_LINKAGE_FILENAME', $metadata['pdf']['filename'] === $metadata['xml']['pdf_filename'], $results, $failures);
$manifest = json_encode([
    'v' => 1,
    'pdf' => ['filename' => $metadata['pdf']['filename'], 'sha256' => $metadata['pdf']['sha256'], 'size' => $metadata['pdf']['size']],
    'xml' => ['filename' => $metadata['xml']['filename'], 'sha256' => $metadata['xml']['sha256'], 'size' => $metadata['xml']['size']],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$packageBytes = $manifest . "\n" . file_get_contents($pdfPath) . "\n" . file_get_contents($xmlPath);
$packageIdentity1 = hash('sha256', $packageBytes);
$packageIdentity2 = hash('sha256', $manifest . "\n" . file_get_contents($pdfPath) . "\n" . file_get_contents($xmlPath));
check_case('PACKAGE_IDENTITY_SHA256', preg_match('/\A[a-f0-9]{64}\z/', $packageIdentity1) === 1, $results, $failures);
check_case('PACKAGE_IDENTITY_DETERMINISTIC', $packageIdentity1 === $packageIdentity2, $results, $failures);

$invalidMime = $input;
$invalidMime['task_document_mimetype'] = 'text/plain';
check_case('FAIL_CLOSED_INVALID_MIME', expect_throw(fn() => $generator->generate($invalidMime)), $results, $failures);
$missingPatient = $input;
unset($missingPatient['task_patient_id']);
check_case('FAIL_CLOSED_MISSING_PATIENT_ID', expect_throw(fn() => $generator->generate($missingPatient)), $results, $failures);
$missingAccession = $input;
unset($missingAccession['task_accession_number']);
check_case('FAIL_CLOSED_MISSING_ACCESSION', expect_throw(fn() => $generator->generate($missingAccession)), $results, $failures);
$missingDocumentName = $input;
unset($missingDocumentName['task_document_name']);
check_case('FAIL_CLOSED_MISSING_DOCUMENT_NAME', expect_throw(fn() => $generator->generate($missingDocumentName)), $results, $failures);
$missingSite = $input;
unset($missingSite['task_site_id']);
check_case('FAIL_CLOSED_MISSING_SITE_ID', expect_throw(fn() => $generator->generate($missingSite)), $results, $failures);
$missingLogicalPath = $input;
unset($missingLogicalPath['task_file_path']);
check_case('FAIL_CLOSED_MISSING_FILE_PATH', expect_throw(fn() => $generator->generate($missingLogicalPath)), $results, $failures);
$missingDelete = $input;
unset($missingDelete['task_delete_file']);
check_case('FAIL_CLOSED_MISSING_DELETE_FILE', expect_throw(fn() => $generator->generate($missingDelete)), $results, $failures);
$missingType = $input;
unset($missingType['task_document_type']);
check_case('FAIL_CLOSED_MISSING_DOCUMENT_TYPE', expect_throw(fn() => $generator->generate($missingType)), $results, $failures);
$badPdfName = $input;
$badPdfName['pdf_filename'] = 'unsafe.pdf';
check_case('FAIL_CLOSED_INVALID_PDF_NAME', expect_throw(fn() => $generator->generate($badPdfName)), $results, $failures);
$badHashArtifact = $pdfArtifact;
$badHashArtifact['sha256'] = str_repeat('0', 64);
check_case('FAIL_CLOSED_PDF_HASH', expect_throw(fn() => new ReportDeliveryPackage($badHashArtifact, $doc1, $xmlPath)), $results, $failures);
$badSizeArtifact = $pdfArtifact;
$badSizeArtifact['size'] = $pdfSize + 1;
check_case('FAIL_CLOSED_PDF_SIZE', expect_throw(fn() => new ReportDeliveryPackage($badSizeArtifact, $doc1, $xmlPath)), $results, $failures);
check_case('FAIL_CLOSED_MISSING_XML', expect_throw(fn() => new ReportDeliveryPackage($pdfArtifact, $doc1, $testDir . '/missing.xml')), $results, $failures);
$badXml = new PhilipsSubmissionDocument($doc1->filename, '<invalid>', hash('sha256', '<invalid>'), strlen('<invalid>'), $doc1->pdfFilename, $doc1->taskFilePath, $doc1->documentTypeApplicable, $doc1->deleteFile, $doc1->documentType);
check_case('FAIL_CLOSED_XML_WELL_FORMED', simplexml_load_string($badXml->content) === false, $results, $failures);
$badXmlPath = $testDir . '/invalid.xml';
file_put_contents($badXmlPath, $badXml->content);
check_case('FAIL_CLOSED_PACKAGE_XML_WELL_FORMED', expect_throw(fn() => new ReportDeliveryPackage($pdfArtifact, $badXml, $badXmlPath)), $results, $failures);
$nulXmlContent = str_replace('</submission>', "\0</submission>", $doc1->content);
$nulXml = new PhilipsSubmissionDocument($doc1->filename, $nulXmlContent, hash('sha256', $nulXmlContent), strlen($nulXmlContent), $doc1->pdfFilename, $doc1->taskFilePath, $doc1->documentTypeApplicable, $doc1->deleteFile, $doc1->documentType);
$nulXmlPath = $testDir . '/nul.xml';
file_put_contents($nulXmlPath, $nulXml->content);
check_case('FAIL_CLOSED_PACKAGE_XML_NUL', expect_throw(fn() => new ReportDeliveryPackage($pdfArtifact, $nulXml, $nulXmlPath)), $results, $failures);
$wrongLinkDocument = new PhilipsSubmissionDocument($doc1->filename, $doc1->content, $doc1->sha256, $doc1->size, 'VOXEL_OTHER_001.pdf', $doc1->taskFilePath, $doc1->documentTypeApplicable, $doc1->deleteFile, $doc1->documentType);
check_case('FAIL_CLOSED_PACKAGE_FILENAME_LINKAGE', expect_throw(fn() => new ReportDeliveryPackage($pdfArtifact, $wrongLinkDocument, $xmlPath)), $results, $failures);

$worker = file_get_contents($root . '/bin/report_delivery_worker.php');
check_case('PDF_ONLY_PROFILE_SYMBOL', is_string($worker) && str_contains($worker, 'PROFILE_PDF_ONLY'), $results, $failures);
check_case('SUBMISSION_PROFILE_SYMBOL', is_string($worker) && str_contains($worker, 'PROFILE_SUBMISSION_DOCUMENT'), $results, $failures);
check_case('IDEMPOTENCY_NULL_PROFILE', ReportDeliveryRepository::deliveryProfileIdentity(null) === 'pdf_only', $results, $failures);
check_case('IDEMPOTENCY_PROFILE_SEPARATION', ReportDeliveryRepository::deliveryProfileIdentity('pdf_only') !== ReportDeliveryRepository::deliveryProfileIdentity('submission_document'), $results, $failures);

foreach ($results as $name => $result) {
    printf("CASE|%s|%s\n", $name, $result);
}
printf("SYNTHETIC_TESTS=%s\n", count($failures) === 0 ? 'PASS' : 'FAIL');
printf("FAILURE_COUNT=%d\n", count($failures));
printf("PDF_BYTES=%d\n", strlen($pdfBytes));
printf("XML_BYTES=%d\n", $doc1->size);
printf("PACKAGE_IDENTITY_PREFIX=%s\n", substr($packageIdentity1, 0, 16));
printf("REAL_TRANSPORT_CALLED=NO\n");
printf("REAL_DATABASE_USED=NO\n");
printf("REAL_JOB_CREATED=NO\n");
printf("FAILURES=%s\n", implode(',', $failures));
exit(count($failures) === 0 ? 0 : 1);
