<?php

declare(strict_types=1);

$root = dirname(__DIR__);
define('BASE_PATH', sys_get_temp_dir() . '/voxel-author-resolver-test-' . bin2hex(random_bytes(4)));
require_once $root . '/app/autoload.php';

use App\Config\ReportDeliveryRuntimeConfig;
use App\Services\PhilipsSubmissionAuthorResolver;
use App\Services\PhilipsSubmissionDocumentGenerator;

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$expect = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};

$clearRuntime = static function (): void {
    foreach ([
        ReportDeliveryRuntimeConfig::PHILIPS_AUTHOR_FALLBACK_ENABLED,
        ReportDeliveryRuntimeConfig::PHILIPS_AUTHOR_FALLBACK_FAMILY,
        ReportDeliveryRuntimeConfig::PHILIPS_AUTHOR_FALLBACK_GIVEN,
        ReportDeliveryRuntimeConfig::PHILIPS_AUTHOR_FALLBACK_MIDDLE,
    ] as $name) {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    }
};

$automaticContext = [
    'tenant_id' => 2,
    'destination_id' => 7,
    'ambiente' => 'producao',
    'transport' => 'philips_non_dicom',
    'delivery_profile' => 'submission_document',
    'dispatch_mode' => 'automatic_production',
];
$controlledContext = array_replace($automaticContext, ['dispatch_mode' => 'controlled_production']);
$noClinicalMetadata = [
    'task_author_id' => '4',
    'task_author_humanname_family' => null,
    'task_author_humanname_given' => null,
    'task_author_humanname_middle' => null,
    'author_humanname_flat' => false,
];
$explicitConfiguration = [
    'philips_submission' => [
        'task_author_humanname_family' => 'CONFIGURED-FAMILY',
        'task_author_humanname_given' => 'CONFIGURED-GIVEN',
        'task_author_humanname_middle' => 'CONFIGURED-MIDDLE',
    ],
];
$resolver = new PhilipsSubmissionAuthorResolver();

$clinical = $resolver->resolve(
    $noClinicalMetadata,
    $explicitConfiguration,
    $controlledContext,
    array_replace($noClinicalMetadata, [
        'task_author_humanname_family' => 'CLINICAL-FAMILY',
        'task_author_humanname_given' => 'CLINICAL-GIVEN',
        'task_author_humanname_middle' => 'CLINICAL-MIDDLE',
    ])
);
$expect($clinical['author_source'] === PhilipsSubmissionAuthorResolver::SOURCE_CLINICAL, 'Clinical author must have priority');
$expect($clinical['task_author_humanname_family'] === 'CLINICAL-FAMILY', 'Clinical family must be preserved in controlled mode');
$expect($clinical['task_author_humanname_given'] === 'CLINICAL-GIVEN', 'Clinical given must be preserved in controlled mode');
$expect($clinical['task_author_humanname_middle'] === 'CLINICAL-MIDDLE', 'Clinical middle must be preserved in controlled mode');

$explicit = $resolver->resolve($noClinicalMetadata, $explicitConfiguration, $automaticContext, $noClinicalMetadata);
$expect($explicit['author_source'] === PhilipsSubmissionAuthorResolver::SOURCE_EXPLICIT_CONFIGURATION, 'Automatic explicit configuration must be identified');
$expect($explicit['author_decision'] === PhilipsSubmissionAuthorResolver::DECISION_REAL_AUTHOR_AVAILABLE, 'Explicit configuration must be a real-author decision');
$expect($explicit['task_author_humanname_family'] === 'CONFIGURED-FAMILY', 'Configured family must be explicit');
$expect($explicit['task_author_humanname_given'] === 'CONFIGURED-GIVEN', 'Configured given must be explicit');
$expect($explicit['task_author_humanname_middle'] === 'CONFIGURED-MIDDLE', 'Configured middle must be explicit');

$clearRuntime();
$disabled = $resolver->resolveForNoSendDiagnostic($noClinicalMetadata, [], $automaticContext, $noClinicalMetadata);
$expect($disabled['author_source'] === PhilipsSubmissionAuthorResolver::SOURCE_UNRESOLVED, 'Disabled fallback must remain unresolved');
$expect($disabled['author_decision'] === PhilipsSubmissionAuthorResolver::DECISION_AUTHOR_UNRESOLVED, 'Disabled fallback must not claim a decision');
$expect($disabled['task_author_id_resolution'] === 'UNRESOLVED', 'Technical author ID must not be mapped by assumption');

putenv(ReportDeliveryRuntimeConfig::PHILIPS_AUTHOR_FALLBACK_ENABLED . '=true');
$fallback = $resolver->resolveForNoSendDiagnostic($noClinicalMetadata, [], $automaticContext, $noClinicalMetadata);
$expect($fallback['author_source'] === PhilipsSubmissionAuthorResolver::SOURCE_FALLBACK_MISSING_DATA, 'Enabled no-send fallback must be identifiable');
$expect($fallback['author_decision'] === PhilipsSubmissionAuthorResolver::DECISION_FALLBACK_REQUIRED, 'Fallback must expose the required decision');
$expect($fallback['author_fallback_used'] === 'YES', 'Fallback use must be explicit');
$expect($fallback['task_author_id_resolution'] === 'UNRESOLVED', 'task_author_id must remain unresolved without a formal association');
$expect($fallback['task_author_humanname_family'] === 'VOXEL', 'Fallback family must use the documented default');
$expect($fallback['task_author_humanname_given'] === 'AUTHOR_MISSING', 'Fallback given must identify missing real data');
$expect($fallback['task_author_humanname_middle'] === '', 'Fallback middle must be empty by default');

