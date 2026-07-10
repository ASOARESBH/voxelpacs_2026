<?php /** @var object $estudo */ ?>
<div class="pacs-card reports-card">
    <div class="pacs-card-header"><i class="fa fa-microchip"></i> Equipamento</div>
    <div class="pacs-card-body reports-card-body">
        <div class="report-field"><span>Equipamento</span><strong><?= htmlspecialchars($estudo->station_name ?: '—') ?></strong></div>
        <div class="report-field-row">
            <div class="report-field"><span>Fabricante</span><strong><?= htmlspecialchars($estudo->manufacturer ?: '—') ?></strong></div>
            <div class="report-field"><span>Modelo</span><strong><?= htmlspecialchars($estudo->manufacturer_model_name ?: '—') ?></strong></div>
        </div>
        <div class="report-field"><span>Protocolo</span><strong><?= htmlspecialchars($estudo->protocol_name ?: '—') ?></strong></div>
        <div class="report-field"><span>Região Examinada</span><strong><?= htmlspecialchars($estudo->body_part_examined ?: '—') ?></strong></div>
    </div>
</div>

<div class="pacs-card reports-card">
    <div class="pacs-card-header"><i class="fa fa-history"></i> Histórico do Paciente</div>
    <div class="pacs-card-body reports-card-body">
        <p class="text-pacs-muted" style="font-size:.78rem;margin:0;">Exames anteriores deste paciente — em breve.</p>
    </div>
</div>

<div class="reports-card-actions">
    <button type="button" class="btn-pacs-outline w-100" id="btn-open-viewer"
            data-estudo-id="<?= (int) $estudo->id ?>">
        <i class="fa fa-x-ray"></i> Abrir DICOM Viewer
    </button>
    <button type="button" class="btn-pacs-outline w-100" id="btn-timeline">
        <i class="fa fa-timeline"></i> Timeline
    </button>
    <button type="button" class="btn-pacs-outline w-100" id="btn-comparativos">
        <i class="fa fa-clone"></i> Comparativos
    </button>
</div>
