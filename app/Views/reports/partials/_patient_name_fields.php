<?php
/** @var object $estudo */
$fieldPrefix = (string) ($fieldPrefix ?? 'patient-name');
$structured = \App\Helpers\DicomPersonName::components(
    \App\Services\PhilipsSubmissionMetadataResolver::patientNameFromTagsRaw($estudo->tags_raw ?? null)
) ?? \App\Helpers\DicomPersonName::components((string) ($estudo->patient_name ?? ''));
$source = $structured !== null ? 'dicom_pn' : 'manual_confirmation';
$family = $structured['family'] ?? '';
$given = $structured['given'] ?? '';
$middle = $structured['middle'] ?? '';
?>
<div class="patient-name-confirmation" data-patient-name-source="<?= htmlspecialchars($source, ENT_QUOTES, 'UTF-8') ?>">
    <p class="text-pacs-muted" style="font-size:.82rem;">
        <?= htmlspecialchars(t('reports.patient_name.help'), ENT_QUOTES, 'UTF-8') ?>
    </p>
    <div class="row g-2">
        <div class="col-md-4">
            <label class="form-label" for="<?= htmlspecialchars($fieldPrefix, ENT_QUOTES, 'UTF-8') ?>-family"><?= htmlspecialchars(t('reports.patient_name.family'), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="text" class="form-control" id="<?= htmlspecialchars($fieldPrefix, ENT_QUOTES, 'UTF-8') ?>-family" value="<?= htmlspecialchars($family, ENT_QUOTES, 'UTF-8') ?>" maxlength="100" <?= $structured !== null ? 'readonly' : 'required' ?>>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="<?= htmlspecialchars($fieldPrefix, ENT_QUOTES, 'UTF-8') ?>-given"><?= htmlspecialchars(t('reports.patient_name.given'), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="text" class="form-control" id="<?= htmlspecialchars($fieldPrefix, ENT_QUOTES, 'UTF-8') ?>-given" value="<?= htmlspecialchars($given, ENT_QUOTES, 'UTF-8') ?>" maxlength="100" <?= $structured !== null ? 'readonly' : 'required' ?>>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="<?= htmlspecialchars($fieldPrefix, ENT_QUOTES, 'UTF-8') ?>-middle"><?= htmlspecialchars(t('reports.patient_name.middle'), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="text" class="form-control" id="<?= htmlspecialchars($fieldPrefix, ENT_QUOTES, 'UTF-8') ?>-middle" value="<?= htmlspecialchars($middle, ENT_QUOTES, 'UTF-8') ?>" maxlength="100">
        </div>
    </div>
    <input type="hidden" id="<?= htmlspecialchars($fieldPrefix, ENT_QUOTES, 'UTF-8') ?>-source" value="<?= htmlspecialchars($source, ENT_QUOTES, 'UTF-8') ?>">
</div>
