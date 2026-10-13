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
$authorResolver = file_get_contents($root . '/app/Services/PhilipsSubmissionAuthorResolver.php');
$metadataResolver = file_get_contents($root . '/app/Services/PhilipsSubmissionMetadataResolver.php');
$generator = file_get_contents($root . '/app/Services/PhilipsSubmissionDocumentGenerator.php');
$outbox = file_get_contents($root . '/app/Services/ReportDeliveryOutboxService.php');
$contract = file_get_contents($root . '/docs/PHILIPS_SUBMISSION_DOCUMENT_CONTRACT.md');
foreach ([$controller, $view, $producer, $authorResolver, $metadataResolver, $generator, $outbox, $contract] as $source) {
    expect_profile(is_string($source), 'Submission source must be readable');
}
foreach (['PROFILE_SUBMISSION_DOCUMENT', 'validatePhilipsSubmissionConfiguration', 'task_file_path', 'task_site_id', 'task_document_name', 'task_delete_file', 'task_document_type_applicable', '11502-2'] as $marker) {
    expect_profile(str_contains($controller, $marker), "Controller must validate {$marker}");
}
expect_profile(!str_contains($controller, 'allowDefaultAuthor'), 'Controller must not expose a default-author permission');
expect_profile(!str_contains($controller, "'task_author_id' => 'identificador"), 'Controller must not require configured author ID');
expect_profile(str_contains($controller, 'A autoria Philips é resolvida pelo signatário'), 'Controller must explain signer-only authorship');
expect_profile(!str_contains($view, 'nondicom-task-author-source'), 'View must not expose author source selector');
expect_profile(!str_contains($view, 'nondicom-task-author-id'), 'View must not expose author ID input');
expect_profile(!str_contains($view, 'syncTaskAuthorSource'), 'View must not retain default-author JavaScript');
expect_profile(str_contains($view, 'médico que assinou a versão liberada'), 'View must explain signer source');
expect_profile(str_contains($producer, 'PhilipsSubmissionAuthorResolver'), 'Producer must use dedicated author resolver');
expect_profile(str_contains($producer, 'resolveForNoSendDiagnostic'), 'Producer must isolate no-send validation path');
expect_profile(str_contains($authorResolver, 'findSignerForReportVersion'), 'Resolver must consult the exact signed version');
expect_profile(str_contains($authorResolver, 'author_signer_id'), 'Resolver must accept frozen signer identity');
expect_profile(str_contains($authorResolver, 'SIGNER_ID'), 'Resolver must classify real signer ID');
expect_profile(str_contains($authorResolver, 'preg_split'), 'Resolver must apply token rule to signer names');
expect_profile(!str_contains($authorResolver, 'DEFAULT_999'), 'Resolver must remove DEFAULT_999');
expect_profile(!str_contains($authorResolver, "task_author_source' => 'default"), 'Resolver must remove default author source');
expect_profile(!str_contains($metadataResolver, 'referringPhysicianRaw'), 'Metadata resolver must not derive author from referring physician');
expect_profile(str_contains($metadataResolver, 'studyDocumentDate'), 'Metadata resolver must derive date from StudyDate/StudyTime');
expect_profile(str_contains($generator, 'encoding="iso-8859-1"'), 'Generator must preserve ISO-8859-1 contract');
expect_profile(str_contains($outbox, 'author_signer_id'), 'Outbox must freeze signer identity for new automatic jobs');
expect_profile(str_contains($outbox, 'r.assinado_por'), 'Outbox signer snapshot must use report signatory');
expect_profile(str_contains($contract, 'signatário'), 'Contract must document signer semantics');
expect_profile(str_contains($contract, 'ReferringPhysicianName'), 'Contract must distinguish requesting physician');
expect_profile(str_contains($contract, 'task_image_date'), 'Contract must document image date');
echo "REPORT_DELIVERY_SUBMISSION_PROFILE_STATIC_OK\n";
echo "SIGNER_ONLY_CONFIGURATION=PASS\n";
echo "DEFAULT_999_REMOVED=PASS\n";
echo "REFERRING_PHYSICIAN_NOT_AUTHOR=PASS\n";
echo "OUTBOX_SIGNER_FREEZE=PASS\n";
echo "XML_CONTRACT=PASS\n";
