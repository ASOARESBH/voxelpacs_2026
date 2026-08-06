<?php
/** @var object $estudo */
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
