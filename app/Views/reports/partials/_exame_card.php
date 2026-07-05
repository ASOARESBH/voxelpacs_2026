<?php
/** @var object $estudo */
$dtEstudo = '—';
if (!empty($estudo->study_date)) {
    try { $dtEstudo = (new DateTime($estudo->study_date))->format('d/m/Y'); } catch (\Throwable $e) { $dtEstudo = $estudo->study_date; }
}
$hrEstudo = $estudo->study_time ?: '—';
?>
<div class="pacs-card reports-card">
    <div class="pacs-card-header"><i class="fa fa-file-waveform"></i> Exame</div>
    <div class="pacs-card-body reports-card-body">
        <div class="report-field-row">
            <div class="report-field"><span>Modalidade</span><strong><?= htmlspecialchars($estudo->modalities ?? '—') ?></strong></div>
            <div class="report-field"><span>Especialidade</span><strong><?= htmlspecialchars($estudo->especialidade ?: '—') ?></strong></div>
        </div>
        <div class="report-field"><span>Descrição</span><strong><?= htmlspecialchars($estudo->study_description ?: '—') ?></strong></div>
        <div class="report-field-row">
            <div class="report-field"><span>Data</span><strong><?= htmlspecialchars($dtEstudo) ?></strong></div>
            <div class="report-field"><span>Hora</span><strong><?= htmlspecialchars($hrEstudo) ?></strong></div>
        </div>
        <div class="report-field-row">
            <div class="report-field"><span>Nº Imagens</span><strong><?= number_format((int) ($estudo->num_instances ?? 0)) ?></strong></div>
            <div class="report-field"><span>Nº Séries</span><strong><?= number_format((int) ($estudo->num_series ?? 0)) ?></strong></div>
        </div>
        <div class="report-field"><span>Accession Number</span><strong><?= htmlspecialchars($estudo->accession_number ?: '—') ?></strong></div>
        <div class="report-field"><span>Study UID</span><strong class="report-uid"><?= htmlspecialchars($estudo->study_instance_uid ?: '—') ?></strong></div>
        <div class="report-field"><span>Instituição</span><strong><?= htmlspecialchars($estudo->institution_name ?: '—') ?></strong></div>
        <div class="report-field"><span>Solicitante</span><strong><?= htmlspecialchars($estudo->referring_physician_name ?: '—') ?></strong></div>
    </div>
</div>
