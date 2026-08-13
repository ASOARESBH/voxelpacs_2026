<?php
/**
 * VOXEL PACS — Visualização de Laudo em PDF/Impressão
 * Dispatcher: escolhe o partial de template visual (App\Services\ReportLayoutService)
 * conforme o `report_layout_template_id` da Unidade do estudo, e delega a
 * renderização completa para ele. Nenhuma lógica de dado do laudo vive aqui —
 * só a escolha de QUAL layout aplicar. Ver app/Views/reports/pdf/templates/.
 */
$r = $report ?? [];
// Conteúdo clínico livre. Mantém leitura de colunas legadas para laudos
// antigos, mas os layouts não impõem mais rótulos de seções ao radiologista.
$corpoLaudo = (string) ($r['corpo_laudo'] ?? '');
if (trim($corpoLaudo) === '') {
    $blocosLegados = array_filter([
        (string) ($r['secao_exame'] ?? ''),
        (string) ($r['secao_tecnica'] ?? ''),
        (string) ($r['secao_achados'] ?? ''),
        (string) ($r['secao_conclusao'] ?? ''),
        (string) ($r['secao_recomendacao'] ?? ''),
    ], static fn($valor) => trim(strip_tags($valor)) !== '');
    $corpoLaudo = implode('<br><br>', $blocosLegados);
}
$paciente = htmlspecialchars($r['patient_name_display'] ?? $r['patient_name'] ?? 'Paciente', ENT_QUOTES);
$download = $download ?? false;

$templateCodigo = $templateCodigo ?? \App\Services\ReportLayoutService::PADRAO;
$partial = (new \App\Services\ReportLayoutService())->caminhoPartial($templateCodigo);

require $partial;
