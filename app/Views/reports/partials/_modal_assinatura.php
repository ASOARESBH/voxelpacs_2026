<?php
/** @var object $report */
?>
<div class="modal fade" id="modalAssinatura" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content reports-modal">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa fa-signature"></i> Assinar Laudo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-pacs-muted" style="font-size:.82rem;">
                    <?= htmlspecialchars(t('reports.assinatura.fluxo_modal')) ?>
                </p>
                <div id="assinatura-erro" class="reports-alert-erro" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-pacs-outline" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn-pacs-outline" id="btn-assinar-somente">
                    <i class="fa fa-signature"></i> Somente Assinar
                </button>
                <button type="button" class="btn-pacs-primary" id="btn-assinar-fechar">
                    <i class="fa fa-signature"></i> Assinar e Fechar
                </button>
            </div>
        </div>
    </div>
</div>
