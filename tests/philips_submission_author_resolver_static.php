<?php

declare(strict_types=1);

$root = dirname(__DIR__);
define('BASE_PATH', sys_get_temp_dir() . '/voxel-author-resolver-test-' . bin2hex(random_bytes(4)));
require_once $root . '/app/autoload.php';

use App\Config\ReportDeliveryRuntimeConfig;
use App\Contracts\PhilipsSubmissionAuthorLookup;
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
$directoryLookup = new class implements PhilipsSubmissionAuthorLookup {
    /** @var array<int, array{id:int,tenant_id:int,nome:string,ativo:int}> */
    public array $rows = [
        4 => ['id' => 4, 'tenant_id' => 2, 'nome' => 'Family^Given^Middle', 'ativo' => 1],
        5 => ['id' => 5, 'tenant_id' => 2, 'nome' => 'Nome Plano Completo', 'ativo' => 1],
        6 => ['id' => 6, 'tenant_id' => 3, 'nome' => 'OTHER^TENANT', 'ativo' => 1],
        7 => ['id' => 7, 'tenant_id' => 2, 'nome' => 'Inativo', 'ativo' => 0],
    ];

    public function findActiveBiMedico(int $tenantId, int $authorId): ?array
    {
        $row = $this->rows[$authorId] ?? null;
        return $row !== null && $row['tenant_id'] === $tenantId && $row['ativo'] === 1 ? $row : null;
    }
};
$directoryResolver = new PhilipsSubmissionAuthorResolver($directoryLookup);

$directoryConfiguration = [
    'philips_submission' => [
        'task_author_source' => 'bi_medicos',
        'task_author_id' => '4',
    ],
];
$directoryAuthor = $directoryResolver->resolve(
    $noClinicalMetadata,
    $directoryConfiguration,
    $automaticContext,
    array_replace($noClinicalMetadata, ['task_author_source' => 'bi_medicos'])
);
$expect($directoryAuthor['author_source'] === PhilipsSubmissionAuthorResolver::SOURCE_BI_MEDICOS, 'bi_medicos author source must be explicit');
$expect($directoryAuthor['author_decision'] === PhilipsSubmissionAuthorResolver::DECISION_REAL_AUTHOR_AVAILABLE, 'bi_medicos author must be a real-author decision');
$expect($directoryAuthor['task_author_id_resolution'] === 'RESOLVED', 'bi_medicos author ID must resolve');
$expect($directoryAuthor['task_author_humanname_family'] === 'Family', 'Structured bi_medicos family must preserve PN components');
$expect($directoryAuthor['task_author_humanname_given'] === 'Given', 'Structured bi_medicos given must preserve PN components');
$expect($directoryAuthor['task_author_humanname_middle'] === 'Middle', 'Structured bi_medicos middle must preserve PN components');

$flatDirectoryAuthor = $directoryResolver->resolve(
    array_replace($noClinicalMetadata, ['task_author_id' => '5', 'task_author_source' => 'bi_medicos']),
    $directoryConfiguration,
    $automaticContext,
    array_replace($noClinicalMetadata, ['task_author_id' => '5', 'task_author_source' => 'bi_medicos'])
);
$expect($flatDirectoryAuthor['author_source'] === PhilipsSubmissionAuthorResolver::SOURCE_BI_MEDICOS, 'Flat bi_medicos author source must be explicit');
$expect($flatDirectoryAuthor['task_author_humanname_family'] === 'Nome Plano Completo', 'Flat bi_medicos name must remain integral in family');
$expect($flatDirectoryAuthor['task_author_humanname_given'] === '', 'Flat bi_medicos author given must remain empty');
$expect($flatDirectoryAuthor['task_author_humanname_middle'] === '', 'Flat bi_medicos author middle must remain empty');
$expect($flatDirectoryAuthor['author_humanname_flat'] === true, 'Flat bi_medicos author must use the existing flat-author contract');

$controlledDirectoryAuthor = $directoryResolver->resolve(
    array_replace($noClinicalMetadata, ['task_author_id' => '4', 'task_author_source' => 'bi_medicos']),
    $directoryConfiguration,
    $controlledContext,
    array_replace($noClinicalMetadata, ['task_author_id' => '4', 'task_author_source' => 'bi_medicos'])
);
$expect($controlledDirectoryAuthor['author_decision'] === PhilipsSubmissionAuthorResolver::DECISION_AUTHOR_UNRESOLVED, 'controlled_production must not perform a live bi_medicos lookup');
$expect($controlledDirectoryAuthor['task_author_id_resolution'] === 'SOURCE_NOT_ALLOWED_FOR_MODE', 'controlled_production source lookup must be blocked explicitly');

$wrongTenantAuthor = $directoryResolver->resolve(
    array_replace($noClinicalMetadata, ['task_author_id' => '6', 'task_author_source' => 'bi_medicos']),
    $directoryConfiguration,
    $automaticContext,
    array_replace($noClinicalMetadata, ['task_author_id' => '6', 'task_author_source' => 'bi_medicos'])
);
$expect($wrongTenantAuthor['author_decision'] === PhilipsSubmissionAuthorResolver::DECISION_AUTHOR_UNRESOLVED, 'Cross-tenant bi_medicos author must fail closed');
$expect($wrongTenantAuthor['task_author_id_resolution'] === 'NOT_FOUND', 'Cross-tenant bi_medicos author must be classified as not found');

