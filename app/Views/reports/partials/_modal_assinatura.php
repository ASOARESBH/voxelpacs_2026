<?php
/** @var object $report */
$crmAtual = \App\Core\Auth::user()->crm ?? '';
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
                    Confirme sua senha para assinar digitalmente este laudo. Após assinado, o laudo se torna
                    permanentemente somente-leitura.
                </p>
                <div class="mb-3">
                    <label class="form-label-dark">CRM</label>
                    <input type="text" id="assinatura-crm" class="form-control-dark" value="<?= htmlspecialchars($crmAtual) ?>" placeholder="CRM/UF 000000">
                </div>
                <div class="mb-3">
                    <label class="form-label-dark">Senha</label>
                    <input type="password" id="assinatura-senha" class="form-control-dark" placeholder="Sua senha de acesso" autocomplete="current-password">
                </div>
                <div id="assinatura-erro" class="reports-alert-erro" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-pacs-outline" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn-pacs-primary" id="btn-confirmar-assinatura">
                    <i class="fa fa-signature"></i> Confirmar Assinatura
                </button>
            </div>
        </div>
    </div>
</div>
