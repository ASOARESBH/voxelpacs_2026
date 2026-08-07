<?php
/** Relatório SLA Médicos — ver RelatorioSlaCalcService pro motor de cálculo. */
function relStatusSlaBadge(string $status): string {
    $map = [
        'verde'    => ['rel-sla-verde',    'fa-circle-check',       'Dentro do prazo'],
        'amarelo'  => ['rel-sla-amarelo',  'fa-triangle-exclamation','Atenção'],
        'vermelho' => ['rel-sla-vermelho', 'fa-circle-exclamation', 'Estourado'],
        'sem_sla'  => ['rel-sla-neutro',   'fa-circle-minus',       'Sem SLA definido'],
    ];
    [$cls, $ico, $label] = $map[$status] ?? $map['sem_sla'];
    return "<span class=\"rel-sla-badge {$cls}\"><i class=\"fa {$ico}\"></i> {$label}</span>";
}
function relFormatarMinutos(?int $min): string {
    if ($min === null) return '—';
    $h = intdiv($min, 60); $m = $min % 60;
    return $h > 0 ? "{$h}h{$m}min" : "{$m}min";
}
function relSlaUrl(array $filtros, int $pagina = null): string {
    $q = $filtros;
    unset($q['tenant_id'], $q['institution_names_autorizadas'], $q['por_pagina']);
    if ($pagina !== null) $q['pagina'] = $pagina;
    return '/relatorios/sla-medicos?' . http_build_query($q);
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
.rel-filtros-acoes{display:flex;gap:.6rem;margin-top:1.1rem;align-items:center;}
.rel-btn-primary{background:var(--pacs-primary,#1a56db);color:#fff;border:none;border-radius:6px;padding:.5rem 1.2rem;font-size:.85rem;font-weight:600;cursor:pointer;}
.rel-btn-outline{background:transparent;border:1px solid var(--pacs-border,#3a3f4b);color:var(--pacs-text-muted,#8892a4);border-radius:6px;padding:.5rem 1rem;font-size:.85rem;cursor:pointer;text-decoration:none;}
.rel-export-group{margin-left:auto;display:flex;gap:.5rem;}
.rel-modo-sla{display:flex;gap:1.5rem;flex-wrap:wrap;padding-top:1rem;margin-top:1rem;border-top:1px solid var(--pacs-border,#2d3244);}
.rel-modo-bloco{flex:1;min-width:260px;}
.rel-tempo-maior{display:flex;gap:.5rem;align-items:end;}
.rel-tempo-maior input{width:100px;}
.rel-tempo-maior select{width:110px;}
.rel-table-wrap{background:var(--pacs-card-bg,#1e2330);border:1px solid var(--pacs-border,#2d3244);border-radius:10px;overflow:auto;margin-bottom:1.25rem;}
.rel-table{width:100%;border-collapse:collapse;font-size:.82rem;}
.rel-table th{text-align:left;padding:.7rem .9rem;background:var(--pacs-input-bg,#252b3b);color:var(--pacs-text-muted,#8892a4);
    font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--pacs-border,#2d3244);}
.rel-table td{padding:.6rem .9rem;border-bottom:1px solid var(--pacs-border,#2d3244);color:var(--pacs-text,#e2e8f0);}
.rel-table tbody tr:hover{background:rgba(26,86,219,.05);}
.rel-empty{text-align:center;padding:3rem 1rem;color:var(--pacs-text-muted,#8892a4);}
.rel-pagination{display:flex;justify-content:space-between;align-items:center;padding:.85rem 1rem;font-size:.8rem;color:var(--pacs-text-muted,#8892a4);}
.rel-pag-links{display:flex;gap:.3rem;}
.rel-pag-btn{padding:.3rem .65rem;border:1px solid var(--pacs-border,#3a3f4b);border-radius:6px;color:var(--pacs-text-muted,#8892a4);text-decoration:none;font-size:.78rem;}
.rel-pag-btn.active{background:var(--pacs-primary,#1a56db);color:#fff;border-color:var(--pacs-primary,#1a56db);}
.rel-sla-badge{display:inline-flex;align-items:center;gap:.35rem;border-radius:20px;padding:.2rem .65rem;font-size:.75rem;font-weight:600;white-space:nowrap;}
.rel-sla-verde{background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.3);}
.rel-sla-amarelo{background:rgba(245,158,11,.12);color:#f59e0b;border:1px solid rgba(245,158,11,.3);}
.rel-sla-vermelho{background:rgba(224,82,82,.12);color:#e05252;border:1px solid rgba(224,82,82,.3);}
.rel-sla-neutro{background:rgba(148,163,184,.12);color:#94a3b8;border:1px solid rgba(148,163,184,.3);}
.rel-agregado-nome{font-weight:600;}
.rel-secao-titulo{font-size:.95rem;font-weight:700;margin:0 0 .75rem;color:var(--pacs-text,#e2e8f0);}
</style>

<div class="rel-page-header">
    <div>
        <h1 class="h3 mb-0"><i class="fa fa-gauge-high me-2 text-pacs-primary"></i><?= htmlspecialchars(t('relatorios.sla.titulo')) ?></h1>
        <p class="text-muted small mb-0 mt-1"><?= htmlspecialchars(t('relatorios.sla.subtitulo')) ?></p>
    </div>
</div>

<form method="GET" action="/relatorios/sla-medicos" id="formFiltrosSla">
<div class="rel-filtros">
    <div class="rel-filtros-grid">
        <div>
            <label class="rel-label"><?= htmlspecialchars(t('relatorios.filtro.data')) ?></label>
            <select name="periodo" class="rel-select" onchange="document.getElementById('relPersonalizadoSla').style.display = this.value==='personalizado' ? 'flex' : 'none';">
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
        <div id="relPersonalizadoSla" style="display:<?= $filtros['periodo']==='personalizado'?'flex':'none' ?>;gap:.5rem;">
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
            <label class="rel-label"><?= htmlspecialchars(t('relatorios.filtro.relatorio_por')) ?></label>
            <select name="relatorio_por" class="rel-select">
                <option value="registro"  <?= $filtros['relatorio_por']==='registro'?'selected':'' ?>><?= htmlspecialchars(t('relatorios.filtro.relatorio_por_registro')) ?></option>
                <option value="estudo"    <?= $filtros['relatorio_por']==='estudo'?'selected':'' ?>><?= htmlspecialchars(t('relatorios.filtro.relatorio_por_estudo')) ?></option>
                <option value="conclusao" <?= $filtros['relatorio_por']==='conclusao'?'selected':'' ?>><?= htmlspecialchars(t('relatorios.filtro.relatorio_por_conclusao')) ?></option>
            </select>
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

    <!-- Modos de visão do relatório SLA — não são mutuamente exclusivos -->
    <div class="rel-modo-sla">
        <div class="rel-modo-bloco">
            <label class="rel-label"><?= htmlspecialchars(t('relatorios.sla.modo_situacao')) ?></label>
            <div class="rel-chips">
                <?php foreach ($opcoes['situacoes'] as $s): ?>
                <label class="rel-chip">
                    <input type="checkbox" name="situacoes[]" value="<?= htmlspecialchars($s) ?>" <?= in_array($s, $filtros['situacoes'], true)?'checked':'' ?>>
                    <?= htmlspecialchars(t('relatorios.situacao.' . $s)) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="rel-modo-bloco">
            <label class="rel-label"><?= htmlspecialchars(t('relatorios.sla.modo_tempo')) ?></label>
            <div class="rel-tempo-maior">
                <input type="number" name="tempo_valor" class="rel-input" min="0" step="1" value="<?= htmlspecialchars((string)($filtros['tempo_valor'] ?? '')) ?>" placeholder="0">
                <select name="tempo_unidade" class="rel-select">
                    <option value="horas"   <?= $filtros['tempo_unidade']==='horas'?'selected':'' ?>><?= htmlspecialchars(t('relatorios.sla.horas')) ?></option>
                    <option value="minutos" <?= $filtros['tempo_unidade']==='minutos'?'selected':'' ?>><?= htmlspecialchars(t('relatorios.sla.minutos')) ?></option>
                </select>
            </div>
        </div>
    </div>

    <div class="rel-filtros-acoes">
        <button type="submit" class="rel-btn-primary"><i class="fa fa-filter me-1"></i> <?= htmlspecialchars(t('relatorios.filtro.filtrar')) ?></button>
        <a href="/relatorios/sla-medicos" class="rel-btn-outline"><?= htmlspecialchars(t('relatorios.filtro.limpar')) ?></a>
        <div class="rel-export-group">
            <a class="rel-btn-outline" href="/relatorios/sla-medicos/exportar?<?= http_build_query(array_merge($filtros, ['formato'=>'pdf'])) ?>"><i class="fa fa-file-pdf me-1"></i> PDF</a>
            <a class="rel-btn-outline" href="/relatorios/sla-medicos/exportar?<?= http_build_query(array_merge($filtros, ['formato'=>'xlsx'])) ?>"><i class="fa fa-file-excel me-1"></i> XLS</a>
        </div>
    </div>
</div>
</form>

<h2 class="rel-secao-titulo"><?= htmlspecialchars(t('relatorios.sla.secao_detalhe')) ?></h2>
<div class="rel-table-wrap">
<table class="rel-table">
    <thead>
        <tr>
            <th><?= htmlspecialchars(t('relatorios.coluna.data')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.paciente')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.unidade')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.modalidade')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.medico')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.tempo_decorrido')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.sla_alvo')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.status_sla')) ?></th>
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
            <td><?= htmlspecialchars($l['modalities'] ?? '') ?></td>
            <td><?= htmlspecialchars($l['assumido_por'] ?: t('relatorios.sla.nao_atribuido')) ?></td>
            <td><?= relFormatarMinutos($l['tempo_decorrido_min']) ?></td>
            <td><?= $l['sla_alvo_min'] !== null ? relFormatarMinutos($l['sla_alvo_min']) : '—' ?></td>
            <td><?= relStatusSlaBadge($l['status_sla']) ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
<div class="rel-pagination">
    <span><?= number_format($total) ?> <?= htmlspecialchars(t('relatorios.total_exames')) ?></span>
    <?php if ($totalPaginas > 1): ?>
    <div class="rel-pag-links">
        <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
            <a href="<?= relSlaUrl($filtros, $p) ?>" class="rel-pag-btn <?= $p===$filtros['pagina']?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
</div>

<h2 class="rel-secao-titulo"><?= htmlspecialchars(t('relatorios.sla.secao_resumo_medico')) ?></h2>
<div class="rel-table-wrap">
<table class="rel-table">
    <thead>
        <tr>
            <th><?= htmlspecialchars(t('relatorios.coluna.medico')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.total')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.sla.dentro_prazo')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.sla.atencao')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.sla.estourado')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.sla.sem_sla')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.tempo_medio_laudo')) ?></th>
            <th><?= htmlspecialchars(t('relatorios.coluna.percentual_cumprimento')) ?></th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($agregado)): ?>
        <tr><td colspan="8" class="rel-empty"><?= htmlspecialchars(t('relatorios.nenhum_resultado')) ?></td></tr>
    <?php else: foreach ($agregado as $g): ?>
        <tr>
            <td class="rel-agregado-nome"><?= htmlspecialchars($g['nome']) ?></td>
            <td><?= (int)$g['total'] ?></td>
            <td><?= (int)$g['verde'] ?></td>
            <td><?= (int)$g['amarelo'] ?></td>
            <td><?= (int)$g['vermelho'] ?></td>
            <td><?= (int)$g['sem_sla'] ?></td>
            <td><?= $g['tempo_medio_laudo_min'] !== null ? relFormatarMinutos($g['tempo_medio_laudo_min']) : '—' ?></td>
            <td><?= $g['percentual_cumprimento'] !== null ? $g['percentual_cumprimento'] . '%' : '—' ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
