<?php
/**
 * Template de Laudo — "Clássico Centralizado" (padrão do sistema).
 * Logo/nome centralizados no cabeçalho, corpo justificado à esquerda,
 * assinatura centralizada, rodapé simples.
 *
 * Conteúdo idêntico ao antigo app/Views/reports/pdf.php (pré-2026-08-11) —
 * é o template aplicado quando a unidade não escolheu nenhum (ver
 * App\Services\ReportLayoutService::PADRAO), preservando o visual que já
 * existia para toda unidade não configurada.
 *
 * Variáveis recebidas do dispatcher (reports/pdf.php): $r, $paciente, $download.
 */
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
            <div class="pdf-pinfo"><span>Solicitante: </span><strong><?= htmlspecialchars(\App\Helpers\DicomPersonName::format($r['referring_physician_name'] ?? null) ?: '—', ENT_QUOTES) ?></strong></div>
        </div>
    </div>

    <?php if (trim(strip_tags($corpoLaudo)) !== ''): ?>
    <div class="pdf-section pdf-section-free">
        <div class="pdf-section-content"><?= $corpoLaudo ?></div>
    </div>
    <?php endif; ?>

    <!-- Assinatura -->
    <div class="pdf-signature">
        <div class="pdf-sig-info">
            <?php if (!empty($r['assinatura_caminho_arquivo'])): ?>
            <img src="/reports/assinatura-imagem?report_id=<?= (int) ($r['id'] ?? 0) ?>"
                 alt="Assinatura de <?= htmlspecialchars($r['medico_nome'] ?? '', ENT_QUOTES) ?>"
                 style="max-width:220px;max-height:70px;display:block;margin-bottom:.35rem;">
            <?php endif; ?>
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
