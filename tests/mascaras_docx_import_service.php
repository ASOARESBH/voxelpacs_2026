<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\MascaraDocxImportService;

$arquivo = '/home/ubuntu/upload/TOMOGRAFIACOMPUTADORIZADA-mascaraspt1(1).docx';
if (!is_file($arquivo)) {
    fwrite(STDERR, "Arquivo de exemplo DOCX não encontrado: {$arquivo}\n");
    exit(1);
}

$service = new MascaraDocxImportService();
$mascaras = $service->analisar($arquivo);

if (!$mascaras) {
    fwrite(STDERR, "Nenhuma máscara foi detectada.\n");
    exit(1);
}

$revisar = array_values(array_filter($mascaras, static fn(array $m): bool => !empty($m['revisar'])));
if (count($mascaras) !== 27) {
    fwrite(STDERR, 'Esperadas 27 máscaras; encontradas ' . count($mascaras) . ".\n");
    exit(1);
}
if (count($revisar) !== 1 || ($revisar[0]['nome'] ?? '') !== 'LAUDO ESTRUTURADO - PROTOCOLO AVC') {
    fwrite(STDERR, "A máscara de protocolo AVC deveria ser a única marcada para revisão.\n");
    exit(1);
}

$tomografias = array_filter($mascaras, static fn(array $m): bool => strpos($m['nome'], 'TOMOGRAFIA') !== false);
if (!$tomografias || array_filter($tomografias, static fn(array $m): bool => $m['modalidade'] !== 'CT')) {
    fwrite(STDERR, "A modalidade sugerida para tomografias deveria ser CT.\n");
    exit(1);
}

$temNegritoPreservado = (bool) array_filter($mascaras, static fn(array $m): bool =>
    strpos($m['secao_tecnica'] . $m['secao_achados'] . $m['secao_conclusao'], '<strong>') !== false
);
if (!$temNegritoPreservado) {
    fwrite(STDERR, "O parser não preservou conteúdo em negrito como <strong>.\n");
    exit(1);
}

$primeiras = array_slice(array_map(static fn(array $m): array => [
    'nome' => $m['nome'],
    'modalidade' => $m['modalidade'],
    'revisar' => $m['revisar'],
    'tecnica_chars' => strlen(strip_tags($m['secao_tecnica'])),
    'achados_chars' => strlen(strip_tags($m['secao_achados'])),
    'impressao_chars' => strlen(strip_tags($m['secao_conclusao'])),
], $mascaras), 0, 5);

echo json_encode([
    'total' => count($mascaras),
    'revisar' => count($revisar),
    'primeiras' => $primeiras,
    'revisar_nomes' => array_column($revisar, 'nome'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
