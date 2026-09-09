<!-- Catálogo Downloads: classifica o ZIP antes do armazenamento privado e não publica automaticamente. -->
<!-- Materialização de runtime para publicação restrita da separação de produtos. -->
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h1 class="h3 mb-1"><?= htmlspecialchars(t('downloads.new')) ?></h1><p class="text-muted mb-0"><?= htmlspecialchars(t('downloads.upload_help')) ?></p></div>
        <a class="btn btn-outline-secondary" href="/platform/downloads"><?= htmlspecialchars(t('downloads.back')) ?></a>
    </div>
    <div class="card shadow-sm"><div class="card-body">
        <form method="post" enctype="multipart/form-data" action="/platform/downloads">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) $csrfToken) ?>">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label" for="product_key"><?= htmlspecialchars(t('downloads.product')) ?></label><select required class="form-select" id="product_key" name="product_key"><option value="voxel_view_desktop"><?= htmlspecialchars(t('downloads.product.voxel_view_desktop')) ?></option><option value="voxel_router_desktop"><?= htmlspecialchars(t('downloads.product.voxel_router_desktop')) ?></option></select><div class="form-text"><?= htmlspecialchars(t('downloads.product_help')) ?></div></div>
                <div class="col-md-4"><label class="form-label" for="version_name"><?= htmlspecialchars(t('downloads.version')) ?></label><input required pattern="[0-9]+(\.[0-9]+){1,3}([-+][A-Za-z0-9.-]+)?" maxlength="64" class="form-control" id="version_name" name="version_name" placeholder="1.0.0"></div>
                <div class="col-md-2"><label class="form-label" for="platform"><?= htmlspecialchars(t('downloads.platform')) ?></label><select class="form-select" id="platform" name="platform"><option value="windows">Windows</option><option value="mac">macOS</option><option value="linux">Linux</option></select></div>
                <div class="col-md-2"><label class="form-label" for="channel"><?= htmlspecialchars(t('downloads.channel')) ?></label><select class="form-select" id="channel" name="channel"><option value="stable">Stable</option><option value="beta">Beta</option></select></div>
                <div class="col-12"><label class="form-label" for="package"><?= htmlspecialchars(t('downloads.package_zip')) ?></label><input required accept=".zip,application/zip" class="form-control" id="package" type="file" name="package"><div class="form-text"><?= htmlspecialchars(t('downloads.package_help')) ?></div></div>
                <div class="col-12"><label class="form-label" for="notes"><?= htmlspecialchars(t('downloads.notes')) ?></label><textarea class="form-control" id="notes" name="notes" rows="4" maxlength="6000"></textarea></div>
                <div class="col-12"><button class="btn btn-primary" type="submit"><i class="fa fa-save me-1"></i><?= htmlspecialchars(t('downloads.save_draft')) ?></button></div>
            </div>
        </form>
    </div></div>
</div>
