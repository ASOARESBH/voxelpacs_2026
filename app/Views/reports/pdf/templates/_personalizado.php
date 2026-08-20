<?php
/**
 * Layout Personalizado — renderizado pelo mesmo dispatcher reports/pdf.php.
 * O HTML configurável já foi sanitizado e as variáveis são substituídas no
 * ReportCustomTemplateService; este partial não aceita conteúdo do request.
 */
$customTemplate = $customTemplate ?? null;
if (!is_array($customTemplate)) {
    require __DIR__ . '/_classico_centralizado.php';
    return;
}

$tituloPersonalizado = trim((string) ($tituloMascara ?? ''));
if ($tituloPersonalizado === '') {
    $tituloPersonalizado = trim((string) ($r['study_description'] ?? $r['modalities'] ?? 'Laudo Médico'));
}
$documento = (new \App\Services\ReportCustomTemplateService())
    ->renderReport($customTemplate, $r, (string) ($corpoLaudo ?? ''), $tituloPersonalizado);

$token = rawurlencode((string) ($r['public_token'] ?? ''));
$acoesItens = [
    '<button type="button" onclick="window.print()">Imprimir</button>',
    '<a href="/reports/r/' . $token . '/pdf?download=1">Baixar PDF</a>',
];
if (!$portalPatientPdf) {
    $acoesItens[] = '<a href="/estudos">Voltar à Worklist</a>';
}
$acoes = '<div class="voxel-custom-actions">'
    . implode('', $acoesItens)
    . '</div><style>.voxel-custom-actions{display:flex;gap:8px;width:210mm;margin:12px auto}.voxel-custom-actions button,.voxel-custom-actions a{padding:8px 12px;border:1px solid #cbd5e1;border-radius:4px;background:#fff;color:#1e293b;font:700 12px Arial,sans-serif;text-decoration:none;cursor:pointer}.voxel-custom-actions button{background:#075a9e;color:#fff;border-color:#075a9e}@media print{.voxel-custom-actions{display:none!important}}</style>';
$documento = str_replace('<body>', '<body>' . $acoes, $documento);
if (!empty($download)) {
    $documento = str_replace('</body>', '<script>window.addEventListener("load",function(){window.print();});</script></body>', $documento);
}
echo $documento;
