<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';

use App\Repositories\ReportDeliveryWorkerRepository;

function expect_completion_guard(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$validIdentity = str_repeat('a', 64);

expect_completion_guard(
    !ReportDeliveryWorkerRepository::completionMetadataAllows('submission_document', []),
    'submission_document sem metadata não pode completar'
);
expect_completion_guard(
    !ReportDeliveryWorkerRepository::completionMetadataAllows('submission_document', [
        'package_verified' => 'FAIL',
        'package_identity' => $validIdentity,
    ]),
    'package_verified diferente de PASS deve bloquear'
);
expect_completion_guard(
    !ReportDeliveryWorkerRepository::completionMetadataAllows('submission_document', [
        'package_verified' => 'PASS',
        'package_identity' => 'not-a-sha256',
    ]),
    'package_identity inválida deve bloquear'
);
expect_completion_guard(
    ReportDeliveryWorkerRepository::completionMetadataAllows('submission_document', [
        'package_verified' => 'PASS',
        'package_identity' => $validIdentity,
    ]),
    'package válido deve permitir conclusão'
);
expect_completion_guard(
    ReportDeliveryWorkerRepository::completionMetadataAllows('pdf_only', []),
    'pdf_only deve permanecer compatível'
);
expect_completion_guard(
    ReportDeliveryWorkerRepository::completionMetadataAllows('', []),
    'profile legado NULL deve permanecer compatível'
);

$repository = (string) file_get_contents($root . '/app/Repositories/ReportDeliveryWorkerRepository.php');
$worker = (string) file_get_contents($root . '/bin/report_delivery_worker.php');
$controller = (string) file_get_contents($root . '/app/Controllers/ReportDeliveryWorkerController.php');
$routes = (string) file_get_contents($root . '/routes/web.php');

expect_completion_guard(str_contains($repository, 'completionMetadataAllows'), 'guard não está no Repository compartilhado');
expect_completion_guard(str_contains($repository, "SET status = 'delivered'"), 'persistência de delivered não foi localizada');
expect_completion_guard(str_contains($repository, '$this->pdo->rollBack();') && str_contains($repository, 'completionMetadataAllows'), 'rejeição do package deve fazer rollback transacional');
expect_completion_guard(str_contains($worker, "throw new DeliveryWorkerFailure('completion_not_confirmed')"), 'worker não trata rejeição de completeJob');
expect_completion_guard(str_contains($controller, 'completeJob(') && str_contains($controller, "'metadata'"), 'controller deve encaminhar metadata ao guard persistente');
expect_completion_guard(str_contains($controller, "'remote_reference'"), 'controller deve preservar a referência remota compatível');
expect_completion_guard(str_contains($routes, "'/api/report-delivery/jobs/{id}/complete'"), 'rota de complete deve permanecer explicitamente mapeada');

fwrite(STDOUT, "REPORT_DELIVERY_COMPLETION_GUARD_STATIC_OK\n");
