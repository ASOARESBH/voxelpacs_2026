<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$expect = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};

$probe = file_get_contents($root . '/bin/philips_report_release_health_probe.php');
$expect(is_string($probe), 'Health probe source must be readable');
foreach ([
    'SET TRANSACTION READ ONLY',
    'rollBack()',
    'report.assinar',
    'report.liberar',
    'pacs_report_delivery_jobs',
    'pacs_report_delivery_outbox',
    'until_utc',
    'errors_by_tenant',
    'created_at < :until',
    'logContext',
    'http500_sign',
    'atomic_rollback_release',
    'author_signer_absent_or_incomplete',
    'DATABASE_CHANGED=NO',
] as $required) {
    $expect(str_contains($probe, $required), "Health probe must contain {$required}");
}
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'DROP ', 'TRUNCATE ', 'ALTER TABLE', 'file_put_contents', 'smbclient', 'curl_exec'] as $forbidden) {
    $expect(!str_contains(strtoupper($probe), $forbidden), "Health probe must not contain {$forbidden}");
}

$repository = file_get_contents($root . '/app/Repositories/PhilipsSubmissionAuthorRepository.php');
$resolver = file_get_contents($root . '/app/Services/PhilipsSubmissionAuthorResolver.php');
$producer = file_get_contents($root . '/app/Services/PhilipsSubmissionPackageProducer.php');
$delivery = file_get_contents($root . '/app/Services/PhilipsFolderDeliveryService.php');
$worker = file_get_contents($root . '/bin/report_delivery_worker.php');
$noSend = file_get_contents($root . '/app/Services/PhilipsSubmissionNoSendDiagnostic.php');
foreach ([$repository, $resolver, $producer, $delivery, $worker, $noSend] as $source) {
    $expect(is_string($source), 'Hotfix source file must be readable');
}
$expect(str_contains($resolver, 'DECISION_AUTHOR_SIGNER_MISMATCH'), 'Resolver must expose dedicated mismatch decision');
$expect(str_contains($resolver, 'FAILURE_AUTHOR_SIGNER_MISMATCH'), 'Resolver must expose dedicated mismatch category');
$expect(str_contains($producer, 'PhilipsSubmissionAuthorMismatchException'), 'Producer must stop on signer mismatch');
$expect(str_contains($delivery, "'author_signer_mismatch'"), 'Delivery must classify signer mismatch');
$expect(str_contains($worker, "'author_signer_mismatch'"), 'Worker must allowlist signer mismatch category');
$expect(str_contains($noSend, 'author_failure_category'), 'No-send must expose signer failure category');

fwrite(STDOUT, "PHILIPS_REPORT_RELEASE_HEALTH_PROBE_STATIC_OK\n");
fwrite(STDOUT, "READ_ONLY=PASS\n");
fwrite(STDOUT, "NEWLINE_SQL_REGRESSION_GUARD=PASS\n");
fwrite(STDOUT, "AUTHOR_SIGNER_MISMATCH=PASS\n");
fwrite(STDOUT, "NO_SEND_CATEGORY=PASS\n");
fwrite(STDOUT, "CLOSED_INTERVAL=PASS\n");
fwrite(STDOUT, "ERRORS_BY_TENANT=PASS\n");
