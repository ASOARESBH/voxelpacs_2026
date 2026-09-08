    </div><!-- /platform-main -->
</div><!-- /platform-wrapper -->
<?php $v = defined('ASSET_VERSION') ? ASSET_VERSION : '2.1.0'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($includeQuill)): ?>
<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
<script src="/assets/js/shared/voxel-quill-factory.js?v=<?= $v ?>"></script>
<?php endif; ?>
<script src="/assets/js/shared/voxel-voltar.js?v=<?= $v ?>"></script>
<script src="/assets/js/shared/notification-center.js?v=<?= $v ?>"></script>
<script>
// Tooltips (ex: descrição de modalidade DICOM — dicom-modality)
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
</script>
</body>
</html>
