<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Unidades</h1>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
        <span class="fw-semibold small">
            <i class="fa fa-hospital me-1 text-primary"></i>
            <?= number_format(count($unidades ?? [])) ?> unidade(s)
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Nome</th>
                        <th>Cidade</th>
                        <th>Estado</th>
                        <th class="text-end pe-3">Total de Exames</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($unidades)): ?>
                    <tr>
                        <td colspan="4" class="text-center py-5 text-muted">
                            <i class="fa fa-inbox fa-2x mb-2 d-block opacity-25"></i>
                            Nenhuma unidade cadastrada.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($unidades as $u): ?>
                    <tr>
                        <td class="ps-3 fw-semibold"><?= htmlspecialchars($u->nome ?? '—') ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($u->cidade ?? '—') ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($u->estado ?? '—') ?></td>
                        <td class="text-end pe-3 fw-semibold"><?= number_format($u->total_exames ?? 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
