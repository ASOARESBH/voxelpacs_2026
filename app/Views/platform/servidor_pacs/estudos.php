<?php
// View: Servidor PACS — Lista de estudos importados
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="fa fa-x-ray me-2 text-primary"></i>Estudos DICOM</h1>
        <small class="text-muted">Estudos importados do Orthanc PACS — Total: <strong><?= number_format($total) ?></strong></small>
    </div>
    <a href="/platform/servidor-pacs" class="btn btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i> Voltar
    </a>
</div>

<!-- FILTROS -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="GET" action="/platform/servidor-pacs/estudos" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold">InstitutionName</label>
                <select name="institution" class="form-select form-select-sm">
                    <option value="">Todas as instituições</option>
                    <?php foreach ($institutions as $inst): ?>
                        <option value="<?= htmlspecialchars($inst) ?>" <?= $filtroInstitution === $inst ? 'selected' : '' ?>>
                            <?= htmlspecialchars($inst) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Negócio</label>
                <select name="tenant" class="form-select form-select-sm">
                    <option value="">Todos os negócios</option>
                    <?php foreach ($negocios as $n): ?>
                        <option value="<?= $n['id'] ?>" <?= $filtroTenant == $n['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($n['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Status Roteamento</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <option value="roteado" <?= $filtroStatus === 'roteado' ? 'selected' : '' ?>>Roteados</option>
                    <option value="nao_roteado" <?= $filtroStatus === 'nao_roteado' ? 'selected' : '' ?>>Não Roteados</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="fa fa-filter me-1"></i> Filtrar
                </button>
            </div>
            <div class="col-md-2">
                <a href="/platform/servidor-pacs/estudos" class="btn btn-outline-secondary btn-sm w-100">
                    <i class="fa fa-times me-1"></i> Limpar
                </a>
            </div>
        </form>
    </div>
</div>

<!-- TABELA DE ESTUDOS -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($estudos)): ?>
            <div class="p-5 text-center text-muted">
                <i class="fa fa-x-ray fa-3x mb-3"></i>
                <p class="mb-2">Nenhum estudo encontrado.</p>
                <a href="/platform/servidor-pacs" class="btn btn-primary">Sincronizar Estudos</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th>Paciente</th>
                            <th>Data do Estudo</th>
                            <th>InstitutionName</th>
                            <th>Negócio</th>
                            <th class="text-center">Séries</th>
                            <th>Accession</th>
                            <th class="text-center">Estável</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($estudos as $e): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold small"><?= htmlspecialchars($e['patient_name'] ?? '—') ?></div>
                                    <div class="text-muted" style="font-size:.75rem;">
                                        ID: <?= htmlspecialchars($e['patient_id'] ?? '—') ?>
                                        <?php if ($e['patient_sex']): ?>
                                            | <?= $e['patient_sex'] === 'M' ? '♂' : ($e['patient_sex'] === 'F' ? '♀' : $e['patient_sex']) ?>
                                        <?php endif; ?>
                                        <?php if ($e['patient_birth_date']): ?>
                                            | <?= date('d/m/Y', strtotime($e['patient_birth_date'])) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="small">
                                    <?= $e['study_date'] ? date('d/m/Y', strtotime($e['study_date'])) : '—' ?>
                                    <?php if ($e['study_description']): ?>
                                        <br><span class="text-muted"><?= htmlspecialchars(substr($e['study_description'], 0, 40)) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code class="small"><?= htmlspecialchars($e['institution_name'] ?? '(vazio)') ?></code>
                                </td>
                                <td>
                                    <?php if ($e['tenant_id']): ?>
                                        <span class="badge bg-success">
                                            <i class="fa fa-building me-1"></i>
                                            <?= htmlspecialchars($e['negocio_nome'] ?? 'Negócio #'.$e['tenant_id']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">
                                            <i class="fa fa-question-circle me-1"></i> Não roteado
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?= $e['num_series'] ?></span>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars($e['accession_number'] ?? '—') ?></td>
                                <td class="text-center">
                                    <?php if ($e['is_stable']): ?>
                                        <i class="fa fa-check-circle text-success" title="Estável"></i>
                                    <?php else: ?>
                                        <i class="fa fa-clock text-warning" title="Aguardando"></i>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINAÇÃO -->
            <?php if ($totalPaginas > 1): ?>
                <div class="d-flex justify-content-between align-items-center p-3 border-top">
                    <small class="text-muted">
                        Mostrando <?= (($pagina - 1) * $porPagina) + 1 ?> a <?= min($pagina * $porPagina, $total) ?> de <?= number_format($total) ?> estudos
                    </small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($pagina > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?pagina=<?= $pagina - 1 ?>&institution=<?= urlencode($filtroInstitution) ?>&tenant=<?= $filtroTenant ?>&status=<?= $filtroStatus ?>">
                                        <i class="fa fa-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($p = max(1, $pagina - 2); $p <= min($totalPaginas, $pagina + 2); $p++): ?>
                                <li class="page-item <?= $p === $pagina ? 'active' : '' ?>">
                                    <a class="page-link" href="?pagina=<?= $p ?>&institution=<?= urlencode($filtroInstitution) ?>&tenant=<?= $filtroTenant ?>&status=<?= $filtroStatus ?>">
                                        <?= $p ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($pagina < $totalPaginas): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?pagina=<?= $pagina + 1 ?>&institution=<?= urlencode($filtroInstitution) ?>&tenant=<?= $filtroTenant ?>&status=<?= $filtroStatus ?>">
                                        <i class="fa fa-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
