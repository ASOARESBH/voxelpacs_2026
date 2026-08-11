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
        .pdf-section-content:empty::before { content: 'Não informado.'; color: #bbb; font-style: italic; }

        .pdf-signature { margin-top: 2.5rem; padding-top: 1rem; border-top: 1px solid #ddd; text-align: left; }
        .pdf-sig-img { max-width: 180px; max-height: 60px; display: block; margin-bottom: .3rem; }
        .pdf-sig-line { width: 180px; border-top: 1px solid #999; margin-bottom: .3rem; }
        .pdf-sig-info { font-size: .78rem; }
        .pdf-sig-info strong { display: block; font-size: .82rem; color: #111; }
        .pdf-sig-info span { color: #777; }
        .pdf-hash { font-size: .62rem; color: #bbb; word-break: break-all; margin-top: .4rem; }

        .pdf-footer { margin-top: 2rem; font-size: .65rem; color: #aaa; text-align: center; }

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

    <div class="pdf-actions">
        <button class="btn-print" onclick="window.print()">🖨️ Imprimir</button>
        <a href="/reports/pdf?report_id=<?= (int)($r['id'] ?? 0) ?>&download=1" class="btn-back">⬇️ Baixar PDF</a>
        <a href="/estudos" class="btn-back">← Worklist</a>
    </div>

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

    <div class="pdf-section">
        <div class="pdf-section-title">Exame</div>
        <div class="pdf-section-content"><?= $r['secao_exame'] ?? '' ?></div>
    </div>
    <div class="pdf-section">
        <div class="pdf-section-title">Técnica</div>
        <div class="pdf-section-content"><?= $r['secao_tecnica'] ?? '' ?></div>
    </div>
    <div class="pdf-section">
        <div class="pdf-section-title">Achados</div>
        <div class="pdf-section-content"><?= $r['secao_achados'] ?? '' ?></div>
    </div>
    <div class="pdf-section">
        <div class="pdf-section-title">Conclusão</div>
        <div class="pdf-section-content"><?= $r['secao_conclusao'] ?? '' ?></div>
    </div>
    <?php if (!empty($r['secao_recomendacao'])): ?>
    <div class="pdf-section">
        <div class="pdf-section-title">Recomendação</div>
        <div class="pdf-section-content"><?= $r['secao_recomendacao'] ?></div>
    </div>
    <?php endif; ?>

    <div class="pdf-signature">
        <?php if (!empty($r['assinatura_caminho_arquivo'])): ?>
        <img class="pdf-sig-img" src="/reports/assinatura-imagem?report_id=<?= (int) ($r['id'] ?? 0) ?>"
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

    <div class="pdf-footer">
        <?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?> — Laudo <?= (int)($r['id'] ?? 0) ?>
    </div>

</div>
<?php if ($download): ?>
<script>window.onload = function() { window.print(); }</script>
<?php endif; ?>
</body>
</html>
