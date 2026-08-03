<?php
/**
 * VOXEL PACS — Worklist de Estudos (v4)
 * Layout reformulado: compacto, ícones de sexo coloridos, SLA semafórico, Ações espaçosas.
 */

/* ─── helpers de URL ─────────────────────────────────────────────────────── */
function estudoUrl(array $filtros, int $pagina = 1): string {
    $p = array_merge($filtros, ['pagina' => $pagina]);
    unset($p['situacao_rapida']);
    // Separar modalidades[] (array) dos demais campos escalares
    $mods = isset($p['modalidades']) && is_array($p['modalidades']) ? $p['modalidades'] : [];
    unset($p['modalidades']);
    // Filtrar campos vazios/nulos (exceto arrays)
    $query = http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
    // Adicionar modalidades[] individualmente para gerar ?modalidades[]=CT&modalidades[]=MR
    foreach ($mods as $mod) {
        if ($mod !== '' && $mod !== null) {
            $query .= ($query ? '&' : '') . 'modalidades%5B%5D=' . rawurlencode($mod);
        }
    }
    return '/estudos?' . $query;
}

/* ─── badge de prioridade DICOM (0040,1003) ─────────────────────────────── */
function prioridadeBadge(string $dicomValue, string $lang = 'pt_BR'): string {
    $val = strtoupper(trim($dicomValue));
    $map = [
        'STAT'    => 'emergencia',
        'HIGH'    => 'urgencia',
        'ROUTINE' => 'rotina',
        'MEDIUM'  => 'rotina',
        'LOW'     => 'ambulatorial',
    ];
    $key = $map[$val] ?? 'rotina';
    $labels = [
        'pt_BR' => ['emergencia'=>'Emerg&ecirc;ncia','urgencia'=>'Urg&ecirc;ncia','rotina'=>'Rotina','ambulatorial'=>'Ambulatorial'],
        'en'    => ['emergencia'=>'Emergency','urgencia'=>'Urgent','rotina'=>'Routine','ambulatorial'=>'Outpatient'],
        'es'    => ['emergencia'=>'Emergencia','urgencia'=>'Urgente','rotina'=>'Rutina','ambulatorial'=>'Ambulatorio'],
    ];
    $langKey = isset($labels[$lang]) ? $lang : 'pt_BR';
    $label = $labels[$langKey][$key];
    return "<span class=\"wl-prio-badge wl-prio-{$key}\">{$label}</span>";
}
/* ─── badge de situação ──────────────────────────────────────────────────── */
function situacaoBadge(string $sit): string {
    $map = [
        'novo'     => ['sit-novo',     'NOVO'],
        'aberto'   => ['sit-aberto',   'ABERTO'],
        'a_laudar' => ['sit-a-laudar', 'A LAUDAR'],
        'em_laudo' => ['sit-em-laudo', 'EM LAUDO'],
        'rascunho' => ['sit-rascunho', 'RASCUNHO'],
        'assinado' => ['sit-assinado', 'ASSINADO'],
        'liberado' => ['sit-liberado', 'LIBERADO'],
        'urgente'  => ['sit-urgente',  'URGENTE'],
    ];
    [$cls, $label] = $map[$sit] ?? ['sit-novo', strtoupper(str_replace('_', ' ', $sit))];
    return "<span class=\"sit-badge {$cls}\">{$label}</span>";
}

/* ─── ícone de sexo colorido ─────────────────────────────────────────────── */
function sexoIcon(?string $sex): string {
    $s = strtoupper(trim($sex ?? ''));
    if ($s === 'M') return '<span class="sexo-m" title="Masculino"><i class="fa fa-mars"></i></span>';
    if ($s === 'F') return '<span class="sexo-f" title="Feminino"><i class="fa fa-venus"></i></span>';
    return '<span class="sexo-nd" title="Não informado"><i class="fa fa-circle-question"></i></span>';
}

/* ─── badge de modalidade (sigla + tooltip com a descrição no idioma ativo) ── */
function modBadge(string $mod): string {
    $mod  = \App\Services\DicomModalityService::code($mod);
    $desc = \App\Services\DicomModalityService::description($mod);
    return sprintf(
        '<span class="dicom-modality mod-badge mod-%s" data-bs-toggle="tooltip" data-bs-placement="top" title="%s">%s</span>',
        htmlspecialchars($mod),
        htmlspecialchars($desc),
        htmlspecialchars($mod)
    );
}
/* ─── badge de parte do corpo (BodyPartExtractor) ───────────────────────────────────── */
function bodyPartBadge(string $key): string {
    $colors = \App\Services\BodyPartExtractor::COLORS;
    $i18n   = \App\Services\BodyPartExtractor::I18N_KEYS;
    $bg     = $colors[$key]['bg']   ?? '#64748b';
    $txt    = $colors[$key]['text'] ?? '#fff';
    $label  = htmlspecialchars(t($i18n[$key] ?? $key));
    return "<span class=\"bp-badge\" style=\"background:{$bg};color:{$txt}\">{$label}</span>";
}
/* ─── renderiza coluna ESTUDO: apenas texto de study_description ────────────── */
function renderEstudo(array $e): string {
    // Cadeia de fontes (0008,1030 → 0032,1070 → 0018,0015 → fallback)
    $desc     = trim($e['study_description']        ?? '');
    $procDesc = trim($e['requested_procedure_desc'] ?? '');
    $bodyPart = trim($e['body_part_examined']       ?? '');

    $texto = $desc !== '' ? $desc
           : ($procDesc !== '' ? $procDesc
           : ($bodyPart !== '' ? $bodyPart : ''));

    $tooltip  = htmlspecialchars('Tag DICOM (0008,1030) Study Description: ' . ($texto !== '' ? $texto : 'vazia'));
    $display  = $texto !== '' ? htmlspecialchars($texto) : 'SEM DESCRIÇÃO';

    return sprintf(
        '<span class="study-description" title="%s">%s</span>',
        $tooltip,
        $display
    );
}

/* ─── badge de prioridade interna (urgente/critico) ─────────────────────────────── */
function prioridadeInternaBadge(string $p): string {
    if ($p === 'urgente') return '<span class="prio-urgente" title="Urgente"><i class="fa fa-triangle-exclamation"></i></span>';
    if ($p === 'critico') return '<span class="prio-critico" title="Crítico"><i class="fa fa-circle-exclamation"></i></span>';
    return '';
}

/* ─── formatar idade ─────────────────────────────────────────────────────── */
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

/* ─── SLA: formatar tempo ────────────────────────────────────────────────── */
function formatarSla(?string $inicio, ?string $fim = null): string {
    if (!$inicio) return '';
    try {
        $dtI  = new DateTime($inicio);
        $dtF  = $fim ? new DateTime($fim) : new DateTime();
        $diff = $dtI->diff($dtF);
        if ($diff->y >= 1)  return $diff->y . 'a';
        if ($diff->m >= 1)  return $diff->m . 'm' . ($diff->d ? ' ' . $diff->d . 'd' : '');
        if ($diff->d >= 1)  return $diff->d . 'd ' . $diff->h . 'h';
        if ($diff->h >= 1)  return $diff->h . 'h ' . $diff->i . 'm';
        if ($diff->i >= 1)  return $diff->i . 'm';
        return 'agora';
    } catch (\Throwable $t) { return ''; }
}

/* ─── SLA: cor semafórica ────────────────────────────────────────────────── */
function slaClass(?string $inicio, ?string $fim = null): string {
    if (!$inicio) return 'sla-verde';
    try {
        $min = (int)((( $fim ? new DateTime($fim) : new DateTime())->getTimestamp()
                       - (new DateTime($inicio))->getTimestamp()) / 60);
        if ($min < 60)   return 'sla-verde';
        if ($min < 240)  return 'sla-amarelo';
        if ($min < 1440) return 'sla-laranja';
        return 'sla-vermelho';
    } catch (\Throwable $t) { return 'sla-verde'; }
}

