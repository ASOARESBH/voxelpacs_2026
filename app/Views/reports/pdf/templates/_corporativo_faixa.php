<?php
/**
 * Template de Laudo — "Corporativo com Faixa".
 * Faixa de topo colorida (logo à esquerda, dados da instituição à direita,
 * mesma linha), paciente/solicitante em duas colunas, corpo com subtítulos
 * em negrito ("Técnica:", "Análise:", "Impressão:" — rótulos alternativos
 * de exame/achados/conclusão, só nesta apresentação — não altera as chaves
 * internas nem o editor), assinatura à direita, rodapé com endereço completo.
 *
 * Variáveis recebidas do dispatcher (reports/pdf.php): $r, $paciente, $download.
 */
$unidadeNome = $r['unidade_nome_fantasia'] ?? $r['unidade_razao_social'] ?? $r['tenant_nome'] ?? 'Clínica';
$cnpjFmt = '';
if (!empty($r['unidade_cnpj']) && strlen($r['unidade_cnpj']) === 14) {
    $c = $r['unidade_cnpj'];
    $cnpjFmt = substr($c,0,2).'.'.substr($c,2,3).'.'.substr($c,5,3).'/'.substr($c,8,4).'-'.substr($c,12,2);
}
$enderecoPartes = array_filter([
    trim(($r['unidade_logradouro'] ?? '') . ' ' . ($r['unidade_numero'] ?? '')),
    $r['unidade_bairro'] ?? '',
    trim(($r['unidade_cidade'] ?? '') . (!empty($r['unidade_estado']) ? '/' . $r['unidade_estado'] : '')),
]);
$enderecoCompleto = implode(' — ', $enderecoPartes);
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
        .pdf-page { max-width: 820px; margin: 0 auto; }
        .pdf-inner { padding: 0 2rem 2rem; }

        .pdf-band { background: linear-gradient(90deg, #0f3d63, #145a8c); color: #fff; padding: 1rem 2rem; display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
        .pdf-band-logo { width: 48px; height: 48px; border-radius: 6px; background: rgba(255,255,255,.15); display: flex; align-items: center; justify-content: center; flex-shrink: 0; overflow: hidden; }
        .pdf-band-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .pdf-band-nome { font-size: 1.1rem; font-weight: 800; }
        .pdf-band-sub { font-size: .7rem; opacity: .85; }
        .pdf-band-info { margin-left: auto; text-align: right; font-size: .72rem; line-height: 1.5; opacity: .95; }

        .pdf-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem; }
        .pdf-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: .65rem .85rem; }
        .pdf-box h3 { font-size: .7rem; text-transform: uppercase; letter-spacing: .5px; color: #145a8c; margin-bottom: .4rem; }
        .pdf-box .linha { font-size: .74rem; margin-bottom: .15rem; }
        .pdf-box .linha span { color: #64748b; }
        .pdf-box .linha strong { color: #1e293b; }

        .pdf-section { margin-bottom: 1.1rem; }
        .pdf-section-title { font-size: .85rem; font-weight: 800; color: #0f3d63; margin-bottom: .35rem; }
        .pdf-section-content { font-size: .85rem; line-height: 1.7; color: #222; text-align: justify; }
        .pdf-section-content:empty::before { content: 'Não informado.'; color: #aaa; font-style: italic; }

        .pdf-signature { border-top: 2px solid #0f3d63; padding-top: 1rem; margin-top: 2rem; display: flex; justify-content: flex-end; }
        .pdf-sig-info { font-size: .8rem; text-align: right; }
        .pdf-sig-info strong { display: block; font-size: .9rem; color: #0f3d63; }
        .pdf-sig-info span { color: #555; }
        .pdf-hash { font-size: .65rem; color: #999; word-break: break-all; margin-top: .5rem; }

        .pdf-footer { background: #f1f5f9; padding: .75rem 2rem; margin-top: 1.5rem; font-size: .68rem; color: #475569; text-align: center; line-height: 1.6; }

        .pdf-actions { display: flex; gap: .5rem; padding: 1.5rem 2rem 0; }
        .pdf-actions button, .pdf-actions a {
            padding: .4rem .9rem; border-radius: 4px; font-size: .8rem; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: .3rem;
        }
        .btn-print { background: #0f3d63; color: #fff; border: none; }
        .btn-back  { background: #f0f4f8; color: #333; border: 1px solid #ccc; }
        @media print { .pdf-actions { display: none !important; } body { background: #fff; } }
    </style>
</head>
<body>
<div class="pdf-page">

    <div class="pdf-actions">
        <button class="btn-print" onclick="window.print()">🖨️ Imprimir</button>
        <a href="/reports/pdf?report_id=<?= (int)($r['id'] ?? 0) ?>&download=1" class="btn-back">⬇️ Baixar PDF</a>
        <a href="/estudos" class="btn-back">← Worklist</a>
    </div>

    <div class="pdf-band">
        <div class="pdf-band-logo">
            <?php if (!empty($r['unidade_logo_path'])): ?>
                <img src="/<?= htmlspecialchars($r['unidade_logo_path'], ENT_QUOTES) ?>" alt="Logo">
            <?php else: ?>
                <span style="font-size:.55rem;">LOGO</span>
            <?php endif; ?>
        </div>
        <div>
            <div class="pdf-band-nome"><?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?></div>
            <div class="pdf-band-sub">Laudo Médico</div>
        </div>
        <div class="pdf-band-info">
            <?php if ($cnpjFmt): ?><div>CNPJ <?= htmlspecialchars($cnpjFmt, ENT_QUOTES) ?></div><?php endif; ?>
            <?php if (!empty($r['unidade_telefone'])): ?><div><?= htmlspecialchars($r['unidade_telefone'], ENT_QUOTES) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="pdf-inner">
        <div class="pdf-two-col">
            <div class="pdf-box">
                <h3>Paciente</h3>
                <div class="linha"><strong><?= $paciente ?></strong></div>
                <div class="linha"><span>Sexo: </span><strong><?= htmlspecialchars($r['patient_sex'] ?? '—', ENT_QUOTES) ?></strong> · <span>Idade: </span><strong><?= htmlspecialchars($r['patient_age'] ?? '—', ENT_QUOTES) ?></strong></div>
                <div class="linha"><span>Nascimento: </span><strong><?= $r['patient_birth_date'] ? date('d/m/Y', strtotime($r['patient_birth_date'])) : '—' ?></strong></div>
                <div class="linha"><span>Prontuário: </span><strong><?= htmlspecialchars($r['patient_id'] ?? '—', ENT_QUOTES) ?></strong></div>
            </div>
            <div class="pdf-box">
                <h3>Exame / Solicitante</h3>
                <div class="linha"><span>Data: </span><strong><?= $r['study_date'] ? date('d/m/Y', strtotime($r['study_date'])) : '—' ?></strong> · <span>Modalidade: </span><strong><?= htmlspecialchars($r['modalities'] ?? '—', ENT_QUOTES) ?></strong></div>
                <div class="linha"><span>Accession: </span><strong><?= htmlspecialchars($r['accession_number'] ?? '—', ENT_QUOTES) ?></strong></div>
                <div class="linha"><span>Instituição: </span><strong><?= htmlspecialchars($r['institution_name'] ?? '—', ENT_QUOTES) ?></strong></div>
                <div class="linha"><span>Solicitante: </span><strong><?= htmlspecialchars(\App\Helpers\DicomPersonName::format($r['referring_physician_name'] ?? null) ?: '—', ENT_QUOTES) ?></strong></div>
            </div>
        </div>

        <div class="pdf-section">
            <div class="pdf-section-title">Exame</div>
            <div class="pdf-section-content"><?= $r['secao_exame'] ?? '' ?></div>
        </div>
        <div class="pdf-section">
            <div class="pdf-section-title">Técnica</div>
            <div class="pdf-section-content"><?= $r['secao_tecnica'] ?? '' ?></div>
        </div>
        <div class="pdf-section">
            <div class="pdf-section-title">Análise</div>
            <div class="pdf-section-content"><?= $r['secao_achados'] ?? '' ?></div>
        </div>
        <div class="pdf-section">
            <div class="pdf-section-title">Impressão</div>
            <div class="pdf-section-content"><?= $r['secao_conclusao'] ?? '' ?></div>
        </div>
        <?php if (!empty($r['secao_recomendacao'])): ?>
        <div class="pdf-section">
            <div class="pdf-section-title">Recomendação</div>
            <div class="pdf-section-content"><?= $r['secao_recomendacao'] ?></div>
        </div>
        <?php endif; ?>

        <div class="pdf-signature">
            <div class="pdf-sig-info">
                <?php if (!empty($r['assinatura_caminho_arquivo'])): ?>
                <img src="/reports/assinatura-imagem?report_id=<?= (int) ($r['id'] ?? 0) ?>"
                     alt="Assinatura de <?= htmlspecialchars($r['medico_nome'] ?? '', ENT_QUOTES) ?>"
                     style="max-width:220px;max-height:70px;display:block;margin:0 0 .35rem auto;">
                <?php endif; ?>
                <strong><?= htmlspecialchars($r['medico_nome'] ?? '—', ENT_QUOTES) ?></strong>
                <span>CRM: <?= htmlspecialchars($r['medico_crm'] ?? '—', ENT_QUOTES) ?></span><br>
                <span>Assinado em: <?= $r['assinado_em'] ? date('d/m/Y H:i', strtotime($r['assinado_em'])) : 'Não assinado' ?></span>
                <?php if (!empty($r['assinatura_hash'])): ?>
                <div class="pdf-hash">Hash: <?= htmlspecialchars($r['assinatura_hash'], ENT_QUOTES) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="pdf-footer">
        <div><strong><?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?></strong><?= $cnpjFmt ? ' — CNPJ ' . htmlspecialchars($cnpjFmt, ENT_QUOTES) : '' ?></div>
        <?php if ($enderecoCompleto): ?><div><?= htmlspecialchars($enderecoCompleto, ENT_QUOTES) ?></div><?php endif; ?>
        <?php if (!empty($r['unidade_telefone']) || !empty($r['unidade_email'])): ?>
        <div><?= htmlspecialchars($r['unidade_telefone'] ?? '', ENT_QUOTES) ?><?= (!empty($r['unidade_telefone']) && !empty($r['unidade_email'])) ? ' · ' : '' ?><?= htmlspecialchars($r['unidade_email'] ?? '', ENT_QUOTES) ?></div>
        <?php endif; ?>
        <div>Médico responsável: <?= htmlspecialchars($r['medico_nome'] ?? '—', ENT_QUOTES) ?> — CRM <?= htmlspecialchars($r['medico_crm'] ?? '—', ENT_QUOTES) ?> · Laudo ID <?= (int)($r['id'] ?? 0) ?></div>
    </div>

</div>
<?php if ($download): ?>
<script>window.onload = function() { window.print(); }</script>
<?php endif; ?>
</body>
</html>
