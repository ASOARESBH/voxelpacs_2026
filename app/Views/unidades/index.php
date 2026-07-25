<?php
$unidades = $unidades ?? [];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-0 fw-bold"><i class="fa fa-hospital me-2 text-primary"></i>Unidades</h1>
        <p class="text-muted small mb-0 mt-1">Unidades derivadas automaticamente dos InstitutionNames DICOM. O nome DICOM é somente leitura.</p>
    </div>
    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
        <i class="fa fa-hospital me-1"></i><?= count($unidades) ?> unidade(s)
    </span>
</div>

<?php if (!empty($_SESSION['success'])): ?>
<div class="alert alert-success alert-dismissible fade show py-2" role="alert">
    <i class="fa fa-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['success']); endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
    <i class="fa fa-exclamation-triangle me-2"></i><?= htmlspecialchars($_SESSION['error']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['error']); endif; ?>

<?php if (empty($unidades)): ?>
<div class="card shadow-sm">
    <div class="card-body text-center py-5">
        <i class="fa fa-hospital fa-3x text-muted mb-3"></i>
        <h5 class="text-muted">Nenhuma unidade encontrada</h5>
        <p class="text-muted small">As unidades aparecem automaticamente quando estudos DICOM chegam ao sistema.</p>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
<?php foreach ($unidades as $u): ?>
<?php
    $logoUrl   = !empty($u['logo_path']) ? '/'. $u['logo_path'] : null;
    $ativo     = (bool)($u['ativo'] ?? 1);
    $totalEst  = (int)($u['total_estudos'] ?? 0);
    $cidade    = trim(($u['cidade'] ?? '') . ($u['estado'] ? ', ' . $u['estado'] : ''));
    $cnpj      = $u['cnpj'] ?? '';
    if (strlen($cnpj) === 14) {
        $cnpj = substr($cnpj,0,2).'.'.substr($cnpj,2,3).'.'.substr($cnpj,5,3).'/'.substr($cnpj,8,4).'-'.substr($cnpj,12,2);
    }
?>
<div class="col-12 col-md-6 col-xl-4">
    <div class="card shadow-sm h-100 <?= $ativo ? '' : 'opacity-60 border-secondary' ?>">
        <div class="card-body p-3">
            <div class="d-flex align-items-start gap-3">
                <!-- Logo -->
                <div class="unit-logo-wrap flex-shrink-0">
                    <?php if ($logoUrl): ?>
                    <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo" class="unit-logo rounded">
                    <?php else: ?>
                    <div class="unit-logo-placeholder rounded d-flex align-items-center justify-content-center bg-primary-subtle text-primary">
                        <i class="fa fa-hospital fa-lg"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- Dados -->
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="fw-bold text-truncate small" title="<?= htmlspecialchars($u['institution_name']) ?>">
                            <?= htmlspecialchars($u['institution_name']) ?>
                        </span>
                        <?php if (!$ativo): ?>
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="font-size:10px">Inativa</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($u['descricao'])): ?>
                    <p class="text-muted small mb-1 text-truncate"><?= htmlspecialchars($u['descricao']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($u['razao_social'])): ?>
                    <p class="text-muted small mb-1 text-truncate"><i class="fa fa-building me-1 text-muted"></i><?= htmlspecialchars($u['razao_social']) ?></p>
                    <?php endif; ?>
                    <?php if ($cnpj): ?>
                    <p class="text-muted small mb-1"><i class="fa fa-id-card me-1 text-muted"></i><?= htmlspecialchars($cnpj) ?></p>
                    <?php endif; ?>
                    <?php if ($cidade): ?>
                    <p class="text-muted small mb-1"><i class="fa fa-map-marker-alt me-1 text-muted"></i><?= htmlspecialchars($cidade) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <hr class="my-2">
            <div class="d-flex align-items-center justify-content-between">
                <div class="d-flex gap-3">
                    <span class="small text-muted" title="Total de estudos nesta unidade">
                        <i class="fa fa-file-medical me-1 text-primary"></i>
                        <strong class="text-dark"><?= number_format($totalEst) ?></strong> estudos
                    </span>
                    <?php if (!empty($u['sla_minutos'])): ?>
                    <span class="small text-muted" title="SLA específico desta unidade">
                        <i class="fa fa-clock me-1 text-warning"></i>
                        <?= $u['sla_minutos'] >= 60 ? round($u['sla_minutos']/60).'h' : $u['sla_minutos'].'min' ?> SLA
                    </span>
                    <?php endif; ?>
                </div>
                <a href="/unidades/<?= $u['id'] ?>/edit" class="btn btn-outline-primary btn-sm py-1 px-2">
                    <i class="fa fa-edit me-1"></i>Editar
                </a>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.unit-logo-wrap { width: 52px; height: 52px; }
.unit-logo      { width: 52px; height: 52px; object-fit: contain; border: 1px solid #e5e7eb; background: #f9fafb; }
.unit-logo-placeholder { width: 52px; height: 52px; }
.opacity-60 { opacity: .6; }
.min-w-0 { min-width: 0; }
</style>
