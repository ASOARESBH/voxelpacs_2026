<div class="auth-card p-5 my-5">
    <div class="text-center mb-4">
        <div class="auth-logo mb-1"><i class="fa fa-cube me-2"></i>VOXEL B.I</div>
        <p class="text-muted">Selecione a empresa para acessar</p>
    </div>
    <?php foreach ($tenants as $t): ?>
    <form method="POST" action="/selecionar-empresa" class="mb-2">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="tenant_id" value="<?= (int) $t->tenant_id ?>">
        <button type="submit" class="btn btn-outline-primary w-100 text-start py-3">
            <i class="fa fa-building me-2"></i>
            <strong><?= htmlspecialchars($t->nome) ?></strong>
            <span class="badge bg-secondary ms-2"><?= htmlspecialchars($t->role) ?></span>
        </button>
    </form>
    <?php endforeach; ?>
    <div class="text-center mt-3">
        <a href="/logout" class="text-muted text-decoration-none"><small><i class="fa fa-arrow-left me-1"></i>Voltar ao login</small></a>
    </div>
</div>
