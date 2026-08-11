<?php
/**
 * VOXEL PACS — Visualização de Laudo em PDF/Impressão
 * Dispatcher: escolhe o partial de template visual (App\Services\ReportLayoutService)
 * conforme o `report_layout_template_id` da Unidade do estudo, e delega a
 * renderização completa para ele. Nenhuma lógica de dado do laudo vive aqui —
 * só a escolha de QUAL layout aplicar. Ver app/Views/reports/pdf/templates/.
 */
$r = $report ?? [];
$paciente = htmlspecialchars($r['patient_name_display'] ?? $r['patient_name'] ?? 'Paciente', ENT_QUOTES);
$download = $download ?? false;

$templateCodigo = $templateCodigo ?? \App\Services\ReportLayoutService::PADRAO;
$partial = (new \App\Services\ReportLayoutService())->caminhoPartial($templateCodigo);

require $partial;
