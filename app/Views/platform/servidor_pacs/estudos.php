<?php
// View: Servidor PACS — Lista de estudos importados (todos os servidores) + filas de pendência
// Listagem administrativa: Person Name é formatado apenas no momento da leitura.
$statusBadge = [
    'roteado'          => ['class' => 'success', 'icon' => 'fa-check-circle'],
    'nao_identificado' => ['class' => 'warning text-dark', 'icon' => 'fa-question-circle'],
    'conflito'         => ['class' => 'danger', 'icon' => 'fa-exclamation-triangle'],
];
$statusLabelMap = [
    'roteado'          => t('servidor_pacs.status.roteado'),
    'nao_identificado' => t('servidor_pacs.status.nao_identificado'),
    'conflito'         => t('servidor_pacs.status.conflito'),
];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="fa fa-x-ray me-2 text-primary"></i><?= htmlspecialchars(t('servidor_pacs.estudos.titulo')) ?></h1>
        <small class="text-muted"><?= htmlspecialchars(t('servidor_pacs.estudos.subtitulo')) ?> — <?= htmlspecialchars(t('servidor_pacs.estudos.total')) ?>: <strong><?= number_format($total) ?></strong></small>
    </div>
    <a href="/platform/servidor-pacs" class="btn btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i> <?= htmlspecialchars(t('comum.acoes.voltar')) ?>
    </a>
</div>

<!-- SEÇÕES DE PENDÊNCIA — sempre visíveis, nunca escondidas atrás de um filtro -->
<?php if (!empty($conflitos)): ?>
<div class="card border-danger shadow-sm mb-4">
    <div class="card-header bg-danger bg-opacity-10 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold text-danger"><i class="fa fa-exclamation-triangle me-2"></i><?= htmlspecialchars(t('servidor_pacs.estudos.secao_conflitos')) ?> (<?= count($conflitos) ?>)</h6>
    </div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm table-hover mb-0">
<thead class="table-light">
<tr>
<th><?= htmlspecialchars(t('servidor_pacs.estudos.coluna_paciente')) ?></th>
<th>InstitutionName</th>
                        <th><?= htmlspecialchars(t('servidor_pacs.estudos.issuer')) ?></th>
<th><?= htmlspecialchars(t('servidor_pacs.estudos.coluna_servidor')) ?></th>
                        <th><?= htmlspecialchars(t('servidor_pacs.estudos.candidatos')) ?></th>
                        <th><?= htmlspecialchars(t('servidor_pacs.estudos.resolver_para')) ?></th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($conflitos as $e): ?>
<?php $candidatos = json_decode($e['roteamento_candidatos'] ?? '[]', true) ?: []; ?>
<tr>
<td class="small"><?= htmlspecialchars(\App\Helpers\DicomPersonName::displayFromStudy($e) ?: '—') ?></td>
<td><code class="small"><?= htmlspecialchars($e['institution_name'] ?? '—') ?></code></td>
                        <td><code class="small"><?= htmlspecialchars($e['issuer_of_patient_id'] ?? '—') ?></code></td>
