<?php
/**
 * VOXEL PACS — Worklist de Estudos (v4)
 * Painel de resumo, filtros avançados, período inteligente, tabela otimizada.
 * Revisão completa: todos os filtros, prioridade, especialidade, paginação corrigida.
 *
 * Variáveis disponíveis:
 *   $estudos        array  — lista de exames da página atual
 *   $filtros        array  — filtros ativos
 *   $total          int    — total de registros filtrados
 *   $totalPages     int    — total de páginas
 *   $currentPage    int    — página atual
 *   $unidades       array  — lista de institution_name para select
 *   $medicos        array  — lista de assumido_por para select
 *   $especialidades array  — lista de especialidades para select
 *   $contadores     array  — contadores por situação
 *   $resumo         array  — painel de resumo (hoje/semana/mês/total)
 *   $ultimaSinc     string — timestamp da última sincronização
 *   $tempoConsulta  float  — tempo da query em ms
 *   $isAdmin        bool   — usuário é platform admin
 */

function estudoUrl(array $filtros, int $pagina = 1): string {
    $p = array_merge($filtros, ['pagina' => $pagina]);
    unset($p['situacao_rapida']);
    return '/estudos?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
}

function situacaoBadge(string $sit): string {
    $map = [
        'novo'     => ['sit-novo',     'NOVO'],
        'aberto'   => ['sit-aberto',   'ABERTO'],
        'em_laudo' => ['sit-em-laudo', 'EM LAUDO'],
        'rascunho' => ['sit-rascunho', 'RASCUNHO'],
        'assinado' => ['sit-assinado', 'ASSINADO'],
        'liberado' => ['sit-liberado', 'LIBERADO'],
        'urgente'  => ['sit-urgente',  'URGENTE'],
    ];
    [$cls, $label] = $map[$sit] ?? ['sit-novo', strtoupper(str_replace('_',' ',$sit))];
    return "<span class=\"situacao-badge {$cls}\">{$label}</span>";
}

function prioridadeBadge(string $p): string {
    if ($p === 'urgente') return '<span class="prio-badge prio-urgente" title="Urgente"><i class="fa fa-triangle-exclamation"></i></span>';
    if ($p === 'critico') return '<span class="prio-badge prio-critico" title="Crítico"><i class="fa fa-circle-exclamation"></i></span>';
    return '';
}

function modBadge(string $mod): string {
    $mod = strtolower(trim($mod));
    return "<span class=\"mod-badge {$mod}\">" . strtoupper($mod) . "</span>";
}

function formatarIdade(array $e): string {
    $age = $e['patient_age'] ?? '';
    if ($age) {
        $age = preg_replace('/^0+(\d+)([YMD])$/', '$1$2', $age);
        return str_replace(['Y','M','D'], ['a','m','d'], $age);
    }
    if (!empty($e['patient_birth_date'])) {
        try {
            $diff = (new DateTime())->diff(new DateTime($e['patient_birth_date']));
            return $diff->y . 'a';
        } catch (\Throwable $t) {}
    }
    return '';
}

function sortLink(array $filtros, string $col, string $label): string {
    $ativo = $filtros['ordenar'] === $col;
    $dir   = $ativo && $filtros['direcao'] === 'DESC' ? 'ASC' : 'DESC';
    $icon  = $ativo ? ($filtros['direcao'] === 'DESC' ? 'fa-sort-down' : 'fa-sort-up') : 'fa-sort';
    $url   = estudoUrl(array_merge($filtros, ['ordenar' => $col, 'direcao' => $dir]));
    return "<a href=\"{$url}\" style=\"color:inherit;text-decoration:none;white-space:nowrap;\">{$label} <i class=\"fa {$icon} sort-icon\"></i></a>";
}

$temFiltroAtivo = array_filter(array_diff_key($filtros, [
    'ordenar'=>1,'direcao'=>1,'pagina'=>1,'por_pagina'=>1,'periodo'=>1
]), fn($v) => $v !== '');

// Garantir que $especialidades está disponível (compatibilidade)
if (!isset($especialidades)) $especialidades = [];

$periodoLabel = [
    'hoje'=>'Hoje','ontem'=>'Ontem','7dias'=>'7 dias','30dias'=>'30 dias',
    '90dias'=>'90 dias','ano'=>'Este ano','todos'=>'Todos','personalizado'=>'Personalizado'
][$filtros['periodo']] ?? 'Hoje';
?>

<!-- ═══════════════════════════════════════════════════════════
     TABS
═══════════════════════════════════════════════════════════ -->
<div class="pacs-tabs">
    <a href="/agendamentos" class="pacs-tab"><i class="fa fa-calendar-days"></i> Agendamentos</a>
    <a href="/estudos" class="pacs-tab active"><i class="fa fa-list-check"></i> Estudos</a>
