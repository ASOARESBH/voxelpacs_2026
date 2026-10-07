<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este teste é exclusivo do CLI.\n");
    exit(1);
}

function t(string $key): string
{
    return $key;
}

if (ini_set('memory_limit', '128M') === false || ini_get('memory_limit') !== '128M') {
    fwrite(STDERR, "PDF_MEMORY_TEST=NOT_CONCLUSIVE\n");
    exit(2);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/Repositories/RelatorioProdutividadeMedicosRepository.php';
require dirname(__DIR__) . '/app/Services/RelatorioExportService.php';

$linhas = [];
for ($i = 1; $i <= 343; $i++) {
    $linhas[] = [
        'study_date' => '2026-08-01',
        'study_time' => '12:00:00',
        'paciente' => 'Paciente Sintetico ' . $i,
        'patient_id' => 'SYN-' . $i,
        'descricao_estudo' => 'Estudo Sintetico',
        'unidade' => 'Unidade Sintetica',
        'modalities' => 'CR',
        'prioridade' => 'ROUTINE',
        'prioridade_manual' => false,
        'prioridade_origem' => 'ROUTINE',
        'medico_nome' => 'Medico Sintetico',
        'assumido_em' => '2026-08-01 12:00:00',
        'assinado_em' => '2026-08-01 13:00:00',
        'liberado_em' => '2026-08-01 13:30:00',
        'tempo_assinatura_min' => 60,
        'tempo_conclusao_min' => 90,
        'peer_reviews' => 0,
    ];
}

$data = [
    'linhas' => $linhas,
    'porMedico' => [[
        'medico' => 'Medico Sintetico', 'laudos' => 343, 'assinados' => 0,
        'liberados' => 343, 'peer_reviews' => 0, 'sla_medio_min' => 90, 'sla_total_min' => 30870,
    ]],
    'totalizadores' => [
        'laudos' => 343, 'assinados' => 0, 'liberados' => 343,
        'peer_reviews' => 0, 'sla_medio_min' => 90, 'sla_total_min' => 30870,
    ],
    'resumoLiberados' => ['modalidades' => ['CR' => 343], 'prioridades' => ['ROUTINE' => 343]],
    'resumo' => ['Periodo' => '01/08/2026 a 30/09/2026', 'Medico' => 'Medico Sintetico'],
    'tenantNome' => 'Tenant Sintetico',
    'usuarioNome' => 'Usuario Sintetico',
    'geradoEm' => '07/10/2026 12:40',
];

$view = file_get_contents(dirname(__DIR__) . '/app/Views/relatorios/pdf/medicos.php');
if ($view === false || !str_contains($view, 'display:table-header-group') || !str_contains($view, 'page-break-inside:avoid')) {
    throw new RuntimeException('Paginação explícita do template PDF não está presente.');
}

$service = new App\Services\RelatorioExportService();
ob_start();
try {
    $service->streamPdf(
        dirname(__DIR__) . '/app/Views/relatorios/pdf/medicos.php',
        $data,
        'RELATORIO_MEDICOS_SINTETICO.pdf'
    );
    $pdf = (string) ob_get_contents();
} finally {
    ob_end_clean();
}

if (!str_starts_with($pdf, '%PDF') || strlen($pdf) <= 1000) {
    throw new RuntimeException('PDF sintético não foi gerado corretamente.');
}
if (ini_get('memory_limit') !== '128M') {
    throw new RuntimeException('memory_limit temporário não foi restaurado após o PDF.');
}

printf("RELATORIO_MEDICOS_PDF_MEMORY=PASS\nPDF_BYTES=%d\nMEMORY_LIMIT_RESTORED=PASS\n", strlen($pdf));
