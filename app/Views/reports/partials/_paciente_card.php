<?php
/** @var object $estudo */
$sexoMap = ['M' => '♂ Masculino', 'F' => '♀ Feminino', 'O' => '⊕ Outro'];
$sexo    = $sexoMap[strtoupper($estudo->patient_sex ?? '')] ?? ($estudo->patient_sex ?: '—');
$corSexo = match(strtoupper($estudo->patient_sex ?? '')) {
    'M' => '#4fc3f7', 'F' => '#f472b6', default => 'var(--pacs-text-muted)'
};

$nascimento = '—';
$idade = '—';
$nascimentoDICOM = trim((string) ($estudo->patient_birth_date ?? ''));
$nascimentoNumerico = preg_replace('/\D+/', '', $nascimentoDICOM) ?? '';

// A data DICOM completa (YYYYMMDD) é a fonte preferencial porque permite
// calcular a idade com dia e mês, sem depender de um PatientAge importado.
if (strlen($nascimentoNumerico) === 8) {
    $dataNascimento = \DateTimeImmutable::createFromFormat('!Ymd', $nascimentoNumerico);
    $errosData = \DateTimeImmutable::getLastErrors();
    $dataValida = $dataNascimento instanceof \DateTimeImmutable
        && ($errosData === false || ((int) ($errosData['warning_count'] ?? 0) === 0 && (int) ($errosData['error_count'] ?? 0) === 0));

    $hoje = new \DateTimeImmutable('today');
    if ($dataValida && $dataNascimento <= $hoje) {
        $nascimento = $dataNascimento->format('d/m/Y');
        $idade = $dataNascimento->diff($hoje)->y . ' anos';
    }
}

// Mantém a compatibilidade com estudos antigos que não possuem data completa.
if ($idade === '—') {
    $idadeDICOM = trim((string) ($estudo->patient_age ?? ''));
    if (preg_match('/^0*(\d+)([YMD])$/i', $idadeDICOM, $m)) {
        $idade = $m[1] . ['Y' => ' anos', 'M' => ' meses', 'D' => ' dias'][strtoupper($m[2])];
    }
}

// O card prioriza a apresentação de PN, sem alterar metadados ou auditoria.
$nomeDisplay = \App\Helpers\DicomPersonName::displayFromStudy($estudo) ?: '—';
$informacoesEstudo = trim((string) ($estudo->informacoes_manual ?? ''));
$podeVerInformacoes = !empty($canViewStudyInformation) && $informacoesEstudo !== '';
?>
<div class="pacs-card reports-card" id="card-paciente">
    <div class="pacs-card-header"><i class="fa fa-user-injured"></i> Paciente</div>
    <div class="pacs-card-body reports-card-body">

        <div class="rp-field">
            <label><i class="fa fa-id-card"></i> Nome</label>
            <span class="rp-value rp-value--destaque"><?= htmlspecialchars($nomeDisplay) ?></span>
        </div>

        <!-- Bloco visual Sexo + Idade em destaque -->
        <div class="rp-sexo-idade">
            <?php
            $sexoRaw = strtoupper($estudo->patient_sex ?? '');
            if ($sexoRaw === 'M'):
            ?>
            <span class="rp-sexo-icon rp-sexo-masc" title="Masculino">
                <i class="fa fa-mars"></i>
            </span>
            <?php elseif ($sexoRaw === 'F'): ?>
            <span class="rp-sexo-icon rp-sexo-fem" title="Feminino">
                <i class="fa fa-venus"></i>
            </span>
            <?php else: ?>
            <span class="rp-sexo-icon rp-sexo-outro" title="Não informado">
                <i class="fa fa-circle-question"></i>
            </span>
            <?php endif; ?>
            <span class="rp-idade-destaque"><?= htmlspecialchars($idade) ?></span>
            <?php if ($podeVerInformacoes): ?>
                <button type="button" class="rp-informacoes-trigger" data-bs-toggle="modal" data-bs-target="#reportStudyInformationModal" aria-controls="reportStudyInformationModal">
                    <i class="fa fa-triangle-exclamation" aria-hidden="true"></i>
                    <span><?= htmlspecialchars(t('reports.paciente.informacoes')) ?></span>
                </button>
            <?php endif; ?>
        </div>

        <div class="rp-field">
            <label><i class="fa fa-hashtag"></i> ID / Prontuário</label>
            <span class="rp-value rp-mono"><?= htmlspecialchars($estudo->patient_id ?? '—') ?></span>
        </div>

        <div class="rp-row">
            <div class="rp-field">
                <label><i class="fa fa-cake-candles"></i> Nascimento</label>
                <span class="rp-value"><?= htmlspecialchars($nascimento) ?></span>
            </div>
            <div class="rp-field">
                <label><i class="fa fa-venus-mars"></i> Sexo</label>
                <span class="rp-value" style="color:<?= $corSexo ?>;"><?= htmlspecialchars($sexo) ?></span>
            </div>
        </div>

    </div>
</div>

<?php if ($podeVerInformacoes): ?>
    <div class="modal fade" id="reportStudyInformationModal" tabindex="-1" aria-labelledby="reportStudyInformationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content reports-modal reports-information-modal">
                <div class="modal-header">
                    <h5 class="modal-title" id="reportStudyInformationModalLabel"><i class="fa fa-triangle-exclamation me-2"></i><?= htmlspecialchars(t('reports.paciente.informacoes')) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t('reports.paciente.informacoes_fechar')) ?>"></button>
                </div>
                <div class="modal-body"><p class="reports-information-text"><?= nl2br(htmlspecialchars($informacoesEstudo, ENT_QUOTES, 'UTF-8')) ?></p></div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= htmlspecialchars(t('reports.paciente.informacoes_fechar')) ?></button></div>
            </div>
        </div>
    </div>
<?php endif; ?>
