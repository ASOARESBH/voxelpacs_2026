<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'package' => $root . '/app/Services/ReportDeliveryPackage.php',
    'producer' => $root . '/app/Services/PhilipsSubmissionPackageProducer.php',
    'exception' => $root . '/app/Services/PhilipsXmlFieldUnresolvedException.php',
    'outbox' => $root . '/app/Services/ReportDeliveryOutboxService.php',
    'repository' => $root . '/app/Repositories/ReportDeliveryRepository.php',
    'artifact' => $root . '/app/Services/ReportDeliveryArtifactService.php',
    'service' => $root . '/app/Services/PhilipsFolderDeliveryService.php',
    'postgres_migration' => $root . '/database/migrations/2026-09-14_report_delivery_package_profile_postgresql.sql',
    'mysql_migration' => $root . '/database/migrations/2026-09-14_report_delivery_package_profile_mysql.sql',
    'postgres_unique_migration' => $root . '/database/migrations/2026-09-14_report_delivery_profile_aware_job_unique_postgresql.sql',
    'mysql_unique_migration' => $root . '/database/migrations/2026-09-14_report_delivery_profile_aware_job_unique_mysql.sql',
];
foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "MISSING: {$name}\n");
        exit(1);
    }
}

$contents = array_map(static fn(string $path): string => (string) file_get_contents($path), $files);
$required = [
    ['package', 'class ReportDeliveryPackage', 'package object'],
    ['package', 'PhilipsSubmissionDocument $xmlDocument', 'linked XML'],
    ['exception', 'PHILIPS_XML_FIELD_UNRESOLVED', 'fail-closed XML contract'],
    ['producer', 'task_file_path', 'explicit path configuration'],
    ['outbox', 'createOutboxIfAbsent', 'outbox creation contract'],
    ['repository', 'delivery_profile', 'profile persistence'],
    ['artifact', 'storeGeneratedArtifact', 'immutable XML storage'],
    ['service', "PROFILE_SUBMISSION_DOCUMENT", 'explicit profile'],
    ['postgres_migration', 'ADD COLUMN IF NOT EXISTS delivery_profile', 'additive PostgreSQL schema'],
    ['mysql_migration', 'delivery_profile VARCHAR(40) NULL', 'additive MySQL schema'],
    ['postgres_unique_migration', "COALESCE(delivery_profile, 'pdf_only')", 'profile-aware PostgreSQL job identity'],
    ['mysql_unique_migration', 'GENERATED ALWAYS AS', 'profile-aware MySQL job identity'],
];
foreach ($required as [$file, $needle, $label]) {
    if (!str_contains($contents[$file], $needle)) {
        fwrite(STDERR, "MISSING_CONTRACT: {$label}\n");
        exit(1);
    }
}

foreach (['repository', 'outbox'] as $key) {
    if (!str_contains($contents[$key], 'tenant_id')) {
        fwrite(STDERR, "TENANT_SCOPE_MISSING: {$key}\n");
        exit(1);
    }
}

if (str_contains($contents['service'], 'smb' . 'client') || str_contains($contents['producer'], 'smb' . 'client')) {
    fwrite(STDERR, "PACKAGE_CODE_EXECUTES_SMB\n");
    exit(1);
}
if (str_contains($contents['mysql_migration'], 'DROP TABLE') || str_contains($contents['postgres_migration'], 'DROP TABLE')
    || str_contains($contents['mysql_unique_migration'], 'DROP TABLE') || str_contains($contents['postgres_unique_migration'], 'DROP TABLE')) {
    fwrite(STDERR, "DESTRUCTIVE_MIGRATION\n");
    exit(1);
}

echo "REPORT_DELIVERY_PACKAGE_STATIC_OK\n";