$normalProduction = $resolver->resolve($noClinicalMetadata, [], $automaticContext, $noClinicalMetadata);
$expect($normalProduction['author_source'] === PhilipsSubmissionAuthorResolver::SOURCE_UNRESOLVED, 'Normal automatic production must ignore diagnostic fallback');
$expect($normalProduction['author_fallback_used'] === 'NO', 'Normal automatic production must never mark fallback use');

putenv(ReportDeliveryRuntimeConfig::PHILIPS_AUTHOR_FALLBACK_FAMILY . '=não-permitido');
$invalidFallback = $resolver->resolveForNoSendDiagnostic($noClinicalMetadata, [], $automaticContext, $noClinicalMetadata);
$expect($invalidFallback['author_source'] === PhilipsSubmissionAuthorResolver::SOURCE_UNRESOLVED, 'Invalid fallback configuration must fail closed');
$expect($invalidFallback['author_fallback_configuration'] === 'INVALID', 'Invalid fallback configuration must be classified');
putenv(ReportDeliveryRuntimeConfig::PHILIPS_AUTHOR_FALLBACK_FAMILY . '=VOXEL');

$controlledFallback = $resolver->resolveForNoSendDiagnostic($noClinicalMetadata, [], $controlledContext, $noClinicalMetadata);
$expect($controlledFallback['author_source'] === PhilipsSubmissionAuthorResolver::SOURCE_UNRESOLVED, 'Fallback must never alter controlled production');
$expect($controlledFallback['author_fallback_used'] === 'NO', 'Controlled production must not use fallback');

$documentInput = array_replace([
    'pdf_filename' => 'VOXEL_SYNTHETIC_519_V1.pdf',
    'task_patient_id' => 'SYNTH-PATIENT',
    'task_patient_humanname_family' => 'PATIENT',
    'task_patient_humanname_given' => 'SYNTHETIC',
    'task_patient_humanname_middle' => '',
    'task_document_name' => 'SYNTHETIC REPORT',
    'task_document_date' => '20261006101112',
    'task_image_date' => '20261006101112',
    'task_file_path' => '/synthetic/inbox/VOXEL_SYNTHETIC_519_V1.pdf',
    'task_accession_number' => 'SYNTH-ACCESSION',
    'task_document_mimetype' => 'application/pdf',
    'task_patient_birthday' => '19800101',
    'task_patient_gender' => 'F',
    'task_site_id' => 'SYNTHETIC-SITE',
    'task_patient_issuer' => 'SYNTHETIC-ISSUER',
    'task_author_id' => '4',
    'task_modalities' => 'CT',
    'task_delete_file' => false,
    'task_document_type_applicable' => true,
    'task_document_type' => '11502-2',
], $fallback);
$document = (new PhilipsSubmissionDocumentGenerator())->generate($documentInput, $automaticContext);
$expect(str_contains($document->content, '<task_author_humanname_family>VOXEL</task_author_humanname_family>'), 'Fallback family must be serialized only in synthetic memory');
$expect(str_contains($document->content, '<task_author_humanname_given>AUTHOR_MISSING</task_author_humanname_given>'), 'Fallback marker must be serialized only in synthetic memory');
$expect(str_contains($document->content, 'encoding="iso-8859-1"'), 'Fallback XML must retain ISO-8859-1 declaration');
$expect(function_exists('simplexml_load_string') && simplexml_load_string($document->content) !== false, 'Fallback XML must be well formed');

$source = file_get_contents($root . '/app/Services/PhilipsSubmissionAuthorResolver.php');
$expect(is_string($source), 'AuthorResolver source must be readable');
foreach (['bi_medicos', 'bi_users', 'PDO', 'Database::', 'smbclient', 'INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $forbidden) {
    $expect(!str_contains($source, $forbidden), "AuthorResolver must not contain {$forbidden}");
}
$expect(!str_contains($source, "explode(' '") && !str_contains($source, "explode(\" \""), 'AuthorResolver must not split names heuristically');

$clearRuntime();
echo "PHILIPS_SUBMISSION_AUTHOR_RESOLVER_STATIC_OK\n";
echo "CLINICAL_PRECEDENCE=PASS\n";
echo "EXPLICIT_CONFIGURATION=PASS\n";
echo "TASK_AUTHOR_ID_LOOKUP=UNRESOLVED\n";
echo "DIAGNOSTIC_FALLBACK=PASS\n";
echo "CONTROLLED_PRODUCTION_REGRESSION=PASS\n";
echo "ISO_8859_1=PASS\n";
echo "NO_SEND_SIDE_EFFECTS=PASS\n";
