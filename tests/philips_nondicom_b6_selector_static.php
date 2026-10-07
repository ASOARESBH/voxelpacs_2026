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

$diagnosticPath = $root . '/bin/philips_nondicom_b6_selector.php';
$helperPath = $root . '/ops/deploy/voxelpacs-philips-nondicom-b6-selector';
$provisionerPath = $root . '/scripts/provision-philips-nondicom-b6-selector.sh';
$sudoersPath = $root . '/ops/sudoers/voxelpacs-philips-nondicom-b6-selector';
$diagnostic = file_get_contents($diagnosticPath);
$helper = file_get_contents($helperPath);
$provisioner = file_get_contents($provisionerPath);
$sudoers = file_get_contents($sudoersPath);
foreach ([$diagnostic, $helper, $provisioner, $sudoers] as $content) {
    $expect(is_string($content) && $content !== '', 'artefato B6.1 ausente ou vazio');
}

foreach ([
    'private const TENANT_ID = 2',
    'private const DESTINATION_ID = 7',
    'private const EXCLUDED_JOB_ID = 522',
    "private const TRANSPORT = 'philips_non_dicom'",
    "private const PROFILE = 'submission_document'",
    "private const DISPATCH_MODE = 'automatic_production'",
    "private const PR70_SHA = '1473783905d1ede4ef897fef909fdf6f8c29accf'",
    "'MODE' => 'single_test'",
    "'EXCLUDED_JOB_522' => 0",
    'SET TRANSACTION READ ONLY',
    'ORDER BY j.created_at ASC, j.id ASC',
    'array_key_exists(\'referring_physician_name\', $payload)',
    "'PR70_PROVENANCE' => 'NOT_PERSISTED'",
    "'PR70_PROVENANCE_NOTE' => 'OUTBOX_DOES_NOT_PERSIST_PRODUCER_SHA'",
    "'PAYLOAD_BEHAVIOR' => 'NOT_CONFIRMED'",
    'rollBack()',
    'CANDIDATE_FOUND',
    'READY_FOR_SINGLE_RUN',
    'PRODUCTION_DATABASE_ACCESSED',
    'RUNONE',
] as $marker) {
    $expect(str_contains($diagnostic, $marker), "diagnóstico sem contrato: {$marker}");
}
$expect(!str_contains($diagnostic, 'PR70_PAYLOAD_FIX'), 'diagnóstico manteve o contrato antigo de proveniência');

foreach ([
    'INSERT INTO',
    'UPDATE ',
    'DELETE FROM',
    'ALTER TABLE',
    'DROP TABLE',
    'TRUNCATE ',
    'claimJobById',
    'claimNextJob',
    'runOne(',
    'smbclient',
    'systemctl start',
    'systemctl stop',
    'systemctl restart',
    'systemctl enable',
    'shell_exec(',
    'passthru(',
    'proc_open(',
] as $forbidden) {
    $expect(!str_contains(strtolower($diagnostic), strtolower($forbidden)), "diagnóstico contém operação proibida: {$forbidden}");
}

$expect(!str_contains($diagnostic, "'patient_name'"), 'diagnóstico não pode retornar nome do paciente');
$expect(str_contains($diagnostic, "'DATABASE_CHANGED' => 'NO'"), 'sanitização deve fixar banco inalterado');
$expect(str_contains($diagnostic, "'TRANSMISSION' => 'NO'"), 'sanitização deve fixar transmissão ausente');
$expect(str_contains($helper, '[[ "$#" -eq 0 ]]'), 'helper deve rejeitar argumentos');
$expect(str_contains($helper, 'EXPECTED_HOST'), 'helper deve validar host');
$expect(str_contains($helper, 'DIAGNOSTIC_NOT_ROOT_ONLY'), 'helper deve validar ownership/mode');
$expect(str_contains($provisioner, 'WORKTREE_NOT_CLEAN'), 'provisionador deve exigir checkout limpo');
$expect(str_contains($provisioner, 'GIT_SHA_MISMATCH'), 'provisionador deve exigir SHA exata');
$expect(str_contains($provisioner, 'INSTALL=NOT_EXECUTED'), 'dry-run deve não instalar');
$expect(str_contains($provisioner, 'PRODUCTION_CHANGED=NO'), 'dry-run deve não alterar produção');
$expect(str_contains($sudoers, 'manus-admin ALL=(root) NOPASSWD: /usr/local/sbin/voxelpacs-philips-nondicom-b6-selector'), 'sudoers mínimo ausente');
$expect(!preg_match('/NOPASSWD:\s+ALL/i', $sudoers), 'sudoers não pode conceder ALL');

