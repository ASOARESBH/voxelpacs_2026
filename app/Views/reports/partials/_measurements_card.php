<?php
/** @var object $report */
/** @var bool $readonly */
?>
<section
    id="viewer-measurements-card"
    class="pacs-card viewer-measurements-card"
    data-report-id="<?= (int) $report->id ?>"
    data-readonly="<?= $readonly ? '1' : '0' ?>"
    aria-labelledby="viewer-measurements-title"
>
    <div class="viewer-measurements-header">
        <div>
            <h3 id="viewer-measurements-title">Medidas disponíveis do viewer</h3>
            <p id="viewer-measurements-status" class="viewer-measurements-status" aria-live="polite">
                Aguardando medições do VOXEL VIEW.
            </p>
        </div>
        <button id="btn-refresh-measurements" type="button" class="btn btn-sm btn-outline-secondary" title="Atualizar medidas">
            <i class="fa fa-refresh" aria-hidden="true"></i><span class="sr-only">Atualizar medidas</span>
        </button>
    </div>

    <div id="viewer-measurements-list" class="viewer-measurements-list" role="group" aria-label="Medições disponíveis">
        <div class="viewer-measurements-empty">Nenhuma medida sincronizada para este estudo.</div>
    </div>

    <div class="viewer-measurements-target">
        <label for="measurement-target-section">Inserir em</label>
        <select id="measurement-target-section" class="form-control form-control-sm" <?= $readonly ? 'disabled' : '' ?> >
            <option value="achados" selected>Achados</option>
            <option value="conclusao">Conclusão</option>
            <option value="recomendacao">Recomendação</option>
            <option value="tecnica">Técnica</option>
            <option value="exame">Exame</option>
        </select>
    </div>

    <div class="viewer-measurements-actions">
        <button id="btn-copy-measurements" type="button" class="btn btn-sm btn-outline-secondary" disabled>
            <i class="fa fa-copy" aria-hidden="true"></i> Copiar
        </button>
        <button id="btn-insert-measurements" type="button" class="btn btn-sm btn-primary" disabled <?= $readonly ? 'disabled' : '' ?> >
            <i class="fa fa-plus-circle" aria-hidden="true"></i> Inserir no laudo
        </button>
    </div>
</section>
