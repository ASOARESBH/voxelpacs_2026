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

$diagnosticPath = $root . '/bin/philips_job_522_readonly_audit.php';
$helperPath = $root . '/ops/deploy/voxelpacs-philips-job-522-readonly-audit';
$provisionerPath = $root . '/scripts/provision-philips-job-522-readonly-audit.sh';
$sudoersPath = $root . '/ops/sudoers/voxelpacs-philips-job-522-readonly-audit';

$diagnostic = file_get_contents($diagnosticPath);
$helper = file_get_contents($helperPath);
$provisioner = file_get_contents($provisionerPath);
$sudoers = file_get_contents($sudoersPath);
$expect(is_string($diagnostic) && $diagnostic !== '', 'diagnóstico ausente');
$expect(is_string($helper) && $helper !== '', 'helper ausente');
$expect(is_string($provisioner) && $provisioner !== '', 'provisionador ausente');
$expect(is_string($sudoers) && $sudoers !== '', 'sudoers ausente');

foreach ([
    "private const TENANT_ID = 2",
    "private const JOB_ID = 522",
    "private const DESTINATION_ID = 7",
    "'philips_non_dicom'",
    "'submission_document'",
    "'automatic_production'",
    "SET TRANSACTION READ ONLY",
    "pacs_report_delivery_jobs",
    "pacs_report_delivery_outbox",
    "pacs_report_delivery_destinations",
    "pacs_report_delivery_attempts",
    "bi_medicos",
    "AND tenant_id = :tenant_id",
    "AND ativo = 1",
    "job_claimed",
    "database_changed",
    "transmission",
    "rollBack",
] as $marker) {
    $expect(str_contains($diagnostic, $marker), "diagnóstico sem contrato: {$marker}");
}

foreach ([
    'INSERT INTO', 'UPDATE ', 'DELETE FROM', 'ALTER TABLE', 'DROP TABLE', 'TRUNCATE ',
    'shell_exec(', 'system(', 'passthru(', 'popen(', 'smbclient', 'curl ', 'scp ', 'rsync ',
    'systemctl start', 'systemctl stop', 'systemctl restart', 'systemctl enable',
] as $forbidden) {
    $expect(!str_contains(strtolower($diagnostic), strtolower($forbidden)), "diagnóstico contém operação proibida: {$forbidden}");
}
$expect(!str_contains($diagnostic, "'patient_name'"), 'diagnóstico não pode retornar nome do paciente');
$expect(str_contains($diagnostic, "'name_materialized' => 'NO'"), 'diagnóstico não comprova que nome do autor não foi materializado');
$expect(str_contains($diagnostic, "if (\$source !== 'bi_medicos'"), 'fonte de autor não é fail-closed');
$expect(str_contains($diagnostic, "\$this->dispatchMode(\$job) !== 'automatic_production'"), 'lookup não está limitado ao modo automático');

foreach ([
    "readonly DIAGNOSTIC='/usr/local/libexec/voxelpacs/philips_job_522_readonly_audit.php'",
    "[[ \"\$#\" -eq 0 ]]",
    "exec /usr/bin/php \"\$DIAGNOSTIC\"",
    "AUDIT=BLOCKED",
] as $marker) {
    $expect(str_contains($helper, $marker), "helper sem guard: {$marker}");
}
foreach (['bash -c', 'sh -c', 'systemctl start', 'systemctl restart', 'smbclient', 'mysql ', 'psql '] as $forbidden) {
    $expect(!str_contains(strtolower($helper), strtolower($forbidden)), "helper contém comando proibido: {$forbidden}");
}

foreach ([
    "[[ \"\$#\" -eq 2 && \"\$1\" == '--expected-sha' ]]",
    'GIT_SHA_MISMATCH',
    'WORKTREE_NOT_CLEAN',
    'DIAGNOSTIC_SYNTAX_INVALID',
    'visudo -cf',
    'DIAGNOSTIC_INSTALLED=YES',
    'EXECUTION=NOT_PERFORMED',
    'DATABASE_CHANGED=NO',
] as $marker) {
    $expect(str_contains($provisioner, $marker), "provisionador sem guard: {$marker}");
}
$expect(!str_contains($provisioner, 'composer install'), 'provisionador não executa Composer');
$expect(!str_contains($provisioner, 'systemctl restart'), 'provisionador não reinicia serviço');

$lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $sudoers) ?: []), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));
$expect(count($lines) === 1, 'sudoers deve conter exatamente uma regra');
$expect(str_contains($sudoers, 'manus-admin ALL=(root) NOPASSWD: /usr/local/sbin/voxelpacs-philips-job-522-readonly-audit'), 'regra manus-admin ausente');
$expect(!preg_match('/NOPASSWD:\s+ALL/i', $sudoers), 'sudoers não pode conceder ALL');

require_once $root . '/app/autoload.php';
define('PHILIPS_JOB_522_READONLY_AUDIT_LIBRARY', true);
require_once $diagnosticPath;
$class = '\\App\\Diagnostics\\PhilipsJob522ReadonlyAudit';
$expect(class_exists($class), 'classe do diagnóstico não carrega');
$sanitized = $class::sanitize([
    'safe' => 'PASS',
    'patient_name' => 'not-output',
    'payload_json' => ['clinical' => 'not-output'],
    'nested' => ['nome' => 'not-output', 'status' => 'PASS'],
    'database_changed' => 'YES',
]);
$expect(!array_key_exists('patient_name', $sanitized), 'sanitização deixou chave clínica');
$expect(!array_key_exists('payload_json', $sanitized), 'sanitização deixou payload');
$expect(!array_key_exists('nome', $sanitized['nested'] ?? []), 'sanitização deixou nome');
$expect(($sanitized['database_changed'] ?? 'YES') === 'NO', 'sanitização não força banco inalterado');
$expect(($sanitized['transmission'] ?? 'YES') === 'NO', 'sanitização não força ausência de transmissão');

fwrite(STDOUT, "philips_job_522_readonly_audit_static: PASS\n");
