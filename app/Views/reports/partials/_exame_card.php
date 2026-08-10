<?php
/** @var object $estudo */
$dtEstudo = '—';
if (!empty($estudo->study_date)) {
    try { $dtEstudo = (new DateTime($estudo->study_date))->format('d/m/Y'); } catch (\Throwable $e) { $dtEstudo = $estudo->study_date; }
}
$hrEstudo = $estudo->study_time ?: '—';
$studyUid = $estudo->study_instance_uid ?: '—';
?>
<div class="pacs-card reports-card" id="card-exame">
    <div class="pacs-card-header"><i class="fa fa-file-waveform"></i> Exame</div>
    <div class="pacs-card-body reports-card-body">

        <div class="rp-field">
            <label><i class="fa fa-x-ray"></i> Modalidade</label>
            <span class="rp-value">
            <?php
            $modsCard = array_filter(array_map('trim', explode('\\', $estudo->modalities ?? '')));
            if (empty($modsCard)): ?>—<?php else: foreach ($modsCard as $cardMod): ?>
                <span class="dicom-modality"><?= htmlspecialchars(\App\Services\DicomModalityService::code($cardMod)) ?></span>
            <?php endforeach; endif; ?>
            </span>
        </div>

        <div class="rp-field">
            <label><i class="fa fa-file-lines"></i> Descrição do Estudo</label>
            <span class="rp-value"><?= htmlspecialchars($estudo->study_description ?: '—') ?></span>
        </div>

        <?php if (!empty($estudo->series_description)): ?>
        <div class="rp-field">
            <label><i class="fa fa-layer-group"></i> Descrição da Série</label>
            <span class="rp-value"><?= htmlspecialchars($estudo->series_description) ?></span>
        </div>
        <?php endif; ?>

        <div class="rp-row">
            <div class="rp-field">
                <label><i class="fa fa-calendar"></i> Data</label>
                <span class="rp-value"><?= htmlspecialchars($dtEstudo) ?></span>
            </div>
            <div class="rp-field">
                <label><i class="fa fa-clock"></i> Hora</label>
                <span class="rp-value"><?= htmlspecialchars($hrEstudo) ?></span>
            </div>
        </div>

        <?php if (!empty($estudo->body_part_examined)): ?>
        <div class="rp-field">
            <label><i class="fa fa-stethoscope"></i> Parte do Corpo</label>
            <span class="rp-value rp-value--green"><?= htmlspecialchars($estudo->body_part_examined) ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($estudo->laterality)): ?>
        <div class="rp-field">
            <label><i class="fa fa-left-right"></i> Lateralidade</label>
            <span class="rp-value"><?= htmlspecialchars($estudo->laterality) ?></span>
        </div>
        <?php endif; ?>

        <div class="rp-row">
            <div class="rp-field">
                <label><i class="fa fa-layer-group"></i> Séries</label>
                <span class="rp-value"><?= number_format((int) ($estudo->num_series ?? 0)) ?></span>
            </div>
            <div class="rp-field">
                <label><i class="fa fa-images"></i> Imagens</label>
                <span class="rp-value"><?= number_format((int) ($estudo->num_instances ?? 0)) ?></span>
            </div>
        </div>

        <div class="rp-field">
            <label><i class="fa fa-barcode"></i> Accession Number</label>
            <span class="rp-value rp-mono"><?= htmlspecialchars($estudo->accession_number ?: '—') ?></span>
        </div>

        <div class="rp-field">
            <label><i class="fa fa-fingerprint"></i> Study UID</label>
            <span class="rp-value rp-mono rp-value--full" title="<?= htmlspecialchars($estudo->study_instance_uid ?? '') ?>"><?= htmlspecialchars($studyUid) ?></span>
        </div>

        <?php if (!empty($estudo->especialidade)): ?>
        <div class="rp-field">
            <label><i class="fa fa-briefcase-medical"></i> Especialidade</label>
            <span class="rp-value"><?= htmlspecialchars($estudo->especialidade) ?></span>
        </div>
        <?php endif; ?>

        <div class="rp-field">
            <label><i class="fa fa-user-doctor"></i> Médico Solicitante</label>
            <span class="rp-value"><?= htmlspecialchars(\App\Helpers\DicomPersonName::format($estudo->referring_physician_name ?? null) ?: '—') ?></span>
        </div>

        <div class="rp-field rp-pedido-field">
            <label><i class="fa fa-paperclip"></i> <?= htmlspecialchars(t('pedido_medico.coluna')) ?></label>
            <?php if (!empty($pedido) && !empty($pedido['visualizar_url'])): ?>
                <span class="rp-value">
                    <span class="pedido-report-badge"><i class="fa fa-circle-check"></i> <?= htmlspecialchars(t('pedido_medico.status.anexado')) ?></span>
                    <span class="pedido-report-name" title="<?= htmlspecialchars($pedido['nome_original'] ?? '') ?>">
                        <?= htmlspecialchars($pedido['nome_original'] ?? '') ?>
                        <?php if (!empty($pedido['tamanho_formatado'])): ?>
                            <small>(<?= htmlspecialchars($pedido['tamanho_formatado']) ?>)</small>
                        <?php endif; ?>
                    </span>
                    <a href="<?= htmlspecialchars($pedido['visualizar_url']) ?>" target="_blank" rel="noopener" class="pedido-report-link">
                        <i class="fa fa-eye"></i> <?= htmlspecialchars(t('pedido_medico.acao.consultar')) ?>
                    </a>
                </span>
            <?php else: ?>
                <span class="rp-value rp-muted"><?= htmlspecialchars(t('pedido_medico.status.nao_anexado')) ?></span>
            <?php endif; ?>
        </div>

        <?php if (!empty($estudo->institution_name)): ?>
        <div class="rp-field">
            <label><i class="fa fa-hospital"></i> Instituição</label>
            <span class="rp-value"><?= htmlspecialchars($estudo->institution_name) ?></span>
        </div>
        <?php endif; ?>

    </div>
</div>
