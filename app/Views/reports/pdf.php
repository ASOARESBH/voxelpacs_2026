<?php
/**
 * VOXEL PACS — Visualização de Laudo em PDF/Impressão
 * Dispatcher: escolhe o partial de template visual (App\Services\ReportLayoutService)
 * conforme o `report_layout_template_id` da Unidade do estudo, e delega a
 * renderização completa para ele. Nenhuma lógica de dado do laudo vive aqui —
 * só a escolha de QUAL layout aplicar. Ver app/Views/reports/pdf/templates/.
 */
$r = $report ?? [];
$templateCodigo = $templateCodigo ?? \App\Services\ReportLayoutService::PADRAO;
$laudoPossuiConteudo = \App\Services\ReportClinicalContentService::hasReportContent($r);
// Conteúdo clínico livre. Mantém leitura de colunas legadas para laudos
// antigos, mas os layouts não impõem mais rótulos de seções ao radiologista.
$corpoLaudo = (string) ($r['corpo_laudo'] ?? '');
$corpoLaudoAtual = trim(strip_tags($corpoLaudo)) !== '';

// O corpo atual persistido pelo editor é a fonte única de verdade do PDF.
// Se estiver vazio, mantém compatibilidade com laudos históricos em seções ou
// com máscaras antigas ainda não convertidas para corpo livre.
$rotulosSecoesPdf = [
    'tecnica' => 'TÉCNICA',
    'achados' => 'ACHADOS',
    'conclusao' => 'IMPRESSÃO',
];
$secoesClinicasPdf = [];

// Seções são exclusivamente um fallback de compatibilidade para laudos
// históricos. Elas nunca podem sobrepor o HTML atual salvo pelo médico.
$secoesPersistidas = [];
foreach ($rotulosSecoesPdf as $chave => $rotulo) {
    $valor = (string) ($r['secao_' . $chave] ?? '');
    if (trim(strip_tags($valor)) !== '') {
        $secoesPersistidas[$chave] = ['rotulo' => $rotulo, 'conteudo' => $valor];
    }
}
$usarSecoesPersistidas = !$corpoLaudoAtual
    && $templateCodigo === 'moderno_lateral'
    && (int) ($r['template_id'] ?? 0) > 0
    && empty($r['mascara_conteudo_livre'])
    && !empty($secoesPersistidas);
if ($usarSecoesPersistidas) {
    $secoesClinicasPdf = $secoesPersistidas;

    // Compatibilidade com rascunhos gerados por versões anteriores do editor:
    // em alguns casos o conteúdo de uma seção foi salvo literalmente no início
    // da seção seguinte. Remove somente esse prefixo HTML idêntico, preservando
    // qualquer texto clínico que venha depois dele.
    foreach (['achados', 'conclusao'] as $chaveAtual) {
        if (empty($secoesClinicasPdf[$chaveAtual]['conteudo'])) {
            continue;
        }
        $conteudoAtual = ltrim((string) $secoesClinicasPdf[$chaveAtual]['conteudo']);
        foreach (['tecnica', 'achados'] as $chaveAnterior) {
            if ($chaveAnterior === $chaveAtual || empty($secoesClinicasPdf[$chaveAnterior]['conteudo'])) {
                continue;
            }
            $conteudoAnterior = trim((string) $secoesClinicasPdf[$chaveAnterior]['conteudo']);
            if ($conteudoAnterior !== '' && str_starts_with($conteudoAtual, $conteudoAnterior)) {
                $conteudoAtual = ltrim(substr($conteudoAtual, strlen($conteudoAnterior)));
            }
        }
        $secoesClinicasPdf[$chaveAtual]['conteudo'] = $conteudoAtual;
    }

    // Algumas Máscaras históricas traziam "Impressão:" ao fim da técnica e
    // também preenchiam a seção própria de Impressão. Mantém a seção canônica
    // e remove somente o sufixo explícito da técnica para não imprimi-lo duas vezes.
    $tecnica = (string) ($secoesClinicasPdf['tecnica']['conteudo'] ?? '');
    $impressao = (string) ($secoesClinicasPdf['conclusao']['conteudo'] ?? '');
    if (trim(strip_tags($tecnica)) !== '' && trim(strip_tags($impressao)) !== '') {
        $partesTecnica = preg_split(
            '/(?:<br\\s*\\/?\\s*>|<\\/p>\\s*<p>|\\s)*(?:<strong>)?\\s*(?:impressão|conclusão)\\s*:\\s*(?:<\\/strong>)?/isu',
            $tecnica,
            2
        );
        if (is_array($partesTecnica) && count($partesTecnica) === 2) {
            $normalizarTextoClinico = static function (string $html): string {
                $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $texto = preg_replace('/\\s+/u', ' ', $texto) ?? '';
                return mb_strtolower(trim($texto), 'UTF-8');
            };
            if (
                $normalizarTextoClinico($partesTecnica[1]) !== ''
                && $normalizarTextoClinico($partesTecnica[1]) === $normalizarTextoClinico($impressao)
                && trim(strip_tags($partesTecnica[0])) !== ''
            ) {
                $secoesClinicasPdf['tecnica']['conteudo'] = $partesTecnica[0];
            }
        }
    }
    $secoesClinicasPdf = array_filter(
        $secoesClinicasPdf,
        static fn(array $secao): bool => trim(strip_tags($secao['conteudo'] ?? '')) !== ''
    );
}

// Para laudos legados sem corpo persistido, converte marcadores conhecidos
// em seções de compatibilidade. O HTML atual nunca é reinterpretado.
if (!$corpoLaudoAtual && !$usarSecoesPersistidas && empty($r['mascara_conteudo_livre'])
    && trim($corpoLaudo) !== '' && class_exists('DOMDocument')) {
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

if (!$corpoLaudoAtual && empty($secoesClinicasPdf) && empty($r['mascara_conteudo_livre'])) {
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

if (!$corpoLaudoAtual && trim($corpoLaudo) === '') {
    $blocosLegados = array_filter([
        (string) ($r['secao_exame'] ?? ''),
        (string) ($r['secao_tecnica'] ?? ''),
        (string) ($r['secao_achados'] ?? ''),
        (string) ($r['secao_conclusao'] ?? ''),
        (string) ($r['secao_recomendacao'] ?? ''),
    ], static fn($valor) => trim(strip_tags($valor)) !== '');
    $corpoLaudo = implode('<br><br>', $blocosLegados);
}
$paciente = htmlspecialchars(\App\Helpers\DicomPersonName::displayFromStudy($r) ?: 'Paciente', ENT_QUOTES);
$download = $download ?? false;
$portalPatientPdf = !empty($portalPatientPdf);
$reportToken = trim((string) ($r['public_token'] ?? ''));
$reportReturnUrl = $reportToken !== '' ? '/reports/r/' . rawurlencode($reportToken) : '/estudos';

$partial = (new \App\Services\ReportLayoutService())->caminhoPartial($templateCodigo);

require $partial;
