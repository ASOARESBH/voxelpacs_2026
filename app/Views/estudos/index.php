<?php
/**
 * VOXEL PACS — Worklist de Estudos
 * Interface similar ao RAIOSS com dark theme
 */

// Helper para montar URL com filtros
function estudoUrl(array $filtros, int $pagina = 1): string {
    $p = array_merge($filtros, ['pagina' => $pagina]);
    return '/estudos?' . http_build_query(array_filter($p, fn($v) => $v !== ''));
}

// Helper para badge de situação
function situacaoBadge(string $sit): string {
    $map = [
        'novo'          => ['sit-novo',     'NOVO'],
        'aberto'        => ['sit-aberto',   'ABERTO'],
        'em_laudo'      => ['sit-em_laudo', 'EM LAUDO'],
        'rascunho'      => ['sit-rascunho', 'RASCUNHO'],
        'revisao'       => ['sit-revisao',  'REVISÃO'],
        'assinado'      => ['sit-assinado', 'ASSINADO'],
        'liberado'      => ['sit-liberado', 'LIBERADO'],
        'sem_imagens'   => ['sit-sem-imagens', 'SEM IMAGENS'],
        'sem_pendencia' => ['sit-sem-pend', 'SEM PENDÊNCIA'],
        'urgente'       => ['sit-urgente',  'URGENTE'],
        'p_review'      => ['sit-previa',   'P. REVIEW'],
    ];
    [$cls, $label] = $map[$sit] ?? ['sit-novo', strtoupper($sit)];
    return "<span class=\"situacao-badge {$cls}\">{$label}</span>";
}

// Helper para badge de modalidade
function modBadge(string $mod): string {
    $mod = strtolower(trim($mod));
    return "<span class=\"mod-badge {$mod}\">" . strtoupper($mod) . "</span>";
}

// Helper para formatar idade
function formatarIdade(array $e): string {
    $age = $e['patient_age'] ?? '';
    if ($age) {
        $age = preg_replace('/^0+(\d+)([YMD])$/', '$1$2', $age);
        $age = str_replace(['Y','M','D'], ['a','m','d'], $age);
        return $age;
    }
    if (!empty($e['patient_birth_date'])) {
        try {
            $diff = (new DateTime())->diff(new DateTime($e['patient_birth_date']));
            return $diff->y . 'a';
        } catch (\Throwable $t) {}
    }
    return '—';
}
?>

<!-- ═══════════════════════════════════════════════════════════
     TABS: Agendamentos | Estudos
═══════════════════════════════════════════════════════════ -->
<div class="pacs-tabs">
    <a href="/agendamentos" class="pacs-tab">
        <i class="fa fa-calendar-days"></i> Agendamentos
    </a>
    <a href="/estudos" class="pacs-tab active">
        <i class="fa fa-list-check"></i> Estudos
    </a>
</div>

<!-- ═══════════════════════════════════════════════════════════
     BARRA DE FILTROS — LINHA 1
