<?php
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\DesktopDownloadService;

if (!class_exists(ZipArchive::class)) {
    echo "desktop_download_zip_synthetic: SKIPPED (ZipArchive indisponível)\n";
    exit(0);
}

$path = tempnam(sys_get_temp_dir(), 'voxel-desktop-');
if ($path === false) throw new RuntimeException('Não foi possível criar arquivo temporário.');
$zipPath = $path . '.zip';
@unlink($path);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) throw new RuntimeException('Não foi possível criar ZIP sintético.');
$zip->addFromString('installer/README.txt', 'pacote sintético sem conteúdo clínico');
$zip->close();

try {
    $result = DesktopDownloadService::validateZipArchive($zipPath, 'VOXELDesktop-1.0.0.zip', filesize($zipPath));
    if (($result['size_bytes'] ?? 0) < 1 || !preg_match('/^[a-f0-9]{64}$/', (string) ($result['checksum_sha256'] ?? ''))) {
        throw new RuntimeException('Resultado de validação ZIP inválido.');
    }
    echo "desktop_download_zip_synthetic: OK\n";
} finally {
    @unlink($zipPath);
}
