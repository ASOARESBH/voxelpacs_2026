<?php
/** @var array $mascara */
$mascara = $mascara ?? [];
$medicoId = (int) ($medicoId ?? 0);
$nome = htmlspecialchars((string) ($mascara['nome'] ?? 'Máscara de Laudo'), ENT_QUOTES, 'UTF-8');
$modalidade = htmlspecialchars((string) ($mascara['modalidade'] ?? '—'), ENT_QUOTES, 'UTF-8');
$tecnica = (string) ($mascara['secao_tecnica'] ?? '');
$achados = (string) ($mascara['secao_achados'] ?? '');
$impressao = (string) ($mascara['secao_conclusao'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pré-visualização — <?= $nome ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 12px; color: #222; background: #eef2f7; }
        .pdf-page { max-width: 800px; min-height: 100vh; margin: 0 auto; padding: 2rem; background: #fff; }
        .pdf-actions { display: flex; gap: .5rem; margin-bottom: 1.25rem; }
        .pdf-actions button, .pdf-actions a {
            padding: .4rem .9rem; border-radius: 4px; font-size: .8rem; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: .3rem;
        }
        .btn-print { background: #003366; color: #fff; border: none; }
        .btn-back { background: #f0f4f8; color: #333; border: 1px solid #ccd5df; }
        .preview-watermark {
            margin-bottom: 1.25rem; padding: .65rem 1rem; border: 1px solid #d99a20;
            border-left: 5px solid #bf7b00; background: #fff7df; color: #755000;
            font-size: .78rem; font-weight: 700; letter-spacing: .04em; text-align: center;
        }
        .preview-watermark small { display: block; margin-top: .2rem; font-size: .7rem; font-weight: 400; letter-spacing: 0; }
        .pdf-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #003366; padding-bottom: 1rem; margin-bottom: 1.5rem; }
        .pdf-logo-area h1 { font-size: 1.4rem; color: #003366; font-weight: 900; letter-spacing: 1px; }
        .pdf-logo-area p { font-size: .75rem; color: #666; }
        .pdf-header-info { text-align: right; font-size: .75rem; color: #555; max-width: 52%; }
        .pdf-header-info strong { display: block; font-size: .85rem; color: #003366; text-transform: uppercase; }
        .pdf-header-info span { display: block; margin-top: .2rem; overflow-wrap: anywhere; }
        .pdf-section { margin-bottom: 1.2rem; }
        .pdf-section-title { font-size: .85rem; font-weight: 700; text-transform: uppercase; color: #003366; border-bottom: 1px solid #ccd; padding-bottom: .25rem; margin-bottom: .5rem; letter-spacing: .5px; }
        .pdf-section-content { font-size: .85rem; line-height: 1.7; color: #222; overflow-wrap: anywhere; }
        .pdf-section-content p { margin: 0 0 .55rem; }
        .pdf-section-content p:last-child { margin-bottom: 0; }
        .pdf-footer { border-top: 1px solid #ccc; padding-top: .75rem; margin-top: 2rem; display: flex; justify-content: space-between; align-items: center; font-size: .7rem; color: #888; }
        @media print {
            body { background: #fff; }
            .pdf-page { max-width: none; padding: 0; }
            .pdf-actions { display: none !important; }
            .preview-watermark { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }
        @media (max-width: 640px) {
            .pdf-page { padding: 1rem; }
            .pdf-header { display: block; }
            .pdf-header-info { max-width: none; text-align: left; margin-top: .65rem; }
        }
    </style>
</head>
<body>
<div class="pdf-page">
    <div class="pdf-actions">
        <button type="button" class="btn-print" onclick="window.print()">🖨️ Imprimir</button>
        <a class="btn-back" href="/medicos/<?= $medicoId ?>/edit?aba=mascaras">← Voltar para Máscaras</a>
    </div>

    <div class="preview-watermark">
        PRÉ-VISUALIZAÇÃO DE MÁSCARA
        <small>Este conteúdo não está vinculado a nenhum paciente ou estudo real e não representa um laudo assinado.</small>
    </div>

    <header class="pdf-header">
        <div class="pdf-logo-area">
            <h1>VOXEL PACS</h1>
            <p>Pré-visualização de modelo de laudo</p>
        </div>
        <div class="pdf-header-info">
            <strong><?= $nome ?></strong>
            <span>Modalidade: <?= $modalidade ?></span>
        </div>
    </header>

    <?php if (trim(strip_tags($tecnica)) !== ''): ?>
    <section class="pdf-section">
        <h2 class="pdf-section-title">Técnica</h2>
        <div class="pdf-section-content"><?= $tecnica ?></div>
    </section>
    <?php endif; ?>

    <?php if (trim(strip_tags($achados)) !== ''): ?>
    <section class="pdf-section">
        <h2 class="pdf-section-title">Achados</h2>
        <div class="pdf-section-content"><?= $achados ?></div>
    </section>
    <?php endif; ?>

    <?php if (trim(strip_tags($impressao)) !== ''): ?>
    <section class="pdf-section">
        <h2 class="pdf-section-title">Impressão</h2>
        <div class="pdf-section-content"><?= $impressao ?></div>
    </section>
    <?php endif; ?>

    <footer class="pdf-footer">
        <span>VOXEL PACS — Sistema de Laudos Médicos</span>
        <span>Pré-visualização de máscara</span>
        <span>Gerado em: <?= date('d/m/Y H:i') ?></span>
    </footer>
</div>
</body>
</html>