/* ─── link de ordenação ──────────────────────────────────────────────────── */
function sortLink(array $filtros, string $col, string $label): string {
    $ativo = $filtros['ordenar'] === $col;
    $dir   = $ativo && $filtros['direcao'] === 'DESC' ? 'ASC' : 'DESC';
    $icon  = $ativo ? ($filtros['direcao'] === 'DESC' ? 'fa-sort-down' : 'fa-sort-up') : 'fa-sort';
    $url   = estudoUrl(array_merge($filtros, ['ordenar' => $col, 'direcao' => $dir]));
    return "<a href=\"{$url}\" class=\"sort-link\">{$label} <i class=\"fa {$icon}\"></i></a>";
}

/* ─── variáveis de controle ──────────────────────────────────────────────── */
$temFiltroAtivo = array_filter(
    array_diff_key($filtros, [
        'ordenar'=>1,'direcao'=>1,'pagina'=>1,'por_pagina'=>1,'periodo'=>1,
        'situacao_rapida'=>1,  // alias de situacao, não é filtro independente
        'modalidade'=>1,       // campo legado, substituído por modalidades[]
    ]),
    fn($v) => is_array($v) ? !empty($v) : $v !== ''
);

$periodoLabel = [
    'hoje'=>'Hoje','ontem'=>'Ontem','7dias'=>'7 dias','30dias'=>'30 dias',
    '90dias'=>'90 dias','ano'=>'Este ano','todos'=>'Todos','personalizado'=>'Personalizado'
][$filtros['periodo']] ?? '30 dias';
?>

<!-- ═══════════════════════════════════════════════════════════ HEADER WORKLIST -->
<div class="wl-page-header">
    <div class="wl-page-title">
        <i class="fa fa-list-check"></i>
        <span>Worklist de Estudos</span>
    </div>
    <a href="/estudos/instalar" class="wl-pwa-btn" title="Instalar app da Worklist no seu computador">
        <i class="fa fa-download"></i> Instalar App
    </a>
    <button type="button" id="btn-voxel-desktop" class="wl-desktop-btn" title="Baixar o VOXEL Desktop — visualizador oficial VOXEL PACS">
        <i class="fa fa-desktop"></i> <span id="vd-label">VOXEL Desktop</span>
    </button>
</div>

<!-- ═══════════════════════════════════════════════════════════ RESUMO (oculto — ganho de espaço vertical) -->
<div class="wl-resumo" style="display:none;">
    <div class="wl-resumo-cards">
        <div class="wl-card" onclick="setPeriodo('hoje')" title="Hoje">
            <span class="wl-card-num"><?= number_format($resumo['hoje']) ?></span>
            <span class="wl-card-lbl"><i class="fa fa-calendar-day"></i> Hoje</span>
        </div>
        <div class="wl-card" onclick="setPeriodo('7dias')" title="7 dias">
            <span class="wl-card-num"><?= number_format($resumo['semana']) ?></span>
            <span class="wl-card-lbl"><i class="fa fa-calendar-week"></i> 7 dias</span>
        </div>
        <div class="wl-card wl-card-active" onclick="setPeriodo('30dias')" title="30 dias">
            <span class="wl-card-num"><?= number_format($resumo['mes']) ?></span>
            <span class="wl-card-lbl"><i class="fa fa-calendar"></i> 30 dias</span>
        </div>
        <?php if ($resumo['urgentes'] > 0): ?>
        <div class="wl-card wl-card-urgente" onclick="setFiltroRapido('prioridade','urgente')" title="Urgentes">
            <span class="wl-card-num"><?= number_format($resumo['urgentes']) ?></span>
            <span class="wl-card-lbl"><i class="fa fa-triangle-exclamation"></i> Urgentes</span>
        </div>
        <?php endif; ?>
    </div>
    <div class="wl-resumo-right">
        <div class="wl-card wl-card-total" onclick="setPeriodo('todos')" title="Total PACS">
            <span class="wl-card-num"><?= number_format($resumo['total']) ?></span>
            <span class="wl-card-lbl"><i class="fa fa-database"></i> Total PACS</span>
        </div>
        <?php if ($ultimaSinc): ?>
        <div class="wl-sinc">
            <i class="fa fa-rotate"></i>
            Sinc. <?= htmlspecialchars(date('d/m H:i', strtotime($ultimaSinc))) ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ FILTROS -->
<form id="formFiltros" method="GET" action="/estudos" autocomplete="off">

