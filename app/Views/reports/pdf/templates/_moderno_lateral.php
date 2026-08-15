<?php
/**
 * Template de Laudo — "Moderno Lateral".
 *
 * Composição institucional inspirada no modelo Orix: cabeçalho em duas colunas,
 * identificação clínica compacta, texto livre em uma coluna e assinatura
 * centralizada. Esta é a única view usada para visualização, impressão e
 * "Salvar como PDF" pelo navegador.
 *
 * Variáveis recebidas do dispatcher (reports/pdf.php): $r, $paciente, $download.
 */
$unidadeNome = trim((string) ($r['unidade_nome_fantasia'] ?? ''));
if ($unidadeNome === '') {
    $unidadeNome = trim((string) ($r['unidade_razao_social'] ?? ''));
}
if ($unidadeNome === '') {
    $unidadeNome = (string) ($r['tenant_nome'] ?? 'Clínica');
}

$formatarData = static function (?string $valor): string {
    if (!$valor) return '—';
    $timestamp = strtotime($valor);
    return $timestamp ? date('d/m/Y', $timestamp) : '—';
};

$formatarDataHora = static function (?string $valor): string {
    if (!$valor) return 'Não assinado';
    $timestamp = strtotime($valor);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : 'Não assinado';
};

$solicitante = \App\Helpers\DicomPersonName::format($r['referring_physician_name'] ?? null) ?: '—';
$descricaoExame = trim((string) ($r['study_description'] ?? ''));
if ($descricaoExame === '') {
    $descricaoExame = trim((string) ($r['requested_procedure_desc'] ?? ''));
}
if ($descricaoExame === '') {
    $descricaoExame = trim((string) ($r['body_part_examined'] ?? ''));
}
if ($descricaoExame === '') {
    $descricaoExame = trim((string) ($r['modalities'] ?? 'Laudo Médico'));
}
// A Máscara define o título clínico do laudo quando estiver vinculada ao report.
// Sem Máscara, mantém o Study Description/modalidade como fallback seguro.
$tituloLaudo = trim((string) ($tituloMascara ?? '')) ?: $descricaoExame;
$logoUnidade = trim((string) ($r['unidade_logo_path'] ?? ''));
$crm = trim((string) ($r['medico_crm'] ?? ''));
$crmExibicao = $crm === '' ? '—' : (preg_match('/\bCRM\b/i', $crm) ? $crm : 'CRM ' . $crm);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laudo — <?= $paciente ?></title>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #f1f5f9; }
        body { color: #171717; font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 1.42; }
        .pdf-page { display: flex; flex-direction: column; width: min(100%, 210mm); min-height: 297mm; margin: 22px auto; padding: 18mm 18mm 23mm; background: #fff; box-shadow: 0 6px 30px rgba(15,23,42,.13); }
        .pdf-actions { display: flex; flex-wrap: wrap; gap: 8px; width: min(100%, 210mm); margin: 22px auto 0; }
        .pdf-actions button, .pdf-actions a { display: inline-flex; align-items: center; gap: 5px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px; color: #1e293b; background: #fff; font: 700 12px Arial, sans-serif; text-decoration: none; cursor: pointer; }
        .pdf-actions .btn-print { border-color: #075a9e; background: #075a9e; color: #fff; }

        .pdf-header { display: table; width: 100%; padding-bottom: 9px; border-bottom: 1px solid #777; }
        .pdf-header-left, .pdf-header-right { display: table-cell; vertical-align: middle; }
        .pdf-header-left { width: 62%; padding-right: 12px; }
        .pdf-header-right { width: 38%; padding-left: 18px; text-align: right; }
        .pdf-logo { display: block; max-width: 196px; max-height: 78px; object-fit: contain; object-position: left center; }
        .pdf-logo-fallback { color: #075a9e; font-size: 22px; font-weight: 700; letter-spacing: .2px; }
        .pdf-header-right strong { display: block; font-size: 15px; line-height: 1.1; letter-spacing: .1px; }
        .pdf-header-right span { display: block; margin-top: 4px; color: #404040; font-size: 9px; font-weight: 700; letter-spacing: .7px; text-transform: uppercase; }
        .pdf-header-rules { display: inline-block; width: 106px; margin-top: 10px; border-top: 1px solid #d4d4d4; border-bottom: 1px solid #d4d4d4; height: 6px; }

        .pdf-patient { display: table; width: 100%; margin-top: 11px; padding: 0 0 11px; border-bottom: 1px solid #777; }
        .pdf-patient-col { display: table-cell; width: 50%; vertical-align: top; }
        .pdf-patient-col:first-child { padding-right: 16px; }
        .pdf-patient-col:last-child { padding-left: 16px; }
        .pdf-patient-line { min-height: 17px; font-size: 10px; line-height: 1.55; }
        .pdf-patient-line strong { font-weight: 700; }

        .pdf-exam-title { margin: 29px 0 24px; color: #111; font-size: 17px; font-weight: 700; line-height: 1.34; text-align: center; text-transform: uppercase; }
        .pdf-report-content { color: #171717; font-size: 13px; line-height: 1.62; text-align: left; }
        .pdf-clinical-section { margin: 0 0 21px; page-break-inside: avoid; }
        .pdf-clinical-section:last-child { margin-bottom: 0; }
        .pdf-clinical-section-title { margin: 0 0 6px; color: #111; font-size: 12px; font-weight: 700; line-height: 1.35; text-transform: uppercase; }
        .pdf-clinical-section-content { font-size: 13px; line-height: 1.62; }
        .pdf-clinical-section-content p { margin: 0 0 11px; }
        .pdf-report-content h1, .pdf-report-content h2, .pdf-report-content h3, .pdf-report-content h4, .pdf-report-content h5, .pdf-report-content h6 { margin: 18px 0 6px; font: 700 12px Arial, Helvetica, sans-serif; }
        .pdf-report-content h1:first-child, .pdf-report-content h2:first-child, .pdf-report-content h3:first-child, .pdf-report-content h4:first-child, .pdf-report-content h5:first-child, .pdf-report-content h6:first-child { margin-top: 0; }
        .pdf-report-content p { margin: 0 0 9px; }
        .pdf-report-content ul, .pdf-report-content ol { margin: 0 0 9px 19px; padding: 0; }
        .pdf-report-content li { margin: 0 0 3px; }
        .pdf-report-empty { color: #737373; font-style: italic; }

        .pdf-signature { margin-top: auto; padding-top: 50px; text-align: center; page-break-inside: avoid; }
        .pdf-signature-image { display: block; max-width: 210px; max-height: 68px; margin: 0 auto 3px; object-fit: contain; }
        .pdf-signer-name { font-size: 10px; font-weight: 700; }
        .pdf-signer-details, .pdf-verification { color: #404040; font-size: 8px; line-height: 1.45; }
        .pdf-verification { margin-top: 6px; }
        .pdf-hash { max-width: 490px; margin: 3px auto 0; color: #737373; font-size: 6.5px; line-height: 1.25; overflow-wrap: anywhere; }

        .pdf-footer { margin-top: 12px; padding-top: 7px; border-top: 1px solid #e5e5e5; color: #525252; font-size: 7px; text-align: center; }

        @media (max-width: 680px) {
            .pdf-page { min-height: 0; margin: 0; padding: 22px; box-shadow: none; }
            .pdf-header, .pdf-patient { display: block; }
            .pdf-header-left, .pdf-header-right, .pdf-patient-col { display: block; width: 100%; padding: 0 !important; text-align: left; }
            .pdf-header-right { margin-top: 16px; }
            .pdf-header-rules { width: 100%; }
            .pdf-patient-col + .pdf-patient-col { margin-top: 8px; }
            .pdf-actions { width: 100%; margin: 0; padding: 12px; }
        }

        @media print {
            @page { size: A4 portrait; margin: 0; }
            html, body { width: 210mm; min-height: 297mm; background: #fff; }
            body { font-size: 11px; }
            .pdf-actions { display: none !important; }
            .pdf-page { display: flex; flex-direction: column; width: 210mm; min-height: 297mm; margin: 0; padding: 18mm 18mm 23mm; box-shadow: none; }
            .pdf-header, .pdf-patient, .pdf-signature, .pdf-footer { position: static; }
            .pdf-patient { margin-top: 11px; }
            .pdf-exam-title { margin-top: 29px; }
            .pdf-signature { margin-top: auto; padding-top: 50px; }
            .pdf-footer { margin-top: 12px; }
            .pdf-report-content, .pdf-clinical-section-content { font-size: 13px; line-height: 1.62; }
            .pdf-clinical-section-title { font-size: 12px; font-weight: 700; }
        }
    </style>
</head>
<body>
    <div class="pdf-actions">
        <button type="button" class="btn-print" onclick="window.print()">Imprimir</button>
        <a href="/reports/r/<?= rawurlencode((string) ($r['public_token'] ?? '')) ?>/pdf?download=1">Baixar PDF</a>
        <a href="/estudos">Voltar à Worklist</a>
    </div>

    <main class="pdf-page">
        <header class="pdf-header">
            <div class="pdf-header-left">
                <?php if ($logoUnidade !== ''): ?>
                    <img class="pdf-logo" src="/<?= htmlspecialchars($logoUnidade, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?>">
                <?php else: ?>
                    <div class="pdf-logo-fallback"><?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?></div>
                <?php endif; ?>
            </div>
            <div class="pdf-header-right">
                <strong>Laudo Médico</strong>
                <span><?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?></span>
                <i class="pdf-header-rules" aria-hidden="true"></i>
            </div>
        </header>

        <section class="pdf-patient" aria-label="Identificação do paciente e exame">
            <div class="pdf-patient-col">
                <div class="pdf-patient-line"><strong>Paciente:</strong> <?= $paciente ?></div>
                <div class="pdf-patient-line"><strong>Data de Nascimento:</strong> <?= $formatarData($r['patient_birth_date'] ?? null) ?></div>
                <div class="pdf-patient-line"><strong>Médico(a) Solicitante:</strong> <?= htmlspecialchars($solicitante, ENT_QUOTES) ?></div>
            </div>
            <div class="pdf-patient-col">
                <div class="pdf-patient-line"><strong>ID do Paciente:</strong> <?= htmlspecialchars((string) ($r['patient_id'] ?? '—'), ENT_QUOTES) ?></div>
                <div class="pdf-patient-line"><strong>Data do Exame:</strong> <?= $formatarData($r['study_date'] ?? null) ?></div>
                <div class="pdf-patient-line"><strong>Prontuário:</strong> <?= htmlspecialchars((string) ($r['accession_number'] ?? '—'), ENT_QUOTES) ?></div>
            </div>
        </section>

        <h1 class="pdf-exam-title"><?= htmlspecialchars($tituloLaudo, ENT_QUOTES) ?></h1>

        <?php if (!empty($secoesClinicasPdf)): ?>
            <article class="pdf-report-content">
                <?php foreach ($secoesClinicasPdf as $secao): ?>
                    <section class="pdf-clinical-section">
                        <h2 class="pdf-clinical-section-title"><?= htmlspecialchars((string) $secao['rotulo'], ENT_QUOTES) ?></h2>
                        <div class="pdf-clinical-section-content"><?= (string) $secao['conteudo'] ?></div>
                    </section>
                <?php endforeach; ?>
            </article>
        <?php elseif (trim(strip_tags($corpoLaudo)) !== ''): ?>
            <article class="pdf-report-content"><?= $corpoLaudo ?></article>
        <?php else: ?>
            <p class="pdf-report-empty">Laudo não informado.</p>
        <?php endif; ?>

        <section class="pdf-signature" aria-label="Assinatura digital do médico">
            <?php if (!empty($r['assinatura_caminho_arquivo'])): ?>
                <img class="pdf-signature-image" src="/reports/r/<?= rawurlencode((string) ($r['public_token'] ?? '')) ?>/assinatura" alt="Assinatura de <?= htmlspecialchars((string) ($r['medico_nome'] ?? ''), ENT_QUOTES) ?>">
            <?php endif; ?>
            <div class="pdf-signer-name"><?= htmlspecialchars((string) ($r['medico_nome'] ?? '—'), ENT_QUOTES) ?></div>
            <div class="pdf-signer-details">Médico responsável · <?= htmlspecialchars($crmExibicao, ENT_QUOTES) ?></div>
            <div class="pdf-verification">Assinado digitalmente em <?= $formatarDataHora($r['assinado_em'] ?? null) ?></div>
            <?php if (!empty($r['assinatura_hash'])): ?>
                <div class="pdf-hash">Código de verificação: <?= htmlspecialchars((string) $r['assinatura_hash'], ENT_QUOTES) ?></div>
            <?php endif; ?>
        </section>

        <footer class="pdf-footer">
            <?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?> · Laudo médico digital
        </footer>
    </main>

    <?php if ($download): ?>
        <script>window.onload = function () { window.print(); };</script>
    <?php endif; ?>
</body>
</html>
