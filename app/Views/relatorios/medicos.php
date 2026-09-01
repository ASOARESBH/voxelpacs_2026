<?php
/** @var array<string,mixed> $filtros */
/** @var array<string,array<int,mixed>> $opcoes */
/** @var array<int,array<string,mixed>> $linhas */
/** @var array<string,int|null> $totalizadores */
/** @var array<int,array<string,mixed>> $porMedico */

function relMedicosMinutos(?int $minutos): string {
    if ($minutos === null) return '—';
    $horas = intdiv($minutos, 60);
    $resto = $minutos % 60;
    return $horas > 0 ? "{$horas}h {$resto}min" : "{$resto}min";
}
function relMedicosData(?string $data): string {
    if (!$data) return '—';
    $ts = strtotime($data);
    return $ts ? date('d/m/Y H:i', $ts) : '—';
}
function relMedicosUrl(array $filtros, ?int $pagina = null): string {
    $query = $filtros;
    unset($query['tenant_id'], $query['medico_restrito_id'], $query['por_pagina'], $query['periodo']);
    if ($pagina !== null) $query['pagina'] = $pagina;
    return '/relatorios/medicos?' . http_build_query($query);
}
function relMedicosExportUrl(array $filtros, string $formato): string {
    $query = $filtros;
    unset($query['tenant_id'], $query['medico_restrito_id'], $query['por_pagina'], $query['periodo'], $query['pagina']);
    $query['formato'] = $formato;
    return '/relatorios/medicos/exportar?' . http_build_query($query);
}
?>
<style>
.rel-med-page{padding:1.25rem 1.5rem;}
.rel-med-header{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin-bottom:1rem;}
.rel-med-header h1{font-size:1.25rem;margin:0;color:var(--pacs-text,#e2e8f0);}
.rel-med-header p{margin:.35rem 0 0;color:var(--pacs-text-muted,#8892a4);font-size:.84rem;}
.rel-med-filters,.rel-med-table-wrap,.rel-med-card{background:var(--pacs-card-bg,#1e2330);border:1px solid var(--pacs-border,#2d3244);border-radius:10px;}
.rel-med-filters{padding:1rem 1.2rem;margin-bottom:1rem;}
.rel-med-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:.8rem;}
.rel-med-label{display:block;margin-bottom:.32rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--pacs-text-muted,#8892a4);}
.rel-med-input,.rel-med-select{width:100%;height:36px;border:1px solid var(--pacs-border,#3a3f4b);border-radius:6px;padding:0 .6rem;background:var(--pacs-input-bg,#252b3b);color:var(--pacs-text,#e2e8f0);font-size:.84rem;}
.rel-med-modalities{display:flex;align-items:center;gap:.6rem;margin-top:.6rem;}
.rel-med-modalities .rel-med-label{margin:0;min-width:72px;}
.rel-med-chips{display:flex;gap:.22rem;flex-wrap:nowrap;overflow-x:auto;margin:0;padding-bottom:1px;scrollbar-width:thin;}
.rel-med-chip{display:inline-flex;flex:0 0 auto;align-items:center;gap:.18rem;padding:.14rem .38rem;border-radius:5px;border:1px solid rgba(59,130,246,.30);background:rgba(59,130,246,.07);font-size:.68rem;line-height:1.1;color:var(--pacs-text,#e2e8f0);cursor:pointer;}
.rel-med-chip input{width:12px;height:12px;margin:0;accent-color:var(--pacs-primary,#1a56db);}
.rel-med-actions{display:flex;align-items:center;gap:.55rem;margin-top:1rem;}
.rel-med-export{display:flex;gap:.45rem;margin-left:auto;}
.rel-med-button{border:0;border-radius:6px;padding:.5rem .9rem;background:var(--pacs-primary,#1a56db);color:#fff;font-size:.82rem;font-weight:700;cursor:pointer;}
.rel-med-clear{padding:.5rem .8rem;border:1px solid var(--pacs-border,#3a3f4b);border-radius:6px;color:var(--pacs-text-muted,#8892a4);font-size:.82rem;text-decoration:none;}
.rel-med-cards{display:grid;grid-template-columns:repeat(6,minmax(145px,1fr));gap:.75rem;margin-bottom:1.1rem;}
.rel-med-card{padding:.8rem .9rem;min-width:0;}
.rel-med-card-label{display:block;color:var(--pacs-text-muted,#8892a4);font-size:.7rem;text-transform:uppercase;font-weight:700;letter-spacing:.04em;}
.rel-med-card-value{display:block;margin-top:.35rem;color:var(--pacs-text,#e2e8f0);font-size:1.35rem;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.rel-med-card-value.primary{color:#60a5fa}.rel-med-card-value.success{color:#34d399}.rel-med-card-value.purple{color:#c084fc}.rel-med-card-value.warning{color:#fbbf24}
.rel-med-section{margin:1.1rem 0 .65rem;color:var(--pacs-text,#e2e8f0);font-size:.94rem;font-weight:800;}
.rel-med-table-wrap{overflow:auto;margin-bottom:1rem;}
.rel-med-table{width:100%;border-collapse:collapse;font-size:.79rem;min-width:940px;}
.rel-med-table th{padding:.65rem .7rem;text-align:left;color:var(--pacs-text-muted,#8892a4);font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;background:var(--pacs-input-bg,#252b3b);border-bottom:1px solid var(--pacs-border,#2d3244);white-space:nowrap;}
.rel-med-table td{padding:.62rem .7rem;color:var(--pacs-text,#e2e8f0);border-bottom:1px solid var(--pacs-border,#2d3244);vertical-align:top;}
.rel-med-table tbody tr:hover{background:rgba(59,130,246,.06)}
.rel-med-patient{font-weight:700}.rel-med-muted{color:var(--pacs-text-muted,#8892a4)}.rel-med-empty{text-align:center;padding:2.5rem!important;color:var(--pacs-text-muted,#8892a4)!important}
.rel-med-badge{display:inline-flex;align-items:center;gap:.25rem;border-radius:99px;padding:.2rem .45rem;font-size:.68rem;font-weight:700;white-space:nowrap}.rel-med-badge.peer{color:#c084fc;background:rgba(192,132,252,.12);border:1px solid rgba(192,132,252,.28)}.rel-med-badge.none{color:#94a3b8;background:rgba(148,163,184,.1);border:1px solid rgba(148,163,184,.2)}
.rel-med-pagination{display:flex;align-items:center;justify-content:space-between;padding:.75rem .9rem;color:var(--pacs-text-muted,#8892a4);font-size:.78rem}.rel-med-pages{display:flex;gap:.3rem}.rel-med-page{padding:.25rem .55rem;border-radius:5px;border:1px solid var(--pacs-border,#3a3f4b);color:var(--pacs-text-muted,#8892a4);text-decoration:none}.rel-med-page.active{background:var(--pacs-primary,#1a56db);color:#fff;border-color:var(--pacs-primary,#1a56db)}
@media(max-width:1180px){.rel-med-cards{grid-template-columns:repeat(3,1fr)}}@media(max-width:640px){.rel-med-page{padding:1rem}.rel-med-header{display:block}.rel-med-cards{grid-template-columns:repeat(2,1fr)}.rel-med-modalities{align-items:flex-start;flex-direction:column;gap:.3rem}.rel-med-modalities .rel-med-label{min-width:0}.rel-med-chips{flex-wrap:wrap;overflow:visible}}
</style>

<div class="rel-med-page">
    <div class="rel-med-header">
        <div>
            <h1><i class="fa fa-user-doctor me-2 text-pacs-primary"></i>Relatório de Médicos</h1>
            <p>Produtividade clínica, assinaturas, liberações, Peer Review e tempos de SLA por médico.</p>
        </div>
    </div>

    <form method="GET" action="/relatorios/medicos" class="rel-med-filters">
        <div class="rel-med-grid">
            <div>
                <label class="rel-med-label" for="data_de">Data inicial</label>
                <input id="data_de" name="data_de" type="date" class="rel-med-input" value="<?= htmlspecialchars($filtros['data_de']) ?>">
            </div>
            <div>
                <label class="rel-med-label" for="data_ate">Data final</label>
                <input id="data_ate" name="data_ate" type="date" class="rel-med-input" value="<?= htmlspecialchars($filtros['data_ate']) ?>">
            </div>
            <div>
                <label class="rel-med-label" for="base_periodo">Apurar por</label>
                <select id="base_periodo" name="base_periodo" class="rel-med-select">
                    <option value="assinatura" <?= $filtros['base_periodo']==='assinatura'?'selected':'' ?>>Data da assinatura</option>
                    <option value="liberacao" <?= $filtros['base_periodo']==='liberacao'?'selected':'' ?>>Data da liberação</option>
                    <option value="estudo" <?= $filtros['base_periodo']==='estudo'?'selected':'' ?>>Data do estudo</option>
                </select>
            </div>
            <div>
                <label class="rel-med-label" for="prioridade">Prioridade</label>
                <select id="prioridade" name="prioridade" class="rel-med-select">
                    <option value="">Todas as prioridades</option>
                    <?php foreach ($opcoes['prioridades'] as $codigoPrioridade => $rotuloPrioridade): ?>
                        <option value="<?= htmlspecialchars($codigoPrioridade) ?>" <?= $filtros['prioridade'] === $codigoPrioridade ? 'selected' : '' ?>><?= htmlspecialchars($rotuloPrioridade) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="rel-med-label" for="unidade">Unidade</label>
                <select id="unidade" name="unidade" class="rel-med-select">
                    <option value="">Todas as unidades</option>
                    <?php foreach ($opcoes['unidades'] as $unidade): ?>
                        <option value="<?= htmlspecialchars($unidade) ?>" <?= $filtros['unidade']===$unidade?'selected':'' ?>><?= htmlspecialchars($unidade) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="rel-med-label" for="estudo">Estudo / Accession / UID</label>
                <input id="estudo" name="estudo" type="search" class="rel-med-input" placeholder="Descrição ou identificador" value="<?= htmlspecialchars($filtros['estudo']) ?>">
            </div>
            <?php if (!$medicoRestrito): ?>
            <div>
                <label class="rel-med-label" for="medico_id">Médico</label>
                <select id="medico_id" name="medico_id" class="rel-med-select">
                    <option value="">Todos os médicos</option>
                    <?php foreach ($opcoes['medicos'] as $medico): ?>
                        <option value="<?= (int)$medico['id'] ?>" <?= (int)$filtros['medico_id']===(int)$medico['id']?'selected':'' ?>><?= htmlspecialchars($medico['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>
        <div class="rel-med-modalities">
            <span class="rel-med-label">Modalidade</span>
            <div class="rel-med-chips">
                <?php foreach ($opcoes['modalidades'] as $modalidade): ?>
                    <label class="rel-med-chip"><input type="checkbox" name="modalidades[]" value="<?= htmlspecialchars($modalidade) ?>" <?= in_array($modalidade, $filtros['modalidades'], true)?'checked':'' ?>> <?= htmlspecialchars($modalidade) ?></label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="rel-med-actions">
            <button class="rel-med-button" type="submit"><i class="fa fa-filter me-1"></i> Gerar relatório</button>
            <a class="rel-med-clear" href="/relatorios/medicos"><i class="fa fa-xmark me-1"></i> Limpar</a>
            <div class="rel-med-export">
                <a class="rel-med-clear" href="<?= htmlspecialchars(relMedicosExportUrl($filtros, 'csv')) ?>"><i class="fa fa-file-csv me-1"></i> CSV</a>
                <a class="rel-med-clear" href="<?= htmlspecialchars(relMedicosExportUrl($filtros, 'pdf')) ?>"><i class="fa fa-file-pdf me-1"></i> PDF</a>
            </div>
        </div>
    </form>

    <div class="rel-med-cards">
        <div class="rel-med-card"><span class="rel-med-card-label">Exames laudados</span><span class="rel-med-card-value primary"><?= (int)$totalizadores['laudos'] ?></span></div>
        <div class="rel-med-card"><span class="rel-med-card-label">Assinados</span><span class="rel-med-card-value warning"><?= (int)$totalizadores['assinados'] ?></span></div>
        <div class="rel-med-card"><span class="rel-med-card-label">Liberados</span><span class="rel-med-card-value success"><?= (int)$totalizadores['liberados'] ?></span></div>
        <div class="rel-med-card"><span class="rel-med-card-label">Com Peer Review</span><span class="rel-med-card-value purple"><?= (int)$totalizadores['peer_reviews'] ?></span></div>
        <div class="rel-med-card"><span class="rel-med-card-label">SLA médio médico</span><span class="rel-med-card-value"><?= relMedicosMinutos($totalizadores['sla_medio_min']) ?></span></div>
        <div class="rel-med-card"><span class="rel-med-card-label">SLA total acumulado</span><span class="rel-med-card-value"><?= relMedicosMinutos($totalizadores['sla_total_min']) ?></span></div>
    </div>

    <h2 class="rel-med-section">Produtividade por médico</h2>
    <div class="rel-med-table-wrap">
        <table class="rel-med-table">
            <thead><tr><th>Médico</th><th>Exames Laudados</th><th>Assinados</th><th>Liberados</th><th>Peer Review</th><th>SLA Médio</th><th>SLA Total</th></tr></thead>
            <tbody>
            <?php if (!$porMedico): ?><tr><td colspan="7" class="rel-med-empty">Nenhum laudo assinado ou liberado foi encontrado no período selecionado.</td></tr>
            <?php else: foreach ($porMedico as $medico): ?><tr>
                <td class="rel-med-patient"><?= htmlspecialchars($medico['medico']) ?></td><td><?= (int)$medico['laudos'] ?></td><td><?= (int)$medico['assinados'] ?></td><td><?= (int)$medico['liberados'] ?></td><td><?= (int)$medico['peer_reviews'] ?></td><td><?= relMedicosMinutos($medico['sla_medio_min']) ?></td><td><?= relMedicosMinutos($medico['sla_total_min']) ?></td>
            </tr><?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <h2 class="rel-med-section">Detalhamento dos exames laudados</h2>
    <div class="rel-med-table-wrap">
        <table class="rel-med-table">
            <thead><tr><th>Data estudo</th><th>Paciente</th><th>Estudo</th><th>Unidade</th><th>Modalidade</th><th>Prioridade</th><th>Médico</th><th>Assumido</th><th>Assinado</th><th>Liberado</th><th>Tempo médico</th><th>Tempo até conclusão</th><th>Peer Review</th></tr></thead>
            <tbody>
            <?php if (!$linhas): ?><tr><td colspan="13" class="rel-med-empty"><i class="fa fa-magnifying-glass me-1"></i>Nenhum resultado para os filtros selecionados.</td></tr>
            <?php else: foreach ($linhas as $linha): ?><tr>
                <td><?= htmlspecialchars(($linha['study_date'] ?? '') . (!empty($linha['study_time']) ? ' ' . substr((string)$linha['study_time'], 0, 5) : '')) ?></td>
                <td><span class="rel-med-patient"><?= htmlspecialchars($linha['paciente']) ?></span><br><small class="rel-med-muted">ID <?= htmlspecialchars($linha['patient_id']) ?></small></td>
                <td><?= htmlspecialchars($linha['descricao_estudo']) ?></td><td><?= htmlspecialchars($linha['unidade'] ?? '—') ?></td><td><?= htmlspecialchars($linha['modalities'] ?? '—') ?></td><td><?= htmlspecialchars(\App\Repositories\RelatorioProdutividadeMedicosRepository::prioridadeLabel((string) ($linha['prioridade'] ?? 'ROUTINE'))) ?><?php if (!empty($linha['prioridade_manual'])): ?><br><small class="rel-med-muted"><i class="fa fa-shield-halved"></i> <?= htmlspecialchars(t('relatorios.prioridade.manual')) ?> · <?= htmlspecialchars(t('relatorios.prioridade.dicom_original')) ?>: <?= htmlspecialchars(\App\Repositories\RelatorioProdutividadeMedicosRepository::prioridadeLabel((string) ($linha['prioridade_origem'] ?? 'ROUTINE'))) ?></small><?php endif; ?></td><td><?= htmlspecialchars($linha['medico_nome']) ?></td>
                <td><?= relMedicosData($linha['assumido_em']) ?></td><td><?= relMedicosData($linha['assinado_em']) ?></td><td><?= relMedicosData($linha['liberado_em']) ?></td><td><?= relMedicosMinutos($linha['tempo_assinatura_min']) ?></td><td><?= relMedicosMinutos($linha['tempo_conclusao_min']) ?></td>
                <td><?php if ((int)$linha['peer_reviews'] > 0): ?><span class="rel-med-badge peer"><i class="fa fa-user-doctor"></i><?= (int)$linha['peer_reviews'] ?> revisão(ões)</span><?php else: ?><span class="rel-med-badge none">Sem revisão</span><?php endif; ?></td>
            </tr><?php endforeach; endif; ?>
            </tbody>
        </table>
        <div class="rel-med-pagination"><span><?= number_format($total) ?> exame(s) laudado(s)</span>
            <?php if ($totalPaginas > 1): ?><div class="rel-med-pages"><?php for ($pagina = 1; $pagina <= min($totalPaginas, 12); $pagina++): ?><a class="rel-med-page <?= $pagina===$filtros['pagina']?'active':'' ?>" href="<?= htmlspecialchars(relMedicosUrl($filtros, $pagina)) ?>"><?= $pagina ?></a><?php endfor; ?></div><?php endif; ?>
        </div>
    </div>
</div>
