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

// O Moderno Lateral só apresenta canais que a própria Unidade habilitou.
// Sem configuração ativa, o cabeçalho preserva somente o Nome Fantasia.
$temCanalInstitucional = !empty($r['unidade_personalizado_qrcode_habilitado'])
    || !empty($r['unidade_personalizado_site_habilitado'])
    || !empty($r['unidade_personalizado_instagram_habilitado'])
    || !empty($r['unidade_personalizado_facebook_habilitado']);
$qrInstitucionalCabecalho = '';
$canaisInstitucionaisCabecalho = [];
if ($temCanalInstitucional) {
    $servicoTemplatePersonalizado = new \App\Services\ReportCustomTemplateService();
    if (!empty($r['unidade_personalizado_qrcode_habilitado'])) {
        $qrInstitucionalCabecalho = $servicoTemplatePersonalizado->institutionalQrMarkup(
            (string) ($r['unidade_personalizado_qrcode_url'] ?? '')
        );
    }
    foreach ([
        'site' => 'Site institucional',
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
    ] as $canalInstitucional => $rotuloCanal) {
        if (!empty($r['unidade_personalizado_' . $canalInstitucional . '_habilitado'])) {
            $linkCanal = $servicoTemplatePersonalizado->institutionalLinkMarkup(
                (string) ($r['unidade_personalizado_' . $canalInstitucional . '_url'] ?? ''),
                $rotuloCanal
            );
            if ($linkCanal !== '') {
                $canaisInstitucionaisCabecalho[] = $linkCanal;
            }
        }
    }
}
$temPersonalizadoInstitucional = $qrInstitucionalCabecalho !== '' || $canaisInstitucionaisCabecalho !== [];

$formatarData = static function (?string $valor): string {
    if (!$valor) return '—';
    $timestamp = strtotime($valor);
    return $timestamp ? date('d/m/Y', $timestamp) : '—';
};

$formatarDataHoraBrasilia = static function (?string $valor): string {
    if (!$valor) return '';
    try {
        $data = new \DateTimeImmutable($valor, new \DateTimeZone('America/Sao_Paulo'));
        return $data->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i');
    } catch (\Throwable $e) {
        return '';
    }
};
$formatarCnpj = static function (?string $valor): string {
    $digitos = preg_replace('/\D/', '', (string) $valor) ?? '';
    if (strlen($digitos) !== 14) return trim((string) $valor);
    return substr($digitos, 0, 2) . '.' . substr($digitos, 2, 3) . '.' . substr($digitos, 5, 3) . '/' . substr($digitos, 8, 4) . '-' . substr($digitos, 12, 2);
};

$solicitante = \App\Helpers\DicomPersonName::format($r['referring_physician_name'] ?? null) ?: '—';
// O conteúdo clínico configurado pelo médico é a única abertura do laudo.
// Study Description, procedimento, região anatômica e modalidade DICOM não
// são promovidos automaticamente a título de impressão.
$logoUnidade = trim((string) ($r['pdf_snapshot_logo_src'] ?? $r['unidade_logo_path'] ?? ''));
// No viewer, logo_path é relativo a public/. No snapshot, a fonte é data URI.
if ($logoUnidade !== '' && !str_starts_with($logoUnidade, 'data:')) {
    $logoUnidade = '/' . ltrim($logoUnidade, '/');
}
$assinaturaSrc = trim((string) ($r['pdf_snapshot_signature_src'] ?? ''));
if ($assinaturaSrc === '' && !empty($r['assinatura_caminho_arquivo'])) {
    $assinaturaSrc = '/reports/r/' . rawurlencode((string) ($r['public_token'] ?? '')) . '/assinatura';
}
$crm = trim((string) ($r['medico_crm'] ?? ''));
$crmUf = strtoupper(trim((string) ($r['medico_crm_uf'] ?? '')));
$crmExibicao = $crm === '' ? '' : (preg_match('/\bCRM\b/i', $crm) ? $crm : 'CRM' . ($crmUf !== '' ? '-' . $crmUf : '') . ' ' . $crm);
$especialidadeMedico = trim((string) ($r['medico_especialidade'] ?? ''));
$empresaNome = trim((string) ($r['tenant_nome'] ?? '')) ?: $unidadeNome;
$empresaCnpj = $formatarCnpj($r['tenant_cnpj'] ?? null);
$registroEmpresaUf = strtoupper(trim((string) ($r['registro_crm_uf'] ?? '')));
$registroEmpresaNumero = trim((string) ($r['registro_crm_numero'] ?? ''));
$registroEmpresa = $registroEmpresaNumero === '' ? '' : 'CRM' . ($registroEmpresaUf !== '' ? '-' . $registroEmpresaUf : '') . ' ' . $registroEmpresaNumero;
$assinadoEmBrasilia = $formatarDataHoraBrasilia($r['assinado_em'] ?? null);
$tokenValidacao = strtolower(trim((string) ($r['assinatura_hash'] ?? '')));