<td class="small"><?= htmlspecialchars($e['servidor_nome'] ?? '—') ?></td>
                            <td class="small">
                                <?php foreach ($candidatos as $c): ?>
                                    <span class="badge bg-secondary bg-opacity-25 text-dark border me-1"><?= htmlspecialchars($c['nome']) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <form class="d-flex gap-1 form-resolver" data-id="<?= (int)$e['id'] ?>">
                                    <select name="tenant_id" class="form-select form-select-sm" required style="max-width:200px;">
                                        <option value="">...</option>
                                        <?php foreach ($negocios as $n): ?>
                                            <option value="<?= (int)$n['id'] ?>"><?= htmlspecialchars($n['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-danger"><?= htmlspecialchars(t('servidor_pacs.estudos.botao_resolver')) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($naoIdentificados)): ?>
<div class="card border-warning shadow-sm mb-4">
<div class="card-header bg-warning bg-opacity-10 d-flex justify-content-between align-items-center">
<h6 class="mb-0 fw-bold text-warning-emphasis"><i class="fa fa-question-circle me-2"></i><?= htmlspecialchars(t('servidor_pacs.estudos.secao_nao_identificados')) ?> (<?= count($naoIdentificados) ?>)</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:400px;">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light sticky-top">
<tr>
<th><?= htmlspecialchars(t('servidor_pacs.estudos.coluna_paciente')) ?></th>
<th>InstitutionName</th>
                        <th><?= htmlspecialchars(t('servidor_pacs.estudos.issuer')) ?></th>
<th><?= htmlspecialchars(t('servidor_pacs.estudos.coluna_servidor')) ?></th>
                        <th><?= htmlspecialchars(t('servidor_pacs.estudos.resolver_para')) ?></th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($naoIdentificados as $e): ?>
<tr>
<td class="small"><?= htmlspecialchars(\App\Helpers\DicomPersonName::displayFromStudy($e) ?: '—') ?></td>
<td><code class="small"><?= htmlspecialchars($e['institution_name'] ?? '(vazio)') ?></code></td>
                        <td><code class="small"><?= htmlspecialchars($e['issuer_of_patient_id'] ?? '—') ?></code></td>
<td class="small"><?= htmlspecialchars($e['servidor_nome'] ?? '—') ?></td>
                            <td>
                                <form class="d-flex gap-1 form-resolver" data-id="<?= (int)$e['id'] ?>">
                                    <select name="tenant_id" class="form-select form-select-sm" required style="max-width:200px;">
                                        <option value=""><?= htmlspecialchars(t('servidor_pacs.configurar.selecione_negocio')) ?></option>
                                        <?php foreach ($negocios as $n): ?>
                                            <option value="<?= (int)$n['id'] ?>"><?= htmlspecialchars($n['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-warning"><?= htmlspecialchars(t('servidor_pacs.estudos.botao_resolver')) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- FILTROS -->
<div class="card border-0 shadow-sm mb-4">
<div class="card-body py-3">
<form method="GET" action="/platform/servidor-pacs/estudos" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= htmlspecialchars(t('servidor_pacs.estudos.coluna_servidor')) ?></label>
                <select name="servidor" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($servidores as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $filtroServidor == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
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
            <div class="col-md-2">
                <label class="form-label small fw-semibold"><?= htmlspecialchars(t('servidor_pacs.estudos.issuer')) ?></label>
                <select name="issuer" class="form-select form-select-sm">
                    <option value=""><?= htmlspecialchars(t('servidor_pacs.estudos.todos_issuers')) ?></option>
                    <?php foreach ($issuers as $issuer): ?>
                        <option value="<?= htmlspecialchars($issuer) ?>" <?= $filtroIssuer === $issuer ? 'selected' : '' ?>><?= htmlspecialchars($issuer) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
<div class="col-md-3">
<label class="form-label small fw-semibold"><?= htmlspecialchars(t('servidor_pacs.estudos.coluna_negocio')) ?></label>
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
                <label class="form-label small fw-semibold"><?= htmlspecialchars(t('servidor_pacs.estudos.status_roteamento')) ?></label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <option value="roteado" <?= $filtroStatus === 'roteado' ? 'selected' : '' ?>><?= htmlspecialchars(t('servidor_pacs.status.roteado')) ?></option>
                    <option value="nao_identificado" <?= $filtroStatus === 'nao_identificado' ? 'selected' : '' ?>><?= htmlspecialchars(t('servidor_pacs.status.nao_identificado')) ?></option>
                    <option value="conflito" <?= $filtroStatus === 'conflito' ? 'selected' : '' ?>><?= htmlspecialchars(t('servidor_pacs.status.conflito')) ?></option>
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa fa-filter"></i></button>
            </div>
            <div class="col-md-1">
                <a href="/platform/servidor-pacs/estudos" class="btn btn-outline-secondary btn-sm w-100"><i class="fa fa-times"></i></a>
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
                <p class="mb-2"><?= htmlspecialchars(t('servidor_pacs.estudos.nenhum_estudo')) ?></p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th><?= htmlspecialchars(t('servidor_pacs.estudos.coluna_paciente')) ?></th>
<th><?= htmlspecialchars(t('servidor_pacs.estudos.coluna_data')) ?></th>
<th>InstitutionName</th>
                            <th><?= htmlspecialchars(t('servidor_pacs.estudos.issuer')) ?></th>
<th><?= htmlspecialchars(t('servidor_pacs.estudos.coluna_servidor')) ?></th>
                            <th><?= htmlspecialchars(t('servidor_pacs.estudos.coluna_negocio')) ?></th>
                            <th class="text-center"><?= htmlspecialchars(t('servidor_pacs.estudos.coluna_series')) ?></th>
                            <th class="text-center"><?= htmlspecialchars(t('servidor_pacs.estudos.coluna_status')) ?></th>
                            <th class="text-end"><?= htmlspecialchars(t('comum.acoes.titulo')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($estudos as $e): ?>
                            <?php $sb = $statusBadge[$e['roteamento_status']] ?? $statusBadge['nao_identificado']; ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold small"><?= htmlspecialchars(\App\Helpers\DicomPersonName::displayFromStudy($e) ?: '—') ?></div>
                                    <div class="text-muted" style="font-size:.75rem;">ID: <?= htmlspecialchars($e['patient_id'] ?? '—') ?></div>
                                </td>
                                <td class="small">
<?= $e['study_date'] ? date('d/m/Y', strtotime($e['study_date'])) : '—' ?>
</td>
<td><code class="small"><?= htmlspecialchars($e['institution_name'] ?? '(vazio)') ?></code></td>
                                <td><code class="small"><?= htmlspecialchars($e['issuer_of_patient_id'] ?? '—') ?></code></td>
<td class="small"><?= htmlspecialchars($e['servidor_nome'] ?? '—') ?></td>
                                <td class="small">
                                    <?= $e['negocio_nome'] ? htmlspecialchars($e['negocio_nome']) : '<span class="text-muted">—</span>' ?>
                                </td>
                                <td class="text-center"><span class="badge bg-secondary"><?= $e['num_series'] ?></span></td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $sb['class'] ?>"><i class="fa <?= $sb['icon'] ?> me-1"></i><?= htmlspecialchars($statusLabelMap[$e['roteamento_status']] ?? $e['roteamento_status']) ?></span>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-secondary btn-ver-tags" data-id="<?= (int)$e['id'] ?>" title="<?= htmlspecialchars(t('servidor_pacs.estudos.ver_tags')) ?>">
                                        <i class="fa fa-tags"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINAÇÃO -->
            <?php if ($totalPaginas > 1): ?>
                <?php $qs = "&servidor={$filtroServidor}&institution=" . urlencode($filtroInstitution) . "&issuer=" . urlencode($filtroIssuer) . "&tenant={$filtroTenant}&status={$filtroStatus}"; ?>
                <div class="d-flex justify-content-between align-items-center p-3 border-top">
                    <small class="text-muted">
                        <?= (($pagina - 1) * $porPagina) + 1 ?>–<?= min($pagina * $porPagina, $total) ?> / <?= number_format($total) ?>
                    </small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($pagina > 1): ?>
                                <li class="page-item"><a class="page-link" href="?pagina=<?= $pagina - 1 ?><?= $qs ?>"><i class="fa fa-chevron-left"></i></a></li>
                            <?php endif; ?>
                            <?php for ($p = max(1, $pagina - 2); $p <= min($totalPaginas, $pagina + 2); $p++): ?>
                                <li class="page-item <?= $p === $pagina ? 'active' : '' ?>"><a class="page-link" href="?pagina=<?= $p ?><?= $qs ?>"><?= $p ?></a></li>
                            <?php endfor; ?>
                            <?php if ($pagina < $totalPaginas): ?>
                                <li class="page-item"><a class="page-link" href="?pagina=<?= $pagina + 1 ?><?= $qs ?>"><i class="fa fa-chevron-right"></i></a></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL: TAGS DICOM COMPLETAS -->
<div class="modal fade" id="modalTags" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa fa-tags me-2"></i><?= htmlspecialchars(t('servidor_pacs.estudos.modal_tags_titulo')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="modalTagsBody" class="text-muted text-center py-4"><i class="fa fa-spinner fa-spin me-2"></i><?= htmlspecialchars(t('servidor_pacs.estudos.carregando')) ?></div>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.btn-ver-tags').forEach(btn => {
    btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        const body = document.getElementById('modalTagsBody');
        body.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i><?= htmlspecialchars(t('servidor_pacs.estudos.carregando')) ?>';
        const modal = new bootstrap.Modal(document.getElementById('modalTags'));
        modal.show();

        fetch(`/platform/servidor-pacs/estudos/${id}/tags`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    body.innerHTML = `<div class="text-center text-muted py-3">${data.message}</div>`;
                    return;
                }
                const tags = data.tags || {};
                const keys = Object.keys(tags).sort();
                if (keys.length === 0) {
                    body.innerHTML = '<div class="text-center text-muted py-3">Nenhuma tag disponível.</div>';
                    return;
                }
                let html = '<table class="table table-sm table-striped"><tbody>';
                keys.forEach(k => {
                    const v = typeof tags[k] === 'object' ? JSON.stringify(tags[k]) : tags[k];
                    html += `<tr><td class="text-muted small" style="width:40%;">${escapeHtml(k)}</td><td class="small">${escapeHtml(String(v ?? ''))}</td></tr>`;
                });
                html += '</tbody></table>';
                body.innerHTML = html;
            })
            .catch(() => { body.innerHTML = '<div class="text-center text-danger py-3">Erro ao carregar tags.</div>'; });
    });
});

function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

document.querySelectorAll('.form-resolver').forEach(form => {
    form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const id = form.dataset.id;
        const tenantId = form.querySelector('select[name="tenant_id"]').value;
        if (!tenantId) return;

        fetch(`/platform/servidor-pacs/estudos/${id}/resolver`, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body: `tenant_id=${encodeURIComponent(tenantId)}`
        })
            .then(r => r.json())
            .then(data => { if (data.success) location.reload(); else alert(data.message); });
    });
});
</script>
