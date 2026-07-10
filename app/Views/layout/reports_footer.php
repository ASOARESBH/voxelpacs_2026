    </div><!-- /reports-body-grid -->
</div><!-- /reports-app -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>

<?php $v = defined('ASSET_VERSION') ? ASSET_VERSION : '2.1.0'; ?>
<script src="/assets/js/reports/reports-editor.js?v=<?= $v ?>"></script>
<script src="/assets/js/reports/reports-autosave.js?v=<?= $v ?>"></script>
<script src="/assets/js/reports/reports-templates.js?v=<?= $v ?>"></script>
<script src="/assets/js/reports/reports-autotext.js?v=<?= $v ?>"></script>
<script src="/assets/js/reports/reports-signature.js?v=<?= $v ?>"></script>
<script src="/assets/js/reports/reports-history.js?v=<?= $v ?>"></script>
<script src="/assets/js/reports/reports-main.js?v=<?= $v ?>"></script>
<script>
    window.VoxelReports.main.init();
</script>
</body>
</html>
