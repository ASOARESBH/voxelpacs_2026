<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$scriptPath = $root . '/scripts/publish-philips-folder-bridge.sh';
$script = file_get_contents($scriptPath);

function expect_bridge_publisher(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

expect_bridge_publisher(is_string($script), 'Publisher da Bridge ausente');

foreach ([
    "EXPECTED_HOST='gateway-dicom-01'",
    'RUNTIME_FILE="$RUNTIME_DIR/philips_folder_bridge.py"',
    "BRIDGE_UNIT='voxelpacs-philips-folder-bridge.service'",
    "BACKUP_ROOT='/var/backups/voxelpacs/philips-folder-bridge'",
    "REPOSITORY='https://raw.githubusercontent.com/ASOARESBH/voxelpacs_2026'",
    "SOURCE_PATH='deploy/report-delivery-gateway-bridge/philips_folder_bridge.py'",
    "LOCK_PATH='/run/lock/voxelpacs-philips-folder-bridge-publish.lock'",
    'flock -n "$lock_fd"',
    'stat -c \'%u:%g:%a\' "$BACKUP_ROOT"',
    'is_sha "$source_sha"',
    'is_sha256 "$source_sha256"',
    '--dry-run',
    '--apply',
    '--rollback-sha',
    'SOURCE_CHECKSUM_MISMATCH',
    'INSTALLED_CHECKSUM_MISMATCH',
    'systemctl restart "$BRIDGE_UNIT"',
    'systemctl show -p MainPID --value "$BRIDGE_UNIT"',
    'ps -p "$bridge_pid" -o args=',
    'BRIDGE_MAINPID_BINDING_INVALID',
    'DICOM_CSTORE=NOT_CHANGED',
    'SMB=NOT_EXECUTED',
    'TRANSMISSION=NO',
] as $needle) {
    expect_bridge_publisher(str_contains($script, $needle), "Contrato ausente: {$needle}");
}

foreach (['scp ', 'rsync ', 'smbclient', 'DROP ', 'TRUNCATE ', 'INSERT ', 'UPDATE ', 'PHILIPS_FOLDER_ALLOW_JOB_ID', 'X-VOXEL-', '.env'] as $forbidden) {
    expect_bridge_publisher(!str_contains($script, $forbidden), "Publisher contém escopo proibido: {$forbidden}");
}

expect_bridge_publisher(substr_count($script, 'systemctl restart "$BRIDGE_UNIT"') === 1, 'Somente a unidade Philips pode ser reiniciada');
expect_bridge_publisher(!str_contains($script, 'pgrep -fc'), 'Publisher não pode contar o próprio comando de busca');
expect_bridge_publisher(str_contains($script, 'BRIDGE_POLICY_CHANGED=NO'), 'Publisher não declara policy inalterada');
expect_bridge_publisher(str_contains($script, 'ast.parse'), 'Publisher não valida sintaxe Python antes da publicação');

fwrite(STDOUT, "PHILIPS_FOLDER_BRIDGE_PUBLISHER_STATIC_OK\n");