</div>

<!-- ═══════════════════════════════════════════════════════════
     PAINEL DE RESUMO (cards)
═══════════════════════════════════════════════════════════ -->
<div class="estudos-resumo-cards">
    <div class="resumo-card" onclick="setPeriodo('hoje')" title="Filtrar por hoje">
        <div class="resumo-card-valor"><?= number_format($resumo['hoje']) ?></div>
        <div class="resumo-card-label"><i class="fa fa-calendar-day"></i> Hoje</div>
    </div>
    <div class="resumo-card" onclick="setPeriodo('7dias')" title="Últimos 7 dias">
        <div class="resumo-card-valor"><?= number_format($resumo['semana']) ?></div>
        <div class="resumo-card-label"><i class="fa fa-calendar-week"></i> 7 dias</div>
    </div>
    <div class="resumo-card" onclick="setPeriodo('30dias')" title="Últimos 30 dias">
        <div class="resumo-card-valor"><?= number_format($resumo['mes']) ?></div>
        <div class="resumo-card-label"><i class="fa fa-calendar"></i> 30 dias</div>
    </div>
    <?php if ($resumo['urgentes'] > 0): ?>
    <div class="resumo-card resumo-card-urgente" onclick="setFiltroRapido('prioridade','urgente')" title="Ver urgentes">
        <div class="resumo-card-valor"><?= number_format($resumo['urgentes']) ?></div>
        <div class="resumo-card-label"><i class="fa fa-triangle-exclamation"></i> Urgentes</div>
    </div>
    <?php endif; ?>
    <div class="resumo-card resumo-card-total" onclick="setPeriodo('todos')" title="Ver todos" style="margin-left:auto;">
        <div class="resumo-card-valor"><?= number_format($resumo['total']) ?></div>
        <div class="resumo-card-label"><i class="fa fa-database"></i> Total PACS</div>
    </div>
    <?php if ($ultimaSinc): ?>
    <div class="resumo-sinc-info">
        <i class="fa fa-rotate"></i>
        Sinc. <?= htmlspecialchars(date('d/m H:i', strtotime($ultimaSinc))) ?>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════
     FORMULÁRIO DE FILTROS
═══════════════════════════════════════════════════════════ -->
<form id="formFiltros" method="GET" action="/estudos" autocomplete="off">

