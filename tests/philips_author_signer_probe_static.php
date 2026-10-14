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

$probe = file_get_contents($root . '/bin/philips_author_signer_probe.php');
$expect(is_string($probe), 'Signer probe source must be readable');
foreach ([
    "--read-only",
    'Database::getInstance()',
    'SET TRANSACTION READ ONLY',
    'rollBack()',
    'PhilipsSubmissionAuthorRepository',
    'PhilipsSubmissionAuthorResolver',
    'resolveForNoSendDiagnostic',
    'SIGNER_PATH',
    'SIGNER_FOUND',
    'DUPLICATE',
    'SQLSTATE',
    'DATABASE_CHANGED=NO',
    'PDO::FETCH_ASSOC',
    "rv.acao = 'assinado'",
    'm.tenant_id = r.tenant_id',
] as $required) {
    $expect(str_contains($probe, $required), "Signer probe must contain {$required}");
}
foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'DROP ', 'TRUNCATE ', 'ALTER TABLE', 'file_put_contents', 'smbclient', 'curl_exec', 'echo $payload', 'print_r($job'] as $forbidden) {
    $expect(!str_contains(strtoupper($probe), strtoupper($forbidden)), "Signer probe must not contain {$forbidden}");
}
$expect(str_contains($probe, "'SELF'") && str_contains($probe, "'PRIOR_ASSINADO'"), 'Signer paths must be explicit');
$expect(str_contains($probe, "'SIGNER_ID'"), 'Probe must classify a real signer ID without printing its value');
$expect(str_contains($probe, "'REAL'"), 'Probe must classify real signer identity');

fwrite(STDOUT, "PHILIPS_AUTHOR_SIGNER_PROBE_STATIC_OK\n");
fwrite(STDOUT, "READ_ONLY=PASS\n");
fwrite(STDOUT, "ROLLBACK=PASS\n");
fwrite(STDOUT, "SIGNER_PATHS=PASS\n");
fwrite(STDOUT, "NO_PHI_OUTPUT=PASS\n");
