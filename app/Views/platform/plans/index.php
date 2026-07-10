<?php
/**
 * VOXEL PACS — Planos da Plataforma
 */
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.3rem;font-weight:700;color:var(--pacs-text);margin-bottom:.25rem;">
            <i class="fa fa-tags me-2 text-pacs-primary"></i>Planos
        </h1>
        <p style="color:var(--pacs-text-muted);font-size:.82rem;">Gerencie os planos disponíveis para os negócios</p>
    </div>
    <a href="/platform/plans/create" class="btn-pacs-primary">
        <i class="fa fa-plus"></i> Novo Plano
    </a>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="pacs-alert pacs-alert-success mb-3">
        <i class="fa fa-check-circle"></i>
        <?= htmlspecialchars($_SESSION['success']) ?>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<div class="pacs-card">
    <div style="overflow-x:auto;">
        <table class="platform-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nome do Plano</th>
                    <th>Preço/Mês</th>
                    <th>Usuários</th>
                    <th>PACS</th>
                    <th>Exames/Mês</th>
                    <th>Recursos</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($planos)): ?>
                <tr>
                    <td colspan="9" style="text-align:center;padding:2rem;color:var(--pacs-text-muted);">
                        <i class="fa fa-tags fa-2x d-block mb-2"></i>
                        Nenhum plano cadastrado. <a href="/platform/plans/create" style="color:var(--pacs-primary);">Criar primeiro plano</a>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($planos as $p): ?>
                <tr>
                    <td style="color:var(--pacs-text-muted);"><?= $p->id ?? $p['id'] ?></td>
                    <td style="font-weight:600;">
                        <?= htmlspecialchars($p->nome ?? $p['nome']) ?>
                        <?php if (!empty($p->slug ?? $p['slug'])): ?>
                            <small style="color:var(--pacs-text-muted);display:block;font-weight:400;"><?= htmlspecialchars($p->slug ?? $p['slug']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--pacs-success);font-weight:600;">
                        R$ <?= number_format((float)($p->preco_mensal ?? $p['preco_mensal'] ?? 0), 2, ',', '.') ?>
                    </td>
                    <td><?= $p->max_usuarios ?? $p['max_usuarios'] ?? '∞' ?></td>
                    <td><?= $p->max_pacs ?? $p['max_pacs'] ?? '∞' ?></td>
                    <td><?= number_format((int)($p->max_exames_mes ?? $p['max_exames_mes'] ?? 0)) ?></td>
                    <td>
                        <?php
                        $recursos = [];
                        if ($p->permite_preditivo ?? $p['permite_preditivo'] ?? false) $recursos[] = '<span class="esp-tag">Preditivo</span>';
                        if ($p->permite_benchmark ?? $p['permite_benchmark'] ?? false) $recursos[] = '<span class="esp-tag">Benchmark</span>';
                        if ($p->permite_api ?? $p['permite_api'] ?? false) $recursos[] = '<span class="esp-tag">API</span>';
                        echo implode(' ', $recursos) ?: '<span style="color:var(--pacs-text-muted);">Básico</span>';
                        ?>
                    </td>
                    <td>
                        <?php $ativo = (bool)($p->ativo ?? $p['ativo'] ?? true); ?>
                        <span class="badge badge-<?= $ativo ? 'ativo' : 'inativo' ?>">
                            <?= $ativo ? 'Ativo' : 'Inativo' ?>
                        </span>
                    </td>
                    <td>
                        <a href="/platform/plans/<?= $p->id ?? $p['id'] ?>/edit" class="pacs-btn" title="Editar">
                            <i class="fa fa-pen"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