═══════════════════════════════════════════════════════════ -->
<form method="GET" action="/estudos" id="formFiltros">
<div class="pacs-filters">

    <!-- Busca geral -->
    <input type="text" name="q" class="form-control" style="width:160px;"
           placeholder="Pesquisar por..." value="<?= htmlspecialchars($filtros['q']) ?>">

    <!-- Nome do paciente -->
    <input type="text" name="paciente" class="form-control" style="width:180px;"
           placeholder="Nome do paciente" value="<?= htmlspecialchars($filtros['paciente']) ?>">

    <!-- Data início -->
    <div class="d-flex align-items-center gap-1">
        <i class="fa fa-calendar text-pacs-muted" style="font-size:.75rem;"></i>
        <input type="date" name="dt_inicio" class="form-control" style="width:130px;"
               value="<?= htmlspecialchars($filtros['dt_inicio']) ?>" title="Data início">
    </div>

    <!-- Data fim -->
    <span style="color:var(--pacs-text-muted);font-size:.75rem;">até</span>
    <input type="date" name="dt_fim" class="form-control" style="width:130px;"
           value="<?= htmlspecialchars($filtros['dt_fim']) ?>" title="Data fim">

    <!-- Ordenar -->
    <select name="ordenar" class="form-select" style="width:160px;">
        <option value="study_date"      <?= $filtros['ordenar']==='study_date'?'selected':'' ?>>Ordenar: Dt Estudo</option>
        <option value="patient_name"    <?= $filtros['ordenar']==='patient_name'?'selected':'' ?>>Ordenar: Paciente</option>
        <option value="institution_name"<?= $filtros['ordenar']==='institution_name'?'selected':'' ?>>Ordenar: Unidade</option>
        <option value="situacao"        <?= $filtros['ordenar']==='situacao'?'selected':'' ?>>Ordenar: Situação</option>
    </select>

    <!-- Direção -->
    <select name="direcao" class="form-select" style="width:120px;">
        <option value="DESC" <?= $filtros['direcao']==='DESC'?'selected':'' ?>>Decrescente</option>
        <option value="ASC"  <?= $filtros['direcao']==='ASC'?'selected':'' ?>>Crescente</option>
    </select>

    <!-- Unidade -->
    <select name="unidade" class="form-select" style="width:160px;">
        <option value="">Todas as unidades</option>
        <?php foreach ($unidades as $u): ?>
            <option value="<?= htmlspecialchars($u) ?>" <?= $filtros['unidade']===$u?'selected':'' ?>>
                <?= htmlspecialchars($u) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <!-- Situação -->
    <select name="situacao" class="form-select" style="width:150px;">
        <option value="">Todas as situações</option>
        <?php foreach ($situacoes as $s): ?>
            <option value="<?= $s ?>" <?= $filtros['situacao']===$s?'selected':'' ?>>
                <?= strtoupper(str_replace('_',' ',$s)) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <!-- Botão buscar -->
    <button type="submit" class="btn-pacs-primary">
        <i class="fa fa-magnifying-glass"></i> Buscar
    </button>

    <?php if (array_filter(array_diff_key($filtros, ['ordenar'=>1,'direcao'=>1,'pagina'=>1,'por_pagina'=>1]))): ?>
    <a href="/estudos" class="btn-pacs-outline">
        <i class="fa fa-xmark"></i> Limpar
    </a>
    <?php endif; ?>

</div>

<!-- ═══════════════════════════════════════════════════════════
     BARRA DE FILTROS — LINHA 2 (Modalidades + Situação rápida)
═══════════════════════════════════════════════════════════ -->
<div class="pacs-filters-row2">

    <!-- Filtros rápidos de situação -->
    <select name="situacao_rapida" class="form-select" style="width:160px;font-size:.72rem;height:28px;padding:.1rem .5rem;"
            onchange="document.querySelector('[name=situacao]').value=this.value; document.getElementById('formFiltros').submit();">
        <option value="">A laudar (Todos)</option>
        <option value="novo"     <?= $filtros['situacao']==='novo'?'selected':'' ?>>Novo</option>
        <option value="aberto"   <?= $filtros['situacao']==='aberto'?'selected':'' ?>>Aberto</option>
        <option value="urgente"  <?= $filtros['situacao']==='urgente'?'selected':'' ?>>Urgente</option>
    </select>

    <span style="color:var(--pacs-border);margin:0 .25rem;">|</span>

    <!-- Botões de modalidade -->
    <?php
    $mods = ['CR','CT','CTG','DO','DR','DX','ECG','ES','MG','MR','OF','OT','US'];
    $modAtual = strtoupper($filtros['modalidade']);
    foreach ($mods as $m):
    ?>
        <button type="button"
                class="mod-btn <?= $modAtual === $m ? 'active' : '' ?>"
                onclick="setModalidade('<?= $m ?>')">
            <?= $m ?>
        </button>
    <?php endforeach; ?>

    <input type="hidden" name="modalidade" id="inputModalidade" value="<?= htmlspecialchars($filtros['modalidade']) ?>">

    <span style="color:var(--pacs-border);margin:0 .25rem;">|</span>

    <!-- Especialidade -->
    <input type="text" name="especialidade" class="form-control" style="width:180px;"
           placeholder="Especialidade" value="<?= htmlspecialchars($filtros['especialidade']) ?>">

    <span style="font-size:.72rem;color:var(--pacs-text-muted);margin-left:auto;">
        <?= number_format($total) ?> estudo<?= $total !== 1 ? 's' : '' ?> encontrado<?= $total !== 1 ? 's' : '' ?>
        &nbsp;<span style="color:var(--pacs-border);">|</span>&nbsp;
        <i class="fa fa-server" style="font-size:.65rem;"></i> Orthanc PACS
    </span>

</div>
</form>

<!-- ═══════════════════════════════════════════════════════════
     TABELA DE ESTUDOS
