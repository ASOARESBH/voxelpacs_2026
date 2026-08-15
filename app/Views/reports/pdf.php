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
$tituloMascara = trim((string) ($r['mascara_titulo'] ?? ''));

// Para laudos antigos, preserva as seções da própria persistência ou da
// Máscara vinculada. O Moderno Lateral usa esses blocos com rótulo em negrito;
// os demais layouts continuam consumindo corpoLaudo normalmente.
$rotulosSecoesPdf = [
    'tecnica' => 'TÉCNICA',
    'achados' => 'ACHADOS',
    'conclusao' => 'IMPRESSÃO',
];
$secoesClinicasPdf = [];

// O editor livre persiste títulos em <h*> ou <p><strong>. Quando existirem,
// eles são a fonte de verdade, pois podem ter sido ajustados pelo médico após
// aplicar a Máscara. Sem marcadores, aplica o fallback das colunas/Máscara.
if (trim($corpoLaudo) !== '' && class_exists('DOMDocument')) {
    $dom = new \DOMDocument('1.0', 'UTF-8');
    $previousErrors = libxml_use_internal_errors(true);
    $fragment = '<div id="voxel-pdf-secoes">' . $corpoLaudo . '</div>';
    if ($dom->loadHTML('<?xml encoding="UTF-8">' . $fragment, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
        $container = $dom->getElementById('voxel-pdf-secoes');
        $currentKey = null;
        if ($container) {
            foreach ($container->childNodes as $node) {
                if ($node instanceof \DOMElement) {
                    $title = strtoupper(trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? ''));
                    $normalized = strtr($title, ['É' => 'E', 'Á' => 'A', 'Ç' => 'C', 'Ã' => 'A', 'Õ' => 'O']);
                    $candidate = match ($normalized) {
                        'TECNICA' => 'tecnica',
                        'ACHADOS' => 'achados',
                        'IMPRESSAO', 'CONCLUSAO' => 'conclusao',
                        default => null,
                    };
                    if ($candidate !== null && in_array(strtolower($node->tagName), ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p'], true)) {
                        $currentKey = $candidate;
                        continue;
                    }
                }
                if ($currentKey !== null && isset($rotulosSecoesPdf[$currentKey])) {
                    $secoesClinicasPdf[$currentKey]['rotulo'] = $rotulosSecoesPdf[$currentKey];
                    $secoesClinicasPdf[$currentKey]['conteudo'] = ($secoesClinicasPdf[$currentKey]['conteudo'] ?? '') . $dom->saveHTML($node);
                }
            }
        }
    }
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
    $secoesClinicasPdf = array_filter($secoesClinicasPdf, static fn(array $secao): bool => trim(strip_tags($secao['conteudo'] ?? '')) !== '');
}

if (empty($secoesClinicasPdf)) {
    foreach ($rotulosSecoesPdf as $chave => $rotulo) {
        $valor = (string) ($r['secao_' . $chave] ?? '');
        if (trim(strip_tags($valor)) === '' && isset($r['mascara_secoes'][$chave])) {
            $valor = (string) $r['mascara_secoes'][$chave];
        }
        if (trim(strip_tags($valor)) !== '') {
            $secoesClinicasPdf[$chave] = ['rotulo' => $rotulo, 'conteudo' => $valor];
        }
    }
}

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
