<?php
/**
 * Template de Laudo — "Minimalista".
 * Cabeçalho só em texto (sem logo — para unidade sem arte gráfica pronta),
 * dados do paciente numa linha compacta, corpo com espaçamento generoso,
 * assinatura à esquerda (nome/CRM abaixo da imagem), rodapé discreto
 * (nome da instituição + número do laudo).
 *
 * Variáveis recebidas do dispatcher (reports/pdf.php): $r, $paciente, $download.
 */
$unidadeNome = $r['unidade_nome_fantasia'] ?? $r['unidade_razao_social'] ?? $r['tenant_nome'] ?? 'Clínica';
$formatarCnpj = static function (?string $valor): string {
    $digitos = preg_replace('/\\D/', '', (string) $valor) ?? '';
    if (strlen($digitos) !== 14) return trim((string) $valor);
    return substr($digitos, 0, 2) . '.' . substr($digitos, 2, 3) . '.' . substr($digitos, 5, 3) . '/' . substr($digitos, 8, 4) . '-' . substr($digitos, 12, 2);
};
$unidadeCnpj = $formatarCnpj($r['unidade_cnpj'] ?? null);
$unidadeTelefone = trim((string) ($r['unidade_telefone'] ?? ''));
$unidadeEnderecoPartes = array_filter([
    trim((string) ($r['unidade_logradouro'] ?? '') . (($r['unidade_numero'] ?? '') !== '' ? ', ' . $r['unidade_numero'] : '')),
    trim((string) ($r['unidade_complemento'] ?? '')),
    trim((string) ($r['unidade_bairro'] ?? '')),
    trim((string) ($r['unidade_cidade'] ?? '') . (($r['unidade_estado'] ?? '') !== '' ? '/' . $r['unidade_estado'] : '')),
]);
$unidadeEndereco = implode(' — ', $unidadeEnderecoPartes);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laudo — <?= $paciente ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Arial', sans-serif; font-size: 12px; color: #333; background: #fff; }
        .pdf-page { max-width: 760px; margin: 0 auto; padding: 2.25rem 2rem; }

        .pdf-header { display: flex; justify-content: space-between; align-items: baseline; padding-bottom: .75rem; margin-bottom: 1.25rem; border-bottom: 1px solid #ddd; }
        .pdf-header-nome { font-size: 1rem; font-weight: 700; color: #111; }
        .pdf-header-contato { font-size: .68rem; color: #888; text-align: right; }

        .pdf-patient-line { font-size: .78rem; color: #333; margin-bottom: 1.75rem; }
        .pdf-patient-line strong { color: #111; }
        .pdf-patient-line .sep { color: #ccc; margin: 0 .4rem; }

        .pdf-section { margin-bottom: 1.6rem; }
        .pdf-section-title { font-size: .78rem; font-weight: 700; text-transform: uppercase; color: #555; letter-spacing: .8px; margin-bottom: .5rem; }
        .pdf-section-content { font-size: .85rem; line-height: 1.8; color: #222; text-align: justify; }
        .pdf-section-content .ql-align-center { text-align: center; }
        .pdf-section-content .ql-align-right { text-align: right; }
        .pdf-section-content .ql-align-justify { text-align: justify; }
        .pdf-section-content a { color: #075a9e; text-decoration: underline; word-break: break-word; }
        .pdf-section-content:empty::before { content: 'Não informado.'; color: #bbb; font-style: italic; }

        .pdf-signature { margin-top: 2.5rem; padding-top: 1rem; border-top: 1px solid #ddd; text-align: left; }
        .pdf-sig-img { max-width: 180px; max-height: 60px; display: block; margin-bottom: .3rem; }
        .pdf-sig-line { width: 180px; border-top: 1px solid #999; margin-bottom: .3rem; }
        .pdf-sig-info { font-size: .78rem; }
        .pdf-sig-info strong { display: block; font-size: .82rem; color: #111; }
        .pdf-sig-info span { color: #777; }
        .pdf-hash { font-size: .62rem; color: #bbb; word-break: break-all; margin-top: .4rem; }

        .pdf-footer { margin-top: 2rem; font-size: .65rem; color: #aaa; line-height: 1.45; text-align: center; }
        .pdf-footer-name { color: #666; font-weight: 700; }
        .pdf-footer-details { margin-top: .2rem; }

        .pdf-actions { display: flex; gap: .5rem; margin-bottom: 1.5rem; }
        .pdf-actions button, .pdf-actions a {
            padding: .4rem .9rem; border-radius: 4px; font-size: .8rem; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: .3rem;
        }
        .btn-print { background: #333; color: #fff; border: none; }
        .btn-back  { background: #f5f5f5; color: #333; border: 1px solid #ddd; }
        @media print { .pdf-actions { display: none !important; } body { background: #fff; } }
    </style>
</head>
<body>
<div class="pdf-page">

    <?php if (empty($snapshotPdf)): ?>
    <div class="pdf-actions">
        <button class="btn-print" onclick="window.print()">🖨️ Imprimir</button>
        <a href="/reports/r/<?= rawurlencode((string) ($r['public_token'] ?? '')) ?>/pdf?download=1" class="btn-back">⬇️ Baixar PDF</a>
        <?php if (!$portalPatientPdf): ?>
            <a href="<?= htmlspecialchars($reportReturnUrl, ENT_QUOTES) ?>" class="btn-back" data-voxel-voltar="<?= htmlspecialchars($reportReturnUrl, ENT_QUOTES) ?>">← Voltar ao Laudário</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="pdf-header">
        <div class="pdf-header-nome"><?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?></div>
        <div class="pdf-header-contato">
            <?= htmlspecialchars($r['unidade_telefone'] ?? '', ENT_QUOTES) ?>
            <?php if (!empty($r['unidade_telefone']) && !empty($r['unidade_email'])): ?> · <?php endif; ?>
            <?= htmlspecialchars($r['unidade_email'] ?? '', ENT_QUOTES) ?>
        </div>
    </div>

    <div class="pdf-patient-line">
        <strong><?= $paciente ?></strong>
        <span class="sep">|</span><?= htmlspecialchars($r['patient_sex'] ?? '—', ENT_QUOTES) ?>
        <span class="sep">|</span><?= $r['patient_birth_date'] ? date('d/m/Y', strtotime($r['patient_birth_date'])) : '—' ?> (<?= htmlspecialchars($r['patient_age'] ?? '—', ENT_QUOTES) ?>)
        <span class="sep">|</span>Prontuário <?= htmlspecialchars($r['patient_id'] ?? '—', ENT_QUOTES) ?>
        <span class="sep">|</span><?= $r['study_date'] ? date('d/m/Y', strtotime($r['study_date'])) : '—' ?> · <?= htmlspecialchars($r['modalities'] ?? '—', ENT_QUOTES) ?>
        <span class="sep">|</span>Solicitante: <?= htmlspecialchars(\App\Helpers\DicomPersonName::format($r['referring_physician_name'] ?? null) ?: '—', ENT_QUOTES) ?>
    </div>

    <?php if (trim(strip_tags($corpoLaudo)) !== ''): ?>
    <div class="pdf-section pdf-section-free">
        <div class="pdf-section-content"><?= $corpoLaudo ?></div>
    </div>
    <?php endif; ?>

    <div class="pdf-signature">
        <?php $assinaturaSrc = (string) ($r['pdf_snapshot_signature_src'] ?? ''); ?>
        <?php if ($assinaturaSrc === '' && !empty($r['assinatura_caminho_arquivo'])) $assinaturaSrc = '/reports/r/' . rawurlencode((string) ($r['public_token'] ?? '')) . '/assinatura'; ?>
        <?php if ($assinaturaSrc !== ''): ?>
        <img class="pdf-sig-img" src="<?= htmlspecialchars($assinaturaSrc, ENT_QUOTES) ?>"
             alt="Assinatura de <?= htmlspecialchars($r['medico_nome'] ?? '', ENT_QUOTES) ?>">
        <?php else: ?>
        <div class="pdf-sig-line"></div>
        <?php endif; ?>
        <div class="pdf-sig-info">
            <strong><?= htmlspecialchars($r['medico_nome'] ?? '—', ENT_QUOTES) ?></strong>
            <span>CRM <?= htmlspecialchars($r['medico_crm'] ?? '—', ENT_QUOTES) ?> · Assinado em <?= $r['assinado_em'] ? date('d/m/Y H:i', strtotime($r['assinado_em'])) : 'não assinado' ?></span>
            <?php if (!empty($r['assinatura_hash'])): ?>
            <div class="pdf-hash">Hash: <?= htmlspecialchars($r['assinatura_hash'], ENT_QUOTES) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <footer class="pdf-footer" aria-label="Identificação da unidade">
        <div class="pdf-footer-name"><?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?></div>
        <?php if ($unidadeCnpj !== '' || $unidadeTelefone !== '' || $unidadeEndereco !== ''): ?>
            <div class="pdf-footer-details">
                <?php if ($unidadeCnpj !== ''): ?>CNPJ <?= htmlspecialchars($unidadeCnpj, ENT_QUOTES) ?><?php endif; ?>
                <?php if ($unidadeCnpj !== '' && ($unidadeTelefone !== '' || $unidadeEndereco !== '')): ?> · <?php endif; ?>
                <?php if ($unidadeTelefone !== ''): ?>Telefone <?= htmlspecialchars($unidadeTelefone, ENT_QUOTES) ?><?php endif; ?>
                <?php if ($unidadeTelefone !== '' && $unidadeEndereco !== ''): ?> · <?php endif; ?>
                <?php if ($unidadeEndereco !== ''): ?><?= htmlspecialchars($unidadeEndereco, ENT_QUOTES) ?><?php endif; ?>
            </div>
        <?php endif; ?>
    </footer>

</div>
<?php if ($download): ?>
<script>window.onload = function() { window.print(); }</script>
<?php endif; ?>
</body>
</html>
