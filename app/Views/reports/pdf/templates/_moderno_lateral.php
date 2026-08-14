<?php
/**
 * Template de Laudo — "Moderno Lateral".
 * Logo à esquerda no cabeçalho, dados do paciente logo abaixo, corpo do
 * laudo centralizado, assinatura centralizada, rodapé minimalista
 * (nome + CNPJ da unidade).
 *
 * Variáveis recebidas do dispatcher (reports/pdf.php): $r, $paciente, $download.
 */
$unidadeNome = $r['unidade_nome_fantasia'] ?? $r['unidade_razao_social'] ?? $r['tenant_nome'] ?? 'Clínica';
$cnpjFmt = '';
if (!empty($r['unidade_cnpj']) && strlen($r['unidade_cnpj']) === 14) {
    $c = $r['unidade_cnpj'];
    $cnpjFmt = substr($c,0,2).'.'.substr($c,2,3).'.'.substr($c,5,3).'/'.substr($c,8,4).'-'.substr($c,12,2);
}
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

        .pdf-header { display: flex; align-items: center; gap: 1rem; border-bottom: 2px solid #1e293b; padding-bottom: 1rem; margin-bottom: 1.25rem; }
        .pdf-logo-box { width: 56px; height: 56px; border-radius: 6px; background: #f1f5f9; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center; flex-shrink: 0; overflow: hidden; }
        .pdf-logo-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .pdf-title-area h1 { font-size: 1.15rem; color: #1e293b; font-weight: 800; }
        .pdf-title-area p { font-size: .75rem; color: #64748b; }
        .pdf-header-badge { margin-left: auto; text-align: right; font-size: .7rem; color: #64748b; }
        .pdf-header-badge strong { display: block; font-size: .8rem; color: #1e293b; }

        .pdf-patient-box { background: #f8fafc; padding: .75rem 1rem; margin-bottom: 1.5rem; border-radius: 6px; }
        .pdf-patient-box h2 { font-size: .95rem; color: #1e293b; margin-bottom: .4rem; }
        .pdf-patient-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: .25rem .5rem; }
        .pdf-pinfo { font-size: .72rem; }
        .pdf-pinfo span { color: #64748b; }
        .pdf-pinfo strong { color: #1e293b; }

        .pdf-section { margin-bottom: 1.2rem; text-align: center; }
        .pdf-section-title { font-size: .8rem; font-weight: 700; text-transform: uppercase; color: #1e293b; margin-bottom: .4rem; letter-spacing: .6px; }
        .pdf-section-content { font-size: .85rem; line-height: 1.7; color: #222; text-align: left; max-width: 620px; margin: 0 auto; }
        .pdf-section-content:empty::before { content: 'Não informado.'; color: #aaa; font-style: italic; }

        .pdf-signature { border-top: 1px solid #e2e8f0; padding-top: 1rem; margin-top: 2rem; text-align: center; }
        .pdf-sig-info { font-size: .8rem; display: inline-block; }
        .pdf-sig-info strong { display: block; font-size: .9rem; color: #1e293b; }
        .pdf-sig-info span { color: #64748b; }
        .pdf-hash { font-size: .65rem; color: #999; word-break: break-all; margin-top: .5rem; }

        .pdf-footer { border-top: 1px solid #e2e8f0; padding-top: .6rem; margin-top: 1.5rem; text-align: center; font-size: .68rem; color: #94a3b8; }

        .pdf-actions { display: flex; gap: .5rem; margin-bottom: 1.5rem; }
        .pdf-actions button, .pdf-actions a {
            padding: .4rem .9rem; border-radius: 4px; font-size: .8rem; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: .3rem;
        }
        .btn-print { background: #1e293b; color: #fff; border: none; }
        .btn-back  { background: #f1f5f9; color: #333; border: 1px solid #ccc; }
        @media print { .pdf-actions { display: none !important; } body { background: #fff; } }
    </style>
</head>
<body>
<div class="pdf-page">

    <div class="pdf-actions">
        <button class="btn-print" onclick="window.print()">🖨️ Imprimir</button>
        <a href="/reports/r/<?= rawurlencode((string) ($r['public_token'] ?? '')) ?>/pdf?download=1" class="btn-back">⬇️ Baixar PDF</a>
        <a href="/estudos" class="btn-back">← Worklist</a>
    </div>

    <div class="pdf-header">
        <div class="pdf-logo-box">
            <?php if (!empty($r['unidade_logo_path'])): ?>
                <img src="/<?= htmlspecialchars($r['unidade_logo_path'], ENT_QUOTES) ?>" alt="Logo">
            <?php else: ?>
                <span style="font-size:.6rem;color:#94a3b8;">LOGO</span>
            <?php endif; ?>
        </div>
        <div class="pdf-title-area">
            <h1><?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?></h1>
            <p>Laudo Médico</p>
        </div>
        <div class="pdf-header-badge">
            <strong><?= $r['study_date'] ? date('d/m/Y', strtotime($r['study_date'])) : '—' ?></strong>
            <span><?= htmlspecialchars($r['modalities'] ?? '—', ENT_QUOTES) ?> · Acc. <?= htmlspecialchars($r['accession_number'] ?? '—', ENT_QUOTES) ?></span>
        </div>
    </div>

    <div class="pdf-patient-box">
        <h2><?= $paciente ?></h2>
        <div class="pdf-patient-grid">
            <div class="pdf-pinfo"><span>Sexo: </span><strong><?= htmlspecialchars($r['patient_sex'] ?? '—', ENT_QUOTES) ?></strong></div>
            <div class="pdf-pinfo"><span>Nascimento: </span><strong><?= $r['patient_birth_date'] ? date('d/m/Y', strtotime($r['patient_birth_date'])) : '—' ?></strong></div>
            <div class="pdf-pinfo"><span>Idade: </span><strong><?= htmlspecialchars($r['patient_age'] ?? '—', ENT_QUOTES) ?></strong></div>
            <div class="pdf-pinfo"><span>Prontuário: </span><strong><?= htmlspecialchars($r['patient_id'] ?? '—', ENT_QUOTES) ?></strong></div>
            <div class="pdf-pinfo"><span>Instituição: </span><strong><?= htmlspecialchars($r['institution_name'] ?? '—', ENT_QUOTES) ?></strong></div>
            <div class="pdf-pinfo"><span>Solicitante: </span><strong><?= htmlspecialchars(\App\Helpers\DicomPersonName::format($r['referring_physician_name'] ?? null) ?: '—', ENT_QUOTES) ?></strong></div>
        </div>
    </div>

    <?php if (trim(strip_tags($corpoLaudo)) !== ''): ?>
    <div class="pdf-section pdf-section-free">
        <div class="pdf-section-content"><?= $corpoLaudo ?></div>
    </div>
    <?php endif; ?>

    <div class="pdf-signature">
        <div class="pdf-sig-info">
            <?php if (!empty($r['assinatura_caminho_arquivo'])): ?>
            <img src="/reports/r/<?= rawurlencode((string) ($r['public_token'] ?? '')) ?>/assinatura"
                 alt="Assinatura de <?= htmlspecialchars($r['medico_nome'] ?? '', ENT_QUOTES) ?>"
                 style="max-width:220px;max-height:70px;display:block;margin:0 auto .35rem;">
            <?php endif; ?>
            <strong><?= htmlspecialchars($r['medico_nome'] ?? '—', ENT_QUOTES) ?></strong>
            <span>CRM: <?= htmlspecialchars($r['medico_crm'] ?? '—', ENT_QUOTES) ?></span><br>
            <span>Assinado em: <?= $r['assinado_em'] ? date('d/m/Y H:i', strtotime($r['assinado_em'])) : 'Não assinado' ?></span>
            <?php if (!empty($r['assinatura_hash'])): ?>
            <div class="pdf-hash">Hash: <?= htmlspecialchars($r['assinatura_hash'], ENT_QUOTES) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="pdf-footer">
        <?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?><?= $cnpjFmt ? ' · CNPJ ' . htmlspecialchars($cnpjFmt, ENT_QUOTES) : '' ?>
    </div>

</div>
<?php if ($download): ?>
<script>window.onload = function() { window.print(); }</script>
<?php endif; ?>
</body>
</html>
