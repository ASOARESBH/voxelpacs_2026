<?php /** @var object $estudo */ ?>
<div class="pacs-card reports-card" id="card-equipamento">
    <div class="pacs-card-header"><i class="fa fa-microchip"></i> Equipamento</div>
    <div class="pacs-card-body reports-card-body">
        <div class="rp-field">
            <label><i class="fa fa-desktop"></i> Equipamento</label>
            <span class="rp-value"><?= htmlspecialchars($estudo->station_name ?: '—') ?></span>
        </div>
        <div class="rp-row">
            <div class="rp-field">
                <label><i class="fa fa-industry"></i> Fabricante</label>
                <span class="rp-value"><?= htmlspecialchars($estudo->manufacturer ?: '—') ?></span>
            </div>
            <div class="rp-field">
                <label><i class="fa fa-tag"></i> Modelo</label>
                <span class="rp-value"><?= htmlspecialchars($estudo->manufacturer_model_name ?: '—') ?></span>
            </div>
        </div>
        <div class="rp-field">
            <label><i class="fa fa-list-check"></i> Protocolo</label>
            <span class="rp-value"><?= htmlspecialchars($estudo->protocol_name ?: '—') ?></span>
        </div>
        <div class="rp-field">
            <label><i class="fa fa-person"></i> Região Examinada</label>
            <span class="rp-value"><?= htmlspecialchars($estudo->body_part_examined ?: '—') ?></span>
        </div>
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