require_once $root . '/app/autoload.php';
define('PHILIPS_NON_DICOM_B6_SELECTOR_LIBRARY', true);
require_once $diagnosticPath;
$class = '\\App\\Diagnostics\\PhilipsNonDicomB6Selector';
$expect(class_exists($class), 'classe B6.1 não carrega');
$expect($class::payloadBehavior(['referring_physician_name' => 'synthetic']) === 'CONFIRMED', 'payload com referring_physician_name não foi confirmado');
$expect($class::payloadBehavior([]) === 'NOT_CONFIRMED', 'payload sem referring_physician_name foi aceito');
$expect($class::payloadBehavior(null) === 'NOT_CONFIRMED', 'payload não parseável foi aceito');
$expect($class::classifyProvenance(null) === 'NOT_PERSISTED', 'ausência de SHA não foi classificada como NOT_PERSISTED');
$expect($class::classifyProvenance('1473783905d1ede4ef897fef909fdf6f8c29accf') === 'CONFIRMED', 'SHA formal correta não foi confirmada');
$expect($class::classifyProvenance('0000000000000000000000000000000000000000') === 'NOT_CONFIRMED', 'SHA formal divergente foi aceita');
$sanitized = $class::sanitize([
    'CANDIDATE_FOUND' => 'NO',
    'EXCLUDED_PAYLOAD_REFERRING_PHYSICIAN' => 1,
    'candidate' => [
        'PAYLOAD_REFERRING_PHYSICIAN_PRESENT' => 'YES',
        'PAYLOAD_PARSE' => 'PASS',
        'PAYLOAD_BEHAVIOR' => 'CONFIRMED',
        'JOB_ID' => 531,
    ],
    'patient_name' => 'not-output',
    'payload_json' => ['clinical' => 'not-output'],
    'DATABASE_CHANGED' => 'YES',
    'PRODUCTION_DATABASE_ACCESSED' => 'NO',
    'RUNONE' => 'unexpected',
    'TRANSMISSION' => 'YES',
]);
$expect(!array_key_exists('patient_name', $sanitized), 'sanitização deixou nome clínico');
$expect(!array_key_exists('payload_json', $sanitized), 'sanitização deixou payload bruto');
$expect(($sanitized['candidate']['PAYLOAD_REFERRING_PHYSICIAN_PRESENT'] ?? '') === 'YES', 'presença técnica do campo foi removida');
$expect(($sanitized['candidate']['PAYLOAD_PARSE'] ?? '') === 'PASS', 'PAYLOAD_PARSE foi removido');
$expect(($sanitized['candidate']['PAYLOAD_BEHAVIOR'] ?? '') === 'CONFIRMED', 'PAYLOAD_BEHAVIOR foi removido');
$expect(($sanitized['EXCLUDED_PAYLOAD_REFERRING_PHYSICIAN'] ?? 0) === 1, 'agregado do blocker foi removido');
$expect(($sanitized['DATABASE_CHANGED'] ?? 'YES') === 'NO', 'sanitização não força banco inalterado');
$expect(($sanitized['PRODUCTION_DATABASE_ACCESSED'] ?? 'NO') === 'YES', 'sanitização não registra acesso read-only');
$expect(($sanitized['RUNONE'] ?? '') === 'NOT_EXECUTED', 'sanitização não força runOne ausente');
$expect(($sanitized['TRANSMISSION'] ?? 'YES') === 'NO', 'sanitização não força transmissão ausente');

fwrite(STDOUT, "PHILIPS_NON_DICOM_B6_SELECTOR_STATIC=PASS\n");
fwrite(STDOUT, "READ_ONLY=PASS\nNO_CLAIM=PASS\nNO_WORKER=PASS\nNO_BRIDGE=PASS\nNO_SMB=PASS\nNO_TRANSMISSION=PASS\nPR70_PROVENANCE_FAIL_CLOSED=PASS\n");
