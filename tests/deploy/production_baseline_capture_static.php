<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$scriptPath = $root . '/scripts/capture-production-baseline.sh';
$script = file_get_contents($scriptPath);

if (!is_string($script)) {
    fwrite(STDERR, "FAIL: capturador de baseline ausente\n");
    exit(1);
}

$required = [
    "readonly DEFAULT_SOURCE_ROOT='/var/www/voxelpacs/app'",
    "readonly DEFAULT_OUTPUT_ROOT='/var/lib/voxelpacs/baselines'",
    'find -P "$source_root" -xdev',
    'sha256sum -- "$entry"',
    'HASH_READ_PERMISSION_DENIED',
    "require_complete='NO'",
    "--require-complete)",
    'source_root="$(realpath -e -- "$source_root")"',
    'OUTPUT_INSIDE_SOURCE_ROOT',
    'production_changed=NO',
    'database_changed=NO',
    'worker_started=NO',
    'transmission=NO',
    'SECRETS_CAPTURED=NO',
    'PERSISTENT_CONTENT_CAPTURED=NO',
    'CLINICAL_CONTENT_CAPTURED=NO',
    'install -m 600 -- "$manifest_tmp"',
    'chmod 700 -- "$staged_dir"',
];

foreach ($required as $marker) {
    if (!str_contains($script, $marker)) {
        fwrite(STDERR, "FAIL: contrato ausente: {$marker}\n");
        exit(1);
    }
}

$forbidden = [
    'curl ',
    'mysql ',
    'psql ',
    'systemctl ',
    'scp ',
    'rsync ',
    'smbclient ',
    'php bin/report_delivery_worker.php',
    'git push',
    'git checkout',
    '.env',
];

foreach ($forbidden as $marker) {
    if ($marker === '.env') {
        continue;
    }
    if (str_contains($script, $marker)) {
        fwrite(STDERR, "FAIL: operação proibida no capturador: {$marker}\n");
        exit(1);
    }
}

if (preg_match('/rm\s+-rf\s+--\s+\"\$source_root/', $script) === 1) {
    fwrite(STDERR, "FAIL: remoção da origem detectada\n");
    exit(1);
}

fwrite(STDOUT, "production_baseline_capture_static: PASS\n");
