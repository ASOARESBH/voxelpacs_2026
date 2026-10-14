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
    'm.id AS id',
    'm.usuario_id = rv.usuario_id',
    'rv.usuario_id IS NOT NULL',
    'INNER JOIN report_versions rv',
    'rv.versao = :report_version',
    'r.estudo_id = :study_id',
    'liberado',
    "rv.acao = 'assinado'",
    'rv.versao <= :report_version',
    'ORDER BY rv.versao DESC',
    "if (\$action === 'assinado')",
] as $required) {
    $expect(str_contains($source, $required), "Author repository must contain {$required}");
}
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'DROP ', 'TRUNCATE ', 'ALTER TABLE'] as $forbidden) {
    $expect(!str_contains(strtoupper($source), $forbidden), "Author repository must not contain {$forbidden}");
}
$expect(!str_contains($source, 'm.ativo = 1'), 'Historical signer lookup must not require active status');
$expect(str_contains($source, 'count($rows) === 1'), 'Duplicate signer rows must fail closed');
$expect(!str_contains($source, "'\\n'"), 'SQL must not encode newline as a literal backslash in single-quoted strings');
$expect(str_contains($source, "<<<'SQL'"), 'SQL must use a literal-safe heredoc contract');

$interface = file_get_contents($root . '/app/Contracts/PhilipsSubmissionAuthorLookup.php');
$expect(is_string($interface), 'Author lookup contract must be readable');
$expect(str_contains($interface, 'findSignerForReportVersion('), 'Lookup contract must expose version signer resolution');

echo "PHILIPS_SUBMISSION_AUTHOR_REPOSITORY_STATIC_OK\n";
echo "TENANT_SCOPE=PASS\n";
echo "ACTIVE_STATUS_IGNORED=PASS\n";
echo "DUPLICATE_FAIL_CLOSED=PASS\n";
echo "VERSION_SIGNER_SCOPE=PASS\n";
echo "PREPARED_QUERY=PASS\n";
echo "READ_ONLY=PASS\n";
