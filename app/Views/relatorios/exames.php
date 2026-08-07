<?php
/**
 * Relatório de Exames — puramente analítico (sem abrir/laudar).
 * Helpers de badge (modBadge/prioridadeBadge/situacaoBadge) são cópias
 * deliberadas dos de app/Views/estudos/index.php — são funções soltas no
 * escopo daquele arquivo, não uma lib compartilhável; replicar é mais
 * seguro que criar um require cruzado entre views (ver modules/relatorios.md).
 */
function modBadge(string $mod): string {
    $mod = strtoupper(trim($mod));
    if ($mod === '') return '';
    return '<span class="rel-mod-badge">' . htmlspecialchars($mod) . '</span>';
}
function prioridadeBadgeRel(string $p): string {
    $map = [
        'normal'  => ['rel-prio-normal',  'Normal'],
        'urgente' => ['rel-prio-urgente', 'Urgente'],
        'critico' => ['rel-prio-critico', 'Crítico'],
    ];
    [$cls, $label] = $map[$p] ?? ['rel-prio-normal', ucfirst($p)];
    return "<span class=\"rel-prio-badge {$cls}\">{$label}</span>";
}
function situacaoBadgeRel(string $sit): string {
    $map = [
        'novo' => ['rel-sit-novo', 'NOVO'], 'aberto' => ['rel-sit-aberto', 'ABERTO'],
        'a_laudar' => ['rel-sit-a-laudar', 'A LAUDAR'], 'em_laudo' => ['rel-sit-em-laudo', 'EM LAUDO'],
        'rascunho' => ['rel-sit-rascunho', 'RASCUNHO'], 'revisao' => ['rel-sit-rascunho', 'REVISÃO'],
        'assinado' => ['rel-sit-assinado', 'ASSINADO'], 'liberado' => ['rel-sit-liberado', 'LIBERADO'],
    ];
    [$cls, $label] = $map[$sit] ?? ['rel-sit-novo', strtoupper(str_replace('_', ' ', $sit))];
    return "<span class=\"rel-sit-badge {$cls}\">{$label}</span>";
}