<!-- Linha 1: busca geral + período + ordenação + unidade + situação + ações -->
<div class="wl-filters wl-filters-row1">
    <input type="text" name="q" class="wl-input" style="width:160px;"
           placeholder="Pesquisar..." value="<?= htmlspecialchars($filtros['q']) ?>">

    <input type="text" name="paciente" class="wl-input" style="width:160px;"
           placeholder="Nome do paciente" value="<?= htmlspecialchars($filtros['paciente']) ?>">

    <!-- Período -->
    <select name="periodo" id="selectPeriodo" class="wl-select" style="width:110px;"
            onchange="toggleDatasPersonalizadas(this.value)">
        <?php foreach (['hoje'=>'Hoje','ontem'=>'Ontem','7dias'=>'7 dias','30dias'=>'30 dias','90dias'=>'90 dias','ano'=>'Este ano','todos'=>'Todos','personalizado'=>'Personalizado'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= $filtros['periodo']===$v?'selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
    </select>

    <span id="datasPersonalizadas" style="display:<?= $filtros['periodo']==='personalizado'?'flex':'none' ?>;gap:.25rem;align-items:center;">
        <input type="date" name="dt_inicio" class="wl-input" style="width:130px;" value="<?= htmlspecialchars($filtros['dt_inicio']) ?>">
        <span class="wl-sep">até</span>
        <input type="date" name="dt_fim"    class="wl-input" style="width:130px;" value="<?= htmlspecialchars($filtros['dt_fim']) ?>">
    </span>

    <select name="ordenar" class="wl-select" style="width:148px;">
        <option value="study_date"       <?= $filtros['ordenar']==='study_date'?'selected':'' ?>>Dt Estudo</option>
        <option value="patient_name"     <?= $filtros['ordenar']==='patient_name'?'selected':'' ?>>Paciente</option>
        <option value="institution_name" <?= $filtros['ordenar']==='institution_name'?'selected':'' ?>>Unidade</option>
        <option value="modalities"       <?= $filtros['ordenar']==='modalities'?'selected':'' ?>>Modalidade</option>
        <option value="situacao"         <?= $filtros['ordenar']==='situacao'?'selected':'' ?>>Situação</option>
        <option value="prioridade"       <?= $filtros['ordenar']==='prioridade'?'selected':'' ?>>Prioridade</option>
        <option value="study_description" <?= $filtros['ordenar']==='study_description'?'selected':'' ?>>Descrição</option>
    </select>
    <select name="direcao" class="wl-select" style="width:100px;">
        <option value="DESC" <?= $filtros['direcao']==='DESC'?'selected':'' ?>>Desc.</option>
        <option value="ASC"  <?= $filtros['direcao']==='ASC'?'selected':'' ?>>Cresc.</option>
    </select>

    <select name="unidade" class="wl-select" style="width:145px;">
        <option value="">Todas as unidades</option>
        <?php foreach ($unidades as $u): ?>
            <option value="<?= htmlspecialchars($u) ?>" <?= $filtros['unidade']===$u?'selected':'' ?>><?= htmlspecialchars($u) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="situacao" id="selectSituacao" class="wl-select" style="width:138px;">
        <option value="">Todas as situações</option>
        <option value="novo"     <?= $filtros['situacao']==='novo'?'selected':'' ?>>NOVO</option>
        <option value="aberto"   <?= $filtros['situacao']==='aberto'?'selected':'' ?>>ABERTO</option>
        <option value="a_laudar" <?= $filtros['situacao']==='a_laudar'?'selected':'' ?>>A LAUDAR</option>
        <option value="em_laudo" <?= $filtros['situacao']==='em_laudo'?'selected':'' ?>>EM LAUDO</option>
        <option value="rascunho" <?= $filtros['situacao']==='rascunho'?'selected':'' ?>>RASCUNHO</option>
        <option value="assinado" <?= $filtros['situacao']==='assinado'?'selected':'' ?>>ASSINADO</option>
        <option value="liberado" <?= $filtros['situacao']==='liberado'?'selected':'' ?>>LIBERADO</option>
    </select>

    <button type="submit" class="wl-btn-primary"><i class="fa fa-magnifying-glass"></i> Buscar</button>
    <?php if ($temFiltroAtivo): ?>
    <a href="/estudos" class="wl-btn-outline"><i class="fa fa-xmark"></i> Limpar</a>
    <?php endif; ?>
</div>

<!-- Linha 2: situação rápida + modalidades + solicitante + médico + por página + info -->
<div class="wl-filters wl-filters-row2">
    <!-- Situação rápida -->
    <select name="situacao_rapida" class="wl-select wl-select-sm"
            onchange="document.getElementById('selectSituacao').value=this.value;">
        <option value="">A laudar (Todos)</option>
        <option value="novo"     <?= $filtros['situacao']==='novo'?'selected':'' ?>>Novo</option>
        <option value="aberto"   <?= $filtros['situacao']==='aberto'?'selected':'' ?>>Aberto</option>
        <option value="a_laudar" <?= $filtros['situacao']==='a_laudar'?'selected':'' ?>>A laudar</option>
        <option value="em_laudo" <?= $filtros['situacao']==='em_laudo'?'selected':'' ?>>Em laudo</option>
        <option value="urgente"  <?= $filtros['situacao']==='urgente'?'selected':'' ?>>Urgente</option>
        <option value="rascunho" <?= $filtros['situacao']==='rascunho'?'selected':'' ?>>Rascunho</option>
        <option value="assinado" <?= $filtros['situacao']==='assinado'?'selected':'' ?>>Assinado</option>
        <option value="liberado" <?= $filtros['situacao']==='liberado'?'selected':'' ?>>Liberado</option>
    </select>

    <span class="wl-divider"></span>

    <!-- Botões de modalidade -->
    <?php
    $modsAll  = ['CR','CT','CTG','DO','DR','DX','ECG','ES','MG','MR','NM','OF','OT','PT','RF','US','XA'];
    $modsAtivas = $modsAtivas ?? [];
    foreach ($modsAll as $m): $isAtivo = in_array($m, $modsAtivas, true);
    ?>
        <button type="button" class="wl-mod-btn <?= $isAtivo?'active':'' ?>"
                data-mod="<?= $m ?>" onclick="toggleModalidade('<?= $m ?>')"><?= $m ?></button>
    <?php endforeach; ?>
    <div id="modalidadesInputs">
    <?php foreach ($modsAtivas as $mAtiva): ?>
        <input type="hidden" name="modalidades[]" value="<?= htmlspecialchars($mAtiva) ?>">
    <?php endforeach; ?>
    </div>

    <span class="wl-divider"></span>

    <!-- Solicitante -->
    <input type="text" name="especialidade" class="wl-input wl-input-sm" style="width:140px;"
           placeholder="Solicitante" value="<?= htmlspecialchars($filtros['especialidade']) ?>">

    <!-- Médico responsável -->
    <?php if (!empty($medicos)): ?>
    <div class="wl-medico-wrap">
        <select name="medico" class="wl-input wl-input-sm" style="width:180px;">
            <option value="">Médico responsável</option>
            <?php foreach ($medicos as $med):
                $nomeMed = is_array($med) ? $med['nome'] : $med; ?>
                <option value="<?= htmlspecialchars($nomeMed) ?>"
                    <?= $filtros['medico']===$nomeMed?'selected':'' ?>>
                    <?= htmlspecialchars($nomeMed) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <!-- Por página -->
    <select name="por_pagina" class="wl-select wl-select-sm">
        <?php foreach ([25,50,100,250] as $pp): ?>
            <option value="<?= $pp ?>" <?= $filtros['por_pagina']===$pp?'selected':'' ?>><?= $pp ?>/pág</option>
        <?php endforeach; ?>
    </select>

    <!-- Info direita -->
    <div class="wl-info-right">
        <span><?= number_format($total) ?> estudo<?= $total!==1?'s':'' ?></span>
        <?php if (isset($tempoConsulta)): ?>
        <span class="wl-sep-dot">·</span>
        <span title="Tempo SQL"><?= $tempoConsulta ?>ms</span>
        <?php endif; ?>
        <span class="wl-sep-dot">·</span>
        <i class="fa fa-server" style="font-size:.62rem;"></i>
        <span>Orthanc PACS</span>
        <?php if ($filtros['periodo'] !== 'todos'): ?>
        <span class="wl-period-badge"><?= $periodoLabel ?></span>
        <?php endif; ?>
    </div>
</div>
</form>

<!-- ═══════════════════════════════════════════════════════════ TABELA -->
<div class="wl-table-wrap">
<table class="wl-table">
    <thead>
        <tr>
            <th class="col-check"><input type="checkbox" id="checkAll" onchange="toggleAll(this)"></th>
            <th class="col-dt"><?= sortLink($filtros,'study_date','Dt Estudo') ?></th>
            <th class="col-paciente"><?= sortLink($filtros,'patient_name','Paciente') ?></th>
            <th class="col-unidade"><?= sortLink($filtros,'institution_name','Unidade') ?></th>
            <th class="col-modalidades">Modalidades</th>
            <th class="col-prioridade" title="Prioridade DICOM (0040,1003)">Prioridade</th>
            <th class="col-estudo">Estudo</th>
            <th class="col-solicitante"><?= sortLink($filtros,'especialidade','Solicitante') ?></th>
            <th class="col-sit"><?= sortLink($filtros,'situacao','Situação') ?></th>
            <th class="col-sla" title="SLA Padrão e SLA Médico"><i class="fa fa-clock"></i> SLA</th>
            <th class="col-acoes">Ações</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($estudos)): ?>
        <tr>
            <td colspan="11" class="wl-empty">
                <i class="fa fa-magnifying-glass"></i>
                <div>Nenhum estudo encontrado<?= $temFiltroAtivo?' com os filtros aplicados':'' ?>.</div>
                <?php if ($temFiltroAtivo): ?>
                <a href="/estudos">Limpar filtros</a>
                <?php endif; ?>
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($estudos as $e):
            $sit  = $e['situacao']  ?? 'novo';
            $prio = $e['prioridade']?? 'normal';
            $sex  = strtoupper(trim($e['patient_sex'] ?? ''));
            $mods = array_filter(array_map('trim', explode('\\', $e['modalities'] ?? '')));
            if (empty($mods) && !empty($e['modalities'])) $mods = [trim($e['modalities'])];
            $rowClass = 'wl-row' . ($prio==='urgente'?' row-urgente':($prio==='critico'?' row-critico':''));

            // Data/Hora
            $dtFmt = '—';
            if (!empty($e['study_date'])) {
                try { $dtFmt = (new DateTime($e['study_date']))->format('d/m/Y'); } catch(\Throwable $t) {}
            }
            $hrFmt = '';
            if (!empty($e['study_time']) && strlen($e['study_time']) >= 4) {
                $hrFmt = substr($e['study_time'],0,2).':'.substr($e['study_time'],2,2);
            }

            // SLA Padrão
            $slaP    = formatarSla($e['recebido_em'] ?? null);
            $slaPCls = slaClass($e['recebido_em'] ?? null);

            // SLA Médico
            $slaM    = '';
            $slaMCls = '';
            if (!empty($e['assumido_em'])) {
                $fimSla = in_array($sit, ['liberado','assinado']) ? ($e['laudo_assinado_em'] ?? null) : null;
                $slaM    = formatarSla($e['assumido_em'], $fimSla);
                $slaMCls = slaClass($e['assumido_em'], $fimSla);
            }

            // Permissões de ação
            $podeAssumir = $isMedicoLogado && in_array($sit, ['novo','aberto']);
            $podeLaudar  = $isMedicoLogado && in_array($sit, ['a_laudar','em_laudo','rascunho']);

            // Recebido há
            $recebidoHa = formatarSla($e['recebido_em'] ?? null);
        ?>
        <tr class="<?= $rowClass ?>" data-id="<?= $e['id'] ?>" title="Duplo clique para abrir">
            <!-- Check -->
            <td class="col-check">
                <input type="checkbox" class="row-check" value="<?= $e['id'] ?>">
            </td>

            <!-- Data/Hora -->
            <td class="col-dt">
                <?= prioridadeInternaBadge($prio) ?>
                <div class="wl-date"><?= $dtFmt ?></div>
                <?php if ($hrFmt): ?><div class="wl-time"><?= $hrFmt ?></div><?php endif; ?>
            </td>

            <!-- Paciente: ícone sexo + nome + info -->
            <td class="col-paciente">
                <div class="wl-pac-row">
                    <?= sexoIcon($sex) ?>
                    <div class="wl-pac-info">
                        <div class="wl-pac-nome"><?= htmlspecialchars($e['patient_name'] ?? '—') ?></div>
                        <div class="wl-pac-sub">
                            <?php $idade = formatarIdade($e); if ($idade) echo $idade; ?>
                            <?php if (!empty($e['patient_id'])): ?>
                                <span class="wl-sep-dot">·</span><?= htmlspecialchars($e['patient_id']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </td>

                        <!-- Unidade -->
            <td class="col-unidade">
                <?= htmlspecialchars($e['institution_name'] ?? '—') ?>
            </td>
            <!-- Modalidades -->
            <td class="col-modalidades">
                <?php foreach ($mods as $mod) echo modBadge($mod); ?>
            </td>
            <!-- Prioridade DICOM (0040,1003) -->
            <td class="col-prioridade">
                <?= prioridadeBadge($e['dicom_priority'] ?? '', 'pt_BR') ?>
            </td>
            <!-- Estudo: apenas study_description -->
            <td class="col-estudo">
                <?= renderEstudo($e) ?>
            </td>

            <!-- Solicitante -->
            <td class="col-solicitante">
                <?php
                $sol = $e['especialidade'] ?: \App\Helpers\DicomPersonName::format($e['referring_physician_name'] ?? null);
                if ($sol): ?>
                    <span class="wl-sol-tag"><?= htmlspecialchars($sol) ?></span>
                <?php else: ?>
                    <span class="wl-muted">—</span>
                <?php endif; ?>
            </td>

            <!-- Situação -->
            <td class="col-sit">
                <?= situacaoBadge($sit) ?>
                <?php if (!empty($e['assumido_por'])): ?>
                <div class="wl-assumido-por" title="Assumido por <?= htmlspecialchars($e['assumido_por']) ?>">
                    <i class="fa fa-user-doctor"></i> <?= htmlspecialchars(explode(' ', $e['assumido_por'])[0]) ?>
                </div>
                <?php endif; ?>
            </td>

            <!-- SLA -->
            <td class="col-sla">
                <?php if ($slaP): ?>
                <div class="sla-pill <?= $slaPCls ?>" title="SLA Padrão: <?= htmlspecialchars($slaP) ?> desde chegada">
                    <i class="fa fa-clock"></i> <?= $slaP ?>
                </div>
                <?php endif; ?>
                <?php if ($slaM): ?>
                <div class="sla-pill sla-med <?= $slaMCls ?>" title="SLA Médico: <?= htmlspecialchars($slaM) ?> desde assunção">
                    <i class="fa fa-user-doctor"></i> <?= $slaM ?>
                </div>
                <?php endif; ?>
                <?php if (!$slaP && !$slaM): ?>
                <span class="wl-muted">—</span>
                <?php endif; ?>
            </td>

            <!-- Ações -->
            <td class="col-acoes">
                <div class="wl-acoes-wrap">
                    <?php if ($podeAssumir): ?>
                    <button type="button" class="wl-btn-assumir"
                            data-id="<?= $e['id'] ?>"
                            data-paciente="<?= htmlspecialchars($e['patient_name'] ?? '') ?>"
                            title="Assumir para laudo">
                        <i class="fa fa-hand-holding-medical"></i> Assumir
                    </button>
                    <?php elseif ($podeLaudar): ?>
                    <button type="button" class="wl-btn-laudo" title="Módulo de laudo em breve" disabled>
                        <i class="fa fa-file-medical"></i> Laudo
                    </button>
                    <?php endif; ?>

                    <?php if (!empty($e['study_instance_uid']) || !empty($e['orthanc_id'])): ?>
                    <div class="wl-viewer-wrap">
                        <button type="button" class="wl-btn-abrir viewer-trigger" title="Abrir estudo">
                            <i class="fa fa-eye"></i> Abrir <i class="fa fa-caret-down" style="font-size:.55rem;"></i>
                        </button>
                        <div class="wl-viewer-menu">
                            <a href="/estudos/<?= $e['id'] ?>/abrir" class="wl-vm-item" target="_blank">
                                <i class="fa fa-globe"></i> <?= htmlspecialchars(t('viewer_desktop.menu.web')) ?>
                            </a>
                            <a href="/estudos/<?= $e['id'] ?>/abrir-radiant" class="wl-vm-item" target="_blank">
                                <img src="/assets/img/icon-radiant.ico" alt="" class="wl-vm-icon"> <?= htmlspecialchars(t('viewer_desktop.menu.radiant')) ?>
                            </a>
                            <a href="/estudos/<?= $e['id'] ?>/abrir-weasis" class="wl-vm-item" target="_blank">
                                <img src="/assets/img/icon-weasis.svg" alt="" class="wl-vm-icon"> <?= htmlspecialchars(t('viewer_desktop.menu.weasis')) ?>
                            </a>
                        </div>
                    </div>
                    <?php else: ?>
                    <span class="wl-btn-abrir" style="opacity:.3;cursor:not-allowed;" title="Sem UID">
                        <i class="fa fa-eye-slash"></i>
                    </span>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div>

<!-- ═══════════════════════════════════════════════════════════ BARRA DE SELEÇÃO -->
<div id="wl-sel-bar" class="wl-sel-bar" style="display:none;">
    <div class="wl-sel-bar-inner">
        <span id="wl-sel-count" class="wl-sel-count">0 selecionados</span>
        <button type="button" id="btn-download-lote" class="wl-btn-download" onclick="iniciarDownloadLote()">
            <i class="fa fa-download"></i> Download DICOM
        </button>
        <button type="button" class="wl-btn-limpar-sel" onclick="limparSelecao()">
            <i class="fa fa-xmark"></i> Limpar
        </button>
    </div>
    <div id="wl-dl-progress" class="wl-dl-progress" style="display:none;">
        <div class="wl-dl-prog-label" id="wl-dl-prog-label"><i class="fa fa-spinner fa-spin"></i> Preparando download...</div>
        <div class="wl-dl-prog-bar-wrap"><div class="wl-dl-prog-bar" id="wl-dl-prog-bar" style="width:0%"></div></div>
    </div>
</div>
<!-- ═══════════════════════════════════════════════════════════ PAGINAÇÃO -->
<?php if ($totalPages > 1 || $total > 0): ?>
<div class="wl-pagination">
    <span class="wl-pag-info">
        <?php if ($filtros['por_pagina'] > 0 && $total > 0): ?>
            Mostrando <?= number_format(($currentPage-1)*$filtros['por_pagina']+1) ?>–<?= number_format(min($currentPage*$filtros['por_pagina'],$total)) ?>
            de <?= number_format($total) ?> estudos
        <?php else: ?>
            <?= number_format($total) ?> estudos
        <?php endif; ?>
    </span>
    <?php if ($totalPages > 1): ?>
    <div class="wl-pag-links">
        <?php if ($currentPage > 1): ?>
            <a href="<?= estudoUrl($filtros, 1) ?>" class="wl-pag-btn" title="Primeira"><i class="fa fa-angles-left"></i></a>
            <a href="<?= estudoUrl($filtros, $currentPage-1) ?>" class="wl-pag-btn"><i class="fa fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php
        $start = max(1, $currentPage-2); $end = min($totalPages, $currentPage+2);
        if ($start > 1) echo '<span class="wl-pag-btn" style="pointer-events:none;opacity:.4;">…</span>';
        for ($pg = $start; $pg <= $end; $pg++):
        ?>
            <a href="<?= estudoUrl($filtros, $pg) ?>" class="wl-pag-btn <?= $pg===$currentPage?'active':'' ?>"><?= $pg ?></a>
        <?php endfor;
        if ($end < $totalPages) echo '<span class="wl-pag-btn" style="pointer-events:none;opacity:.4;">…</span>';
        ?>
        <?php if ($currentPage < $totalPages): ?>
            <a href="<?= estudoUrl($filtros, $currentPage+1) ?>" class="wl-pag-btn"><i class="fa fa-chevron-right"></i></a>
            <a href="<?= estudoUrl($filtros, $totalPages) ?>" class="wl-pag-btn" title="Última"><i class="fa fa-angles-right"></i></a>
        <?php endif; ?>
    </div>
    <span class="wl-pag-info">Página <?= $currentPage ?> de <?= $totalPages ?></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════ ESTILOS -->
<style>
/* ── Reset / base ───────────────────────────────────────────────────────── */
.wl-muted{color:var(--pacs-text-muted);font-size:.72rem;}
.wl-sep{color:var(--pacs-text-muted);font-size:.75rem;}
.wl-sep-dot{color:var(--pacs-border);margin:0 .2rem;}
.wl-divider{width:1px;height:18px;background:var(--pacs-border);margin:0 .3rem;flex-shrink:0;}

/* ── Resumo ─────────────────────────────────────────────────────────────── */
.wl-resumo{display:flex;justify-content:space-between;align-items:center;gap:.5rem;margin-bottom:.75rem;flex-wrap:wrap;}
.wl-resumo-cards{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;}
.wl-resumo-right{display:flex;align-items:center;gap:.5rem;}
.wl-card{background:var(--pacs-surface);border:1px solid var(--pacs-border);border-radius:8px;
    padding:.4rem .85rem;min-width:82px;text-align:center;cursor:pointer;
    transition:border-color .15s,background .15s;display:flex;flex-direction:column;gap:.05rem;}
.wl-card:hover{border-color:var(--pacs-primary);background:rgba(79,195,247,.06);}
.wl-card-active{border-color:var(--pacs-primary);}
.wl-card-urgente{border-color:#f97316;}
.wl-card-urgente:hover{background:rgba(249,115,22,.08);}
.wl-card-total{border-color:var(--pacs-primary);}
.wl-card-num{font-size:1.35rem;font-weight:700;color:var(--pacs-text-primary);line-height:1.2;}
.wl-card-urgente .wl-card-num{color:#f97316;}
.wl-card-total .wl-card-num{color:var(--pacs-primary);}
.wl-card-lbl{font-size:.62rem;color:var(--pacs-text-muted);text-transform:uppercase;letter-spacing:.04em;}
.wl-sinc{font-size:.68rem;color:var(--pacs-text-muted);display:flex;align-items:center;gap:.25rem;
    padding:.2rem .5rem;border-left:1px solid var(--pacs-border);}

/* ── Filtros ────────────────────────────────────────────────────────────── */
.wl-filters{display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;margin-bottom:.3rem;}
.wl-input{background:var(--pacs-surface);border:1px solid var(--pacs-border);border-radius:6px;
    color:var(--pacs-text-primary);padding:.3rem .55rem;font-size:.78rem;outline:none;height:30px;}
.wl-input:focus{border-color:var(--pacs-primary);}
.wl-input-sm{height:26px;font-size:.72rem;padding:.15rem .45rem;}
.wl-select{background:var(--pacs-surface);border:1px solid var(--pacs-border);border-radius:6px;
    color:var(--pacs-text-primary);padding:.3rem .55rem;font-size:.78rem;height:30px;cursor:pointer;}
.wl-select-sm{height:26px;font-size:.72rem;padding:.1rem .4rem;}
.wl-btn-primary{background:var(--pacs-primary);color:#fff;border:none;border-radius:6px;
    padding:.3rem .85rem;font-size:.78rem;font-weight:600;cursor:pointer;height:30px;
    display:inline-flex;align-items:center;gap:.3rem;white-space:nowrap;}
.wl-btn-primary:hover{opacity:.88;}
.wl-btn-outline{background:transparent;border:1px solid var(--pacs-border);border-radius:6px;
    color:var(--pacs-text-secondary);padding:.3rem .75rem;font-size:.78rem;cursor:pointer;height:30px;
    display:inline-flex;align-items:center;gap:.3rem;text-decoration:none;white-space:nowrap;}
.wl-btn-outline:hover{border-color:var(--pacs-primary);color:var(--pacs-primary);}
.wl-mod-btn{background:var(--pacs-surface);border:1px solid var(--pacs-border);border-radius:4px;
    color:var(--pacs-text-muted);font-size:.65rem;font-weight:600;padding:.1rem .3rem;cursor:pointer;
    height:22px;transition:all .12s;white-space:nowrap;}
.wl-mod-btn:hover{border-color:var(--pacs-primary);color:var(--pacs-primary);}
.wl-mod-btn.active{background:var(--pacs-primary);border-color:var(--pacs-primary);color:#fff;}
.wl-medico-wrap{display:flex;align-items:center;gap:.25rem;}
.wl-info-right{display:flex;align-items:center;gap:.3rem;font-size:.7rem;color:var(--pacs-text-muted);
    margin-left:auto;flex-wrap:wrap;}
.wl-period-badge{background:rgba(79,195,247,.15);color:var(--pacs-primary);border-radius:4px;
    padding:.05rem .35rem;font-size:.63rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;}

/* ── Tabela ─────────────────────────────────────────────────────────────── */
.wl-table-wrap{overflow-x:auto;max-height:calc(100vh - 230px);border-radius:8px;
    border:1px solid var(--pacs-border);margin-top:.4rem;}
.wl-table{width:100%;border-collapse:collapse;font-size:.78rem;}
.wl-table thead th{background:var(--pacs-surface-2, var(--pacs-surface));
    border-bottom:2px solid var(--pacs-border);padding:.45rem .55rem;
    white-space:nowrap;font-size:.7rem;font-weight:600;color:var(--pacs-text-secondary);
    text-transform:uppercase;letter-spacing:.03em;position:sticky;top:0;z-index:2;}
.wl-table tbody tr{border-bottom:1px solid var(--pacs-border);transition:background .1s;}
.wl-table tbody tr:hover{background:rgba(79,195,247,.04);}
.wl-table td{padding:.45rem .55rem;vertical-align:middle;}

/* Larguras das colunas */
.col-check{width:24px;text-align:center;}
.col-dt{width:88px;}
.col-paciente{min-width:180px;max-width:240px;}
.col-unidade{width:110px;font-size:.72rem;color:var(--pacs-text-secondary);}
.col-modalidades{width:90px;text-align:center;}
.col-prioridade{width:100px;text-align:center;}
.col-estudo{min-width:150px;}
.study-description{display:block;font-size:11px;font-weight:600;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px;}
/* Badges de prioridade DICOM */
.wl-prio-badge{display:inline-block;padding:.18rem .55rem;border-radius:20px;font-size:.68rem;font-weight:700;letter-spacing:.03em;white-space:nowrap;}
.wl-prio-emergencia{background:#DC2626;color:#fff;}
.wl-prio-urgencia{background:#F97316;color:#fff;}
.wl-prio-rotina{background:#3B82F6;color:#fff;}
.wl-prio-ambulatorial{background:#22C55E;color:#fff;}
.col-solicitante{width:150px;}
.col-sit{width:100px;}
.col-sla{width:88px;text-align:center;}
.col-acoes{width:140px;text-align:center;}

/* Linhas especiais */
.row-urgente td:first-child{border-left:3px solid #f97316;}
.row-critico td:first-child{border-left:3px solid #ef4444;}

/* ── Ícones de sexo ─────────────────────────────────────────────────────── */
.sexo-m{color:#3b82f6;font-size:.9rem;flex-shrink:0;}
.sexo-f{color:#ec4899;font-size:.9rem;flex-shrink:0;}
.sexo-nd{color:var(--pacs-text-muted);font-size:.8rem;flex-shrink:0;}

/* ── Célula paciente ────────────────────────────────────────────────────── */
.wl-pac-row{display:flex;align-items:center;gap:.4rem;}
.wl-pac-info{min-width:0;}
.wl-pac-nome{font-weight:600;font-size:.78rem;color:var(--pacs-text-primary);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;}
.wl-pac-sub{font-size:.67rem;color:var(--pacs-text-muted);margin-top:.05rem;}

/* ── Célula estudo ──────────────────────────────────────────────────────── */



.bp-badge{display:inline-flex;align-items:center;justify-content:center;
    padding:.1rem .38rem;border-radius:4px;
    font-size:.62rem;font-weight:700;letter-spacing:.03em;
    margin:.05rem .05rem .05rem 0;white-space:nowrap;}




/* ── Badges de modalidade ───────────────────────────────────────────────── */
.mod-badge{display:inline-block;border-radius:3px;padding:.05rem .28rem;
    font-size:.62rem;font-weight:700;letter-spacing:.03em;
    background:rgba(79,195,247,.15);color:var(--pacs-primary);border:1px solid rgba(79,195,247,.3);}
.mod-CT{background:rgba(239,68,68,.12);color:#dc2626;border-color:rgba(239,68,68,.25);}
.mod-MR{background:rgba(124,58,237,.12);color:#7c3aed;border-color:rgba(124,58,237,.25);}
.mod-US{background:rgba(16,185,129,.12);color:#059669;border-color:rgba(16,185,129,.25);}
.mod-XA,.mod-CR,.mod-DR{background:rgba(234,179,8,.12);color:#a16207;border-color:rgba(234,179,8,.25);}
.mod-MG{background:rgba(236,72,153,.12);color:#be185d;border-color:rgba(236,72,153,.25);}
.mod-NM{background:rgba(249,115,22,.12);color:#c2410c;border-color:rgba(249,115,22,.25);}

/* ── Badges de situação ─────────────────────────────────────────────────── */
.sit-badge{display:inline-block;border-radius:4px;padding:.12rem .4rem;
    font-size:.65rem;font-weight:700;letter-spacing:.04em;white-space:nowrap;}
.sit-novo    {background:#f1f5f9;color:#475569;}
.sit-aberto  {background:#eff6ff;color:#1d4ed8;}
.sit-a-laudar{background:#fff7ed;color:#c2410c;border:1px solid rgba(194,65,12,.25);}
.sit-em-laudo{background:#f5f3ff;color:#7c3aed;}
.sit-rascunho{background:#fefce8;color:#a16207;}
.sit-assinado{background:#ecfdf5;color:#065f46;}
.sit-liberado{background:#f0fdf4;color:#059669;}
.sit-urgente {background:#fef2f2;color:#dc2626;}
.wl-assumido-por{font-size:.62rem;color:var(--pacs-text-muted);margin-top:.15rem;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:95px;}

/* ── SLA pills ──────────────────────────────────────────────────────────── */
.sla-pill{display:inline-flex;align-items:center;gap:.2rem;border-radius:4px;
    padding:.1rem .32rem;font-size:.65rem;font-weight:600;white-space:nowrap;
    line-height:1.3;margin-bottom:.1rem;}
.sla-med{margin-top:.1rem;}
.sla-verde  {background:rgba(16,185,129,.12); color:#059669;}
.sla-amarelo{background:rgba(234,179,8,.15);  color:#a16207;}
.sla-laranja{background:rgba(249,115,22,.15); color:#c2410c;}
.sla-vermelho{background:rgba(239,68,68,.15); color:#dc2626;}

/* ── Solicitante ────────────────────────────────────────────────────────── */
.wl-sol-tag{font-size:.7rem;color:var(--pacs-text-secondary);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;max-width:145px;}

/* ── Ações ──────────────────────────────────────────────────────────────── */
.wl-acoes-wrap{display:flex;flex-direction:column;gap:.25rem;align-items:center;}
.wl-btn-assumir{background:linear-gradient(135deg,#0ea5e9,#0284c7);color:#fff;
    border:none;border-radius:5px;padding:.22rem .55rem;font-size:.7rem;font-weight:600;
    cursor:pointer;display:inline-flex;align-items:center;gap:.22rem;white-space:nowrap;
    transition:opacity .15s,transform .1s;width:100%;justify-content:center;}
.wl-btn-assumir:hover{opacity:.88;transform:scale(1.02);}
.wl-btn-assumir:disabled{opacity:.4;cursor:not-allowed;}
.wl-btn-laudo{background:rgba(124,58,237,.12);color:#7c3aed;border:1px solid rgba(124,58,237,.3);
    border-radius:5px;padding:.22rem .55rem;font-size:.7rem;font-weight:600;cursor:not-allowed;
    display:inline-flex;align-items:center;gap:.22rem;white-space:nowrap;width:100%;justify-content:center;}
.wl-viewer-wrap{position:relative;width:100%;}
.wl-btn-abrir{background:var(--pacs-primary);color:#fff;border:none;border-radius:5px;
    padding:.22rem .55rem;font-size:.7rem;font-weight:600;cursor:pointer;
    display:inline-flex;align-items:center;gap:.22rem;white-space:nowrap;width:100%;
    justify-content:center;transition:opacity .15s;}
.wl-btn-abrir:hover{opacity:.88;}
.wl-viewer-menu{display:none;position:absolute;right:0;top:calc(100% + 3px);z-index:50;
    background:var(--pacs-surface);border:1px solid var(--pacs-border);border-radius:6px;
    box-shadow:0 4px 16px rgba(0,0,0,.25);min-width:160px;overflow:hidden;}
.wl-viewer-menu.show{display:block;}
.wl-vm-item{display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;
    font-size:.75rem;color:var(--pacs-text-secondary);text-decoration:none;white-space:nowrap;}
.wl-vm-item:hover{background:rgba(79,195,247,.08);color:var(--pacs-primary);}
.wl-vm-icon{width:14px;height:14px;object-fit:contain;}

/* ── Prioridade ─────────────────────────────────────────────────────────── */
.prio-urgente{color:#f97316;margin-right:.2rem;}
.prio-critico{color:#ef4444;margin-right:.2rem;}

/* ── Data/Hora ──────────────────────────────────────────────────────────── */
.wl-date{font-size:.78rem;font-weight:500;color:var(--pacs-text-primary);}
.wl-time{font-size:.67rem;color:var(--pacs-text-muted);}

/* ── Sort link ──────────────────────────────────────────────────────────── */
.sort-link{color:inherit;text-decoration:none;white-space:nowrap;display:inline-flex;align-items:center;gap:.2rem;}
.sort-link:hover{color:var(--pacs-primary);}

/* ── Empty state ────────────────────────────────────────────────────────── */
.wl-empty{text-align:center;padding:3rem 1rem;color:var(--pacs-text-secondary);}
.wl-empty i{font-size:2rem;opacity:.35;display:block;margin-bottom:.75rem;}
.wl-empty a{color:var(--pacs-primary);}

/* ── Paginação ──────────────────────────────────────────────────────────── */
.wl-pagination{display:flex;align-items:center;gap:.5rem;padding:.25rem 0 .15rem;flex-wrap:wrap;}
.wl-pag-info{font-size:.72rem;color:var(--pacs-text-muted);}
.wl-pag-links{display:flex;gap:.2rem;margin:0 auto;}
.wl-pag-btn{display:inline-flex;align-items:center;justify-content:center;
    min-width:28px;height:28px;border-radius:5px;font-size:.75rem;
    background:var(--pacs-surface);border:1px solid var(--pacs-border);
    color:var(--pacs-text-secondary);text-decoration:none;padding:0 .4rem;
    transition:all .12s;cursor:pointer;}
.wl-pag-btn:hover{border-color:var(--pacs-primary);color:var(--pacs-primary);}
.wl-pag-btn.active{background:var(--pacs-primary);border-color:var(--pacs-primary);color:#fff;}

/* ── Responsividade ─────────────────────────────────────────────────────── */
@media (max-width: 1200px) {
    .col-solicitante{display:none;}
    .col-unidade{width:100px;}
    .col-modalidades{display:none;}
}
@media (max-width: 900px) {
    .wl-filters-row1{flex-wrap:wrap;}

    .wl-pac-nome{max-width:130px;}
}
@media (max-width: 640px) {
    .wl-resumo{flex-direction:column;align-items:flex-start;}
    .wl-table-wrap{max-height:none;}
    .col-unidade,.col-solicitante,.col-modalidades{display:none;}
}
/* ── Barra de Seleção / Download em Lote ─────────────────────────────── */
.wl-sel-bar{background:var(--pacs-surface);border:1px solid var(--pacs-primary);border-radius:8px;
    padding:.5rem .85rem;margin:.4rem 0;box-shadow:0 2px 8px rgba(14,165,233,.15);}
.wl-sel-bar-inner{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;}
.wl-sel-count{font-size:.8rem;font-weight:600;color:var(--pacs-primary);min-width:160px;}
.wl-btn-download{background:var(--pacs-primary);color:#fff;border:none;border-radius:6px;
    padding:.3rem .85rem;font-size:.78rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:.35rem;
    transition:background .15s;}
.wl-btn-download:hover:not(:disabled){background:#0284c7;}
.wl-btn-download:disabled{opacity:.55;cursor:not-allowed;}
.wl-btn-limpar-sel{background:transparent;border:1px solid var(--pacs-border);border-radius:6px;
    color:var(--pacs-text-muted);padding:.3rem .65rem;font-size:.75rem;cursor:pointer;
    transition:border-color .15s,color .15s;}
.wl-btn-limpar-sel:hover{border-color:var(--pacs-text-muted);color:var(--pacs-text-primary);}
.wl-dl-progress{margin-top:.4rem;}
.wl-dl-prog-label{font-size:.75rem;color:var(--pacs-text-muted);margin-bottom:.3rem;}
.wl-dl-prog-bar-wrap{background:var(--pacs-bg);border-radius:4px;height:6px;overflow:hidden;}
.wl-dl-prog-bar{height:100%;background:var(--pacs-primary);border-radius:4px;
    transition:width .3s ease,background .3s;}
/* checkbox desabilitado */
.row-check:disabled{opacity:.3;cursor:not-allowed;}

/* ── Worklist: elimina padding excessivo do container principal ─────────── */
/* O #pacs-page tem padding:1.25rem global; na worklist queremos padding mínimo
   para maximizar a área visível de estudos na tela do médico.              */
#pacs-page{
    padding:.5rem .75rem .25rem;
}
</style>

<!-- ═══════════════════════════════════════════════════════════ JAVASCRIPT -->
<script>
function toggleModalidade(mod) {
    const container = document.getElementById('modalidadesInputs');
    const existing  = container.querySelector('input[value="' + mod + '"]');
    if (existing) {
        existing.remove();
    } else {
        const inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'modalidades[]';
        inp.value = mod;
        container.appendChild(inp);
    }
    document.querySelectorAll('.wl-mod-btn').forEach(b => {
        b.classList.toggle('active', !!container.querySelector('input[value="' + b.dataset.mod + '"]'));
    });
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
    // Navega direto pela URL em vez de depender de um campo do form existir no
    // DOM — corrige o card "Urgentes" (campo=prioridade), que não tem select
    // visível na tela e por isso nunca filtrava nada antes desta correção.
    const url = new URL(window.location.href);
    url.searchParams.set(campo, valor);
    url.searchParams.delete('pagina');
    window.location.href = url.pathname + '?' + url.searchParams.toString();
}

// ── Auto-submit dos filtros — nenhuma alteração exige clique manual em
//    "Buscar" nem recarregar a tela pelo menu lateral (causa raiz do bug:
//    a maioria dos campos de filtro não tinha listener de 'change' nenhum;
//    só situacao_rapida/por_pagina/modalidade já disparavam sozinhos, o que
//    tornava o comportamento inconsistente entre os campos da mesma tela).
(function () {
    const form = document.getElementById('formFiltros');
    if (!form) return;

    // Selects e datas: submit imediato ao mudar. "periodo" fica de fora —
    // já tem lógica própria (toggleDatasPersonalizadas) para não submeter
    // antes de o usuário escolher as datas do período personalizado.
    form.querySelectorAll('select:not([name="periodo"]), input[type="date"]').forEach(el => {
        el.addEventListener('change', () => form.submit());
    });

    // Campos de texto livre (Pesquisar, Nome do paciente, Solicitante):
    // submit após uma pausa de digitação, para não gerar 1 requisição por tecla.
    let debounceTimer = null;
    form.querySelectorAll('input[type="text"]').forEach(el => {
        el.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => form.submit(), 600);
        });
    });
})();
const MAX_SEL = 5;
function atualizarBarraSel() {
    const selecionados = document.querySelectorAll('.row-check:checked');
    const n = selecionados.length;
    const bar = document.getElementById('wl-sel-bar');
    const cnt = document.getElementById('wl-sel-count');
    bar.style.display = n > 0 ? 'block' : 'none';
    cnt.textContent = n + ' estudo' + (n !== 1 ? 's' : '') + ' selecionado' + (n !== 1 ? 's' : '') + (n >= MAX_SEL ? ' (máx. ' + MAX_SEL + ')' : '');
    // Bloqueia checkboxes não selecionados se atingiu o limite
    document.querySelectorAll('.row-check').forEach(c => {
        if (!c.checked) c.disabled = n >= MAX_SEL;
    });
    // Atualiza checkAll
    const todos = document.querySelectorAll('.row-check');
    const checkAll = document.getElementById('checkAll');
    if (checkAll) checkAll.checked = todos.length > 0 && n === todos.length;
}
function toggleAll(master) {
    const checks = document.querySelectorAll('.row-check');
    let count = 0;
    checks.forEach(c => {
        if (master.checked && count < MAX_SEL) { c.checked = true; c.disabled = false; count++; }
        else if (!master.checked) { c.checked = false; c.disabled = false; }
    });
    atualizarBarraSel();
}
function limparSelecao() {
    document.querySelectorAll('.row-check').forEach(c => { c.checked = false; c.disabled = false; });
    const checkAll = document.getElementById('checkAll');
    if (checkAll) checkAll.checked = false;
    atualizarBarraSel();
}
// Listener nos checkboxes de linha
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('row-check')) atualizarBarraSel();
});

// Duplo clique para abrir
document.querySelectorAll('.wl-table tbody tr[data-id]').forEach(row => {
    row.addEventListener('dblclick', function(e) {
        if (e.target.closest('a,button,input')) return;
        window.open('/estudos/' + this.dataset.id + '/abrir', '_blank');
    });
});

// ── Menu Abrir (dropdown) ────────────────────────────────────────────────
(function () {
    let menuAberto = null;
    function fechar() { if (menuAberto) { menuAberto.classList.remove('show'); menuAberto = null; } }
    document.addEventListener('click', function(e) {
        const trigger = e.target.closest('.viewer-trigger');
        if (trigger) {
            e.preventDefault(); e.stopPropagation();
            const menu = trigger.parentElement.querySelector('.wl-viewer-menu');
            const jaAberto = menu === menuAberto;
            fechar();
            if (!jaAberto) { menu.classList.add('show'); menuAberto = menu; }
            return;
        }
        if (!e.target.closest('.wl-viewer-menu')) fechar();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') fechar(); });
})();

// ── Botão Assumir (AJAX) ─────────────────────────────────────────────────
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.wl-btn-assumir');
    if (!btn) return;
    e.stopPropagation();
    const estudoId = btn.dataset.id;
    const paciente = btn.dataset.paciente || 'este estudo';
    if (!confirm('Assumir o estudo de ' + paciente + '?\nO status será alterado para A LAUDAR.')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Assumindo...';
    fetch('/api/estudos/assumir', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        body: JSON.stringify({estudo_id: parseInt(estudoId)})
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            const row = btn.closest('tr');
            // Atualiza badge de situação
            const sitCell = row ? row.querySelector('.sit-badge') : null;
            if (sitCell) { sitCell.className = 'sit-badge sit-a-laudar'; sitCell.textContent = 'A LAUDAR'; }
            // Substitui botão
            btn.outerHTML = '<button type="button" class="wl-btn-laudo" disabled title="Laudo em breve"><i class="fa fa-file-medical"></i> Laudo</button>';
            // Flash na linha
            if (row) {
                row.style.transition = 'background .4s';
                row.style.background = 'rgba(14,165,233,.08)';
                setTimeout(() => { row.style.background = ''; }, 1200);
            }
        } else {
            alert('Não foi possível assumir: ' + (data.msg || 'Erro desconhecido'));
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-hand-holding-medical"></i> Assumir';
        }
    })
    .catch(() => {
        alert('Erro de comunicação. Tente novamente.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-hand-holding-medical"></i> Assumir';
    });
});

// ── Download em Lote ─────────────────────────────────────────────────────
function iniciarDownloadLote() {
    const ids = Array.from(document.querySelectorAll('.row-check:checked')).map(c => parseInt(c.value));
    // Captura o nome do primeiro paciente selecionado para nomear o ZIP
    const primeiraLinha = document.querySelector('.row-check:checked');
    const nomePaciente  = primeiraLinha
        ? (primeiraLinha.closest('tr')?.querySelector('.wl-pac-nome')?.textContent?.trim() || 'PACIENTE')
        : 'PACIENTE';
    window._dlPaciente = nomePaciente;
    if (ids.length === 0) { alert('Selecione ao menos 1 estudo.'); return; }
    if (ids.length > MAX_SEL) { alert('Máximo de ' + MAX_SEL + ' estudos por download.'); return; }
    const btn  = document.getElementById('btn-download-lote');
    const prog = document.getElementById('wl-dl-progress');
    const bar  = document.getElementById('wl-dl-prog-bar');
    const lbl  = document.getElementById('wl-dl-prog-label');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Iniciando...';
    prog.style.display = 'block';
    bar.style.width = '5%';
    lbl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Criando archive no Orthanc...';
    fetch('/api/download-lote/iniciar', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        body: JSON.stringify({estudo_ids: ids})
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) throw new Error(data.msg || 'Erro ao iniciar job');
        pollJob(data.job_id, data.log_id);
    })
    .catch(err => {
        resetDownloadUI();
        alert('Erro ao iniciar download: ' + err.message);
    });
}
function pollJob(jobId, logId, tentativa = 0) {
    const MAX_TENTATIVAS = 120; // ~2 min
    const bar = document.getElementById('wl-dl-prog-bar');
    const lbl = document.getElementById('wl-dl-prog-label');
    if (tentativa > MAX_TENTATIVAS) {
        resetDownloadUI();
        alert('Timeout: o Orthanc demorou demais para gerar o arquivo.');
        return;
    }
    fetch('/api/download-lote/status?job_id=' + encodeURIComponent(jobId) + '&log_id=' + encodeURIComponent(logId), {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) throw new Error(data.msg || 'Erro no polling');
        const pct = Math.max(5, Math.min(90, data.progress || 0));
        bar.style.width = pct + '%';
        if (data.state === 'Success') {
            bar.style.width = '100%';
            lbl.innerHTML = '<i class="fa fa-check"></i> Pronto! Iniciando download...';
            bar.style.background = '#22c55e';
            setTimeout(() => {
                const patient = encodeURIComponent(window._dlPaciente || 'PACIENTE');
                window.location.href = '/api/download-lote/baixar-inteligente?job_id=' + encodeURIComponent(jobId) + '&log_id=' + encodeURIComponent(logId) + '&patient=' + patient;
                setTimeout(resetDownloadUI, 3000);
            }, 600);
        } else if (data.state === 'Failure') {
            throw new Error('O Orthanc falhou ao gerar o archive.');
        } else {
            lbl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processando... ' + (data.progress || 0) + '%';
            setTimeout(() => pollJob(jobId, logId, tentativa + 1), 1000);
        }
    })
    .catch(err => {
        resetDownloadUI();
        alert('Erro no download: ' + err.message);
    });
}
function resetDownloadUI() {
    const btn  = document.getElementById('btn-download-lote');
    const prog = document.getElementById('wl-dl-progress');
    const bar  = document.getElementById('wl-dl-prog-bar');
    if (btn)  { btn.disabled = false; btn.innerHTML = '<i class="fa fa-download"></i> Download DICOM'; }
    if (prog) prog.style.display = 'none';
    if (bar)  { bar.style.width = '0%'; bar.style.background = ''; }
}

// ── Botão VOXEL Desktop ─────────────────────────────────────────────────────
(function () {
    const btn   = document.getElementById('btn-voxel-desktop');
    const label = document.getElementById('vd-label');
    if (!btn) return;

    // Detecta se o VOXEL Desktop está instalado tentando abrir o protocolo voxel://
    // e verificando se a aba permanece visível (heurística padrão de mercado)
    function detectarInstalado(cb) {
        const iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        document.body.appendChild(iframe);
        let respondeu = false;
        const t = setTimeout(function () {
            if (!respondeu) cb(false);
            document.body.removeChild(iframe);
        }, 800);
        try {
            iframe.src = 'voxel://ping';
            // Se o protocolo estiver registrado, o navegador não vai lançar erro
            // Consideramos instalado após 300ms sem erro
            setTimeout(function () {
                respondeu = true;
                clearTimeout(t);
                cb(true);
                document.body.removeChild(iframe);
            }, 300);
        } catch (e) {
            respondeu = true;
            clearTimeout(t);
            cb(false);
            document.body.removeChild(iframe);
        }
    }

    // Verifica se já foi detectado nesta sessão (sessionStorage)
    const cached = sessionStorage.getItem('voxel_desktop_instalado');
    if (cached === '1') {
        btn.classList.add('vd-instalado');
        label.textContent = 'VOXEL Desktop Instalado';
        btn.title = 'Abrir VOXEL Desktop';
    } else if (cached !== '0') {
        // Primeira visita: tenta detectar silenciosamente
        detectarInstalado(function (instalado) {
            sessionStorage.setItem('voxel_desktop_instalado', instalado ? '1' : '0');
            if (instalado) {
                btn.classList.add('vd-instalado');
                label.textContent = 'VOXEL Desktop Instalado';
                btn.title = 'Abrir VOXEL Desktop';
            }
        });
    }

    btn.addEventListener('click', function () {
        const instalado = sessionStorage.getItem('voxel_desktop_instalado') === '1';
        if (instalado) {
            // Abre o VOXEL Desktop sem estudo específico
            window.location.href = 'voxel://open';
        } else {
            // Detecta OS e faz download do instalador
            const ua = navigator.userAgent || '';
            let platform = 'windows';
            if (/Mac/i.test(ua))   platform = 'mac';
            if (/Linux/i.test(ua) && !/Android/i.test(ua)) platform = 'linux';
            window.location.href = '/desktop/download?platform=' + platform;
        }
    });
}());
</script>
