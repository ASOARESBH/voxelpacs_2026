<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$helper = (string) file_get_contents($root . '/scripts/release_backup.sh');
$provision = (string) file_get_contents($root . '/scripts/provision-release-backup.sh');
$sudoers = (string) file_get_contents($root . '/ops/sudoers/voxelpacs-release-backup');
$docs = (string) file_get_contents($root . '/ops/deploy/README_ROOT_ONLY_RELEASE_BACKUP.md');
$test = (string) file_get_contents($root . '/tests/deploy/release_backup_isolated.sh');

$expect($helper !== '', 'helper de backup ausente');
$expect(str_contains($helper, "readonly APP_ROOT='/var/www/voxelpacs/app'"), 'APP_ROOT fixo ausente');
$expect(str_contains($helper, "readonly BACKUP_ROOT='/var/backups/voxelpacs/releases'"), 'BACKUP_ROOT fixo ausente');
$expect(str_contains($helper, "readonly RESTORE_TEST_ROOT='/var/backups/voxelpacs/restore-tests'"), 'RESTORE_TEST_ROOT fixo ausente');
$expect(str_contains($helper, "readonly LOCK_PATH='/run/lock/voxelpacs-release-backup.lock'"), 'lock fixo ausente');
$expect(str_contains($helper, '[[ "$EUID" -eq 0 ]]'), 'helper não exige root');
$expect(str_contains($helper, 'manus-admin|manus-deploy'), 'caller allowlisted ausente');
$expect(str_contains($helper, '[[ "$#" -eq 3'), 'contrato de três argumentos ausente');
$expect(str_contains($helper, '"$1" == \'create\' || "$1" == \'validate\' || "$1" == \'restore-test\''), 'operação desconhecida não é rejeitada');
$expect(str_contains($helper, "[[ \"\$path\" != '../'*"), 'proteção de traversal ausente');
foreach (['.env|.env.*', 'storage|storage/*', 'logs|logs/*', 'backups|backups/*', 'public/uploads|public/uploads/*'] as $marker) {
    $expect(str_contains($helper, $marker), "path persistente/segredo não bloqueado: {$marker}");
}
$expect(str_contains($helper, 'find -P'), 'find não rejeita symlink');
$expect(str_contains($helper, '--no-recursion'), 'tar sem no-recursion ausente');
$expect(str_contains($helper, 'gzip -n -9'), 'gzip não é determinístico');
$expect(str_contains($helper, 'sha256sum'), 'SHA-256 ausente');
$expect(str_contains($helper, 'PERSISTENT_STATE_CHANGED'), 'guard de persistência ausente');
foreach (['report_delivery_state', 'logs_state', 'backups_state', 'legacy_state'] as $marker) {
    $expect(str_contains($helper, $marker), "estado persistente ausente: {$marker}");
}
$expect(str_contains($helper, 'mv -- "$temp_dir" "$backup_dir"'), 'publicação atômica ausente');
$expect(str_contains($helper, 'RESTORE_TEST=PASS'), 'restore-test ausente');
$expect(!str_contains($helper, 'eval '), 'helper não pode usar eval');
$expect(!str_contains($helper, 'systemctl'), 'helper não pode reiniciar serviço');
$expect(!str_contains($helper, 'sudo '), 'helper não pode chamar sudo');
$expect(!str_contains($helper, 'bash -c'), 'helper não pode abrir shell');
$expect(!str_contains($helper, 'rm -rf -- "$APP_ROOT"'), 'helper não pode remover APP_ROOT');

$expect(str_contains($provision, '--expected-sha'), 'provisionador não exige SHA');
$expect(str_contains($provision, 'WORKTREE_NOT_CLEAN'), 'provisionador não exige árvore limpa');
$expect(str_contains($provision, 'visudo -cf'), 'provisionador não valida sudoers');
$expect(str_contains($provision, 'install -o root -g root -m 0755'), 'helper não é instalado root-owned');
$expect(str_contains($provision, 'install -o root -g root -m 0440'), 'sudoers não é instalado restrito');
$expect(!str_contains($provision, 'NOPASSWD: ALL'), 'provisionador contém sudo genérico');
$expect(!str_contains($provision, 'APP_ROOT='), 'provisionador não deve receber APP_ROOT');

$rules = array_values(array_filter(explode("\n", $sudoers), static fn (string $line): bool => str_starts_with($line, 'manus-admin ')));
$expect(count($rules) === 3, 'sudoers não contém exatamente três operações');
foreach ($rules as $rule) {
    $expect((bool) preg_match('/--sha (\?+)$/', $rule, $match), 'sudoers não tem SHA bounded');
    $expect(strlen($match[1]) === 40, 'sudoers não limita SHA a 40 posições');
}
$expect(!str_contains($sudoers, 'NOPASSWD: ALL'), 'sudoers contém ALL genérico');
foreach (['/bin/sh', '/bin/bash', '/usr/bin/cp', '/usr/bin/rm', '/usr/bin/rsync', '/usr/bin/systemctl'] as $forbidden) {
    $expect(!str_contains($sudoers, $forbidden), "sudoers contém comando proibido: {$forbidden}");
}

foreach (['BACKUP_ROOT', 'RESTORE_TEST_ROOT', 'source-sha', 'manifest', 'checksum', 'restore-test', 'storage', '.env', 'visudo', 'PRODUCTION_DEPLOYED=NO'] as $marker) {
    $expect(str_contains($docs, $marker), "documentação incompleta: {$marker}");
}
foreach (['SHA_INVALID', 'ARGUMENTS_INVALID', 'RESTORE_HASH_MISMATCH', 'BACKUP_MANIFEST_ARCHIVE_MISMATCH', 'BACKUP_LOCK_BUSY', 'SUDOERS_BOUNDED_SHA'] as $marker) {
    $expect(str_contains($test, $marker), "teste isolado incompleto: {$marker}");
}

fwrite(STDOUT, "release_backup_static: PASS\n");
