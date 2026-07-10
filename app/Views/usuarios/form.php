<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><?= htmlspecialchars($title ?? 'Usuário') ?></h1>
    <a href="/usuarios" class="btn btn-outline-secondary btn-sm">
        <i class="fa fa-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="card shadow-sm" style="max-width:560px">
    <div class="card-body">
        <form method="POST" action="<?= $usuario ? '/usuarios/' . ($usuario->id ?? '') . '/update' : '/usuarios' ?>">

            <div class="mb-3">
                <label class="form-label">Nome completo <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control"
                       value="<?= htmlspecialchars($usuario->name ?? '') ?>" required placeholder="Nome do usuário">
            </div>

            <div class="mb-3">
                <label class="form-label">E-mail <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control"
                       value="<?= htmlspecialchars($usuario->email ?? '') ?>" required placeholder="email@clinica.com">
            </div>

            <?php if (!$usuario): ?>
            <div class="mb-3">
                <label class="form-label">Senha <span class="text-danger">*</span></label>
                <input type="password" name="password" class="form-control" required minlength="8" placeholder="Mínimo 8 caracteres">
            </div>
            <?php endif; ?>

            <div class="mb-4">
                <label class="form-label">Perfil de Acesso <span class="text-danger">*</span></label>
                <select name="role" class="form-select">
                    <option value="viewer" <?= ($usuario->tenant_role ?? $usuario->role ?? '') === 'viewer' ? 'selected' : '' ?>>
                        Visualizador — apenas leitura
                    </option>
                    <option value="editor" <?= ($usuario->tenant_role ?? $usuario->role ?? '') === 'editor' ? 'selected' : '' ?>>
                        Editor — pode importar dados
                    </option>
                    <option value="admin"  <?= ($usuario->tenant_role ?? $usuario->role ?? '') === 'admin'  ? 'selected' : '' ?>>
                        Administrador — acesso total
                    </option>
                </select>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save me-1"></i> Salvar
                </button>
                <a href="/usuarios" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>
