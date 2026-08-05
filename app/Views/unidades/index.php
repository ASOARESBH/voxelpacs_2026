<?php
$unidades   = $unidades   ?? [];
$biUnidades = $biUnidades ?? [];
?>

<!-- ── Cabeçalho ──────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-0 fw-bold"><i class="fa fa-hospital me-2 text-primary"></i>Unidades</h1>
        <p class="text-muted small mb-0 mt-1">Gerencie as unidades do seu negócio e vincule aos InstitutionNames DICOM.</p>
    </div>
    <a href="/unidades/nova" class="btn btn-primary btn-sm">
        <i class="fa fa-plus me-1"></i>Nova Unidade
    </a>
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

<!-- ══════════════════════════════════════════════════════════════════════
     SEÇÃO 1 — UNIDADES CADASTRADAS (bi_unidades)
══════════════════════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-4">
    <div class="card-header py-2 d-flex align-items-center gap-2">
        <i class="fa fa-building text-primary"></i>
        <span class="fw-semibold small">Unidades Cadastradas</span>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-2" style="font-size:10px">
            <?= count($biUnidades) ?> unidade(s)
        </span>
        <a href="/unidades/nova" class="btn btn-outline-primary btn-sm py-0 px-2 ms-auto" style="font-size:11px">
            <i class="fa fa-plus me-1"></i>Nova
        </a>
    </div>
    <div class="card-body p-3">
        <?php if (empty($biUnidades)): ?>
        <div class="text-center py-4">
            <i class="fa fa-building fa-2x text-muted mb-2"></i>
            <p class="text-muted small mb-2">Nenhuma unidade cadastrada ainda.</p>
            <a href="/unidades/nova" class="btn btn-primary btn-sm">
                <i class="fa fa-plus me-1"></i>Cadastrar primeira unidade
            </a>
        </div>
        <?php else: ?>
        <div class="row g-3">
        <?php foreach ($biUnidades as $bu): ?>
        <?php
            $buLogo    = !empty($bu['logo_path']) ? '/' . $bu['logo_path'] : null;
            $buCnpj    = $bu['cnpj'] ?? '';
            if (strlen($buCnpj) === 14) {
                $buCnpj = substr($buCnpj,0,2).'.'.substr($buCnpj,2,3).'.'.substr($buCnpj,5,3).'/'.substr($buCnpj,8,4).'-'.substr($buCnpj,12,2);
            }
            $buCidade  = trim(($bu['cidade'] ?? '') . ($bu['estado'] ? ', ' . $bu['estado'] : ''));
            $buVinculos = !empty($bu['institution_names']) ? explode('|', $bu['institution_names']) : [];
            $buAtivo   = (bool)($bu['ativo'] ?? 1);
        ?>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card h-100 border <?= $buAtivo ? 'border-primary-subtle' : 'border-secondary-subtle opacity-60' ?>">
                <div class="card-body p-3">
                    <div class="d-flex align-items-start gap-3">
                        <!-- Logo -->
                        <div class="bu-logo-wrap flex-shrink-0">
                            <?php if ($buLogo): ?>
                            <img src="<?= htmlspecialchars($buLogo) ?>" alt="Logo" class="bu-logo rounded">
                            <?php else: ?>
                            <div class="bu-logo-placeholder rounded d-flex align-items-center justify-content-center bg-primary-subtle text-primary">
                                <i class="fa fa-hospital"></i>
                            </div>
                            <?php endif; ?>
                        </div>
                        <!-- Dados -->
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex align-items-center gap-1 mb-1">
                                <span class="fw-bold small text-truncate"
                                      title="<?= htmlspecialchars($bu['nome_fantasia'] ?? $bu['razao_social'] ?? '') ?>">
                                    <?= htmlspecialchars($bu['nome_fantasia'] ?? $bu['razao_social'] ?? 'Sem nome') ?>
                                </span>
                                <?php if (!$buAtivo): ?>
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle flex-shrink-0" style="font-size:10px">Inativa</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($bu['razao_social']) && !empty($bu['nome_fantasia'])): ?>
                            <div class="text-muted small text-truncate"><?= htmlspecialchars($bu['razao_social']) ?></div>
                            <?php endif; ?>
                            <?php if ($buCnpj): ?>
                            <div class="text-muted small"><i class="fa fa-id-card me-1"></i><?= htmlspecialchars($buCnpj) ?></div>
                            <?php endif; ?>
                            <?php if ($buCidade): ?>
                            <div class="text-muted small"><i class="fa fa-map-marker-alt me-1"></i><?= htmlspecialchars($buCidade) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Vínculos institution_names -->
                    <?php if (!empty($buVinculos)): ?>
                    <div class="mt-2 pt-2 border-top">
                        <div class="text-muted mb-1" style="font-size:10px;font-weight:600">
                            <i class="fa fa-link me-1"></i>INSTITUTION NAMES VINCULADOS:
                        </div>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($buVinculos as $vn): ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="font-size:10px">
                                <?= htmlspecialchars(trim($vn)) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="mt-2 pt-2 border-top">
                        <span class="text-warning small" style="font-size:10px">
                            <i class="fa fa-exclamation-triangle me-1"></i>Nenhum InstitutionName vinculado
                        </span>
                    </div>
                    <?php endif; ?>

                    <!-- Copilot sync badge -->
                    <?php if (!empty($bu['copilot_logo_url'])): ?>
                    <div class="mt-2">
                        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:10px">
                            <i class="fa fa-check-circle me-1"></i>Logo sincronizada com VoxelCopilot
                        </span>
                    </div>
                    <?php endif; ?>

                    <div class="mt-2 d-flex justify-content-end">
                        <a href="/unidades/<?= (int)$bu['id'] ?>/editar" class="btn btn-outline-primary btn-sm py-1 px-2">
                            <i class="fa fa-edit me-1"></i>Editar
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     SEÇÃO 2 — INSTITUTION NAMES DICOM (bi_negocio_institution_names)
══════════════════════════════════════════════════════════════════════ -->
<div class="d-flex align-items-center gap-2 mb-2">
    <h2 class="h6 mb-0 fw-bold text-muted">
        <i class="fa fa-list me-1"></i>InstitutionNames DICOM
    </h2>
    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="font-size:10px">
        <?= count($unidades) ?> nome(s)
    </span>
    <span class="text-muted ms-1" style="font-size:10px">
        Derivados automaticamente dos estudos recebidos. Nome DICOM é somente leitura.
    </span>