═══════════════════════════════════════════════════════════ -->
<div class="pacs-table-wrapper" style="max-height:calc(100vh - 280px);">
    <table class="pacs-table">
        <thead>
            <tr>
                <th style="width:20px;"><input type="checkbox" id="checkAll" onchange="toggleAll(this)" style="accent-color:var(--pacs-primary);"></th>
                <th style="width:110px;">
                    <a href="<?= estudoUrl(array_merge($filtros, ['ordenar'=>'study_date','direcao'=>$filtros['ordenar']==='study_date'&&$filtros['direcao']==='DESC'?'ASC':'DESC'])) ?>"
                       style="color:inherit;text-decoration:none;">
                        Dt Estudo
                        <?php if ($filtros['ordenar']==='study_date'): ?>
                            <i class="fa fa-sort-<?= $filtros['direcao']==='DESC'?'down':'up' ?> sort-icon"></i>
                        <?php else: ?>
                            <i class="fa fa-sort sort-icon"></i>
                        <?php endif; ?>
                    </a>
                </th>
                <th style="min-width:160px;">
                    <a href="<?= estudoUrl(array_merge($filtros, ['ordenar'=>'patient_name','direcao'=>$filtros['ordenar']==='patient_name'&&$filtros['direcao']==='DESC'?'ASC':'DESC'])) ?>"
                       style="color:inherit;text-decoration:none;">
                        Paciente
                        <?php if ($filtros['ordenar']==='patient_name'): ?>
                            <i class="fa fa-sort-<?= $filtros['direcao']==='DESC'?'down':'up' ?> sort-icon"></i>
                        <?php else: ?>
                            <i class="fa fa-sort sort-icon"></i>
                        <?php endif; ?>
                    </a>
                </th>
                <th style="min-width:130px;">
                    <a href="<?= estudoUrl(array_merge($filtros, ['ordenar'=>'institution_name','direcao'=>$filtros['ordenar']==='institution_name'&&$filtros['direcao']==='DESC'?'ASC':'DESC'])) ?>"
                       style="color:inherit;text-decoration:none;">
                        Unidade
                        <?php if ($filtros['ordenar']==='institution_name'): ?>
                            <i class="fa fa-sort-<?= $filtros['direcao']==='DESC'?'down':'up' ?> sort-icon"></i>
                        <?php else: ?>
                            <i class="fa fa-sort sort-icon"></i>
                        <?php endif; ?>
                    </a>
                </th>
                <th style="width:60px;">M</th>
                <th style="min-width:140px;">Especialidade</th>
                <th style="min-width:160px;">Estudo</th>
                <th style="min-width:120px;">
                    <a href="<?= estudoUrl(array_merge($filtros, ['ordenar'=>'situacao','direcao'=>$filtros['ordenar']==='situacao'&&$filtros['direcao']==='DESC'?'ASC':'DESC'])) ?>"
                       style="color:inherit;text-decoration:none;">
                        Situação
                        <?php if ($filtros['ordenar']==='situacao'): ?>
                            <i class="fa fa-sort-<?= $filtros['direcao']==='DESC'?'down':'up' ?> sort-icon"></i>
                        <?php else: ?>
                            <i class="fa fa-sort sort-icon"></i>
                        <?php endif; ?>
                    </a>
                </th>
                <th style="width:80px;text-align:center;">Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($estudos)): ?>
            <tr>
                <td colspan="9" style="text-align:center;padding:3rem;color:var(--pacs-text-muted);">
                    <i class="fa fa-x-ray fa-2x mb-2 d-block"></i>
                    Nenhum estudo encontrado com os filtros aplicados.<br>
                    <small style="font-size:.75rem;color:var(--pacs-text-muted);display:block;margin-top:.5rem;">
                        Os estudos são importados do servidor Orthanc PACS.<br>
                        Acesse <a href="/platform/servidor-pacs" style="color:var(--pacs-primary);">Plataforma &rsaquo; Servidor PACS</a>
                        e clique em <strong>Sincronizar</strong> para importar os exames.
                    </small>
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($estudos as $e): ?>
            <?php
                // Formata data/hora
                $dtEstudo = $e['study_date'] ?? '';
                $hrEstudo = $e['study_time'] ?? '';
                if ($dtEstudo) {
                    try {
                        $dt = new DateTime($dtEstudo);
                        $dtFmt = $dt->format('d/m/Y');
                    } catch (\Throwable $t) {
                        $dtFmt = $dtEstudo;
                    }
                } else {
                    $dtFmt = '—';
                }
                if ($hrEstudo && strlen($hrEstudo) >= 6) {
                    $hrFmt = substr($hrEstudo,0,2).':'.substr($hrEstudo,2,2).':'.substr($hrEstudo,4,2);
                } else {
                    $hrFmt = $hrEstudo ?: '—';
                }

                // Modalidades
                $mods = array_filter(array_map('trim', explode('\\', $e['modalities'] ?? '')));
                if (empty($mods) && !empty($e['modalities'])) {
                    $mods = [trim($e['modalities'])];
                }

                // Sexo + Idade
                $sexo  = $e['patient_sex'] ?? '?';
                $idade = formatarIdade($e);
                $sexoLabel = strtoupper($sexo) === 'F' ? 'F' : (strtoupper($sexo) === 'M' ? 'M' : '?');

                // Situação
                $sit = $e['situacao'] ?? 'novo';

                // Classe da linha
                $rowClass = $sit === 'urgente' ? 'priority-high' : 'priority-normal';
            ?>
            <tr class="<?= $rowClass ?>" data-id="<?= $e['id'] ?>">
                <td><input type="checkbox" class="row-check" style="accent-color:var(--pacs-primary);"></td>

                <!-- Dt Estudo -->
                <td class="dt-estudo">
                    <div class="date"><?= htmlspecialchars($dtFmt) ?></div>
                    <div class="time"><?= htmlspecialchars($hrFmt) ?></div>
                </td>

                <!-- Paciente -->
                <td class="paciente-cell">
                    <div class="nome"><?= htmlspecialchars($e['patient_name'] ?? 'ANON') ?></div>
                    <div class="info">
                        <?= htmlspecialchars($sexoLabel) ?> / <?= htmlspecialchars($idade) ?>
                        <?php if (!empty($e['accession_number'])): ?>
                            &bull; <span style="color:var(--pacs-text-muted);"><?= htmlspecialchars($e['accession_number']) ?></span>
                        <?php endif; ?>
                    </div>
                </td>

                <!-- Unidade -->
                <td>
                    <span style="font-size:.78rem;"><?= htmlspecialchars($e['institution_name'] ?? '—') ?></span>
                </td>

                <!-- Modalidade (M) -->
                <td>
                    <?php if (!empty($mods)): ?>
                        <?php foreach ($mods as $m): ?>
                            <?= modBadge($m) ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="text-pacs-muted">—</span>
                    <?php endif; ?>
                </td>

                <!-- Especialidade -->
                <td>
                    <?php if (!empty($e['especialidade'])): ?>
                        <span class="esp-tag"><?= htmlspecialchars($e['especialidade']) ?></span>
                    <?php elseif (!empty($e['referring_physician_name'])): ?>
                        <span class="esp-tag"><?= htmlspecialchars($e['referring_physician_name']) ?></span>
                    <?php else: ?>
                        <span class="text-pacs-muted">—</span>
                    <?php endif; ?>
                </td>

                <!-- Estudo (descrição) -->
                <td>
                    <div style="font-size:.78rem;font-weight:500;"><?= htmlspecialchars($e['study_description'] ?: '—') ?></div>
                    <?php if (!empty($e['num_instances'])): ?>
                        <div style="font-size:.68rem;color:var(--pacs-text-muted);">
                            <i class="fa fa-images"></i> <?= number_format($e['num_instances']) ?> imagens
                        </div>
                    <?php endif; ?>
                </td>

                <!-- Situação -->
                <td><?= situacaoBadge($sit) ?></td>

                <!-- Ações -->
                <td>
                    <?php
                    $donoId       = $e['usuario_responsavel_id'] ?? null;
                    $souDono      = $donoId && (int)$donoId === (int)(\App\Core\Auth::userId() ?? 0);
                    $reportsUrl   = '/reports/' . rawurlencode($e['study_instance_uid'] ?? '');
                    ?>
                    <div class="pacs-actions">
                        <a href="/estudos/<?= $e['id'] ?>/abrir"
                           class="pacs-btn btn-open"
                           title="Abrir no OHIF Viewer (nova aba)"
                           target="_blank"
                           <?= empty($e['study_instance_uid']) && empty($e['orthanc_id']) ? 'style="opacity:.45;pointer-events:none;" title="Sem StudyInstanceUID"' : '' ?>>
                            <i class="fa fa-eye"></i> Abrir
                        </a>

                        <?php if (in_array($sit, ['novo','aberto','urgente'], true)): ?>
                            <button type="button" class="pacs-btn btn-assumir" title="Assumir laudo"
                                    onclick="assumirEstudo(<?= (int)$e['id'] ?>, this)"
                                    <?= empty($e['study_instance_uid']) ? 'style="opacity:.45;pointer-events:none;" title="Sem StudyInstanceUID"' : '' ?>>
                                <i class="fa fa-hand"></i> Assumir
                            </button>
                        <?php elseif (in_array($sit, ['em_laudo','rascunho','revisao'], true)): ?>
                            <a href="#" class="pacs-btn btn-laudar" title="<?= $souDono ? 'Continuar laudo' : 'Ver laudo em edição' ?>"
                               onclick="window.open('<?= htmlspecialchars($reportsUrl) ?>','_blank');return false;">
                                <i class="fa fa-file-pen"></i> Laudar
                            </a>
                        <?php else: ?>
                            <a href="#" class="pacs-btn" title="Abrir laudo"
                               onclick="window.open('<?= htmlspecialchars($reportsUrl) ?>','_blank');return false;">
                                <i class="fa fa-file-medical"></i> Laudo
                            </a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ═══════════════════════════════════════════════════════════
     PAGINAÇÃO
