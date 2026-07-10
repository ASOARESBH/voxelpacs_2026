<?php
/** @var object $estudo */
$sexoMap = ['M' => 'Masculino', 'F' => 'Feminino'];
$sexo = $sexoMap[strtoupper($estudo->patient_sex ?? '')] ?? ($estudo->patient_sex ?: '—');

$idade = $estudo->patient_age ?: '—';
if ($idade && preg_match('/^0*(\d+)([YMD])$/', $idade, $m)) {
    $idade = $m[1] . ['Y' => ' anos', 'M' => ' meses', 'D' => ' dias'][$m[2]];
}

$nascimento = '—';
if (!empty($estudo->patient_birth_date)) {
    try { $nascimento = (new DateTime($estudo->patient_birth_date))->format('d/m/Y'); } catch (\Throwable $e) {}
}
?>
<div class="pacs-card reports-card">
    <div class="pacs-card-header"><i class="fa fa-user"></i> Paciente</div>
    <div class="pacs-card-body reports-card-body">
        <div class="report-field"><span>Nome</span><strong><?= htmlspecialchars($estudo->patient_name_display ?? $estudo->patient_name ?? '—') ?></strong></div>
        <div class="report-field-row">
            <div class="report-field"><span>Sexo</span><strong><?= htmlspecialchars($sexo) ?></strong></div>
            <div class="report-field"><span>Idade</span><strong><?= htmlspecialchars($idade) ?></strong></div>
        </div>
        <div class="report-field"><span>Nascimento</span><strong><?= htmlspecialchars($nascimento) ?></strong></div>
        <div class="report-field-row">
            <div class="report-field"><span>CPF</span><strong>—</strong></div>
            <div class="report-field"><span>Prontuário</span><strong><?= htmlspecialchars($estudo->patient_id ?? '—') ?></strong></div>
        </div>
        <div class="report-field-row">
            <div class="report-field"><span>Convênio</span><strong>—</strong></div>
            <div class="report-field"><span>Telefone</span><strong>—</strong></div>
        </div>
        <div class="report-field"><span>Cidade</span><strong>—</strong></div>
    </div>
</div>
