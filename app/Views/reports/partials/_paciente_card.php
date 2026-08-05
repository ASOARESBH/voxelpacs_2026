<?php
/** @var object $estudo */
// ── Formatadores ─────────────────────────────────────────────────────────────
$sexoMap = ['M' => '♂ Masculino', 'F' => '♀ Feminino', 'O' => '⊕ Outro'];
$sexo    = $sexoMap[strtoupper($estudo->patient_sex ?? '')] ?? ($estudo->patient_sex ?: '—');
$corSexo = match(strtoupper($estudo->patient_sex ?? '')) {
    'M' => '#4fc3f7', 'F' => '#f472b6', default => 'var(--pacs-text-muted)'
};

$idade = $estudo->patient_age ?: '—';
if ($idade !== '—' && preg_match('/^0*(\d+)([YMD])$/i', trim($idade), $m)) {
    $idade = $m[1] . ['Y' => ' anos', 'M' => ' meses', 'D' => ' dias'][strtoupper($m[2])];
}

$nascimento = '—';
if (!empty($estudo->patient_birth_date)) {
    try { $nascimento = (new DateTime($estudo->patient_birth_date))->format('d/m/Y'); } catch (\Throwable $e) {}
}

$nomeDisplay = \App\Helpers\DicomPersonName::format($estudo->patient_name_display ?? $estudo->patient_name ?? null) ?: '—';
?>
<div class="pacs-card reports-card" id="card-paciente">
    <div class="pacs-card-header"><i class="fa fa-user-injured"></i> Paciente</div>
    <div class="pacs-card-body reports-card-body">

        <!-- (0010,0010) Patient Name -->
        <div class="report-field">
            <span><i class="fa fa-id-card me-1" style="opacity:.6;"></i>(0010,0010) Nome</span>
            <strong style="font-size:.92rem;color:var(--pacs-text);"><?= htmlspecialchars($nomeDisplay) ?></strong>
        </div>

        <!-- (0010,0020) Patient ID -->
        <div class="report-field">
            <span><i class="fa fa-hashtag me-1" style="opacity:.6;"></i>(0010,0020) ID / Prontuário</span>
            <strong class="report-uid"><?= htmlspecialchars($estudo->patient_id ?? '—') ?></strong>
        </div>

        <!-- (0010,0030) Birth Date + (0010,1010) Age -->
        <div class="report-field-row">
            <div class="report-field">
                <span><i class="fa fa-cake-candles me-1" style="opacity:.6;"></i>(0010,0030) Nascimento</span>
                <strong><?= htmlspecialchars($nascimento) ?></strong>
            </div>
            <div class="report-field">
                <span><i class="fa fa-hourglass-half me-1" style="opacity:.6;"></i>(0010,1010) Idade</span>
                <strong><?= htmlspecialchars($idade) ?></strong>
            </div>
        </div>

        <!-- (0010,0040) Sex -->
        <div class="report-field">
            <span><i class="fa fa-venus-mars me-1" style="opacity:.6;"></i>(0010,0040) Sexo</span>
            <strong style="color:<?= $corSexo ?>;"><?= htmlspecialchars($sexo) ?></strong>
        </div>

    </div>
</div>
