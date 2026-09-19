<?php
/** @var object $estudo */
?>
<div class="modal fade" id="modalLiberacao" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content reports-modal">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa fa-paper-plane"></i> <?= htmlspecialchars(t('reports.patient_name.title'), ENT_QUOTES, 'UTF-8') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t('reports.paciente.informacoes_fechar'), ENT_QUOTES, 'UTF-8') ?>"></button>
            </div>
            <div class="modal-body">
                <?php $fieldPrefix = 'release-patient-name'; include __DIR__ . '/_patient_name_fields.php'; ?>
                <div id="liberacao-erro" class="reports-alert-erro" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-pacs-outline" data-bs-dismiss="modal"><?= htmlspecialchars(t('reports.patient_name.cancel'), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="button" class="btn-pacs-success" id="btn-confirmar-liberacao">
                    <i class="fa fa-paper-plane"></i> <?= htmlspecialchars(t('reports.patient_name.confirm'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </div>
    </div>
</div>