═══════════════════════════════════════════════════════════ -->
<?php if ($totalPages > 1): ?>
<div class="pacs-pagination">
    <span>
        Mostrando <?= number_format(($currentPage-1)*$filtros['por_pagina']+1) ?>–<?= number_format(min($currentPage*$filtros['por_pagina'],$total)) ?>
        de <?= number_format($total) ?> estudos
    </span>
    <div class="page-links">
        <?php if ($currentPage > 1): ?>
            <a href="<?= estudoUrl($filtros, 1) ?>" class="page-btn" title="Primeira"><i class="fa fa-angles-left"></i></a>
            <a href="<?= estudoUrl($filtros, $currentPage-1) ?>" class="page-btn"><i class="fa fa-chevron-left"></i></a>
        <?php endif; ?>

        <?php
        $start = max(1, $currentPage - 2);
        $end   = min($totalPages, $currentPage + 2);
        for ($p = $start; $p <= $end; $p++):
        ?>
            <a href="<?= estudoUrl($filtros, $p) ?>" class="page-btn <?= $p === $currentPage ? 'active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>

        <?php if ($currentPage < $totalPages): ?>
            <a href="<?= estudoUrl($filtros, $currentPage+1) ?>" class="page-btn"><i class="fa fa-chevron-right"></i></a>
            <a href="<?= estudoUrl($filtros, $totalPages) ?>" class="page-btn" title="Última"><i class="fa fa-angles-right"></i></a>
        <?php endif; ?>
    </div>
    <span style="font-size:.72rem;">Página <?= $currentPage ?> de <?= $totalPages ?></span>