function relUrl(array $filtros, int $pagina = null): string {
    $q = $filtros;
    unset($q['tenant_id'], $q['institution_names_autorizadas'], $q['por_pagina']);
    if ($pagina !== null) $q['pagina'] = $pagina;
    return '/relatorios/exames?' . http_build_query($q);
}
?>
<style>
.rel-page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;}
.rel-filtros{background:var(--pacs-card-bg,#1e2330);border:1px solid var(--pacs-border,#2d3244);border-radius:10px;padding:1.25rem 1.5rem;margin-bottom:1rem;}
.rel-filtros-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;}
.rel-label{display:block;font-size:.75rem;font-weight:600;color:var(--pacs-text-muted,#8892a4);margin-bottom:.35rem;}
.rel-input,.rel-select{width:100%;height:36px;padding:0 .65rem;font-size:.85rem;color:var(--pacs-text,#e2e8f0);
    background:var(--pacs-input-bg,#252b3b);border:1px solid var(--pacs-border,#3a3f4b);border-radius:6px;}
.rel-chips{display:flex;flex-wrap:wrap;gap:.4rem;}
.rel-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .7rem;font-size:.78rem;font-weight:500;
    background:rgba(26,86,219,.08);border:1px solid rgba(26,86,219,.25);border-radius:20px;cursor:pointer;color:var(--pacs-text,#e2e8f0);}
.rel-chip input{accent-color:var(--pacs-primary,#1a56db);}
.rel-toggle-group{display:flex;gap:.5rem;margin-bottom:.5rem;}
.rel-toggle-btn{flex:1;padding:.4rem;text-align:center;font-size:.8rem;border:1px solid var(--pacs-border,#3a3f4b);
    border-radius:6px;cursor:pointer;color:var(--pacs-text-muted,#8892a4);background:transparent;}
.rel-toggle-btn.active{background:var(--pacs-primary,#1a56db);color:#fff;border-color:var(--pacs-primary,#1a56db);}
.rel-filtros-acoes{display:flex;gap:.6rem;margin-top:1.1rem;align-items:center;}
.rel-btn-primary{background:var(--pacs-primary,#1a56db);color:#fff;border:none;border-radius:6px;padding:.5rem 1.2rem;
    font-size:.85rem;font-weight:600;cursor:pointer;}
.rel-btn-outline{background:transparent;border:1px solid var(--pacs-border,#3a3f4b);color:var(--pacs-text-muted,#8892a4);
    border-radius:6px;padding:.5rem 1rem;font-size:.85rem;cursor:pointer;text-decoration:none;}
.rel-export-group{margin-left:auto;display:flex;gap:.5rem;}
.rel-table-wrap{background:var(--pacs-card-bg,#1e2330);border:1px solid var(--pacs-border,#2d3244);border-radius:10px;overflow:auto;}
.rel-table{width:100%;border-collapse:collapse;font-size:.82rem;}
.rel-table th{text-align:left;padding:.7rem .9rem;background:var(--pacs-input-bg,#252b3b);color:var(--pacs-text-muted,#8892a4);
    font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--pacs-border,#2d3244);}
.rel-table td{padding:.6rem .9rem;border-bottom:1px solid var(--pacs-border,#2d3244);color:var(--pacs-text,#e2e8f0);}
.rel-table tbody tr:hover{background:rgba(26,86,219,.05);}
.rel-mod-badge{background:rgba(14,165,233,.12);color:#38bdf8;border-radius:4px;padding:.15rem .45rem;font-size:.72rem;font-weight:600;}
.rel-prio-badge{border-radius:4px;padding:.15rem .5rem;font-size:.72rem;font-weight:600;}
.rel-prio-normal{background:rgba(148,163,184,.15);color:#94a3b8;}
.rel-prio-urgente{background:rgba(245,158,11,.15);color:#f59e0b;}
.rel-prio-critico{background:rgba(224,82,82,.15);color:#e05252;}
.rel-sit-badge{border-radius:4px;padding:.15rem .5rem;font-size:.7rem;font-weight:700;}
.rel-sit-novo{background:rgba(148,163,184,.15);color:#94a3b8;}
.rel-sit-aberto{background:rgba(14,165,233,.15);color:#38bdf8;}
.rel-sit-a-laudar{background:rgba(245,158,11,.15);color:#f59e0b;}
.rel-sit-em-laudo{background:rgba(124,58,237,.15);color:#a78bfa;}
.rel-sit-rascunho{background:rgba(148,163,184,.15);color:#94a3b8;}
.rel-sit-assinado{background:rgba(34,197,94,.15);color:#22c55e;}
.rel-sit-liberado{background:rgba(34,197,94,.2);color:#22c55e;}
.rel-empty{text-align:center;padding:3rem 1rem;color:var(--pacs-text-muted,#8892a4);}
.rel-pagination{display:flex;justify-content:space-between;align-items:center;padding:.85rem 1rem;font-size:.8rem;color:var(--pacs-text-muted,#8892a4);}
.rel-pag-links{display:flex;gap:.3rem;}
.rel-pag-btn{padding:.3rem .65rem;border:1px solid var(--pacs-border,#3a3f4b);border-radius:6px;color:var(--pacs-text-muted,#8892a4);text-decoration:none;font-size:.78rem;}
.rel-pag-btn.active{background:var(--pacs-primary,#1a56db);color:#fff;border-color:var(--pacs-primary,#1a56db);}
</style>

<div class="rel-page-header">
    <div>
        <h1 class="h3 mb-0"><i class="fa fa-file-medical me-2 text-pacs-primary"></i><?= htmlspecialchars(t('relatorios.exames.titulo')) ?></h1>
        <p class="text-muted small mb-0 mt-1"><?= htmlspecialchars(t('relatorios.exames.subtitulo')) ?></p>
    </div>
</div>

<form method="GET" action="/relatorios/exames" id="formFiltrosExames">
<div class="rel-filtros">
    <div class="rel-filtros-grid">
        <div>
            <label class="rel-label"><?= htmlspecialchars(t('relatorios.filtro.data')) ?></label>
            <select name="periodo" class="rel-select" onchange="document.getElementById('relPersonalizado').style.display = this.value==='personalizado' ? 'flex' : 'none';">
                <option value="hoje"          <?= $filtros['periodo']==='hoje'?'selected':'' ?>><?= htmlspecialchars(t('relatorios.filtro.data_hoje')) ?></option>
                <option value="7dias"         <?= $filtros['periodo']==='7dias'?'selected':'' ?>><?= htmlspecialchars(t('relatorios.filtro.data_7dias')) ?></option>
                <option value="mensal"        <?= $filtros['periodo']==='mensal'?'selected':'' ?>><?= htmlspecialchars(t('relatorios.filtro.data_mensal')) ?></option>
                <option value="personalizado" <?= $filtros['periodo']==='personalizado'?'selected':'' ?>><?= htmlspecialchars(t('relatorios.filtro.data_personalizado')) ?></option>
            </select>
        </div>
        <div>
            <label class="rel-label"><?= htmlspecialchars(t('relatorios.filtro.unidade')) ?></label>
            <select name="unidade" class="rel-select">
                <option value=""><?= htmlspecialchars(t('relatorios.filtro.todas_unidades')) ?></option>
                <?php foreach ($opcoes['unidades'] as $u): ?>
                    <option value="<?= htmlspecialchars($u) ?>" <?= $filtros['unidade']===$u?'selected':'' ?>><?= htmlspecialchars($u) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div id="relPersonalizado" style="display:<?= $filtros['periodo']==='personalizado'?'flex':'none' ?>;gap:.5rem;">
            <div>
                <label class="rel-label"><?= htmlspecialchars(t('relatorios.filtro.data_inicial')) ?></label>
                <input type="date" name="data_de" class="rel-input" value="<?= htmlspecialchars($filtros['data_de']) ?>">
            </div>
            <div>
                <label class="rel-label"><?= htmlspecialchars(t('relatorios.filtro.data_final')) ?></label>
                <input type="date" name="data_ate" class="rel-input" value="<?= htmlspecialchars($filtros['data_ate']) ?>">
            </div>
        </div>
        <div>
            <label class="rel-label"><?= htmlspecialchars(t('relatorios.filtro.medico_ou_solicitante')) ?></label>
            <div class="rel-toggle-group">
                <label class="rel-toggle-btn <?= $filtros['medico_ou_solicitante']==='medico'?'active':'' ?>" onclick="document.getElementById('relModoPessoa').value='medico';this.classList.add('active');this.nextElementSibling.classList.remove('active');">
                    <?= htmlspecialchars(t('relatorios.filtro.medico')) ?>
                </label>
                <label class="rel-toggle-btn <?= $filtros['medico_ou_solicitante']==='solicitante'?'active':'' ?>" onclick="document.getElementById('relModoPessoa').value='solicitante';this.classList.add('active');this.previousElementSibling.classList.remove('active');">
                    <?= htmlspecialchars(t('relatorios.filtro.solicitante')) ?>
                </label>
            </div>
            <input type="hidden" name="modo_pessoa" id="relModoPessoa" value="<?= htmlspecialchars($filtros['medico_ou_solicitante']) ?>">
            <input type="text" name="pessoa" class="rel-input" list="relPessoasLista" value="<?= htmlspecialchars($filtros['pessoa']) ?>" placeholder="<?= htmlspecialchars(t('relatorios.filtro.buscar_pessoa')) ?>">
            <datalist id="relPessoasLista">
                <?php foreach ($opcoes['medicos'] as $m): ?><option value="<?= htmlspecialchars($m['nome']) ?>"><?php endforeach; ?>
                <?php foreach ($opcoes['solicitantes'] as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?>
            </datalist>
        </div>
    </div>

    <div style="margin-top:1rem;">
        <label class="rel-label"><?= htmlspecialchars(t('relatorios.filtro.modalidades')) ?></label>
        <div class="rel-chips">
            <?php foreach ($opcoes['modalidades'] as $mod): ?>
            <label class="rel-chip">
                <input type="checkbox" name="modalidades[]" value="<?= htmlspecialchars($mod) ?>" <?= in_array($mod, $filtros['modalidades'], true)?'checked':'' ?>>
                <?= htmlspecialchars($mod) ?>
            </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div style="margin-top:1rem;">
        <label class="rel-label"><?= htmlspecialchars(t('relatorios.filtro.prioridade')) ?></label>
        <div class="rel-chips">
            <?php foreach ($opcoes['prioridades'] as $p): ?>
            <label class="rel-chip">
                <input type="checkbox" name="prioridades[]" value="<?= htmlspecialchars($p) ?>" <?= in_array($p, $filtros['prioridades'], true)?'checked':'' ?>>
                <?= htmlspecialchars(ucfirst($p)) ?>
            </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="rel-filtros-acoes">
        <button type="submit" class="rel-btn-primary"><i class="fa fa-filter me-1"></i> <?= htmlspecialchars(t('relatorios.filtro.filtrar')) ?></button>
        <a href="/relatorios/exames" class="rel-btn-outline"><?= htmlspecialchars(t('relatorios.filtro.limpar')) ?></a>
        <div class="rel-export-group">
            <a class="rel-btn-outline" href="/relatorios/exames/exportar?<?= http_build_query(array_merge($filtros, ['formato'=>'pdf'])) ?>"><i class="fa fa-file-pdf me-1"></i> PDF</a>
            <a class="rel-btn-outline" href="/relatorios/exames/exportar?<?= http_build_query(array_merge($filtros, ['formato'=>'xlsx'])) ?>"><i class="fa fa-file-excel me-1"></i> XLS</a>
        </div>
    </div>
</div>
</form>

<div class="rel-table-wrap">
<table class="rel-table">
    <thead>
        <tr>
            <th><?= htmlspecialchars(t('relatorios.coluna.data')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.paciente')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.unidade')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.modalidade')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.prioridade')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.situacao')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.medico')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.solicitante')) ?></th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($linhas)): ?>
        <tr><td colspan="8" class="rel-empty"><i class="fa fa-magnifying-glass me-2"></i><?= htmlspecialchars(t('relatorios.nenhum_resultado')) ?></td></tr>
    <?php else: foreach ($linhas as $l): ?>
        <tr>
            <td><?= htmlspecialchars($l['study_date'] ?? '') ?></td>
            <td><?= htmlspecialchars($l['patient_name'] ?? '') ?></td>
            <td><?= htmlspecialchars($l['institution_name'] ?? '') ?></td>
            <td><?php foreach (explode('\\', $l['modalities'] ?? '') as $m) if (trim($m) !== '') echo modBadge($m); ?></td>
            <td><?= prioridadeBadgeRel($l['prioridade'] ?? 'normal') ?></td>
            <td><?= situacaoBadgeRel($l['situacao'] ?? 'novo') ?></td>
            <td><?= htmlspecialchars($l['assumido_por'] ?: '—') ?></td>
            <td><?= htmlspecialchars($l['especialidade'] ?: ($l['referring_physician_name'] ?: '—')) ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
<div class="rel-pagination">
    <span><?= number_format($total) ?> <?= htmlspecialchars(t('relatorios.total_exames')) ?></span>
    <?php if ($totalPaginas > 1): ?>
    <div class="rel-pag-links">
        <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
            <a href="<?= relUrl($filtros, $p) ?>" class="rel-pag-btn <?= $p===$filtros['pagina']?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
</div>
