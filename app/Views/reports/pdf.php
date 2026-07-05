<?php
/**
 * VOXEL PACS — Visualização de Laudo em PDF
 * Layout profissional com cabeçalho, rodapé e QR Code
 */
$r = $report ?? [];
$paciente = htmlspecialchars($r['patient_name_display'] ?? $r['patient_name'] ?? 'Paciente', ENT_QUOTES);
$download = $download ?? false;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laudo — <?= $paciente ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Arial', sans-serif; font-size: 12px; color: #222; background: #fff; }
        .pdf-page { max-width: 800px; margin: 0 auto; padding: 2rem; }

        /* Cabeçalho */
        .pdf-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #003366; padding-bottom: 1rem; margin-bottom: 1.5rem; }
        .pdf-logo-area h1 { font-size: 1.4rem; color: #003366; font-weight: 900; letter-spacing: 1px; }
        .pdf-logo-area p { font-size: .75rem; color: #666; }
        .pdf-header-info { text-align: right; font-size: .75rem; color: #555; }
        .pdf-header-info strong { display: block; font-size: .85rem; color: #003366; }

        /* Dados do paciente */
        .pdf-patient-box { background: #f0f4f8; border-left: 4px solid #003366; padding: .75rem 1rem; margin-bottom: 1.5rem; border-radius: 0 4px 4px 0; }
        .pdf-patient-box h2 { font-size: 1rem; color: #003366; margin-bottom: .5rem; }
        .pdf-patient-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: .25rem .5rem; }
        .pdf-pinfo { font-size: .75rem; }
        .pdf-pinfo span { color: #555; }
        .pdf-pinfo strong { color: #222; }

        /* Seções */
        .pdf-section { margin-bottom: 1.2rem; }
        .pdf-section-title { font-size: .85rem; font-weight: 700; text-transform: uppercase; color: #003366; border-bottom: 1px solid #ccd; padding-bottom: .25rem; margin-bottom: .5rem; letter-spacing: .5px; }
        .pdf-section-content { font-size: .85rem; line-height: 1.7; color: #222; }
        .pdf-section-content:empty::before { content: 'Não informado.'; color: #aaa; font-style: italic; }

        /* Assinatura */
        .pdf-signature { border-top: 2px solid #003366; padding-top: 1rem; margin-top: 2rem; display: flex; justify-content: space-between; align-items: flex-start; }
        .pdf-sig-info { font-size: .8rem; }
        .pdf-sig-info strong { display: block; font-size: .9rem; color: #003366; }
        .pdf-sig-info span { color: #555; }
        .pdf-hash { font-size: .65rem; color: #999; word-break: break-all; margin-top: .5rem; }

        /* Rodapé */
        .pdf-footer { border-top: 1px solid #ccc; padding-top: .75rem; margin-top: 2rem; display: flex; justify-content: space-between; align-items: center; font-size: .7rem; color: #888; }

        /* Botões de ação (não imprimem) */
        .pdf-actions { display: flex; gap: .5rem; margin-bottom: 1.5rem; }
        .pdf-actions button, .pdf-actions a {
            padding: .4rem .9rem; border-radius: 4px; font-size: .8rem; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: .3rem;
        }
        .btn-print { background: #003366; color: #fff; border: none; }
        .btn-back  { background: #f0f4f8; color: #333; border: 1px solid #ccc; }
        @media print {
            .pdf-actions { display: none !important; }
            body { background: #fff; }
        }
    </style>
</head>
<body>
<div class="pdf-page">

    <!-- Ações -->
    <div class="pdf-actions">
        <button class="btn-print" onclick="window.print()">🖨️ Imprimir</button>
        <a href="/reports/pdf?report_id=<?= (int)($r['id'] ?? 0) ?>&download=1" class="btn-back">⬇️ Baixar PDF</a>
        <a href="/estudos" class="btn-back">← Worklist</a>
    </div>

    <!-- Cabeçalho -->
    <div class="pdf-header">
        <div class="pdf-logo-area">
            <h1>VOXEL PACS</h1>
            <p><?= htmlspecialchars($r['tenant_nome'] ?? 'Clínica', ENT_QUOTES) ?></p>
        </div>
        <div class="pdf-header-info">
            <strong>LAUDO MÉDICO</strong>
            <span>Data: <?= $r['study_date'] ? date('d/m/Y', strtotime($r['study_date'])) : '—' ?></span><br>
            <span>Modalidade: <?= htmlspecialchars($r['modalities'] ?? '—', ENT_QUOTES) ?></span><br>
            <span>Accession: <?= htmlspecialchars($r['accession_number'] ?? '—', ENT_QUOTES) ?></span>
        </div>
    </div>

    <!-- Dados do paciente -->
    <div class="pdf-patient-box">
        <h2><?= $paciente ?></h2>
        <div class="pdf-patient-grid">
            <div class="pdf-pinfo"><span>Sexo: </span><strong><?= htmlspecialchars($r['patient_sex'] ?? '—', ENT_QUOTES) ?></strong></div>
            <div class="pdf-pinfo"><span>Nascimento: </span><strong><?= $r['patient_birth_date'] ? date('d/m/Y', strtotime($r['patient_birth_date'])) : '—' ?></strong></div>
            <div class="pdf-pinfo"><span>Idade: </span><strong><?= htmlspecialchars($r['patient_age'] ?? '—', ENT_QUOTES) ?></strong></div>
            <div class="pdf-pinfo"><span>Prontuário: </span><strong><?= htmlspecialchars($r['patient_id'] ?? '—', ENT_QUOTES) ?></strong></div>
            <div class="pdf-pinfo"><span>Instituição: </span><strong><?= htmlspecialchars($r['institution_name'] ?? '—', ENT_QUOTES) ?></strong></div>
            <div class="pdf-pinfo"><span>Solicitante: </span><strong><?= htmlspecialchars($r['referring_physician_name'] ?? '—', ENT_QUOTES) ?></strong></div>
        </div>
    </div>

    <!-- Exame -->
    <div class="pdf-section">
        <div class="pdf-section-title">Exame</div>
        <div class="pdf-section-content"><?= $r['secao_exame'] ?? '' ?></div>
    </div>

    <!-- Técnica -->
    <div class="pdf-section">
        <div class="pdf-section-title">Técnica</div>
        <div class="pdf-section-content"><?= $r['secao_tecnica'] ?? '' ?></div>
    </div>

    <!-- Achados -->
    <div class="pdf-section">
        <div class="pdf-section-title">Achados</div>
        <div class="pdf-section-content"><?= $r['secao_achados'] ?? '' ?></div>
    </div>

    <!-- Conclusão -->
    <div class="pdf-section">
        <div class="pdf-section-title">Conclusão</div>
        <div class="pdf-section-content"><?= $r['secao_conclusao'] ?? '' ?></div>
    </div>

    <!-- Recomendação -->
    <?php if (!empty($r['secao_recomendacao'])): ?>
    <div class="pdf-section">
        <div class="pdf-section-title">Recomendação</div>
        <div class="pdf-section-content"><?= $r['secao_recomendacao'] ?></div>
    </div>
    <?php endif; ?>

    <!-- Assinatura -->
    <div class="pdf-signature">
        <div class="pdf-sig-info">
            <strong><?= htmlspecialchars($r['medico_nome'] ?? '—', ENT_QUOTES) ?></strong>
            <span>CRM: <?= htmlspecialchars($r['medico_crm'] ?? '—', ENT_QUOTES) ?></span><br>
            <span>Assinado em: <?= $r['assinado_em'] ? date('d/m/Y H:i', strtotime($r['assinado_em'])) : 'Não assinado' ?></span>
            <?php if (!empty($r['assinatura_hash'])): ?>
            <div class="pdf-hash">Hash: <?= htmlspecialchars($r['assinatura_hash'], ENT_QUOTES) ?></div>
            <?php endif; ?>
        </div>
        <div style="text-align:center;font-size:.65rem;color:#888">
            <div style="width:80px;height:80px;background:#f0f4f8;border:1px solid #ccc;display:flex;align-items:center;justify-content:center;font-size:.6rem;color:#aaa">
                QR Code<br>(em breve)
            </div>
            <div style="margin-top:.25rem">Verificação Digital</div>
        </div>
    </div>

    <!-- Rodapé -->
    <div class="pdf-footer">
        <span>VOXEL PACS — Sistema de Laudos Médicos</span>
        <span>Laudo ID: <?= (int)($r['id'] ?? 0) ?></span>
        <span>Gerado em: <?= date('d/m/Y H:i') ?></span>
    </div>

</div>
<?php if ($download): ?>
<script>window.onload = function() { window.print(); }</script>
<?php endif; ?>
</body>
</html>

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