</div>
<?php endif; ?>

<script>
const CSRF_TOKEN = <?= json_encode($csrfToken ?? '') ?>;

// ── Assumir laudo (abre /reports em nova aba, sem navegar a worklist) ─
function assumirEstudo(id, btnEl) {
    btnEl.disabled = true;
    fetch('/estudos/' + id + '/assumir', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF_TOKEN }
    })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) {
                alert(data.error === 'em_laudo_outro_usuario'
                    ? 'Este exame já está em edição por ' + (data.usuario_responsavel_nome || 'outro médico') + ' desde ' + (data.hora_inicio_laudo || '—')
                    : 'Não foi possível assumir este exame.');
                btnEl.disabled = false;
                return;
            }

            const novoBtn = document.createElement('a');
            novoBtn.href = '#';
            novoBtn.className = 'pacs-btn btn-laudar';
            novoBtn.title = 'Continuar laudo';
            novoBtn.innerHTML = '<i class="fa fa-file-pen"></i> Laudar';
            novoBtn.onclick = function () { window.open(data.reports_url, '_blank'); return false; };
            btnEl.replaceWith(novoBtn);

            const row = document.querySelector('tr[data-id="' + id + '"] .situacao-badge');
            if (row) row.outerHTML = '<span class="situacao-badge sit-em_laudo">EM LAUDO</span>';

            window.open(data.reports_url, '_blank');
        })
        .catch(() => { btnEl.disabled = false; alert('Falha ao assumir o exame. Tente novamente.'); });
}

// ── Seleção de modalidade ────────────────────────────────────
function setModalidade(mod) {
    const input = document.getElementById('inputModalidade');
    const btns  = document.querySelectorAll('.mod-btn');
    if (input.value === mod) {
        input.value = '';
        btns.forEach(b => b.classList.remove('active'));
    } else {
        input.value = mod;
        btns.forEach(b => {
            b.classList.toggle('active', b.textContent.trim() === mod);
        });
    }
    document.getElementById('formFiltros').submit();
}

// ── Selecionar todos ─────────────────────────────────────────
function toggleAll(master) {
    document.querySelectorAll('.row-check').forEach(c => c.checked = master.checked);
}

// ── Clique na linha → abre viewer ───────────────────────────
document.querySelectorAll('.pacs-table tbody tr[data-id]').forEach(row => {
    row.addEventListener('dblclick', function() {
        const id = this.dataset.id;
        window.open('/estudos/' + id + '/abrir', '_blank');
    });
});
</script>
