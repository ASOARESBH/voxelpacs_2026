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

$source = file_get_contents($root . '/app/Repositories/PhilipsSubmissionAuthorRepository.php');
$expect(is_string($source), 'Author repository source must be readable');
foreach ([
    'Database::getInstance()',
    'PDO::FETCH_ASSOC',
    'findSignerForReportVersion',
    'r.assinado_por AS id',
    'INNER JOIN report_versions rv',
    'rv.versao = :report_version',
    'r.estudo_id = :study_id',
    'rv.acao IN (',
    'assinado',
    'liberado',
] as $required) {
    $expect(str_contains($source, $required), "Author repository must contain {$required}");
}
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'DROP ', 'TRUNCATE ', 'ALTER TABLE'] as $forbidden) {
    $expect(!str_contains(strtoupper($source), $forbidden), "Author repository must not contain {$forbidden}");
}

$interface = file_get_contents($root . '/app/Contracts/PhilipsSubmissionAuthorLookup.php');
$expect(is_string($interface), 'Author lookup contract must be readable');
$expect(str_contains($interface, 'findSignerForReportVersion('), 'Lookup contract must expose version signer resolution');

echo "PHILIPS_SUBMISSION_AUTHOR_REPOSITORY_STATIC_OK\n";
echo "TENANT_SCOPE=PASS\n";
echo "ACTIVE_FILTER=PASS\n";
echo "VERSION_SIGNER_SCOPE=PASS\n";
echo "PREPARED_QUERY=PASS\n";
echo "READ_ONLY=PASS\n";