<!-- Linha 1 -->
<div class="pacs-filters">
    <input type="text" name="q" class="form-control" style="width:190px;"
           placeholder="Pesquisar por..." value="<?= htmlspecialchars($filtros['q']) ?>">
    <input type="text" name="paciente" class="form-control" style="width:175px;"
           placeholder="Nome do paciente" value="<?= htmlspecialchars($filtros['paciente']) ?>">

    <!-- Período -->
    <select name="periodo" id="selectPeriodo" class="form-select" style="width:130px;"
            onchange="toggleDatasPersonalizadas(this.value)">
        <?php foreach (['hoje'=>'Hoje','ontem'=>'Ontem','7dias'=>'7 dias','30dias'=>'30 dias','90dias'=>'90 dias','ano'=>'Este ano','todos'=>'Todos','personalizado'=>'Personalizado'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= $filtros['periodo']===$v?'selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
    </select>

    <span id="datasPersonalizadas" style="display:<?= $filtros['periodo']==='personalizado'?'flex':'none' ?>;gap:.25rem;align-items:center;">
        <input type="date" name="dt_inicio" class="form-control" style="width:135px;" value="<?= htmlspecialchars($filtros['dt_inicio']) ?>">
        <span style="color:var(--pacs-text-muted);font-size:.8rem;">até</span>
        <input type="date" name="dt_fim" class="form-control" style="width:135px;" value="<?= htmlspecialchars($filtros['dt_fim']) ?>">
    </span>

    <select name="ordenar" class="form-select" style="width:155px;">
        <option value="study_date"      <?= $filtros['ordenar']==='study_date'?'selected':'' ?>>Ordenar: Dt Estudo</option>
        <option value="patient_name"    <?= $filtros['ordenar']==='patient_name'?'selected':'' ?>>Ordenar: Paciente</option>
        <option value="institution_name"<?= $filtros['ordenar']==='institution_name'?'selected':'' ?>>Ordenar: Unidade</option>
        <option value="modalities"      <?= $filtros['ordenar']==='modalities'?'selected':'' ?>>Ordenar: Modalidade</option>
        <option value="situacao"        <?= $filtros['ordenar']==='situacao'?'selected':'' ?>>Ordenar: Situação</option>
        <option value="prioridade"      <?= $filtros['ordenar']==='prioridade'?'selected':'' ?>>Ordenar: Prioridade</option>
    </select>
    <select name="direcao" class="form-select" style="width:115px;">
        <option value="DESC" <?= $filtros['direcao']==='DESC'?'selected':'' ?>>Decrescente</option>
        <option value="ASC"  <?= $filtros['direcao']==='ASC'?'selected':'' ?>>Crescente</option>
    </select>

    <select name="unidade" class="form-select" style="width:155px;">
        <option value="">Todas as unidades</option>
        <?php foreach ($unidades as $u): ?>
            <option value="<?= htmlspecialchars($u) ?>" <?= $filtros['unidade']===$u?'selected':'' ?>><?= htmlspecialchars($u) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="situacao" id="selectSituacao" class="form-select" style="width:150px;">
        <option value="">Todas as situações</option>
        <option value="novo"     <?= $filtros['situacao']==='novo'?'selected':'' ?>>NOVO</option>
        <option value="aberto"   <?= $filtros['situacao']==='aberto'?'selected':'' ?>>ABERTO</option>
        <option value="em_laudo" <?= $filtros['situacao']==='em_laudo'?'selected':'' ?>>EM LAUDO</option>
        <option value="rascunho" <?= $filtros['situacao']==='rascunho'?'selected':'' ?>>RASCUNHO</option>
        <option value="assinado" <?= $filtros['situacao']==='assinado'?'selected':'' ?>>ASSINADO</option>
        <option value="liberado" <?= $filtros['situacao']==='liberado'?'selected':'' ?>>LIBERADO</option>
    </select>

    <button type="submit" class="btn-pacs-primary"><i class="fa fa-magnifying-glass"></i> Buscar</button>
    <a href="/estudos?periodo=hoje" class="btn-pacs-outline" title="Limpar filtros e voltar para Hoje"><i class="fa fa-broom"></i> Limpar</a>
</div>

<!-- Linha 1b: Prioridade -->
<div class="pacs-filters" style="margin-top:.25rem;">
    <select name="prioridade" class="form-select" style="width:160px;" onchange="document.getElementById('formFiltros').submit()">
        <option value=""       <?= ($filtros['prioridade']??'')===''			?'selected':'' ?>>Todas as prioridades</option>
        <option value="normal" <?= ($filtros['prioridade']??'')==='normal'	?'selected':'' ?>>Normal</option>
        <option value="rotina" <?= ($filtros['prioridade']??'')==='rotina'	?'selected':'' ?>>Rotina</option>
        <option value="urgente"<?= ($filtros['prioridade']??'')==='urgente'	?'selected':'' ?>>Urgente</option>
        <option value="critico"<?= ($filtros['prioridade']??'')==='critico'	?'selected':'' ?>>Crítico</option>
    </select>
    <?php if (!empty($especialidades)): ?>
    <select name="especialidade" class="form-select" style="width:175px;" onchange="document.getElementById('formFiltros').submit()">
        <option value="">Todas as especialidades</option>
        <?php foreach ($especialidades as $esp): ?>
        <option value="<?= htmlspecialchars($esp) ?>" <?= ($filtros['especialidade']??'')===$esp?'selected':'' ?>><?= htmlspecialchars($esp) ?></option>
        <?php endforeach; ?>
    </select>
    <?php else: ?>
    <input type="text" name="especialidade" class="form-control" style="width:155px;"
           placeholder="Especialidade" value="<?= htmlspecialchars($filtros['especialidade']??'') ?>">
    <?php endif; ?>
</div>

<!-- Linha 2: modalidades + médico + por página -->
<div class="pacs-filters-row2">
    <select name="situacao_rapida" class="form-select" style="width:150px;font-size:.72rem;height:28px;padding:.1rem .5rem;"
            onchange="document.getElementById('selectSituacao').value=this.value; document.getElementById('formFiltros').submit();">
        <option value="">A laudar (Todos)</option>
        <option value="novo"     <?= $filtros['situacao']==='novo'?'selected':'' ?>>Novo</option>
        <option value="aberto"   <?= $filtros['situacao']==='aberto'?'selected':'' ?>>Aberto</option>
        <option value="em_laudo" <?= $filtros['situacao']==='em_laudo'?'selected':'' ?>>Em laudo</option>
        <option value="urgente"  <?= $filtros['situacao']==='urgente'?'selected':'' ?>>Urgente</option>
    </select>
    <span style="color:var(--pacs-border);margin:0 .25rem;">|</span>

    <?php
    $mods    = ['CR','CT','CTG','DO','DR','DX','ECG','ES','MG','MR','NM','OF','OT','PT','RF','US','XA'];
    $modAtual = strtoupper($filtros['modalidade']);
    foreach ($mods as $m):
    ?>
        <button type="button" class="mod-btn <?= $modAtual===$m?'active':'' ?>" onclick="setModalidade('<?= $m ?>')"><?= $m ?></button>
    <?php endforeach; ?>
    <input type="hidden" name="modalidade" id="inputModalidade" value="<?= htmlspecialchars($filtros['modalidade']) ?>">

    <span style="color:var(--pacs-border);margin:0 .25rem;">|</span>

    <!-- especialidade movida para linha 1b -->

    <?php if (!empty($medicos)): ?>
    <select name="medico" class="form-select" style="width:155px;font-size:.72rem;height:28px;padding:.1rem .5rem;">
        <option value="">Médico responsável</option>
        <?php foreach ($medicos as $med): ?>
            <option value="<?= htmlspecialchars($med) ?>" <?= $filtros['medico']===$med?'selected':'' ?>><?= htmlspecialchars($med) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <select name="por_pagina" class="form-select" style="width:80px;font-size:.72rem;height:28px;padding:.1rem .5rem;"
            onchange="document.getElementById('formFiltros').submit()">
        <?php foreach ([25,50,100,250,0] as $pp): ?>
            <option value="<?= $pp ?>" <?= $filtros['por_pagina']===$pp?'selected':'' ?>><?= $pp > 0 ? $pp.'/pág' : 'Todos' ?></option>
        <?php endforeach; ?>
    </select>

    <span style="font-size:.72rem;color:var(--pacs-text-muted);margin-left:auto;display:flex;align-items:center;gap:.4rem;">
        <?= number_format($total) ?> estudo<?= $total!==1?'s':'' ?>
        <?php if (isset($tempoConsulta)): ?>
            <span style="color:var(--pacs-border);">·</span>
            <span title="Tempo de consulta SQL"><?= $tempoConsulta ?>ms</span>
        <?php endif; ?>
        <span style="color:var(--pacs-border);">|</span>
        <i class="fa fa-server" style="font-size:.65rem;"></i> Orthanc PACS
        <?php if ($filtros['periodo']!=='todos'): ?>
            <span style="color:var(--pacs-border);">·</span>
            <span class="periodo-badge"><?= $periodoLabel ?></span>
        <?php endif; ?>
    </span>
</div>
</form>

<!-- ═══════════════════════════════════════════════════════════
     TABELA DE ESTUDOS
═══════════════════════════════════════════════════════════ -->
<div class="pacs-table-wrapper" style="max-height:calc(100vh - 340px);">
    <table class="pacs-table">
        <thead>
            <tr>
                <th style="width:20px;"><input type="checkbox" id="checkAll" onchange="toggleAll(this)" style="accent-color:var(--pacs-primary);"></th>
                <th style="width:105px;"><?= sortLink($filtros,'study_date','Dt Estudo') ?></th>
                <th><?= sortLink($filtros,'patient_name','Paciente') ?></th>
                <th style="width:145px;"><?= sortLink($filtros,'institution_name','Unidade') ?></th>
                <th style="width:50px;">M</th>
                <th style="width:130px;"><?= sortLink($filtros,'especialidade','Especialidade') ?></th>
                <th>Estudo</th>
                <th style="width:95px;"><?= sortLink($filtros,'situacao','Situação') ?></th>
                <th style="width:170px;text-align:center;">Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($estudos)): ?>
            <tr>
                <td colspan="9" style="text-align:center;padding:3rem 1rem;">
                    <div style="font-size:2rem;margin-bottom:.75rem;opacity:.4;"><i class="fa fa-magnifying-glass"></i></div>
                    <div style="font-weight:600;color:var(--pacs-text-secondary);margin-bottom:.4rem;">
                        Nenhum estudo encontrado<?= $temFiltroAtivo?' com os filtros aplicados':'' ?>.
                    </div>
                    <div style="font-size:.8rem;color:var(--pacs-text-muted);">
                        <?php if ($temFiltroAtivo): ?>
                            <a href="/estudos" style="color:var(--pacs-primary);">Limpar filtros</a> para ver todos os estudos.
                        <?php else: ?>
                            Os estudos são importados do servidor Orthanc PACS.<br>
                            Acesse <a href="/platform/servidor-pacs" style="color:var(--pacs-primary);">Plataforma &rsaquo; Servidor PACS</a>
                            e clique em <strong>Sincronizar</strong>.
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($estudos as $e):
                $sit  = $e['situacao']  ?? 'novo';
                $prio = $e['prioridade']?? 'normal';
                $mods = array_filter(array_map('trim', explode('\\', $e['modalities'] ?? '')));
                if (empty($mods) && !empty($e['modalities'])) $mods = [trim($e['modalities'])];
                $rowClass = $prio==='urgente'?'row-urgente':($prio==='critico'?'row-critico':'');
                // Data/Hora
                $dtFmt = '—';
                if (!empty($e['study_date'])) {
                    try { $dtFmt = (new DateTime($e['study_date']))->format('d/m/Y'); } catch(\Throwable $t) { $dtFmt = $e['study_date']; }
                }
                $hrFmt = '';
                if (!empty($e['study_time']) && strlen($e['study_time']) >= 4) {
                    $hrFmt = substr($e['study_time'],0,2).':'.substr($e['study_time'],2,2);
                }
            ?>
            <tr class="<?= $rowClass ?>" data-id="<?= $e['id'] ?>" title="Duplo clique para abrir no OHIF">
                <td><input type="checkbox" class="row-check" value="<?= $e['id'] ?>" style="accent-color:var(--pacs-primary);"></td>

                <td class="dt-estudo">
                    <?= prioridadeBadge($prio) ?>
                    <div class="date"><?= $dtFmt ?></div>
                    <?php if ($hrFmt): ?><div class="time"><?= $hrFmt ?></div><?php endif; ?>
                </td>

                <td class="paciente-cell">
                    <div class="nome"><?= htmlspecialchars($e['patient_name'] ?? '—') ?></div>
                    <div class="info">
                        <?= $e['patient_sex'] ?? '' ?>
                        <?php $idade = formatarIdade($e); if ($idade) echo ' · ' . $idade; ?>
                        <?php if (!empty($e['patient_id'])): ?>
                            · <span title="Patient ID"><?= htmlspecialchars($e['patient_id']) ?></span>
                        <?php endif; ?>
                    </div>
                </td>

                <td style="font-size:.75rem;color:var(--pacs-text-secondary);">
                    <?= htmlspecialchars($e['institution_name'] ?? '—') ?>
                </td>

                <td>
                    <?php foreach ($mods as $mod) echo modBadge($mod); ?>
                    <?php if (empty($mods)) echo '<span class="text-pacs-muted">—</span>'; ?>
                </td>

                <td>
                    <?php if (!empty($e['especialidade'])): ?>
                        <span class="esp-tag"><?= htmlspecialchars($e['especialidade']) ?></span>
                    <?php elseif (!empty($e['referring_physician_name'])): ?>
                        <span class="esp-tag" style="opacity:.7;"><?= htmlspecialchars($e['referring_physician_name']) ?></span>
                    <?php else: ?>
                        <span class="text-pacs-muted">—</span>
                    <?php endif; ?>
                </td>

                <td>
                    <div style="font-size:.78rem;font-weight:500;"><?= htmlspecialchars($e['study_description'] ?: '—') ?></div>
                    <div style="font-size:.68rem;color:var(--pacs-text-muted);display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.1rem;">
                        <?php if (!empty($e['num_series'])): ?>
                            <span title="Séries"><i class="fa fa-layer-group"></i> <?= $e['num_series'] ?></span>
                        <?php endif; ?>
                        <?php if (!empty($e['num_instances'])): ?>
                            <span title="Imagens"><i class="fa fa-images"></i> <?= number_format($e['num_instances']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($e['accession_number'])): ?>
                            <span style="opacity:.6;" title="Accession"><?= htmlspecialchars($e['accession_number']) ?></span>
                        <?php endif; ?>
                    </div>
                </td>

                <td><?= situacaoBadge($sit) ?></td>

                <td style="text-align:center;">
                    <?php
                    $uid    = $e['study_instance_uid'] ?? '';
                    $eid    = (int)$e['id'];
                    $hasUid = !empty($uid);
                    ?>
                    <div class="acoes-grupo">

                    <!-- Botão ABRIR (OHIF Viewer) — sempre à esquerda -->
                    <?php if ($hasUid): ?>
                    <a href="/estudos/<?= $eid ?>/abrir"
                       class="pacs-btn btn-open"
                       title="Abrir no OHIF Viewer"
                       target="_blank">
                        <i class="fa fa-eye"></i> Abrir
                    </a>
                    <?php else: ?>
                    <span class="pacs-btn btn-open" style="opacity:.35;cursor:not-allowed;" title="Sem StudyInstanceUID">
                        <i class="fa fa-eye-slash"></i> Abrir
                    </span>
                    <?php endif; ?>

                    <!-- Botão CONTEXTUAL — muda conforme situação -->
                    <?php if ($sit === 'novo' || $sit === 'aberto'): ?>
                        <!-- ASSUMIR: POST AJAX → muda status para em_laudo e transforma o botão em "A Laudar" -->
                        <button class="pacs-btn btn-assumir"
                                title="Assumir este estudo e iniciar laudo"
                                data-estudo-id="<?= $eid ?>"
                                data-study-uid="<?= htmlspecialchars($uid) ?>"
                                onclick="assumirEstudo(this)">
                            <i class="fa fa-user-doctor"></i> Assumir
                        </button>

                    <?php elseif ($sit === 'em_laudo' || $sit === 'rascunho'): ?>
                        <!-- A LAUDAR: abre o módulo de laudo diretamente -->
                        <?php if ($hasUid): ?>
                        <a href="/reports/<?= urlencode($uid) ?>"
                           class="pacs-btn btn-alaudar"
                           title="Abrir editor de laudo">
                            <i class="fa fa-pen-to-square"></i> A Laudar
                        </a>
                        <?php else: ?>
                        <button class="pacs-btn btn-alaudar"
                                title="Abrir editor de laudo"
                                onclick="abrirLaudoPorId(<?= $eid ?>)">
                            <i class="fa fa-pen-to-square"></i> A Laudar
                        </button>
                        <?php endif; ?>

                    <?php elseif ($sit === 'assinado' || $sit === 'liberado'): ?>
                        <!-- LAUDO PRONTO: visualizar + PDF -->
                        <?php if ($hasUid): ?>
                        <a href="/reports/<?= urlencode($uid) ?>"
                           class="pacs-btn btn-view-report"
                           title="Visualizar laudo"
                           target="_blank">
                            <i class="fa fa-file-medical"></i> Laudo
                        </a>
                        <?php else: ?>
                        <button class="pacs-btn btn-pdf" title="Baixar PDF"
                                onclick="abrirPdfLaudo(<?= $eid ?>)">
                            <i class="fa fa-file-pdf"></i> PDF
                        </button>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- ESTADO GENÉRICO: laudar -->
                        <?php if ($hasUid): ?>
                        <a href="/reports/<?= urlencode($uid) ?>"
                           class="pacs-btn btn-laudar"
                           title="Abrir editor de laudo">
                            <i class="fa fa-pen"></i> Laudar
                        </a>
                        <?php endif; ?>
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
<?php if ($totalPages > 1 || $total > 0): ?>
<div class="pacs-pagination">
    <span style="font-size:.75rem;color:var(--pacs-text-muted);">
        <?php if ($filtros['por_pagina'] > 0 && $total > 0): ?>
            Mostrando <?= number_format(($currentPage-1)*$filtros['por_pagina']+1) ?>–<?= number_format(min($currentPage*$filtros['por_pagina'],$total)) ?>
            de <?= number_format($total) ?> estudos
        <?php else: ?>
            <?= number_format($total) ?> estudos
        <?php endif; ?>
    </span>
    <?php if ($totalPages > 1): ?>
    <div class="page-links">
        <?php if ($currentPage > 1): ?>
            <a href="<?= estudoUrl($filtros, 1) ?>" class="page-btn" title="Primeira"><i class="fa fa-angles-left"></i></a>
            <a href="<?= estudoUrl($filtros, $currentPage-1) ?>" class="page-btn"><i class="fa fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php
        $start = max(1, $currentPage-2); $end = min($totalPages, $currentPage+2);
        if ($start > 1) echo '<span class="page-btn" style="pointer-events:none;opacity:.4;">…</span>';
        for ($p = $start; $p <= $end; $p++):
        ?>
            <a href="<?= estudoUrl($filtros, $p) ?>" class="page-btn <?= $p===$currentPage?'active':'' ?>"><?= $p ?></a>
        <?php endfor;
        if ($end < $totalPages) echo '<span class="page-btn" style="pointer-events:none;opacity:.4;">…</span>';
        ?>
        <?php if ($currentPage < $totalPages): ?>
            <a href="<?= estudoUrl($filtros, $currentPage+1) ?>" class="page-btn"><i class="fa fa-chevron-right"></i></a>
            <a href="<?= estudoUrl($filtros, $totalPages) ?>" class="page-btn" title="Última"><i class="fa fa-angles-right"></i></a>
        <?php endif; ?>
    </div>
    <span style="font-size:.72rem;color:var(--pacs-text-muted);">Página <?= $currentPage ?> de <?= $totalPages ?></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     ESTILOS ADICIONAIS
═══════════════════════════════════════════════════════════ -->
<style>
.estudos-resumo-cards{display:flex;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center;}
.resumo-card{background:var(--pacs-surface);border:1px solid var(--pacs-border);border-radius:8px;padding:.5rem .9rem;min-width:90px;text-align:center;transition:border-color .15s,background .15s;cursor:pointer;}
.resumo-card:hover{border-color:var(--pacs-primary);background:rgba(79,195,247,.06);}
.resumo-card-urgente{border-color:#f97316;}
.resumo-card-urgente:hover{background:rgba(249,115,22,.08);}
.resumo-card-total{border-color:var(--pacs-primary);}
.resumo-card-valor{font-size:1.4rem;font-weight:700;color:var(--pacs-text-primary);line-height:1.2;}
.resumo-card-urgente .resumo-card-valor{color:#f97316;}
.resumo-card-total .resumo-card-valor{color:var(--pacs-primary);}
.resumo-card-label{font-size:.65rem;color:var(--pacs-text-muted);text-transform:uppercase;letter-spacing:.04em;margin-top:.15rem;}
.resumo-sinc-info{display:flex;align-items:center;font-size:.7rem;color:var(--pacs-text-muted);padding:.25rem .5rem;border-left:1px solid var(--pacs-border);gap:.3rem;}
.periodo-badge{background:rgba(79,195,247,.15);color:var(--pacs-primary);border-radius:4px;padding:.05rem .35rem;font-size:.65rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;}
.prio-badge{display:inline-block;margin-right:.2rem;}
.prio-urgente{color:#f97316;}
.prio-critico{color:#ef4444;}
.row-urgente td:first-child{border-left:3px solid #f97316;}
.row-critico td:first-child{border-left:3px solid #ef4444;}
.sit-em-laudo{background:rgba(168,85,247,.18);color:#c084fc;}
.sit-liberado{background:rgba(16,185,129,.18);color:#34d399;}
.sit-novo{background:rgba(100,116,139,.2);color:#94a3b8;}
.sit-aberto{background:rgba(59,130,246,.2);color:#60a5fa;}
.sit-rascunho{background:rgba(234,179,8,.2);color:#facc15;}
.sit-assinado{background:rgba(16,185,129,.2);color:#34d399;}
.sit-urgente{background:rgba(249,115,22,.2);color:#f97316;}

/* ── Grupo de ações (Abrir + botão contextual) ── */
.acoes-grupo{display:inline-flex;gap:.3rem;align-items:center;justify-content:center;flex-wrap:nowrap;}
/* pacs.css define .pacs-btn como quadrado fixo de 26x26px (botões só-ícone da sidebar/topbar).
   Aqui os botões têm ícone+texto, então precisamos anular width/height explicitamente —
   sem isso, o 26x26px do pacs.css prevalece (CSS só sobrescreve por propriedade, não por regra). */
.acoes-grupo .pacs-btn{display:inline-flex;align-items:center;gap:.25rem;width:auto;height:auto;min-width:0;flex-shrink:0;padding:.22rem .55rem;border-radius:5px;font-size:.72rem;font-weight:500;border:1px solid transparent;cursor:pointer;text-decoration:none;transition:background .15s,border-color .15s,transform .1s;white-space:nowrap;line-height:1.4;}
.acoes-grupo .pacs-btn:active{transform:scale(.96);}
/* Abrir — cinza neutro */
.acoes-grupo .btn-open{background:rgba(100,116,139,.18);color:#94a3b8;border-color:rgba(100,116,139,.4);}
.acoes-grupo .btn-open:hover{background:rgba(100,116,139,.32);color:#cbd5e1;}
/* Assumir — azul ciano */
.acoes-grupo .btn-assumir{background:rgba(79,195,247,.15);color:#4fc3f7;border-color:rgba(79,195,247,.5);}
.acoes-grupo .btn-assumir:hover{background:rgba(79,195,247,.28);}
/* A Laudar — roxo/violeta */
.acoes-grupo .btn-alaudar{background:rgba(168,85,247,.18);color:#c084fc;border-color:rgba(168,85,247,.5);}
.acoes-grupo .btn-alaudar:hover{background:rgba(168,85,247,.32);}
/* Laudar genérico */
.acoes-grupo .btn-laudar{background:rgba(168,85,247,.15);color:#c084fc;border-color:rgba(168,85,247,.4);}
.acoes-grupo .btn-laudar:hover{background:rgba(168,85,247,.3);}
/* Laudo assinado */
.acoes-grupo .btn-view-report{background:rgba(16,185,129,.15);color:#34d399;border-color:rgba(16,185,129,.4);}
.acoes-grupo .btn-view-report:hover{background:rgba(16,185,129,.3);}
/* PDF */
.acoes-grupo .btn-pdf{background:rgba(239,68,68,.12);color:#f87171;border-color:rgba(239,68,68,.4);}
.acoes-grupo .btn-pdf:hover{background:rgba(239,68,68,.25);}
/* Estado "carregando" do botão Assumir (spinner, sem texto) — evita colapsar para 0 largura */
.acoes-grupo .pacs-btn:disabled{opacity:.7;cursor:wait;min-width:1.6rem;justify-content:center;}
</style>

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════════════════════════ -->
<script>
function setModalidade(mod) {
    const input = document.getElementById('inputModalidade');
    const btns  = document.querySelectorAll('.mod-btn');
    if (input.value === mod) { input.value = ''; btns.forEach(b => b.classList.remove('active')); }
    else { input.value = mod; btns.forEach(b => b.classList.toggle('active', b.textContent.trim() === mod)); }
    document.getElementById('formFiltros').submit();
}
function toggleDatasPersonalizadas(val) {
    document.getElementById('datasPersonalizadas').style.display = val === 'personalizado' ? 'flex' : 'none';
    if (val !== 'personalizado') document.getElementById('formFiltros').submit();
}
function setPeriodo(periodo) {
    document.getElementById('selectPeriodo').value = periodo;
    toggleDatasPersonalizadas(periodo);
    document.getElementById('formFiltros').submit();
}
function setFiltroRapido(campo, valor) {
    const el = document.querySelector('[name="' + campo + '"]');
    if (el) { el.value = valor; document.getElementById('formFiltros').submit(); }
}

// Atalho de teclado: / para focar na busca
document.addEventListener('keydown', function(e) {
    if (e.key === '/' && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) {
        e.preventDefault();
        const q = document.querySelector('[name="q"]');
        if (q) q.focus();
    }
});
function toggleAll(master) {
    document.querySelectorAll('.row-check').forEach(c => c.checked = master.checked);
}
document.querySelectorAll('.pacs-table tbody tr[data-id]').forEach(row => {
    row.addEventListener('dblclick', function(e) {
        if (e.target.closest('a,button,input')) return;
        window.open('/estudos/' + this.dataset.id + '/abrir', '_blank');
    });
});

// ── Assumir Estudo ──
// Recebe o elemento <button> diretamente (onclick="assumirEstudo(this)")
function assumirEstudo(btn) {
    const estudoId = btn.dataset.estudoId;
    const studyUid = btn.dataset.studyUid;
    if (!estudoId) return;

    // Feedback visual imediato
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';

    fetch('/reports/assumir', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ estudo_id: parseInt(estudoId) })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            // ✔ Transforma o botão em "A Laudar" SEM recarregar a página
            btn.disabled  = false;
            btn.className = 'pacs-btn btn-alaudar';
            btn.title     = 'Abrir editor de laudo';
            btn.innerHTML = '<i class="fa fa-pen-to-square"></i> A Laudar';

            // Ao clicar em "A Laudar", abre o módulo de laudo
            btn.onclick = function() {
                if (studyUid) {
                    window.location.href = '/reports/' + encodeURIComponent(studyUid);
                } else {
                    abrirLaudoPorId(parseInt(estudoId));
                }
            };

            // Atualiza o badge de situação na mesma linha
            var row = btn.closest('tr');
            if (row) {
                var badge = row.querySelector('.situacao-badge');
                if (badge) {
                    badge.className = 'situacao-badge sit-em-laudo';
                    badge.textContent = 'EM LAUDO';
                }
            }
        } else {
            alert(data.msg || 'Erro ao assumir o estudo.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-user-doctor"></i> Assumir';
        }
    })
    .catch(function() {
        alert('Falha na conexão. Tente novamente.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-user-doctor"></i> Assumir';
    });
}

// ── Abrir laudo por estudo_id (fallback sem study_uid) ──
function abrirLaudoPorId(estudoId) {
    fetch('/api/reports/by-estudo?estudo_id=' + estudoId)
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.report_id) {
            window.location.href = '/reports/' + data.report_id;
        } else {
            // Cria novo laudo
            window.location.href = '/reports/novo?estudo_id=' + estudoId;
        }
    })
    .catch(function() { alert('Erro ao abrir laudo.'); });
}

// ── Abrir PDF do Laudo ──
function abrirPdfLaudo(estudoId) {
    // Busca o report_id via API e abre o PDF
    fetch('/api/reports/by-estudo?estudo_id=' + estudoId)
    .then(r => r.json())
    .then(function(data) {
        if (data.report_id) {
            window.open('/reports/pdf?report_id=' + data.report_id, '_blank');
        } else {
            alert('Laudo não encontrado para este estudo.');
        }
    })
    .catch(function() { alert('Erro ao buscar laudo.'); });
}
</script>
