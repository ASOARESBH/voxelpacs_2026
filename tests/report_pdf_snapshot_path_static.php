<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Services/PdfSnapshotPathResolver.php';

use App\Services\PdfSnapshotPathResolver;

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$storage = sys_get_temp_dir() . '/voxelpacs-snapshot-path-' . bin2hex(random_bytes(6));
$inside = $storage . '/report_versions/7/8';
$otherTenant = $storage . '/report_versions/9/8';
mkdir($inside, 0700, true);
mkdir($otherTenant, 0700, true);
$hash = str_repeat('a', 64);
$path = $inside . '/v1-' . $hash . '.pdf';
file_put_contents($path, '%PDF-test');
$otherPath = $otherTenant . '/v1-' . $hash . '.pdf';
file_put_contents($otherPath, '%PDF-test');

if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', $storage);
}

$relative = PdfSnapshotPathResolver::relativePathFor($path, 'report_versions/7/8');
$expect($relative === 'report_versions/7/8/v1-' . $hash . '.pdf', 'O caminho persistido não foi reduzido ao storage relativo.');
$resolvedRelative = PdfSnapshotPathResolver::resolve($relative, 'report_versions/7/8');
$expect($resolvedRelative === realpath($path), 'O caminho relativo válido não foi resolvido no ambiente atual.');
$resolvedLegacy = PdfSnapshotPathResolver::resolve($path, 'report_versions/7/8');
$expect($resolvedLegacy === realpath($path), 'O caminho absoluto legado dentro do storage não foi aceito.');
$expect(PdfSnapshotPathResolver::resolve($otherPath, 'report_versions/7/8') === null, 'Outro tenant escapou do prefixo esperado.');
$outside = sys_get_temp_dir() . '/voxelpacs-outside-' . bin2hex(random_bytes(6)) . '.pdf';
file_put_contents($outside, '%PDF-test');
$expect(PdfSnapshotPathResolver::resolve($outside, 'report_versions/7/8') === null, 'Arquivo fora do storage foi aceito.');
$expect(PdfSnapshotPathResolver::resolve('../' . basename($outside), 'report_versions/7/8') === null, 'Traversal de caminho foi aceito.');

$expect(str_contains((string) file_get_contents($root . '/app/Services/ReportVersionPdfSnapshotService.php'), "':path' => \$relativePath"), 'Snapshot canônico ainda não persiste o caminho relativo.');
$expect(str_contains((string) file_get_contents($root . '/app/Services/ReportVersionPdfSnapshotService.php'), 'PdfSnapshotPathResolver::resolve'), 'Snapshot canônico não usa o resolvedor seguro.');
$expect(str_contains((string) file_get_contents($root . '/app/Services/ReportVersionPdfRevisionService.php'), "':pdf_snapshot_path' => \$relativePath"), 'Revisão PDF ainda não persiste o caminho relativo.');

@unlink($path);
@unlink($otherPath);
@unlink($outside);
@rmdir($inside);
@rmdir($otherTenant);
@rmdir($storage . '/report_versions/7');
@rmdir($storage . '/report_versions/9');
@rmdir($storage . '/report_versions');
@rmdir($storage);

if ($failures !== []) {
    fwrite(STDERR, "REPORT_PDF_SNAPSHOT_PATH_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "REPORT_PDF_SNAPSHOT_PATH_OK\n");
