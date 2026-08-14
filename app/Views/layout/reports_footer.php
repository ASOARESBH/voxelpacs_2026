    </div><!-- /reports-body-grid -->
</div><!-- /reports-app -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>

<?php $v = defined('ASSET_VERSION') ? ASSET_VERSION : '2.1.0'; ?>
<script>
    window.VoxelReports = window.VoxelReports || {};
    window.VoxelReports.chatI18n = <?= json_encode([
        'pending' => t('report_chat.pendente'),
        'clear' => t('report_chat.sem_mensagens'),
        'required' => t('report_chat.mensagem') . ': ' . t('report_chat.erro_generico'),
        'error' => t('report_chat.erro_generico'),
        'confirm' => t('report_chat.confirmar_conclusao'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.VoxelReports.peerReviewI18n = <?= json_encode([
        'confirmar' => t('peer_review.confirmar'),
        'motivoCurto' => t('peer_review.motivo_curto'),
        'error' => t('peer_review.erro'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/js/reports/reports-chat.js?v=<?= $v ?>"></script>
<script src="/assets/js/reports/reports-peer-review.js?v=<?= $v ?>"></script>
<script src="/assets/js/reports/reports-editor.js?v=<?= $v ?>"></script>
<script src="/assets/js/reports/reports-autosave.js?v=<?= $v ?>"></script>
<script src="/assets/js/reports/reports-templates.js?v=<?= $v ?>"></script>
<script src="/assets/js/reports/reports-autotext.js?v=<?= $v ?>"></script>
<script src="/assets/js/reports/reports-signature.js?v=<?= $v ?>"></script>
<script src="/assets/js/reports/reports-history.js?v=<?= $v ?>"></script>
<script src="/assets/js/reports/reports-measurements.js?v=<?= $v ?>"></script>
<script src="/assets/js/reports/reports-main.js?v=<?= $v ?>"></script>
<script>
    window.VoxelReports.main.init();

    // Tooltips (ex: descrição de modalidade DICOM — dicom-modality)
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
</script>
</body>
</html>
