<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="fa fa-hospital me-2 text-primary"></i>Unidades / InstitutionNames</h1>
        <p class="text-muted small mb-0 mt-1">Unidades derivadas automaticamente dos InstitutionNames cadastrados no Negócio. O nome DICOM é somente leitura.</p>
    </div>
</div>

<?php if (!empty($_SESSION['success'])): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fa fa-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['success']); endif; ?>

<?php if (!empty($_SESSION['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fa fa-exclamation-triangle me-2"></i><?= htmlspecialchars($_SESSION['error']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['error']); endif; ?>

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
        <span class="fw-semibold small">
            <i class="fa fa-hospital me-1 text-primary"></i>
            <?= number_format(count($unidades ?? [])) ?> unidade(s) encontrada(s)
        </span>
        <span class="badge bg-secondary small">
            <i class="fa fa-lock me-1"></i>Gerenciado via Negócios
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">#</th>
                        <th><i class="fa fa-lock text-muted me-1" title="Somente leitura"></i>InstitutionName (DICOM)</th>
                        <th>Descrição</th>
                        <th>Cidade / UF</th>
                        <th>Responsável</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Exames</th>
                        <th class="text-center pe-3">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($unidades)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fa fa-inbox fa-2x mb-2 d-block opacity-25"></i>
                            Nenhuma unidade cadastrada.<br>
                            <small>Cadastre InstitutionNames em <a href="/platform/negocios">Admin Platform → Negócios</a>.</small>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($unidades as $u): ?>
                    <tr>
                        <td class="ps-3 text-muted small"><?= (int)($u['id'] ?? 0) ?></td>
                        <td>
                            <span class="badge bg-light text-dark border font-monospace small">
                                <i class="fa fa-lock text-muted me-1"></i><?= htmlspecialchars($u['institution_name'] ?? '—') ?>
                            </span>
                        </td>
                        <td class="small"><?= htmlspecialchars($u['descricao'] ?? '—') ?></td>
                        <td class="text-muted small">
                            <?= htmlspecialchars($u['cidade'] ?? '') ?>
                            <?php if (!empty($u['estado'])): ?><span class="badge bg-secondary ms-1"><?= htmlspecialchars($u['estado']) ?></span><?php endif; ?>
                        </td>
                        <td class="small"><?= htmlspecialchars($u['responsavel'] ?? '—') ?></td>
                        <td class="text-center">
                            <?php if (($u['ativo'] ?? 1)): ?>
                                <span class="badge bg-success">Ativa</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inativa</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-semibold"><?= number_format($u['total_exames'] ?? 0) ?></td>
                        <td class="text-center pe-3">
                            <a href="/unidades/<?= (int)$u['id'] ?>/edit" class="btn btn-sm btn-outline-primary" title="Editar dados complementares">
                                <i class="fa fa-edit"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="alert alert-info mt-3 small">
    <i class="fa fa-info-circle me-2"></i>
    <strong>Como funciona:</strong> As unidades são criadas automaticamente quando você cadastra um <strong>InstitutionName</strong> em 
    <a href="/platform/negocios" class="alert-link">Admin Platform → Negócios → Aba DICOM</a>. 
    Aqui você pode apenas editar dados complementares (descrição, responsável, cidade, SLA, etc.). 
    O campo <code>InstitutionName</code> é bloqueado pois é o identificador DICOM oficial.
</div>
