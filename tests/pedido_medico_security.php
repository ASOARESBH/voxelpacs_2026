<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';
define('BASE_PATH', $root);

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$serviceClass = new ReflectionClass(App\Services\PedidoMedicoService::class);
$service = $serviceClass->newInstanceWithoutConstructor();
$validate = $serviceClass->getMethod('validarArquivo');
$validate->setAccessible(true);

$tmpDir = sys_get_temp_dir() . '/voxel_pedido_security_' . bin2hex(random_bytes(6));
mkdir($tmpDir, 0700, true);
$filesToRemove = [];
$makeFile = static function (string $name, string $contents) use ($tmpDir, &$filesToRemove): string {
    $path = $tmpDir . '/' . $name;
    file_put_contents($path, $contents);
    $filesToRemove[] = $path;
    return $path;
};

$pdf = $makeFile('pedido.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
$pdfResult = $validate->invoke($service, [
    'error' => UPLOAD_ERR_OK,
    'tmp_name' => $pdf,
    'size' => filesize($pdf),
    'name' => 'pedido.pdf',
]);
$assert(($pdfResult['ok'] ?? false) === true, 'PDF válido foi rejeitado.');
$assert(($pdfResult['mime_type'] ?? '') === 'application/pdf', 'MIME real do PDF não foi reconhecido.');

$png = $makeFile('pedido.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
$pngResult = $validate->invoke($service, [
    'error' => UPLOAD_ERR_OK,
    'tmp_name' => $png,
    'size' => filesize($png),
    'name' => 'pedido.png',
]);
$assert(($pngResult['ok'] ?? false) === true, 'PNG válido foi rejeitado.');

$mismatch = $makeFile('pedido.pdf', 'texto que não é PDF');
$mismatchResult = $validate->invoke($service, [
    'error' => UPLOAD_ERR_OK,
    'tmp_name' => $mismatch,
    'size' => filesize($mismatch),
    'name' => 'pedido.pdf',
]);
$assert(($mismatchResult['error'] ?? '') === 'tipo_invalido', 'Arquivo com extensão enganosa foi aceito.');

$large = $makeFile('grande.pdf', 'x');
$largeResult = $validate->invoke($service, [
    'error' => UPLOAD_ERR_OK,
    'tmp_name' => $large,
    'size' => App\Services\PedidoMedicoService::MAX_BYTES + 1,
    'name' => 'grande.pdf',
]);
$assert(($largeResult['error'] ?? '') === 'arquivo_muito_grande', 'Arquivo acima do limite não foi bloqueado.');

$privateDir = $root . '/storage/uploads/pedidos_medicos/999/888';
mkdir($privateDir, 0750, true);
$privateFile = $privateDir . '/teste.pdf';
file_put_contents($privateFile, 'private');
$resolved = $service->caminhoAbsolutoSeguro('999/888/teste.pdf');
$assert($resolved === realpath($privateFile), 'Arquivo privado legítimo não foi resolvido.');
$assert($service->caminhoAbsolutoSeguro('../logs/.gitkeep') === null, 'Traversal de path foi aceito.');

@unlink($privateFile);
@rmdir($privateDir);
@rmdir(dirname($privateDir));
foreach ($filesToRemove as $path) @unlink($path);
@rmdir($tmpDir);

if ($failures) {
    fwrite(STDERR, "FALHOU:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: validação de MIME, limite e path privado aprovada.\n";
