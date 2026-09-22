<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "REPORTS_PDF_LEGACY_SNAPSHOT_FALHOU: {$message}\n");
        exit(1);
    }
};

$snapshot = (string) file_get_contents($root . '/app/Services/ReportVersionPdfSnapshotService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/ReportsController.php');
$modern = (string) file_get_contents($root . '/app/Views/reports/pdf/templates/_moderno_lateral.php');
$artifact = (string) file_get_contents($root . '/app/Services/PdfNonDicomArtifactProducer.php');

$expect(str_contains($snapshot, 'pdf_snapshot_path'), 'Leitura de snapshot não consulta os metadados completos.');
$expect(str_contains($snapshot, '$hasSnapshotMetadata'), 'Ausência total de metadado não está distinguida de snapshot parcial.');
$expect(str_contains($snapshot, "if (!\$hasSnapshotMetadata)"), 'Snapshot histórico sem metadado não retorna fallback explícito.');
$expect(str_contains($snapshot, "throw \$e"), 'Falha de schema inesperada não permanece fail-closed.');
$expect(str_contains($snapshot, "sqlState === '42703'") && str_contains($snapshot, "sqlState === '42S22'"), 'Compatibilidade de coluna ausente não está limitada aos SQLSTATE esperados.');
$expect(str_contains($controller, 'usando fallback legado sem snapshot canônico'), 'Controller não registra o fallback histórico sanitizado.');
$expect(str_contains($controller, "'message_hash' => hash('sha256', \$e->getMessage())"), 'Erro PDF ainda poderia registrar mensagem bruta.');
$expect(str_contains($controller, 'if (is_array($snapshotPdf) && is_file'), 'Snapshot válido não possui caminho de saída explícito.');
$expect(str_contains($artifact, 'buildPdfForLeasedJob'), 'Non-DICOM não usa o produtor central de artefato.');
$expect(str_contains($modern, 'table-layout: fixed'), 'Layout moderno não fixa a geometria das tabelas para dompdf.');
$expect(str_contains($modern, 'overflow-wrap: anywhere'), 'Layout moderno não protege valores longos contra overflow.');
$expect(str_contains($modern, 'width: 44%'), 'Coluna direita não possui largura controlada.');

fwrite(STDOUT, "REPORTS_PDF_LEGACY_SNAPSHOT_OK\n");
