<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';
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
$context = [
    'tenant_id' => 2,
    'report_id' => 526,
    'report_version' => 5,
    'estudo_id' => 1,
    'destination_id' => 7,
    'ambiente' => 'producao',
    'transport' => 'philips_non_dicom',
    'delivery_profile' => 'submission_document',
    'dispatch_mode' => 'automatic_production',
];
$lookup = new class implements PhilipsSubmissionAuthorLookup {
    public ?array $versionSigner = [
        'id' => 4,
        'tenant_id' => 2,
        'nome' => 'Hugo Willian F. de Barros',
        'ativo' => 1,
    ];
    public function findSignerForReportVersion(int $tenantId, int $reportId, int $reportVersion, int $studyId): ?array
    {
        return $this->versionSigner;
    }
};
$resolver = new PhilipsSubmissionAuthorResolver($lookup);
$payload = [
    'referring_physician_name' => 'Solicitante Médico',
    'philips_submission' => [
        'task_author_id' => '999',
        'task_author_source' => 'default',
        'task_author_humanname_family' => 'Legacy Default',
    ],
];
$resolved = $resolver->resolve($payload, [], $context, [
    'task_author_id' => '999',
    'task_author_humanname_family' => 'Solicitante Médico',
]);
$expect($resolved['author_source'] === PhilipsSubmissionAuthorResolver::SOURCE_SIGNER, 'Signer must be the only author source');
$expect($resolved['author_decision'] === PhilipsSubmissionAuthorResolver::DECISION_REAL_AUTHOR_AVAILABLE, 'Signer must be a real-author decision');
$expect($resolved['task_author_id_resolution'] === 'SIGNER_ID', 'Signer ID must be classified');
$expect($resolved['task_author_id'] === '4', 'Signer ID must come from the signed version');
$expect($resolved['task_author_humanname_given'] === 'Hugo', 'First token must be author given');
$expect($resolved['task_author_humanname_family'] === 'Willian F. de Barros', 'Remaining tokens must be author family');
$expect($resolved['task_author_humanname_middle'] === '', 'Author middle must remain empty');
$expect($resolved['author_humanname_flat'] === false, 'Multi-token author must be structured');
$queuedLegacy = $resolver->resolve(array_replace($payload, [
    'status' => 'queued',
    'philips_submission' => [
        'task_author_source' => 'default',
        'task_author_id' => '999',
    ],
]), [], $context, []);
$expect($queuedLegacy['task_author_id'] === '4', 'Queued legacy job must resolve the version signer, not configured author');
$expect($queuedLegacy['task_author_humanname_given'] === 'Hugo', 'Signer must remain distinct from referring physician');
$releasedByOtherUser = $resolver->resolve(array_replace($payload, [
    'released_by' => 'different-user',
    'status' => 'queued',
]), [], array_replace($context, ['report_version' => 8]), []);
$expect($releasedByOtherUser['task_author_id'] === '4', 'Release actor must not replace the signed-version author');
$expect($releasedByOtherUser['author_source'] === PhilipsSubmissionAuthorResolver::SOURCE_SIGNER, 'Release actor must not become the Philips author');
$lookup->versionSigner['ativo'] = 0;
$inactiveSigner = $resolver->resolve($payload, [], $context, []);
$expect($inactiveSigner['author_decision'] === PhilipsSubmissionAuthorResolver::DECISION_REAL_AUTHOR_AVAILABLE, 'Inactive registry must not rewrite historical signer');
$expect($inactiveSigner['task_author_id'] === '4', 'Inactive registry must preserve real signer ID');
$frozenPayload = [
    'author_signer_id' => 4,
    'author_signer_tenant_id' => 2,
    'author_signer_report_id' => 526,
    'author_signer_report_version' => 5,
    'author_signer_study_id' => 1,
    'author_signer_name' => 'Álvaro da Silva',
];
$frozen = $resolver->resolve($frozenPayload, [], $context, []);
$expect($frozen['task_author_id'] === '4', 'Frozen signer display identity must retain the canonical signer ID');
$expect($frozen['task_author_humanname_given'] === 'Álvaro', 'Frozen signer name must preserve accents');
$expect($frozen['task_author_humanname_family'] === 'da Silva', 'Frozen signer particles must remain in family');
$single = $resolver->resolve([
    'author_signer_id' => 4,
    'author_signer_tenant_id' => 2,
    'author_signer_report_id' => 526,
    'author_signer_report_version' => 5,
    'author_signer_study_id' => 1,
    'author_signer_name' => 'Madonna',
], [], $context, []);
$expect($single['task_author_humanname_family'] === 'Madonna', 'Single-token author must remain family');
$expect($single['task_author_humanname_given'] === '', 'Single-token author given must be empty');
$expect($single['author_humanname_flat'] === true, 'Single-token author must use flat contract');
$mismatchedFrozenId = $resolver->resolve([
    'author_signer_id' => 9,
    'author_signer_tenant_id' => 2,
    'author_signer_report_id' => 526,
    'author_signer_report_version' => 5,
    'author_signer_study_id' => 1,
    'author_signer_name' => 'Outro Médico',
], [], $context, []);
$expect($mismatchedFrozenId['author_decision'] === PhilipsSubmissionAuthorResolver::DECISION_AUTHOR_UNRESOLVED, 'Frozen ID different from signed version must fail closed');
$expect($mismatchedFrozenId['task_author_id'] === null, 'Mismatched frozen ID must not be emitted');
$wrongTenant = $resolver->resolve([
    'author_signer_id' => 9,
    'author_signer_tenant_id' => 3,
    'author_signer_report_id' => 526,
    'author_signer_report_version' => 5,
    'author_signer_study_id' => 1,
    'author_signer_name' => 'Other Tenant',
], [], $context, []);
$expect($wrongTenant['author_decision'] === PhilipsSubmissionAuthorResolver::DECISION_AUTHOR_UNRESOLVED, 'Cross-tenant frozen signer must fail closed');
$expect($wrongTenant['task_author_id'] === null, 'Cross-tenant signer must not leave an ID');
$lookup->versionSigner = null;
$missing = $resolver->resolve(['referring_physician_name' => 'Solicitante Médico'], [], $context, []);
$expect($missing['author_source'] === PhilipsSubmissionAuthorResolver::SOURCE_UNRESOLVED, 'Missing signer must remain unresolved');
$expect($missing['author_decision'] === PhilipsSubmissionAuthorResolver::DECISION_AUTHOR_UNRESOLVED, 'Missing signer must fail closed');
$expect($missing['task_author_id'] === null, 'Missing signer must not use configured ID');
$expect($missing['task_author_humanname_family'] === null, 'Missing signer must not use referring physician');
$documentInput = [
    'pdf_filename' => 'VOXEL_SYNTHETIC_526_V5.pdf',
    'task_patient_id' => 'SYNTH-PATIENT',
    'task_patient_humanname_family' => 'Patient',
    'task_patient_humanname_given' => 'Synthetic',
    'task_patient_humanname_middle' => '',
    'task_document_name' => 'SYNTHETIC REPORT',
    'task_document_date' => '20261006101112',
    'task_image_date' => '20261006101112',
    'task_file_path' => '/synthetic/inbox/VOXEL_SYNTHETIC_526_V5.pdf',
    'task_accession_number' => 'SYNTH-ACCESSION',
    'task_document_mimetype' => 'application/pdf',
    'task_patient_birthday' => '19800101',
    'task_patient_gender' => 'F',
    'task_site_id' => 'SYNTHETIC-SITE',
    'task_patient_issuer' => 'SYNTHETIC-ISSUER',
    'task_modalities' => 'CT',
    'task_delete_file' => false,
    'task_document_type_applicable' => true,
    'task_document_type' => '11502-2',
];
$xml = (new PhilipsSubmissionDocumentGenerator())->generate(
    array_replace($documentInput, $frozen),
    $context
);
$encodedGiven = mb_convert_encoding('Álvaro', 'ISO-8859-1', 'UTF-8');
$expect(str_contains($xml->content, 'encoding="iso-8859-1"'), 'XML must retain ISO-8859-1 declaration');
$expect(str_contains($xml->content, '<task_author_id>4</task_author_id>'), 'XML must carry signer ID');
$expect(str_contains($xml->content, '<task_author_humanname_given>' . $encodedGiven . '</task_author_humanname_given>'), 'XML must carry accented given name');
$expect(!str_contains($xml->content, '999'), 'XML must never carry the default author ID');
$source = file_get_contents($root . '/app/Services/PhilipsSubmissionAuthorResolver.php');
$expect(is_string($source), 'AuthorResolver source must be readable');
$expect(!str_contains($source, 'DEFAULT_999'), 'DEFAULT_999 must be removed');
$expect(!str_contains($source, "'task_author_source' => 'default'"), 'Default source must be removed');
echo "PHILIPS_SUBMISSION_AUTHOR_RESOLVER_STATIC_OK\n";
echo "SIGNER_SOURCE=PASS\n";
echo "SIGNER_ID=PASS\n";
echo "AUTHOR_DISTINCT_FROM_REQUESTER=PASS\n";
echo "QUEUED_LEGACY_JOB=PASS\n";
echo "RELEASE_ACTOR_NOT_AUTHOR=PASS\n";
echo "SIGNER_NAME_RULE=PASS\n";
echo "TENANT_SCOPE=PASS\n";
echo "CROSS_TENANT_FAIL_CLOSED=PASS\n";
echo "INACTIVE_SIGNER_PRESERVED=PASS\n";
echo "SINGLE_TOKEN_NAME=PASS\n";
echo "PARTICLES_ACCENTS=PASS\n";
echo "FROZEN_ID_MUST_MATCH_SIGNER=PASS\n";
echo "MISSING_SIGNER_FAIL_CLOSED=PASS\n";
echo "DEFAULT_999_REMOVED=PASS\n";
echo "ISO_8859_1=PASS\n";