// O rodapé identifica a unidade que efetivamente realizou o exame. Dados vazios
// não geram separadores soltos nem substituem a identificação da empresa médica.
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
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #f1f5f9; }
        body { color: #171717; font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 1.42; }
        .pdf-page { display: flex; flex-direction: column; width: min(100%, 210mm); min-height: 297mm; margin: 22px auto; padding: 18mm 18mm 23mm; background: #fff; box-shadow: 0 6px 30px rgba(15,23,42,.13); }
        .pdf-actions { display: flex; flex-wrap: wrap; gap: 8px; width: min(100%, 210mm); margin: 22px auto 0; }
        .pdf-actions button, .pdf-actions a { display: inline-flex; align-items: center; gap: 5px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 4px; color: #1e293b; background: #fff; font: 700 12px Arial, sans-serif; text-decoration: none; cursor: pointer; }
        .pdf-actions .btn-print { border-color: #075a9e; background: #075a9e; color: #fff; }

        .pdf-header { display: table; width: 100%; padding-bottom: 9px; border-bottom: 1px solid #777; }
        .pdf-header-left, .pdf-header-right { display: table-cell; vertical-align: middle; }
        .pdf-header-left { width: 58%; padding-right: 12px; }
        .pdf-header-right { width: 42%; padding-left: 22px; text-align: right; }
        .pdf-logo { display: block; max-width: 196px; max-height: 78px; object-fit: contain; object-position: left center; }
        .pdf-logo-fallback { color: #075a9e; font-size: 22px; font-weight: 700; letter-spacing: .2px; }
        .pdf-header-unit { display: block; margin-top: 4px; color: #404040; font-size: 9px; font-weight: 700; letter-spacing: .7px; text-transform: uppercase; }
        .pdf-header-custom { width: 100%; margin-top: 0; text-align: right; }
        .pdf-header-custom-qr { text-align: right; }
        .pdf-header-custom-qr .voxel-institutional-qr { width: 78px; height: 78px; display: block; margin: 0 0 0 auto; }
        .pdf-header-custom-links { margin-top: 3px; text-align: right; }
        .pdf-header-custom-links .voxel-institutional-link { display: block; color: #404040; font-size: 8px; line-height: 1.5; font-weight: 700; text-decoration: none; }

        .pdf-patient { display: table; width: 100%; margin-top: 11px; padding: 0 0 11px; border-bottom: 1px solid #777; }
        .pdf-patient-col { display: table-cell; vertical-align: top; }
        .pdf-patient-col:first-child { width: 60%; padding-right: 16px; }
        .pdf-patient-col:last-child { width: 40%; padding-left: 22px; text-align: right; }
        .pdf-patient-line { min-height: 17px; font-size: 10px; line-height: 1.55; }
        .pdf-patient-line strong { font-weight: 700; }

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
        .pdf-report-content table { width: 100%; border-collapse: collapse; margin: .7rem 0; }
        .pdf-report-content th, .pdf-report-content td { border: 1px solid #aab4c0; padding: .4rem .5rem; vertical-align: top; }
        .pdf-report-content th { font-weight: 700; background: #f0f4f8; }
        .pdf-report-content u { text-decoration: underline; }
        .pdf-report-content .ql-align-center, .pdf-clinical-section-content .ql-align-center { text-align: center; }
        .pdf-report-content .ql-align-right, .pdf-clinical-section-content .ql-align-right { text-align: right; }
        .pdf-report-content .ql-align-justify, .pdf-clinical-section-content .ql-align-justify { text-align: justify; }
        .pdf-report-content a, .pdf-clinical-section-content a { color: #075a9e; text-decoration: underline; word-break: break-word; }
        .pdf-report-empty { color: #737373; font-style: italic; }

        .pdf-signature { margin-top: 18px; padding-top: 18px; text-align: center; page-break-inside: avoid; }
        .pdf-signature-image { display: block; max-width: 210px; max-height: 68px; margin: 0 auto 3px; object-fit: contain; }
        .pdf-signer-name { color: #111; font-size: 10px; font-weight: 700; }
        .pdf-signer-role, .pdf-signer-details, .pdf-verification, .pdf-company-details { color: #404040; font-size: 8px; line-height: 1.45; }
        .pdf-signer-role { margin-top: 1px; }
        .pdf-verification { margin-top: 7px; font-weight: 700; }
        .pdf-validation-token { max-width: 490px; margin: 3px auto 0; color: #404040; font-family: monospace; font-size: 7px; line-height: 1.35; overflow-wrap: anywhere; }
        .pdf-company-details { margin-top: 5px; font-weight: 700; }
        .pdf-company-details div { margin-top: 1px; }
        .pdf-company-label { font-weight: 700; }
        .pdf-hash { max-width: 490px; margin: 3px auto 0; color: #737373; font-size: 6.5px; line-height: 1.25; overflow-wrap: anywhere; }

        .pdf-footer { margin-top: 12px; padding-top: 7px; border-top: 1px solid #e5e5e5; color: #525252; font-size: 7px; line-height: 1.45; text-align: center; }
        .pdf-footer-name { color: #404040; font-weight: 700; }
        .pdf-footer-details { margin-top: 2px; }

        /* O viewer mantém a página A4 visual. O Dompdf recebe a variante de
           snapshot sem altura mínima para não produzir uma página vazia extra. */
        body.snapshot-pdf { width: auto; min-height: 0; background: #fff; }
        body.snapshot-pdf .pdf-page { display: block; width: auto; min-height: 0; height: auto; margin: 0; padding: 14mm 18mm; }
        body.snapshot-pdf .pdf-signature { margin-top: 16px; padding-top: 16px; }

        @media (max-width: 680px) {
            .pdf-page { min-height: 0; margin: 0; padding: 22px; box-shadow: none; }
            .pdf-header, .pdf-patient { display: block; }
            .pdf-header-left, .pdf-header-right, .pdf-patient-col { display: block; width: 100%; padding: 0 !important; text-align: left; }
            .pdf-header-right { margin-top: 16px; }
            .pdf-patient-col + .pdf-patient-col { margin-top: 8px; }
            .pdf-actions { width: 100%; margin: 0; padding: 12px; }
        }

        @media print {
            @page { size: A4 portrait; margin: 0; }
            html, body { width: 210mm; min-height: 297mm; background: #fff; }
            body { font-size: 11px; }
            .pdf-actions { display: none !important; }
            .pdf-page { display: block; width: 210mm; min-height: 0; margin: 0; padding: 14mm 18mm 14mm; box-shadow: none; }
            .pdf-header, .pdf-patient, .pdf-signature, .pdf-footer { position: static; }
            .pdf-patient { margin-top: 11px; }
            .pdf-signature { margin-top: 16px; padding-top: 16px; }
            .pdf-footer { margin-top: 12px; }
            .pdf-report-content, .pdf-clinical-section-content { font-size: 13px; line-height: 1.62; }
            .pdf-clinical-section-title { font-size: 12px; font-weight: 700; }
            /* Dompdf: o snapshot não deve combinar min-height A4 no body e na página,
               pois isso materializa uma folha em branco após o conteúdo. */
        }
    </style>
</head>
<body class="<?= !empty($snapshotPdf) ? 'snapshot-pdf' : '' ?>">
    <?php if (empty($snapshotPdf)): ?>
    <div class="pdf-actions">
        <button type="button" class="btn-print" onclick="window.print()">Imprimir</button>
        <a href="/reports/r/<?= rawurlencode((string) ($r['public_token'] ?? '')) ?>/pdf?download=1">Baixar PDF</a>
        <?php if (!$portalPatientPdf): ?>
            <a href="<?= htmlspecialchars($reportReturnUrl, ENT_QUOTES) ?>" data-voxel-voltar="<?= htmlspecialchars($reportReturnUrl, ENT_QUOTES) ?>">Voltar ao Laudário</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <main class="pdf-page">
        <header class="pdf-header">
            <div class="pdf-header-left">
                <?php if ($logoUnidade !== ''): ?>
                    <img class="pdf-logo" src="<?= htmlspecialchars($logoUnidade, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?>">
                <?php else: ?>
                    <div class="pdf-logo-fallback"><?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?></div>
                <?php endif; ?>
            </div>
            <div class="pdf-header-right">
                <?php if ($temPersonalizadoInstitucional): ?>
                    <div class="pdf-header-custom" aria-label="Canais institucionais da unidade">
                        <?php if ($qrInstitucionalCabecalho !== ''): ?>
                            <div class="pdf-header-custom-qr"><?= $qrInstitucionalCabecalho ?></div>
                        <?php endif; ?>
                        <?php if ($canaisInstitucionaisCabecalho !== []): ?>
                            <div class="pdf-header-custom-links"><?= implode('', $canaisInstitucionaisCabecalho) ?></div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <span class="pdf-header-unit"><?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?></span>
                <?php endif; ?>
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
            <?php if ($assinaturaSrc !== ''): ?>
                <img class="pdf-signature-image" src="<?= htmlspecialchars($assinaturaSrc, ENT_QUOTES) ?>" alt="Assinatura de <?= htmlspecialchars((string) ($r['medico_nome'] ?? ''), ENT_QUOTES) ?>">
            <?php endif; ?>
            <div class="pdf-signer-name"><?= htmlspecialchars((string) ($r['medico_nome'] ?? '—'), ENT_QUOTES) ?></div>
            <?php if ($especialidadeMedico !== ''): ?>
                <div class="pdf-signer-role"><?= htmlspecialchars($especialidadeMedico, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <?php if ($crmExibicao !== ''): ?>
                <div class="pdf-signer-details"><?= htmlspecialchars($crmExibicao, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <?php if ($assinadoEmBrasilia !== ''): ?>
                <div class="pdf-verification">Assinado digitalmente em <?= htmlspecialchars($assinadoEmBrasilia, ENT_QUOTES) ?> (horário de Brasília)</div>
            <?php else: ?>
                <div class="pdf-verification">Laudo ainda não assinado digitalmente.</div>
            <?php endif; ?>
            <?php if ($tokenValidacao !== ''): ?>
                <div class="pdf-validation-token">Token de validação para auditoria: <?= htmlspecialchars($tokenValidacao, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <div class="pdf-company-details">
                <div><span class="pdf-company-label">Empresa vinculada:</span> <?= htmlspecialchars($empresaNome, ENT_QUOTES) ?></div>
                <?php if ($empresaCnpj !== ''): ?><div>CNPJ <?= htmlspecialchars($empresaCnpj, ENT_QUOTES) ?></div><?php endif; ?>
                <?php if ($registroEmpresa !== ''): ?><div><?= htmlspecialchars($registroEmpresa, ENT_QUOTES) ?></div><?php endif; ?>
            </div>
        </section>

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
    </main>

    <?php if (empty($snapshotPdf)): ?>
    <script src="/assets/js/shared/voxel-voltar.js?v=<?= defined('ASSET_VERSION') ? ASSET_VERSION : '2.2.0' ?>"></script>
    <?php endif; ?>
    <?php if ($download): ?>
        <script>window.onload = function () { window.print(); };</script>
    <?php endif; ?>
</body>
</html>
