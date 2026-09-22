<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$build = (string) file_get_contents($root . '/scripts/build-homolog.sh');
$workflow = (string) file_get_contents($root . '/SKILL-VOXEL-PACS/workflows/homologacao-versionamento.md');

$expect(str_contains($build, 'EXPECTED_BRANCH="versao/1.0"'), 'homolog build is branch restricted');
$expect(str_contains($build, 'ROOT_PROJECT_MUTATION=BLOCKED'), 'build declares root mutation protection');
$expect(str_contains($build, 'exec bash "$ROOT/scripts/build.sh"'), 'wrapper delegates to the official build via bash');
$expect(str_contains($workflow, '/home/ubuntu/voxelpacs_2026_versions/1.0'), 'workflow identifies isolated worktree');
$expect(str_contains($workflow, '/var/www/voxelpacs/releases/1.0'), 'workflow identifies isolated remote release');
$expect(str_contains($workflow, 'voxelpacs_homolog'), 'workflow identifies the homolog database');
$expect(str_contains($workflow, 'não deve alterar `/home/ubuntu/voxelpacs_2026`'), 'workflow forbids root checkout mutation');

$tracked = [];
exec('git -C ' . escapeshellarg($root) . ' ls-files', $tracked);
foreach ($tracked as $path) {
    $expect(!preg_match('/(^|\/)(\.env|\.env\.[^e]|storage\/(logs|uploads|sessions|cache|tmp)\/[^.gitkeep])($|\/)/', $path), "runtime secret/data is tracked: {$path}");
}

$expect(is_file($root . '/VERSAO.txt'), 'version marker exists');
$expect(trim((string) file_get_contents($root . '/VERSAO.txt')) === '1.0', 'version marker is 1.0');

echo "HOMOLOGATION_ISOLATION_STATIC=PASS\n";
