<?php
/**
 * Template HTML → Dompdf do Relatório SLA Médicos (RelatorioExportService::streamPdf).
 * Variáveis injetadas: $linhas, $agregado, $resumo, $tenantNome, $usuarioNome, $geradoEm.
 */
function pdfFormatarMinutos(?int $min): string {
    if ($min === null) return '—';
    $h = intdiv($min, 60); $m = $min % 60;
    return $h > 0 ? "{$h}h{$m}min" : "{$m}min";
}
function pdfStatusSlaLabel(string $s): string {
    return match ($s) { 'verde' => 'Dentro do prazo', 'amarelo' => 'Atenção', 'vermelho' => 'Estourado', default => 'Sem SLA definido' };
}
function pdfStatusSlaCor(string $s): string {
    return match ($s) { 'verde' => '#dcfce7', 'amarelo' => '#fef9c3', 'vermelho' => '#fee2e2', default => '#e5e7eb' };
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
h2 { font-size: 12px; color: #1a56db; margin: 14px 0 6px; }
table { width: 100%; border-collapse: collapse; }
thead th { background: #1a56db; color: #fff; text-align: left; padding: 5px 6px; font-size: 9px; }
tbody td { padding: 4px 6px; font-size: 9px; border-bottom: 1px solid #e5e7eb; }
tbody tr:nth-child(even) { background: #f3f4f6; }
</style>
</head>
<body>
<div class="hdr">
    <h1><?= htmlspecialchars($tenantNome) ?><br><small style="font-size:11px;color:#333;">Relatório SLA Médicos</small></h1>
    <div class="meta">Gerado em <?= htmlspecialchars($geradoEm) ?><br>por <?= htmlspecialchars($usuarioNome) ?></div>
</div>
<div class="resumo">
    <?php foreach ($resumo as $label => $valor): ?>
        <span><strong><?= htmlspecialchars($label) ?>:</strong> <?= htmlspecialchars($valor) ?></span>
    <?php endforeach; ?>
</div>

<h2>Detalhe</h2>
<table>
    <thead>
        <tr><th>Data</th><th>Paciente</th><th>Unidade</th><th>Modalidade</th><th>Médico</th><th>Tempo decorrido</th><th>SLA alvo</th><th>Status</th></tr>
    </thead>
    <tbody>
    <?php foreach ($linhas as $l): ?>
        <tr>
            <td><?= htmlspecialchars($l['study_date'] ?? '') ?></td>
            <td><?= htmlspecialchars($l['patient_name'] ?? '') ?></td>
            <td><?= htmlspecialchars($l['institution_name'] ?? '') ?></td>
            <td><?= htmlspecialchars(str_replace('\\', ' ', $l['modalities'] ?? '')) ?></td>
            <td><?= htmlspecialchars($l['assumido_por'] ?: 'Não atribuído') ?></td>
            <td><?= pdfFormatarMinutos($l['tempo_decorrido_min']) ?></td>
            <td><?= $l['sla_alvo_min'] !== null ? pdfFormatarMinutos($l['sla_alvo_min']) : '—' ?></td>
            <td style="background:<?= pdfStatusSlaCor($l['status_sla']) ?>;"><?= htmlspecialchars(pdfStatusSlaLabel($l['status_sla'])) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($linhas)): ?>
        <tr><td colspan="8" style="text-align:center;padding:12px;color:#888;">Nenhum resultado para os filtros aplicados.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h2>Resumo por médico</h2>
<table>
    <thead>
        <tr><th>Médico</th><th>Total</th><th>Dentro do prazo</th><th>Atenção</th><th>Estourado</th><th>Sem SLA</th><th>Tempo médio de laudo</th><th>% Cumprimento</th></tr>
    </thead>
    <tbody>
    <?php foreach ($agregado as $g): ?>
        <tr>
            <td><?= htmlspecialchars($g['nome']) ?></td>
            <td><?= (int) $g['total'] ?></td>
            <td><?= (int) $g['verde'] ?></td>
            <td><?= (int) $g['amarelo'] ?></td>
            <td><?= (int) $g['vermelho'] ?></td>
            <td><?= (int) $g['sem_sla'] ?></td>
            <td><?= $g['tempo_medio_laudo_min'] !== null ? pdfFormatarMinutos($g['tempo_medio_laudo_min']) : '—' ?></td>
            <td><?= $g['percentual_cumprimento'] !== null ? $g['percentual_cumprimento'] . '%' : '—' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($agregado)): ?>
        <tr><td colspan="8" style="text-align:center;padding:12px;color:#888;">Nenhum resultado.</td></tr>
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
