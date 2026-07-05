<?php
/** @var object $estudo */
/** @var object $report */
/** @var array $secoes */
/** @var object|null $signature */
/** @var string|null $qrDataUri */

$secaoTitulos = [
    'exame'        => 'Exame',
    'tecnica'      => 'Técnica',
    'achados'      => 'Achados',
    'conclusao'    => 'Conclusão',
    'recomendacao' => 'Recomendação',
];

$assinado = $signature !== null;
$dtEstudo = '—';
if (!empty($estudo->study_date)) {
    try { $dtEstudo = (new DateTime($estudo->study_date))->format('d/m/Y'); } catch (\Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 90px 40px 70px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
    .header { position: fixed; top: -70px; left: 0; right: 0; height: 60px; border-bottom: 2px solid #1565c0; padding-bottom: 6px; }
    .header .brand { font-size: 16px; font-weight: bold; color: #1565c0; }
    .header .sub { font-size: 9px; color: #666; }
    .footer { position: fixed; bottom: -50px; left: 0; right: 0; height: 40px; border-top: 1px solid #ccc; font-size: 8px; color: #888; text-align: center; padding-top: 6px; }
    .watermark { position: fixed; top: 300px; left: 80px; font-size: 60px; color: rgba(200,0,0,0.15); transform: rotate(-30deg); font-weight: bold; }
    h1.paciente { font-size: 14px; margin: 0 0 4px 0; }
    table.info { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.info td { padding: 2px 6px; font-size: 10px; vertical-align: top; }
    table.info td.label { color: #666; width: 110px; }
    .secao { margin-bottom: 12px; }
    .secao-titulo { font-size: 12px; font-weight: bold; color: #1565c0; text-transform: uppercase; border-bottom: 1px solid #ddd; margin-bottom: 4px; }
    .secao-conteudo { font-size: 11px; line-height: 1.5; }
    .assinatura { margin-top: 24px; border-top: 1px solid #333; padding-top: 10px; }
    .assinatura table { width: 100%; }
    .assinatura .qr { width: 90px; text-align: center; }
    .assinatura .qr img { width: 80px; height: 80px; }
    .assinatura .dados { font-size: 10px; line-height: 1.6; }
    .hash { font-size: 7px; color: #999; word-break: break-all; }
</style>
</head>
<body>

<div class="header">
    <div class="brand">VOXEL PACS</div>
    <div class="sub">Laudo Médico — Emitido em <?= date('d/m/Y H:i') ?></div>
</div>

<div class="footer">
    VOXEL PACS — Laudo #<?= (int) $report->id ?> — Documento gerado eletronicamente.
</div>

<?php if (!$assinado): ?>
    <div class="watermark">MINUTA<br>NÃO ASSINADO</div>
<?php endif; ?>

<h1 class="paciente"><?= htmlspecialchars($estudo->patient_name_display ?? $estudo->patient_name ?? 'Paciente') ?></h1>

<table class="info">
    <tr>
        <td class="label">Sexo / Idade</td>
        <td><?= htmlspecialchars(($estudo->patient_sex ?: '—') . ' / ' . ($estudo->patient_age ?: '—')) ?></td>
        <td class="label">Data do Exame</td>
        <td><?= htmlspecialchars($dtEstudo . ' ' . ($estudo->study_time ?: '')) ?></td>
    </tr>
    <tr>
        <td class="label">Modalidade</td>
        <td><?= htmlspecialchars($estudo->modalities ?: '—') ?></td>
        <td class="label">Accession Nº</td>
        <td><?= htmlspecialchars($estudo->accession_number ?: '—') ?></td>
    </tr>
    <tr>
        <td class="label">Instituição</td>
        <td><?= htmlspecialchars($estudo->institution_name ?: '—') ?></td>
        <td class="label">Solicitante</td>
        <td><?= htmlspecialchars($estudo->referring_physician_name ?: '—') ?></td>
    </tr>
    <tr>
        <td class="label">Descrição</td>
        <td colspan="3"><?= htmlspecialchars($estudo->study_description ?: '—') ?></td>
    </tr>
</table>

<?php foreach ($secaoTitulos as $chave => $titulo): ?>
    <?php if (!empty(trim(strip_tags($secoes[$chave] ?? '')))): ?>
        <div class="secao">
            <div class="secao-titulo"><?= $titulo ?></div>
            <div class="secao-conteudo"><?= $secoes[$chave] ?></div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php if ($assinado): ?>
    <div class="assinatura">
        <table>
            <tr>
                <td class="dados">
                    <strong>Assinado eletronicamente por:</strong><br>
                    <?= htmlspecialchars($signature->nome_medico) ?><br>
                    <?php if ($signature->crm): ?>CRM: <?= htmlspecialchars($signature->crm) ?><br><?php endif; ?>
                    Data/Hora: <?= htmlspecialchars($signature->data . ' ' . $signature->hora) ?><br>
                    <span class="hash">Hash: <?= htmlspecialchars($signature->hash) ?></span>
                </td>
                <?php if ($qrDataUri): ?>
                <td class="qr">
                    <img src="<?= $qrDataUri ?>" alt="QR de autenticidade">
                </td>
                <?php endif; ?>
            </tr>
        </table>
    </div>
<?php endif; ?>

</body>
</html>
