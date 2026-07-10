<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold"><i class="fa fa-users me-2 text-primary"></i>Usuarios</h1>
        <small class="text-muted">Usuarios com acesso a esta unidade</small>
    </div>
    <a href="/usuarios/create" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i> Novo Usuario</a>
</div>

<?php if (!empty($_GET['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fa fa-exclamation-circle me-2"></i>
    <?= htmlspecialchars($_GET['error'] === 'campos_obrigatorios' ? 'Preencha todos os campos obrigatorios.' : $_GET['error']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
        <span class="fw-semibold small"><i class="fa fa-users me-1 text-primary"></i><?= number_format(count($usuarios ?? [])) ?> usuario(s) cadastrado(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Nome</th>
                        <th>E-mail</th>
                        <th>Perfil</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Cadastrado em</th>
                        <th class="text-center">Ultimo Acesso</th>
                        <th class="text-end pe-3">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($usuarios)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="fa fa-users fa-2x mb-2 d-block opacity-25"></i>
                            Nenhum usuario encontrado.<br>
                            <a href="/usuarios/create" class="btn btn-primary btn-sm mt-2"><i class="fa fa-plus me-1"></i> Criar Primeiro Usuario</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($usuarios as $u):
                        $role = $u['tenant_role'] ?? $u['role'] ?? 'viewer';
                        $st   = $u['status'] ?? 'ativo';
                        $roleLabel = match($role) { 'admin','tenant_admin' => ['Administrador','primary'], 'editor' => ['Editor','info'], default => ['Visualizador','secondary'] };
                    ?>
                    <tr>
                        <td class="ps-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-white" style="width:34px;height:34px;min-width:34px;background:<?= $role==='admin'||$role==='tenant_admin' ? '#3b82f6' : '#8b5cf6' ?>;font-size:.8rem;">
                                    <?= strtoupper(substr($u['name']??'?',0,1)) ?>
                                </div>
                                <span class="fw-semibold small"><?= htmlspecialchars($u['name'] ?? '---') ?></span>
                            </div>
                        </td>
                        <td class="text-muted small"><?= htmlspecialchars($u['email'] ?? '---') ?></td>
                        <td><span class="badge bg-<?= $roleLabel[1] ?>"><?= $roleLabel[0] ?></span></td>
                        <td class="text-center">
                            <?php if ($st === 'ativo'): ?><span class="badge bg-success">Ativo</span>
                            <?php else: ?><span class="badge bg-secondary">Inativo</span><?php endif; ?>
                        </td>
                        <td class="text-center text-muted small">
                            <?= !empty($u['created_at']) ? date('d/m/Y', strtotime($u['created_at'])) : '---' ?>
                        </td>
                        <td class="text-center text-muted small">
                            <?php if (!empty($u['last_login_at'])): ?>
                                <?= date('d/m/Y H:i', strtotime($u['last_login_at'])) ?>
                            <?php else: ?>
                                <span class="text-warning"><i class="fa fa-clock me-1"></i>Nunca acessou</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-3">
                            <div class="btn-group btn-group-sm">
                                <a href="/usuarios/<?= $u['id'] ?>/edit" class="btn btn-outline-secondary" title="Editar"><i class="fa fa-edit"></i></a>
                                <form method="POST" action="/usuarios/<?= $u['id'] ?>/toggle" style="display:inline" onsubmit="return confirm('Confirmar alteracao de status?')">
                                    <button type="submit" class="btn btn-outline-<?= $st==='ativo'?'warning':'success' ?> btn-sm" title="<?= $st==='ativo'?'Desativar':'Ativar' ?>">
                                        <i class="fa fa-<?= $st==='ativo'?'ban':'check' ?>"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
