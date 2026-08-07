<?php
/**
 * Template HTML → Dompdf do Relatório de Exames (RelatorioExportService::streamPdf).
 * Variáveis injetadas: $linhas, $resumo, $tenantNome, $usuarioNome, $geradoEm.
 */
function pdfSituacaoLabel(string $s): string {
    $map = ['novo'=>'Novo','aberto'=>'Aberto','a_laudar'=>'A Laudar','em_laudo'=>'Em Laudo',
        'rascunho'=>'Rascunho','revisao'=>'Revisão','assinado'=>'Assinado','liberado'=>'Liberado','urgente'=>'Urgente'];
    return $map[$s] ?? ucfirst($s);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; font-size: 10px; color: #222; }
@page { margin: 30px 30px 50px 30px; }
.hdr { display: flex; justify-content: space-between; border-bottom: 2px solid #1a56db; padding-bottom: 8px; margin-bottom: 10px; }
.hdr h1 { font-size: 15px; color: #1a56db; }
.hdr .meta { text-align: right; font-size: 9px; color: #555; }
.resumo { background: #f3f4f6; border-left: 3px solid #1a56db; padding: 6px 10px; margin-bottom: 12px; font-size: 9px; }
.resumo span { margin-right: 14px; }
table { width: 100%; border-collapse: collapse; }
thead th { background: #1a56db; color: #fff; text-align: left; padding: 5px 6px; font-size: 9px; }
tbody td { padding: 4px 6px; font-size: 9px; border-bottom: 1px solid #e5e7eb; }
tbody tr:nth-child(even) { background: #f3f4f6; }
</style>
</head>
<body>
<div class="hdr">
    <h1><?= htmlspecialchars($tenantNome) ?><br><small style="font-size:11px;color:#333;">Relatório de Exames</small></h1>
    <div class="meta">Gerado em <?= htmlspecialchars($geradoEm) ?><br>por <?= htmlspecialchars($usuarioNome) ?></div>
</div>
<div class="resumo">
    <?php foreach ($resumo as $label => $valor): ?>
        <span><strong><?= htmlspecialchars($label) ?>:</strong> <?= htmlspecialchars($valor) ?></span>
    <?php endforeach; ?>
</div>
<table>
    <thead>
        <tr><th>Data</th><th>Paciente</th><th>Unidade</th><th>Modalidade</th><th>Prioridade</th><th>Situação</th><th>Médico</th><th>Solicitante</th></tr>
    </thead>
    <tbody>
    <?php foreach ($linhas as $l): ?>
        <tr>
            <td><?= htmlspecialchars($l['study_date'] ?? '') ?></td>
            <td><?= htmlspecialchars($l['patient_name'] ?? '') ?></td>
            <td><?= htmlspecialchars($l['institution_name'] ?? '') ?></td>
            <td><?= htmlspecialchars(str_replace('\\', ' ', $l['modalities'] ?? '')) ?></td>
            <td><?= htmlspecialchars(ucfirst($l['prioridade'] ?? '')) ?></td>
            <td><?= htmlspecialchars(pdfSituacaoLabel($l['situacao'] ?? '')) ?></td>
            <td><?= htmlspecialchars($l['assumido_por'] ?: '—') ?></td>
            <td><?= htmlspecialchars($l['especialidade'] ?: ($l['referring_physician_name'] ?: '—')) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($linhas)): ?>
        <tr><td colspan="8" style="text-align:center;padding:12px;color:#888;">Nenhum resultado para os filtros aplicados.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<script type="text/php">
if (isset($pdf)) {
    $font = $fontMetrics->getFont("Arial", "normal");
    $pdf->page_text(30, 812, "Página {PAGE_NUM} de {PAGE_COUNT}", $font, 8, [0.4,0.4,0.4]);
}
</script>
</body>
</html>