</div>

<?php if (empty($unidades)): ?>
<div class="card shadow-sm">
    <div class="card-body text-center py-4">
        <i class="fa fa-hospital fa-2x text-muted mb-2"></i>
        <h5 class="text-muted small">Nenhum InstitutionName encontrado</h5>
        <p class="text-muted small">Os nomes aparecem automaticamente quando estudos DICOM chegam ao sistema.</p>
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
    // Verifica se este institution_name já está vinculado a alguma bi_unidade
    $vinculadoA = $u['bi_unidade_nome'] ?? null;
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
                    <?php if ($vinculadoA): ?>
                    <div class="text-success small mb-1">
                        <i class="fa fa-link me-1"></i>Vinculado: <strong><?= htmlspecialchars($vinculadoA) ?></strong>
                    </div>
                    <?php else: ?>
                    <div class="text-warning small mb-1" style="font-size:10px">
                        <i class="fa fa-exclamation-triangle me-1"></i>Não vinculado a nenhuma unidade
                    </div>
                    <?php endif; ?>
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
                <a href="/unidades/<?= $u['id'] ?>/edit" class="btn btn-outline-secondary btn-sm py-1 px-2">
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
.unit-logo-wrap       { width: 52px; height: 52px; }
.unit-logo            { width: 52px; height: 52px; object-fit: contain; border: 1px solid #e5e7eb; background: #f9fafb; }
.unit-logo-placeholder{ width: 52px; height: 52px; }
.bu-logo-wrap         { width: 48px; height: 48px; }
.bu-logo              { width: 48px; height: 48px; object-fit: contain; border: 1px solid #e5e7eb; background: #f9fafb; }
.bu-logo-placeholder  { width: 48px; height: 48px; }
.opacity-60           { opacity: .6; }
.min-w-0              { min-width: 0; }
</style>
