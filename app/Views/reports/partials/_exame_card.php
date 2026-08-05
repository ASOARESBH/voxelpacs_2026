<?php
/** @var object $estudo */
$dtEstudo = '—';
if (!empty($estudo->study_date)) {
    try { $dtEstudo = (new DateTime($estudo->study_date))->format('d/m/Y'); } catch (\Throwable $e) { $dtEstudo = $estudo->study_date; }
}
$hrEstudo = $estudo->study_time ?: '—';
// Truncar Study UID para exibição
$studyUidShort = strlen($estudo->study_instance_uid ?? '') > 28
    ? substr($estudo->study_instance_uid, 0, 28) . '…'
    : ($estudo->study_instance_uid ?: '—');
?>
<div class="pacs-card reports-card" id="card-exame">
    <div class="pacs-card-header"><i class="fa fa-file-waveform"></i> Exame</div>
    <div class="pacs-card-body reports-card-body">

        <!-- (0008,0060) Modality -->
        <div class="report-field">
            <span><i class="fa fa-x-ray me-1" style="opacity:.6;"></i>(0008,0060) Modalidade</span>
            <strong>
            <?php
            $modsCard = array_filter(array_map('trim', explode('\\', $estudo->modalities ?? '')));
            if (empty($modsCard)): ?>—<?php else: foreach ($modsCard as $cardMod): ?>
                <span class="dicom-modality" data-bs-toggle="tooltip" data-bs-placement="top"
                      title="<?= htmlspecialchars(\App\Services\DicomModalityService::description($cardMod)) ?>"><?= htmlspecialchars(\App\Services\DicomModalityService::code($cardMod)) ?></span>
            <?php endforeach; endif; ?>
            </strong>
        </div>

        <!-- (0008,1030) Study Description -->
        <div class="report-field">
            <span><i class="fa fa-file-lines me-1" style="opacity:.6;"></i>(0008,1030) Descrição do Estudo</span>
            <strong><?= htmlspecialchars($estudo->study_description ?: '—') ?></strong>
        </div>

        <?php if (!empty($estudo->series_description)): ?>
        <!-- (0008,103E) Series Description -->
        <div class="report-field">
            <span><i class="fa fa-layer-group me-1" style="opacity:.6;"></i>(0008,103E) Descrição da Série</span>
            <strong><?= htmlspecialchars($estudo->series_description) ?></strong>
        </div>
        <?php endif; ?>

        <!-- (0008,0020) Study Date + (0008,0030) Study Time -->
        <div class="report-field-row">
            <div class="report-field">
                <span><i class="fa fa-calendar me-1" style="opacity:.6;"></i>(0008,0020) Data</span>
                <strong><?= htmlspecialchars($dtEstudo) ?></strong>
            </div>
            <div class="report-field">
                <span><i class="fa fa-clock me-1" style="opacity:.6;"></i>(0008,0030) Hora</span>
                <strong><?= htmlspecialchars($hrEstudo) ?></strong>
            </div>
        </div>

        <?php if (!empty($estudo->body_part_examined)): ?>
        <!-- (0018,0015) Body Part Examined — alta importância -->
        <div class="report-field">
            <span><i class="fa fa-stethoscope me-1" style="opacity:.6;"></i>(0018,0015) Parte do Corpo</span>
            <strong style="color:#22c55e;font-size:.88rem;"><?= htmlspecialchars($estudo->body_part_examined) ?></strong>
        </div>
        <?php endif; ?>

        <?php if (!empty($estudo->laterality)): ?>
        <!-- (0020,0060) Laterality -->
        <div class="report-field">
            <span><i class="fa fa-left-right me-1" style="opacity:.6;"></i>(0020,0060) Lateralidade</span>
            <strong><?= htmlspecialchars($estudo->laterality) ?></strong>
        </div>
        <?php endif; ?>

        <!-- (0020,0011) Series Number + (0020,0013) Instance Number -->
        <div class="report-field-row">
            <div class="report-field">
                <span><i class="fa fa-layer-group me-1" style="opacity:.6;"></i>(0020,0011) Séries</span>
                <strong><?= number_format((int) ($estudo->num_series ?? 0)) ?></strong>
            </div>
            <div class="report-field">
                <span><i class="fa fa-images me-1" style="opacity:.6;"></i>(0020,0013) Imagens</span>
                <strong><?= number_format((int) ($estudo->num_instances ?? 0)) ?></strong>
            </div>
        </div>

        <!-- Accession Number -->
        <div class="report-field">
            <span><i class="fa fa-barcode me-1" style="opacity:.6;"></i>Accession Number</span>
            <strong class="report-uid"><?= htmlspecialchars($estudo->accession_number ?: '—') ?></strong>
        </div>

        <!-- (0020,000D) Study UID — interno -->
        <div class="report-field">
            <span><i class="fa fa-fingerprint me-1" style="opacity:.6;"></i>(0020,000D) Study UID</span>
            <strong class="report-uid" title="<?= htmlspecialchars($estudo->study_instance_uid ?? '') ?>"><?= htmlspecialchars($studyUidShort) ?></strong>
        </div>

        <!-- Especialidade -->
        <?php if (!empty($estudo->especialidade)): ?>
        <div class="report-field">
            <span><i class="fa fa-briefcase-medical me-1" style="opacity:.6;"></i>Especialidade</span>
            <strong><?= htmlspecialchars($estudo->especialidade) ?></strong>
        </div>
        <?php endif; ?>

        <!-- Médico Solicitante -->
        <div class="report-field">
            <span><i class="fa fa-user-doctor me-1" style="opacity:.6;"></i>Médico Solicitante</span>
            <strong><?= htmlspecialchars(\App\Helpers\DicomPersonName::format($estudo->referring_physician_name ?? null) ?: '—') ?></strong>
        </div>

        <!-- Instituição -->
        <?php if (!empty($estudo->institution_name)): ?>
        <div class="report-field">
            <span><i class="fa fa-hospital me-1" style="opacity:.6;"></i>(0008,0080) Instituição</span>
            <strong><?= htmlspecialchars($estudo->institution_name) ?></strong>
        </div>
        <?php endif; ?>

    </div>
</div>