$inactiveAuthor = $directoryResolver->resolve(
    array_replace($noClinicalMetadata, ['task_author_id' => '7', 'task_author_source' => 'bi_medicos']),
    $directoryConfiguration,
    $automaticContext,
    array_replace($noClinicalMetadata, ['task_author_id' => '7', 'task_author_source' => 'bi_medicos'])
);
$expect($inactiveAuthor['author_decision'] === PhilipsSubmissionAuthorResolver::DECISION_AUTHOR_UNRESOLVED, 'Inactive bi_medicos author must fail closed');
$expect($inactiveAuthor['task_author_id_resolution'] === 'NOT_FOUND', 'Inactive bi_medicos author must be classified as not found');

$invalidSourceAuthor = $directoryResolver->resolve(
    array_replace($noClinicalMetadata, ['task_author_source' => 'bi_users']),
    ['philips_submission' => ['task_author_source' => 'bi_users', 'task_author_id' => '4']],
    $automaticContext,
    array_replace($noClinicalMetadata, ['task_author_source' => 'bi_users'])
);
$expect($invalidSourceAuthor['author_decision'] === PhilipsSubmissionAuthorResolver::DECISION_AUTHOR_UNRESOLVED, 'Unknown author source must fail closed');
$expect($invalidSourceAuthor['task_author_id_resolution'] === 'INVALID_SOURCE', 'Unknown author source must be classified');

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
    'task_author_source' => 'bi_medicos',
    'task_modalities' => 'CT',
    'task_delete_file' => false,
    'task_document_type_applicable' => true,
    'task_document_type' => '11502-2',
], $fallback);
$document = (new PhilipsSubmissionDocumentGenerator())->generate($documentInput, $automaticContext);
$expect(str_contains($document->content, '<task_author_humanname_family>VOXEL</task_author_humanname_family>'), 'Fallback family must be serialized only in synthetic memory');
$expect(str_contains($document->content, '<task_author_humanname_given>AUTHOR_MISSING</task_author_humanname_given>'), 'Fallback marker must be serialized only in synthetic memory');
$expect(str_contains($document->content, 'encoding="iso-8859-1"'), 'Fallback XML must retain ISO-8859-1 declaration');
$expect(!str_contains($document->content, 'task_author_source'), 'Internal author source must never be serialized into XML');
$expect(function_exists('simplexml_load_string') && simplexml_load_string($document->content) !== false, 'Fallback XML must be well formed');

$structuredDirectoryDocument = (new PhilipsSubmissionDocumentGenerator())->generate(
    array_replace($documentInput, $directoryAuthor),
    $automaticContext
);
$expect(str_contains($structuredDirectoryDocument->content, '<task_author_humanname_family>Family</task_author_humanname_family>'), 'Structured bi_medicos author family must reach XML');
$expect(str_contains($structuredDirectoryDocument->content, '<task_author_humanname_given>Given</task_author_humanname_given>'), 'Structured bi_medicos author given must reach XML');
$expect(!str_contains($structuredDirectoryDocument->content, 'task_author_source'), 'Structured bi_medicos source must remain internal');
$expect(function_exists('simplexml_load_string') && simplexml_load_string($structuredDirectoryDocument->content) !== false, 'Structured bi_medicos XML must be well formed');

$flatDirectoryDocument = (new PhilipsSubmissionDocumentGenerator())->generate(
    array_replace($documentInput, $flatDirectoryAuthor),
    $automaticContext
);
$expect(str_contains($flatDirectoryDocument->content, '<task_author_humanname_family>Nome Plano Completo</task_author_humanname_family>'), 'Flat bi_medicos author must use the explicit flat-author contract');
$expect(str_contains($flatDirectoryDocument->content, '<task_author_humanname_given></task_author_humanname_given>'), 'Flat bi_medicos author given must be empty in XML');
$expect(!str_contains($flatDirectoryDocument->content, 'task_author_source'), 'Flat bi_medicos source must remain internal');
$expect(function_exists('simplexml_load_string') && simplexml_load_string($flatDirectoryDocument->content) !== false, 'Flat bi_medicos XML must be well formed');

$source = file_get_contents($root . '/app/Services/PhilipsSubmissionAuthorResolver.php');
$expect(is_string($source), 'AuthorResolver source must be readable');
foreach (['bi_users', 'PDO', 'Database::', 'smbclient', 'INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $forbidden) {
    $expect(!str_contains($source, $forbidden), "AuthorResolver must not contain {$forbidden}");
}
$expect(!str_contains($source, "explode(' '") && !str_contains($source, "explode(\" \""), 'AuthorResolver must not split names heuristically');

$clearRuntime();
echo "PHILIPS_SUBMISSION_AUTHOR_RESOLVER_STATIC_OK\n";
echo "CLINICAL_PRECEDENCE=PASS\n";
echo "EXPLICIT_CONFIGURATION=PASS\n";
echo "TASK_AUTHOR_ID_LOOKUP=PASS\n";
echo "TENANT_SCOPE=PASS\n";
echo "FLAT_AUTHOR_CONTRACT=PASS\n";
echo "DIAGNOSTIC_FALLBACK=PASS\n";
echo "CONTROLLED_PRODUCTION_REGRESSION=PASS\n";
echo "ISO_8859_1=PASS\n";
echo "NO_SEND_SIDE_EFFECTS=PASS\n";
