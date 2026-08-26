<?php
/**
 * VOXEL PACS — Worklist de Estudos (v4)
 * Layout reformulado: compacto, ícones de sexo coloridos, SLA semafórico, Ações espaçosas.
 */

/* ─── helpers de URL ─────────────────────────────────────────────────────── */
function estudoUrl(array $filtros, int $pagina = 1): string {
    global $urlWorklist;
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
    return ($urlWorklist ?? '/estudos') . '?' . $query;
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
        'pendente' => ['sit-pendente', 'PENDENTE'],
        'a_laudar' => ['sit-a-laudar', 'A LAUDAR'],
        'em_laudo' => ['sit-em-laudo', 'EM LAUDO'],
        'rascunho' => ['sit-rascunho', 'RASCUNHO'],
        'assinado' => ['sit-assinado', 'ASSINADO'],
        'liberado' => ['sit-liberado', 'LIBERADO'],
        'peer_review' => ['sit-peer-review', 'PEER REVIEW'],
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
/* ─── renderiza coluna ESTUDO com fallback clínico de descrições DICOM ─── */
function renderEstudo(array $e): string {
    $resolved = \App\Services\StudyDescriptionResolver::resolve($e);
    $texto = $resolved['description'];
    $tooltip = htmlspecialchars(
        $resolved['tag'] !== ''
            ? 'Tag DICOM ' . $resolved['tag'] . ' ' . $resolved['source'] . ': ' . ($texto !== '' ? $texto : 'vazia')
            : $resolved['source']
    );
    $display = $texto !== '' ? htmlspecialchars($texto) : 'SEM DESCRIÇÃO';

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

/* ─── badge de achado crítico: atributo clínico independente de prioridade ───── */
function achadoCriticoBadge(?string $achadoCriticoEm, ?string $assunto): string {
    if (empty($achadoCriticoEm)) return '';
    $title = 'Achado Crítico comunicado em ' . date('d/m/Y H:i', strtotime($achadoCriticoEm));
    if (trim((string) $assunto) !== '') $title .= ': ' . trim((string) $assunto);
    return sprintf(
        '<span class="achado-critico-badge" title="%s"><i class="fa fa-notes-medical"></i> Achado crítico</span>',
        htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
    );
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
$urlWorklist         = $urlWorklist ?? '/estudos';
$modoGestao          = !empty($modoGestao);
$podeGerenciarPedido = !empty($podeGerenciarPedido);
$csrfToken           = $csrfToken ?? '';
// Controle de visibilidade da coluna Médico:
//   $isMedicoLogado      = true se o usuário logado é um médico cadastrado
//   $medicoLogadoNome    = nome do médico logado (string ou null)
//   $podeVerMedicoLaudo  = true se o médico tem permissão para ver outros médicos
//   $isAdmin             = true para administradores (vêm sempre)
// Regras:
//   - Admin / não-médico: sempre vê o nome do médico responsável
//   - Médico sem permissão: vê apenas o próprio nome (quando é o responsável)
//   - Médico com permissão (ver_medico_laudo=1): vê qualquer médico responsável
$isMedicoLogado     = !empty($isMedicoLogado);
$medicoLogadoNome   = $medicoLogadoNome ?? null;
$podeVerMedicoLaudo = !empty($podeVerMedicoLaudo);
$isAdmin            = !empty($isAdmin);
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
        <i class="fa <?= $modoGestao ? 'fa-clipboard-list' : 'fa-list-check' ?>"></i>
        <span><?= htmlspecialchars($modoGestao ? t('gestao_exames.titulo') : 'Worklist de Estudos') ?></span>
        <?php if ($modoGestao): ?><span class="wl-mode-badge"><?= htmlspecialchars(t('gestao_exames.badge')) ?></span><?php endif; ?>
    </div>
    <a href="/estudos/instalar" class="wl-pwa-btn" title="Instalar app da Worklist no seu computador">
        <i class="fa fa-download"></i> Instalar App
    </a>
    <button type="button" id="btn-voxel-desktop" class="wl-desktop-btn" title="Baixar o VOXEL Desktop — visualizador oficial VOXEL PACS">
        <i class="fa fa-desktop"></i> <span id="vd-label">VOXEL Desktop</span>
    </button>
</div>

<!-- ═══════════════════════════════════════════════════════════ RESUMO (oculto — ganho de espaço vertical) -->
<div class="wl-resumo" style="<?= ($resumo['achados_criticos'] ?? 0) > 0 ? '' : 'display:none;' ?>">
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
        <?php if (($resumo['achados_criticos'] ?? 0) > 0): ?>
        <div class="wl-card wl-card-achado-critico" title="Achados Críticos comunicados pelo CHAT">
            <span class="wl-card-num"><?= number_format($resumo['achados_criticos']) ?></span>
            <span class="wl-card-lbl"><i class="fa fa-notes-medical"></i> Achados Críticos</span>
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
<form id="formFiltros" method="GET" action="<?= htmlspecialchars($urlWorklist) ?>" autocomplete="off">
    <button type="button" id="wl-mobile-filters-toggle" class="wl-mobile-filters-toggle"
            aria-expanded="false"
            data-open-label="<?= htmlspecialchars(t('worklist.mobile.filtros_abrir'), ENT_QUOTES) ?>"
            data-close-label="<?= htmlspecialchars(t('worklist.mobile.filtros_fechar'), ENT_QUOTES) ?>">
        <i class="fa fa-sliders"></i>
        <span><?= htmlspecialchars(t('worklist.mobile.filtros_abrir')) ?></span>
    </button>

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

    <select name="situacao" id="selectSituacao" class="wl-select" style="width:138px;"
            onchange="this.form.elements['situacao_rapida'].value='';">
        <option value="">Todas as situações</option>
        <option value="novo"     <?= $filtros['situacao']==='novo'?'selected':'' ?>>NOVO</option>
        <option value="aberto"   <?= $filtros['situacao']==='aberto'?'selected':'' ?>>ABERTO</option>
        <option value="pendente" <?= $filtros['situacao']==='pendente'?'selected':'' ?>>PENDENTE</option>
        <option value="a_laudar" <?= $filtros['situacao']==='a_laudar'?'selected':'' ?>>A LAUDAR</option>
        <option value="em_laudo" <?= $filtros['situacao']==='em_laudo'?'selected':'' ?>>EM LAUDO</option>
        <option value="rascunho" <?= $filtros['situacao']==='rascunho'?'selected':'' ?>>RASCUNHO</option>
        <option value="assinado" <?= $filtros['situacao']==='assinado'?'selected':'' ?>>ASSINADO</option>
        <option value="liberado" <?= $filtros['situacao']==='liberado'?'selected':'' ?>>LIBERADO</option>
        <option value="peer_review" <?= $filtros['situacao']==='peer_review'?'selected':'' ?>>PEER REVIEW</option>
    </select>

    <button type="submit" class="wl-btn-primary"><i class="fa fa-magnifying-glass"></i> Buscar</button>
    <?php if ($temFiltroAtivo): ?>
    <a href="<?= htmlspecialchars($urlWorklist) ?>" class="wl-btn-outline"><i class="fa fa-xmark"></i> Limpar</a>
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
        <option value="pendente" <?= $filtros['situacao']==='pendente'?'selected':'' ?>>Pendente</option>
        <option value="a_laudar" <?= $filtros['situacao']==='a_laudar'?'selected':'' ?>>A laudar</option>
        <option value="em_laudo" <?= $filtros['situacao']==='em_laudo'?'selected':'' ?>>Em laudo</option>
        <option value="urgente"  <?= $filtros['situacao']==='urgente'?'selected':'' ?>>Urgente</option>
        <option value="rascunho" <?= $filtros['situacao']==='rascunho'?'selected':'' ?>>Rascunho</option>
        <option value="assinado" <?= $filtros['situacao']==='assinado'?'selected':'' ?>>Assinado</option>
        <option value="liberado" <?= $filtros['situacao']==='liberado'?'selected':'' ?>>Liberado</option>
        <option value="peer_review" <?= $filtros['situacao']==='peer_review'?'selected':'' ?>>Peer Review</option>
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
        <span><?= htmlspecialchars(\App\Config\BrandConfig::PACS_SERVER_NAME) ?></span>
        <?php if ($filtros['periodo'] !== 'todos'): ?>
        <span class="wl-period-badge"><?= $periodoLabel ?></span>
        <?php endif; ?>
    </div>
</div>
</form>

<!-- ═══════════════════════════════════════════════════════════ CORPO DA WORKLIST
     Wrapper flex-column com min-height até o fim da viewport — permite que
     .wl-pagination seja empurrado para a borda inferior (margin-top:auto)
     mesmo quando a tabela tem poucos resultados, em vez de "subir" junto com
     um corpo de tabela curto. Ver patterns/layout-rodape-fixo.md. -->
<div class="wl-worklist-body">
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
            <th class="col-medico-laudo" title="Médico responsável pelo laudo">
                <i class="fa fa-user-doctor" style="font-size:.75rem;"></i> Médico
            </th>
            <th class="col-solicitante"><?= sortLink($filtros,'especialidade','Solicitante') ?></th>
            <th class="col-pedido"><?= htmlspecialchars(t('pedido_medico.coluna')) ?></th>
            <th class="col-sit"><?= sortLink($filtros,'situacao','Situação') ?></th>
            <th class="col-sla" title="SLA Padrão e SLA Médico"><i class="fa fa-clock"></i> SLA</th>
            <th class="col-acoes">Ações</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($estudos)): ?>
        <tr>
                <td colspan="13" class="wl-empty">
                <i class="fa fa-magnifying-glass"></i>
                <div>Nenhum estudo encontrado<?= $temFiltroAtivo?' com os filtros aplicados':'' ?>.</div>
                <?php if ($temFiltroAtivo): ?>
                <a href="<?= htmlspecialchars($urlWorklist) ?>">Limpar filtros</a>
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

            // Permissões de ação: o laudo e o peer review são exclusivos do
            // médico que assumiu. usuario_responsavel_id referencia bi_users.id.
            $estudoPertenceAoMedico = (int) ($e['usuario_responsavel_id'] ?? 0) > 0
                && (int) ($e['usuario_responsavel_id'] ?? 0) === (int) ($usuarioLogadoId ?? 0);
            $podeAssumir = $isMedicoLogado && in_array($sit, ['novo','aberto'], true);
            $podeLaudar  = $isMedicoLogado
                && $estudoPertenceAoMedico
                && in_array($sit, ['a_laudar','em_laudo','rascunho'], true);
            $podePeerReview = $isMedicoLogado
                && $estudoPertenceAoMedico
                && in_array($sit, ['assinado', 'liberado'], true)
                && !empty($e['study_instance_uid']);

            // Gestão: consulta administrativa apenas para quem possui a permissão
            // já exigida pelo menu Gerenciar. A rota por token opaco preserva o
            // isolamento do tenant e abre o laudo assinado/liberado em read-only.
            $reportSituacaoGestao = strtolower(trim((string) ($e['report_situacao'] ?? '')));
            $reportTokenGestao = strtolower(trim((string) ($e['report_public_token'] ?? '')));
            $podeConsultarLaudoGestao = $modoGestao
                && $podeGerenciarPedido
                && in_array($reportSituacaoGestao, ['assinado', 'liberado'], true)
                && preg_match('/^[a-f0-9]{48}$/', $reportTokenGestao) === 1;

            // Recebido há
            $recebidoHa = formatarSla($e['recebido_em'] ?? null);
        ?>
        <tr class="<?= $rowClass ?>" data-id="<?= $e['id'] ?>" <?= $modoGestao ? '' : 'title="Duplo clique para abrir"' ?>>
            <!-- Check -->
            <td class="col-check">
                <input type="checkbox" class="row-check" value="<?= $e['id'] ?>">
            </td>

            <!-- Data/Hora -->
            <td class="col-dt" data-label="<?= htmlspecialchars(t('worklist.mobile.coluna.data'), ENT_QUOTES) ?>">
                <?= prioridadeInternaBadge($prio) ?>
                <?= achadoCriticoBadge($e['achado_critico_em'] ?? null, $e['achado_critico_assunto'] ?? null) ?>
                <div class="wl-date"><?= $dtFmt ?></div>
                <?php if ($hrFmt): ?><div class="wl-time"><?= $hrFmt ?></div><?php endif; ?>
            </td>

            <!-- Paciente: ícone sexo + nome + info -->
            <td class="col-paciente" data-label="<?= htmlspecialchars(t('worklist.mobile.coluna.paciente'), ENT_QUOTES) ?>">
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
            <td class="col-unidade" data-label="<?= htmlspecialchars(t('worklist.mobile.coluna.unidade'), ENT_QUOTES) ?>">
                <?= htmlspecialchars($e['institution_name'] ?? '—') ?>
            </td>
            <!-- Modalidades -->
            <td class="col-modalidades" data-label="<?= htmlspecialchars(t('worklist.mobile.coluna.modalidades'), ENT_QUOTES) ?>">
                <?php foreach ($mods as $mod) echo modBadge($mod); ?>
            </td>
            <!-- Prioridade DICOM (0040,1003) -->
            <td class="col-prioridade" data-label="<?= htmlspecialchars(t('worklist.mobile.coluna.prioridade'), ENT_QUOTES) ?>">
                <?= prioridadeBadge($e['dicom_priority_effective'] ?? ($e['dicom_priority'] ?? ''), 'pt_BR') ?>
            </td>
            <!-- Estudo: apenas study_description -->
            <td class="col-estudo" data-label="<?= htmlspecialchars(t('worklist.mobile.coluna.estudo'), ENT_QUOTES) ?>">
                <?= renderEstudo($e) ?>
            </td>

            <!-- Médico responsável pelo laudo -->
            <td class="col-medico-laudo" data-label="<?= htmlspecialchars(t('worklist.mobile.coluna.medico'), ENT_QUOTES) ?>">
                <?php
                // Regra de visibilidade:
                //   1. Se não há médico assumido: exibe —
                //   2. Admin ou não-médico: exibe sempre o nome
                //   3. Médico com permissão (ver_medico_laudo): exibe sempre o nome
                //   4. Médico sem permissão: exibe apenas se for o próprio nome
                $nomeMedicoEstudo = $e['assumido_por'] ?? '';
                $exibirMedico     = false;
                $nomeMedicoExibir = '';
                if ($nomeMedicoEstudo !== '') {
                    if (!$isMedicoLogado || $isAdmin) {
                        // Admin ou não-médico: sempre vê
                        $exibirMedico     = true;
                        $nomeMedicoExibir = $nomeMedicoEstudo;
                    } elseif ($podeVerMedicoLaudo) {
                        // Médico com permissão especial: vê qualquer médico
                        $exibirMedico     = true;
                        $nomeMedicoExibir = $nomeMedicoEstudo;
                    } elseif ($medicoLogadoNome && strcasecmp(trim($nomeMedicoEstudo), trim($medicoLogadoNome)) === 0) {
                        // Médico sem permissão: vê apenas o próprio nome
                        $exibirMedico     = true;
                        $nomeMedicoExibir = $nomeMedicoEstudo;
                    }
                }
                ?>
                <?php if ($exibirMedico): ?>
                    <div class="wl-medico-laudo" title="<?= htmlspecialchars($nomeMedicoExibir) ?>">
                        <i class="fa fa-user-doctor wl-medico-laudo-icon"></i>
                        <span class="wl-medico-laudo-nome"><?= htmlspecialchars($nomeMedicoExibir) ?></span>
                    </div>
                <?php else: ?>
                    <span class="wl-muted">—</span>
                <?php endif; ?>
            </td>

            <!-- Solicitante -->
            <td class="col-solicitante" data-label="<?= htmlspecialchars(t('worklist.mobile.coluna.solicitante'), ENT_QUOTES) ?>">
                <?php
                $sol = $e['especialidade'] ?: \App\Helpers\DicomPersonName::format($e['referring_physician_name'] ?? null);
                if ($sol): ?>
                    <span class="wl-sol-tag"><?= htmlspecialchars($sol) ?></span>
                <?php else: ?>
                    <span class="wl-muted">—</span>
                <?php endif; ?>
            </td>

            <!-- Pedido médico -->
            <td class="col-pedido" data-label="<?= htmlspecialchars(t('worklist.mobile.coluna.pedido'), ENT_QUOTES) ?>">
                <?php if (!empty($e['pedido_id'])): ?>
                    <span class="pedido-anexado-badge" title="<?= htmlspecialchars(t('pedido_medico.status.anexado')) ?>">
                        <i class="fa fa-paperclip"></i> <?= htmlspecialchars(t('pedido_medico.status.anexado')) ?>
                    </span>
                    <a class="pedido-consultar-link" href="/api/gestao-exames/pedidos/<?= (int) $e['pedido_id'] ?>/arquivo" target="_blank" rel="noopener">
                        <i class="fa fa-eye"></i> <?= htmlspecialchars(t('pedido_medico.acao.consultar')) ?>
                    </a>
                <?php else: ?>
                    <span class="wl-muted"><?= htmlspecialchars(t('pedido_medico.status.nao_anexado')) ?></span>
                <?php endif; ?>
            </td>

            <!-- Situação -->
            <td class="col-sit" data-label="<?= htmlspecialchars(t('worklist.mobile.coluna.situacao'), ENT_QUOTES) ?>">
                <?= situacaoBadge($sit) ?>
                <?php
                // Exibe apenas se assumido_por for um nome válido (não numérico, não vazio)
                $apExibir = $e['assumido_por'] ?? '';
                $apValido = $apExibir !== '' && !is_numeric(trim($apExibir)) && strlen(trim($apExibir)) > 1;
                if ($apValido): ?>
                <div class="wl-assumido-por" title="Assumido por <?= htmlspecialchars($apExibir) ?>">
                    <i class="fa fa-user-doctor"></i> <?= htmlspecialchars(explode(' ', trim($apExibir))[0]) ?>
                </div>
                <?php endif; ?>
            </td>

            <!-- SLA -->
            <td class="col-sla" data-label="<?= htmlspecialchars(t('worklist.mobile.coluna.sla'), ENT_QUOTES) ?>">
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
                    <?php if ($modoGestao): ?>
                        <?php if ($podeGerenciarPedido): ?>
                        <button type="button" class="wl-btn-pedido pedido-trigger"
                                data-id="<?= (int) $e['id'] ?>"
                                data-paciente="<?= htmlspecialchars($e['patient_name'] ?? '', ENT_QUOTES) ?>"
                                data-pedido-id="<?= (int) ($e['pedido_id'] ?? 0) ?>"
                                data-pedido-nome="<?= htmlspecialchars($e['pedido_nome_original'] ?? '', ENT_QUOTES) ?>"
                                data-pedido-mime="<?= htmlspecialchars($e['pedido_mime_type'] ?? '', ENT_QUOTES) ?>"
                                data-pedido-tamanho="<?= (int) ($e['pedido_tamanho_bytes'] ?? 0) ?>"
                                title="<?= htmlspecialchars(t('pedido_medico.acao.gerenciar')) ?>">
                            <i class="fa fa-paperclip"></i> <?= htmlspecialchars(t('pedido_medico.acao.pedido')) ?>
                        </button>
                        <?php elseif (!empty($e['pedido_id'])): ?>
                        <a class="pedido-consultar-link" href="/api/gestao-exames/pedidos/<?= (int) $e['pedido_id'] ?>/arquivo" target="_blank" rel="noopener">
                            <i class="fa fa-eye"></i> <?= htmlspecialchars(t('pedido_medico.acao.consultar')) ?>
                        </a>
                        <?php else: ?>
                        <span class="wl-muted">—</span>
                        <?php endif; ?>
                        <?php if ($podeGerenciarPedido): ?>
                        <button type="button" class="wl-btn-gerenciar gerenciar-trigger"
                                data-id="<?= (int) $e['id'] ?>"
                                data-paciente="<?= htmlspecialchars($e['patient_name'] ?? '', ENT_QUOTES) ?>"
                                data-report-id="<?= (int) ($e['report_id'] ?? 0) ?>"
                                data-report-situacao="<?= htmlspecialchars((string) ($e['report_situacao'] ?? ''), ENT_QUOTES) ?>"
                                data-chat-status="<?= htmlspecialchars((string) ($e['chat_status'] ?? ''), ENT_QUOTES) ?>"
                                data-priority="<?= htmlspecialchars((string) ($e['dicom_priority_effective'] ?? $e['dicom_priority'] ?? 'ROUTINE'), ENT_QUOTES) ?>"
                                data-dicom-priority="<?= htmlspecialchars((string) ($e['dicom_priority'] ?? ''), ENT_QUOTES) ?>"
                                title="<?= htmlspecialchars(t('gestao_gerenciar.acao.gerenciar')) ?>">
                            <i class="fa fa-sliders"></i> <?= htmlspecialchars(t('gestao_gerenciar.acao.gerenciar')) ?>
                        </button>
                        <?php if ($podeConsultarLaudoGestao): ?>
                        <a class="wl-btn-laudo wl-btn-laudo-gestao"
                           href="/reports/r/<?= rawurlencode($reportTokenGestao) ?>/pdf?origem=gestao"
                           target="_self"
                           title="<?= htmlspecialchars(t('gestao_gerenciar.menu.ver_laudo_desc'), ENT_QUOTES) ?>">
                            <i class="fa fa-file-medical"></i> <?= htmlspecialchars(t('gestao_gerenciar.js.laudo')) ?>
                        </a>
                        <?php else: ?>
                        <span class="wl-btn-laudo wl-btn-laudo-gestao is-disabled"
                              aria-disabled="true"
                              title="<?= htmlspecialchars(t('gestao_gerenciar.js.sem_laudo'), ENT_QUOTES) ?>">
                            <i class="fa fa-file-medical"></i> <?= htmlspecialchars(t('gestao_gerenciar.js.laudo')) ?>
                        </span>
                        <?php endif; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($podePeerReview && !empty($e['report_public_token'])): ?>
                        <a href="/reports/r/<?= rawurlencode($e['report_public_token']) ?>" target="voxel-laudario"
                           class="wl-btn-peer-review" title="<?= htmlspecialchars(t('peer_review.abrir_worklist')) ?>">
                            <i class="fa fa-rotate"></i> <?= htmlspecialchars(t('peer_review.botao_worklist')) ?>
                        </a>
                        <?php endif; ?>
                        <?php if ($podeAssumir): ?>
                        <button type="button" class="wl-btn-assumir"
                                data-id="<?= $e['id'] ?>"
                                data-paciente="<?= htmlspecialchars($e['patient_name'] ?? '') ?>"
                                data-study-uid="<?= htmlspecialchars($e['study_instance_uid'] ?? '') ?>"
                                title="Assumir para laudo">
                            <i class="fa fa-hand-holding-medical"></i> Assumir
                        </button>
                        <?php elseif ($podeLaudar): ?>
                        <?php if (!$workspaceLaudoHabilitado && !empty($e['report_public_token'])): ?>
                        <!-- Laudário Interno: URL pública usa somente token opaco -->
                        <a href="/reports/r/<?= rawurlencode($e['report_public_token']) ?>" target="voxel-laudario"
                           class="wl-btn-laudo" title="Abrir Laudário Interno VOXEL PACS">
                            <i class="fa fa-file-medical"></i> Laudo
                        </a>
                        <?php elseif (!$workspaceLaudoHabilitado): ?>
                        <!-- Recuperação segura para estudos assumidos antes do token existir. -->
                        <button type="button" class="wl-btn-laudo wl-btn-laudo-recuperar"
                                data-id="<?= (int) $e['id'] ?>" title="Preparar Laudário Interno VOXEL PACS">
                            <i class="fa fa-file-medical"></i> Laudo
                        </button>
                        <?php elseif ($workspaceLaudoHabilitado): ?>
                        <!-- VOXEL Copilot ativo: botão oculto (lauda externamente) -->
                        <?php endif; ?>
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
                                <a href="/estudos/<?= $e['id'] ?>/abrir-voxel" class="wl-vm-item wl-vm-voxel" target="_blank">
                                    <i class="fa fa-desktop" style="width:16px;text-align:center;color:#1a56db;"></i> VOXEL Desktop
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
        <label class="wl-chk-agrupar" title="<?= htmlspecialchars(t('download_lote.agrupar_tooltip')) ?>">
            <input type="checkbox" id="chk-agrupar-zip">
            <?= htmlspecialchars(t('download_lote.agrupar_label')) ?>
        </label>
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
</div><!-- /.wl-worklist-body -->

<?php if ($modoGestao && $podeGerenciarPedido): ?>
<!-- ═══════════════════════════════════════════════════════════ MODAL PEDIDO -->
<div class="modal fade" id="pedidoModal" tabindex="-1" aria-labelledby="pedidoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content pedido-modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pedidoModalLabel">
                    <i class="fa fa-paperclip me-2"></i><?= htmlspecialchars(t('pedido_medico.modal.titulo')) ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t('pedido_medico.acao.fechar')) ?>"></button>
            </div>
            <form id="pedidoForm" enctype="multipart/form-data" method="post">
                <div class="modal-body">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div class="pedido-estudo-context">
                        <span class="pedido-estudo-label"><?= htmlspecialchars(t('pedido_medico.modal.estudo')) ?></span>
                        <strong id="pedidoPacienteNome">—</strong>
                    </div>
                    <div id="pedidoAtual" class="pedido-atual" style="display:none;"></div>
                    <p class="pedido-modal-help"><?= htmlspecialchars(t('pedido_medico.modal.instrucoes')) ?></p>
                    <div class="pedido-file-options">
                        <button type="button" class="pedido-file-option" id="btnPedidoImportar">
                            <i class="fa fa-folder-open"></i>
                            <span><?= htmlspecialchars(t('pedido_medico.acao.importar')) ?></span>
                            <small><?= htmlspecialchars(t('pedido_medico.modal.importar_desc')) ?></small>
                        </button>
                        <button type="button" class="pedido-file-option" id="btnPedidoCamera">
                            <i class="fa fa-camera"></i>
                            <span><?= htmlspecialchars(t('pedido_medico.acao.camera')) ?></span>
                            <small><?= htmlspecialchars(t('pedido_medico.modal.camera_desc')) ?></small>
                        </button>
                    </div>
                    <input type="file" id="pedidoFile" name="pedido" class="visually-hidden"
                           accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,application/pdf,image/*">
                    <input type="file" id="pedidoCameraFile" class="visually-hidden"
                           accept="image/*" capture="environment" aria-label="<?= htmlspecialchars(t('pedido_medico.acao.camera')) ?>">
                    <div id="pedidoArquivoSelecionado" class="pedido-arquivo-selecionado" style="display:none;"></div>
                    <div id="pedidoErro" class="alert alert-danger py-2 small" style="display:none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= htmlspecialchars(t('pedido_medico.acao.cancelar')) ?></button>
                    <button type="button" class="btn btn-outline-danger" id="btnPedidoRemover" style="display:none;">
                        <i class="fa fa-trash"></i> <?= htmlspecialchars(t('pedido_medico.acao.remover')) ?>
                    </button>
                    <button type="submit" class="btn btn-primary" id="btnPedidoSalvar" disabled>
                        <i class="fa fa-cloud-arrow-up"></i> <?= htmlspecialchars(t('pedido_medico.acao.salvar')) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ MODAL GERENCIAR -->
<div class="modal fade" id="gerenciarModal" tabindex="-1" aria-labelledby="gerenciarModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content gestao-gerenciar-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="gerenciarModalLabel">
                    <i class="fa fa-sliders me-2"></i><?= htmlspecialchars(t('gestao_gerenciar.modal.titulo')) ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t('gestao_gerenciar.acao.fechar')) ?>"></button>
            </div>
            <div class="modal-body">
                <div class="gerenciar-estudo-context">
                    <span><?= htmlspecialchars(t('gestao_gerenciar.modal.estudo')) ?></span>
                    <strong id="gerenciarPacienteNome">—</strong>
                    <small id="gerenciarEstudoMeta">—</small>
                </div>
                <div id="gerenciarFeedback" class="alert py-2 small" style="display:none;"></div>
                <div class="gerenciar-submenu" role="menu" aria-label="<?= htmlspecialchars(t('gestao_gerenciar.modal.submenu')) ?>">
                    <a id="gerenciarVerLaudo" class="gerenciar-menu-item" href="#" target="_blank" rel="noopener" style="display:none;">
                        <i class="fa fa-file-medical"></i>
                        <span><strong><?= htmlspecialchars(t('gestao_gerenciar.menu.ver_laudo')) ?></strong><small><?= htmlspecialchars(t('gestao_gerenciar.menu.ver_laudo_desc')) ?></small></span>
                        <i class="fa fa-arrow-up-right-from-square ms-auto"></i>
                    </a>
                    <button type="button" id="gerenciarChat" class="gerenciar-menu-item">
                        <i class="fa fa-comments"></i>
                        <span><strong><?= htmlspecialchars(t('gestao_gerenciar.menu.chat')) ?></strong><small id="gerenciarChatDesc"><?= htmlspecialchars(t('gestao_gerenciar.menu.chat_desc')) ?></small></span>
                        <span id="gerenciarChatBadge" class="gerenciar-menu-badge" style="display:none;"><?= htmlspecialchars(t('gestao_gerenciar.menu.pendente')) ?></span>
                    </button>
                    <button type="button" id="gerenciarDescricao" class="gerenciar-menu-item">
                        <i class="fa fa-file-medical"></i>
                        <span><strong><?= htmlspecialchars(t('gestao_gerenciar.menu.descricao')) ?></strong><small id="gerenciarDescricaoDesc"><?= htmlspecialchars(t('gestao_gerenciar.menu.descricao_desc')) ?></small></span>
                        <i class="fa fa-chevron-right ms-auto"></i>
                    </button>
                    <button type="button" id="gerenciarPrioridade" class="gerenciar-menu-item">
                        <i class="fa fa-flag"></i>
                        <span><strong><?= htmlspecialchars(t('gestao_gerenciar.menu.prioridade')) ?></strong><small id="gerenciarPrioridadeDesc"><?= htmlspecialchars(t('gestao_gerenciar.menu.prioridade_desc')) ?></small></span>
                        <i class="fa fa-chevron-right ms-auto"></i>
                    </button>
                </div>
                <div id="gerenciarLockNotice" class="gerenciar-lock-notice" style="display:none;">
                    <i class="fa fa-lock"></i> <span><?= htmlspecialchars(t('gestao_gerenciar.menu.bloqueado_pendencia')) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ MODAL CHAT GERENCIAR -->
<div class="modal fade" id="gerenciarChatModal" tabindex="-1" aria-labelledby="gerenciarChatModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content gestao-chat-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="gerenciarChatModalLabel"><i class="fa fa-comments me-2"></i><?= htmlspecialchars(t('gestao_gerenciar.chat.titulo')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t('gestao_gerenciar.acao.fechar')) ?>"></button>
            </div>
            <div class="modal-body">
                <div id="gerenciarChatStatus" class="alert py-2 small" style="display:none;"></div>
                <div id="gerenciarChatHistory" class="gerenciar-chat-history"><div class="chat-empty"><i class="fa fa-spinner fa-spin"></i> <?= htmlspecialchars(t('gestao_gerenciar.chat.carregando')) ?></div></div>
                <form id="gerenciarChatForm" class="gerenciar-chat-form">
                    <input type="hidden" id="gerenciarChatReportId" name="report_id" value="0">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="origem" value="gestao_exames">
                    <div class="gerenciar-chat-grid">
                        <label><?= htmlspecialchars(t('gestao_gerenciar.chat.destinatario')) ?>
                            <select id="gerenciarChatTipo" name="destinatario_tipo" class="form-select form-select-sm">
                                <option value="grupo"><?= htmlspecialchars(t('gestao_gerenciar.chat.grupo')) ?></option>
                                <option value="usuario"><?= htmlspecialchars(t('gestao_gerenciar.chat.usuario')) ?></option>
                            </select>
                        </label>
                        <label id="gerenciarChatGrupoWrap"><?= htmlspecialchars(t('gestao_gerenciar.chat.grupo')) ?>
                            <select id="gerenciarChatGrupo" name="destinatario_grupo" class="form-select form-select-sm"></select>
                        </label>
                        <label id="gerenciarChatUsuarioWrap" style="display:none;"><?= htmlspecialchars(t('gestao_gerenciar.chat.usuario')) ?>
                            <select id="gerenciarChatUsuario" name="destinatario_user_id" class="form-select form-select-sm"></select>
                        </label>
                        <label><?= htmlspecialchars(t('gestao_gerenciar.chat.tema')) ?>
                            <select id="gerenciarChatAssuntoCodigo" name="assunto_codigo" class="form-select form-select-sm"></select>
                        </label>
                        <label class="gerenciar-chat-assunto"><?= htmlspecialchars(t('gestao_gerenciar.chat.assunto')) ?>
                            <input id="gerenciarChatAssunto" name="assunto" class="form-control form-control-sm" maxlength="180" placeholder="<?= htmlspecialchars(t('gestao_gerenciar.chat.assunto_placeholder')) ?>">
                        </label>
                    </div>
                    <label class="gerenciar-chat-mensagem"><?= htmlspecialchars(t('gestao_gerenciar.chat.mensagem')) ?>
                        <textarea id="gerenciarChatMensagem" name="mensagem" class="form-control" rows="3" maxlength="5000" required></textarea>
                    </label>
                    <div class="gerenciar-chat-footer">
                        <small id="gerenciarChatHint" class="text-muted"></small>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm" id="gerenciarChatEnviar"><i class="fa fa-paper-plane"></i> <?= htmlspecialchars(t('gestao_gerenciar.chat.enviar')) ?></button>
                            <button type="button" class="btn btn-success btn-sm" id="gerenciarChatConcluir"><i class="fa fa-check"></i> <?= htmlspecialchars(t('gestao_gerenciar.chat.concluir')) ?></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ MODAL PRIORIDADE -->
<div class="modal fade" id="gerenciarPrioridadeModal" tabindex="-1" aria-labelledby="gerenciarPrioridadeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content gestao-gerenciar-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="gerenciarPrioridadeModalLabel"><i class="fa fa-flag me-2"></i><?= htmlspecialchars(t('gestao_gerenciar.prioridade.titulo')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t('gestao_gerenciar.acao.fechar')) ?>"></button>
            </div>
            <form id="gerenciarPrioridadeForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div class="gerenciar-prioridade-atual"><span><?= htmlspecialchars(t('gestao_gerenciar.prioridade.atual')) ?></span><strong id="gerenciarPrioridadeAtual">—</strong><small id="gerenciarPrioridadeDicom">—</small></div>
                    <label><?= htmlspecialchars(t('gestao_gerenciar.prioridade.nova')) ?>
                        <select id="gerenciarPrioridadeSelect" name="prioridade" class="form-select"></select>
                    </label>
                    <label class="mt-3"><?= htmlspecialchars(t('gestao_gerenciar.prioridade.motivo')) ?>
                        <textarea id="gerenciarPrioridadeMotivo" name="motivo" class="form-control" rows="4" minlength="20" maxlength="1000" required></textarea>
                    </label>
                    <div class="d-flex justify-content-between mt-1"><small class="text-muted"><?= htmlspecialchars(t('gestao_gerenciar.prioridade.minimo')) ?></small><small id="gerenciarPrioridadeCount" class="text-muted">0/20</small></div>
                    <div id="gerenciarPrioridadeDestinatarios" class="alert alert-info py-2 small mt-3" style="display:none;">
                        <div class="fw-semibold mb-1"><i class="fa fa-bell me-1"></i><?= htmlspecialchars(t('gestao_gerenciar.prioridade.destinatarios_titulo')) ?></div>
                        <div id="gerenciarPrioridadeDestinatariosLista"></div>
                    </div>
                    <div id="gerenciarPrioridadeAviso" class="alert alert-warning py-2 small mt-3" style="display:none;"><i class="fa fa-lock"></i> <?= htmlspecialchars(t('gestao_gerenciar.menu.bloqueado_pendencia')) ?></div>
                    <div id="gerenciarPrioridadeErro" class="alert alert-danger py-2 small mt-3" style="display:none;"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= htmlspecialchars(t('gestao_gerenciar.acao.cancelar')) ?></button><button type="submit" class="btn btn-primary" id="gerenciarPrioridadeSalvar"><i class="fa fa-save"></i> <?= htmlspecialchars(t('gestao_gerenciar.prioridade.salvar')) ?></button></div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ MODAL DESCRIÇÃO -->
<div class="modal fade" id="gerenciarDescricaoModal" tabindex="-1" aria-labelledby="gerenciarDescricaoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content gestao-gerenciar-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="gerenciarDescricaoModalLabel"><i class="fa fa-file-medical me-2"></i><?= htmlspecialchars(t('gestao_gerenciar.descricao.titulo')) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t('gestao_gerenciar.acao.fechar')) ?>"></button>
            </div>
            <form id="gerenciarDescricaoForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div id="gerenciarDescricaoStatus" class="alert py-2 small" style="display:none;"></div>
                    <p id="gerenciarDescricaoModalidade" class="text-muted small mb-3">—</p>
                    <label class="w-100"><?= htmlspecialchars(t('gestao_gerenciar.descricao.campo')) ?>
                        <input id="gerenciarDescricaoInput" name="descricao" class="form-control mt-1" list="gerenciarDescricaoSugestoes" maxlength="255" required autocomplete="off" placeholder="<?= htmlspecialchars(t('gestao_gerenciar.descricao.placeholder')) ?>">
                    </label>
                    <datalist id="gerenciarDescricaoSugestoes"></datalist>
                    <div class="mt-3">
                        <small class="text-muted d-block mb-1"><?= htmlspecialchars(t('gestao_gerenciar.descricao.sugestoes')) ?></small>
                        <div id="gerenciarDescricaoSugestoesLista" class="d-flex flex-wrap gap-1"></div>
                    </div>
                </div>
                <div class="modal-footer d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= htmlspecialchars(t('gestao_gerenciar.acao.cancelar')) ?></button>
                    <button type="submit" class="btn btn-primary" id="gerenciarDescricaoAplicar"><i class="fa fa-check"></i> <?= htmlspecialchars(t('gestao_gerenciar.descricao.individual')) ?></button>
                    <button type="button" class="btn btn-outline-primary" id="gerenciarDescricaoLote" style="display:none;"><i class="fa fa-layer-group"></i> <?= htmlspecialchars(t('gestao_gerenciar.descricao.lote')) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="gerenciarI18n" class="d-none"
     data-carregando-acoes="<?= htmlspecialchars(t('gestao_gerenciar.js.carregando_acoes')) ?>"
     data-study-uid="<?= htmlspecialchars(t('gestao_gerenciar.js.study_uid')) ?>"
     data-laudo="<?= htmlspecialchars(t('gestao_gerenciar.js.laudo')) ?>"
     data-sem-laudo="<?= htmlspecialchars(t('gestao_gerenciar.js.sem_laudo')) ?>"
     data-prioridade="<?= htmlspecialchars(t('gestao_gerenciar.js.prioridade')) ?>"
     data-sem-mensagens="<?= htmlspecialchars(t('gestao_gerenciar.js.sem_mensagens')) ?>"
     data-aguardando-saneamento="<?= htmlspecialchars(t('gestao_gerenciar.js.aguardando_saneamento')) ?>"
     data-aguardando-contraparte="<?= htmlspecialchars(t('gestao_gerenciar.js.aguardando_contraparte')) ?>"
     data-primeiro-envio="<?= htmlspecialchars(t('gestao_gerenciar.js.primeiro_envio')) ?>"
     data-enviando="<?= htmlspecialchars(t('gestao_gerenciar.js.enviando')) ?>"
     data-enviado="<?= htmlspecialchars(t('gestao_gerenciar.js.enviado')) ?>"
     data-concluindo="<?= htmlspecialchars(t('gestao_gerenciar.js.concluindo')) ?>"
     data-concluido="<?= htmlspecialchars(t('gestao_gerenciar.js.concluido')) ?>"
     data-confirmar-conclusao="<?= htmlspecialchars(t('gestao_gerenciar.js.confirmar_conclusao')) ?>"
     data-dicom-original="<?= htmlspecialchars(t('gestao_gerenciar.js.dicom_original')) ?>"
     data-sem-override="<?= htmlspecialchars(t('gestao_gerenciar.js.sem_override')) ?>"
     data-motivo-curto="<?= htmlspecialchars(t('gestao_gerenciar.js.motivo_curto')) ?>"
     data-confirmar-prioridade="<?= htmlspecialchars(t('gestao_gerenciar.js.confirmar_prioridade')) ?>"
     data-destinatarios-carregando="<?= htmlspecialchars(t('gestao_gerenciar.js.destinatarios_carregando')) ?>"
     data-destinatarios-nenhum="<?= htmlspecialchars(t('gestao_gerenciar.js.destinatarios_nenhum')) ?>"
     data-destinatarios-grupo="<?= htmlspecialchars(t('gestao_gerenciar.js.destinatarios_grupo')) ?>"
     data-destinatarios-membros="<?= htmlspecialchars(t('gestao_gerenciar.js.destinatarios_membros')) ?>"
     data-destinatarios-canais="<?= htmlspecialchars(t('gestao_gerenciar.js.destinatarios_canais')) ?>"
     data-erro-contexto="<?= htmlspecialchars(t('gestao_gerenciar.erro.contexto')) ?>"
     data-erro-operacao="<?= htmlspecialchars(t('gestao_gerenciar.erro.interno')) ?>"
     data-status-assinado="<?= htmlspecialchars(t('gestao_gerenciar.js.status_assinado')) ?>"
     data-status-liberado="<?= htmlspecialchars(t('gestao_gerenciar.js.status_liberado')) ?>"
     data-status-novo="<?= htmlspecialchars(t('relatorios.situacao.novo')) ?>"
     data-status-aberto="<?= htmlspecialchars(t('relatorios.situacao.aberto')) ?>"
     data-status-a-laudar="<?= htmlspecialchars(t('relatorios.situacao.a_laudar')) ?>"
     data-status-em-laudo="<?= htmlspecialchars(t('relatorios.situacao.em_laudo')) ?>"
     data-status-rascunho="<?= htmlspecialchars(t('relatorios.situacao.rascunho')) ?>"
     data-status-revisao="<?= htmlspecialchars(t('relatorios.situacao.revisao')) ?>"
     data-status-peer-review="<?= htmlspecialchars(t('peer_review.status_aberta')) ?>"
     data-prioridade-stat="<?= htmlspecialchars(t('gestao_gerenciar.prioridade.stat')) ?>"
     data-prioridade-high="<?= htmlspecialchars(t('gestao_gerenciar.prioridade.high')) ?>"
     data-prioridade-routine="<?= htmlspecialchars(t('gestao_gerenciar.prioridade.routine')) ?>"
     data-prioridade-medium="<?= htmlspecialchars(t('gestao_gerenciar.prioridade.medium')) ?>"
     data-prioridade-low="<?= htmlspecialchars(t('gestao_gerenciar.prioridade.low')) ?>"
     data-tema-erro-pedido="<?= htmlspecialchars(t('gestao_gerenciar.chat.tema.erro_pedido')) ?>"
     data-tema-contraste="<?= htmlspecialchars(t('gestao_gerenciar.chat.tema.contraste')) ?>"
     data-tema-exames-complementares="<?= htmlspecialchars(t('gestao_gerenciar.chat.tema.exames_complementares')) ?>"
     data-tema-duvida-administrativa="<?= htmlspecialchars(t('gestao_gerenciar.chat.tema.duvida_administrativa')) ?>"
     data-tema-outro="<?= htmlspecialchars(t('gestao_gerenciar.chat.tema.outro')) ?>"
     data-descricao-modalidade="<?= htmlspecialchars(t('gestao_gerenciar.descricao.modalidade')) ?>"
     data-descricao-sem-sugestoes="<?= htmlspecialchars(t('gestao_gerenciar.descricao.sem_sugestoes')) ?>"
     data-confirmar-descricao-lote="<?= htmlspecialchars(t('gestao_gerenciar.descricao.confirmar_lote')) ?>"></div>
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
/* Empurra .wl-pagination (margin-top:auto) para a borda inferior mesmo com
   poucos resultados; cresce além disso quando a tabela precisa de mais
   espaço (min-height é piso, não teto) — ver patterns/layout-rodape-fixo.md. */
.wl-worklist-body{display:flex;flex-direction:column;min-height:calc(100vh - 230px);}
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
/* Coluna Médico responsável pelo laudo */
.col-medico-laudo{width:130px;min-width:100px;}
.wl-medico-laudo{display:flex;align-items:center;gap:.3rem;}
.wl-medico-laudo-icon{font-size:.72rem;color:#60a5fa;flex-shrink:0;}
.wl-medico-laudo-nome{font-size:.72rem;font-weight:600;color:var(--pacs-text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:110px;}
/* Badges de prioridade DICOM */
.wl-prio-badge{display:inline-block;padding:.18rem .55rem;border-radius:20px;font-size:.68rem;font-weight:700;letter-spacing:.03em;white-space:nowrap;}
.wl-prio-emergencia{background:#DC2626;color:#fff;}
.wl-prio-urgencia{background:#F97316;color:#fff;}
.wl-prio-rotina{background:#3B82F6;color:#fff;}
.wl-prio-ambulatorial{background:#22C55E;color:#fff;}
.col-solicitante{width:150px;}
.col-pedido{width:145px;min-width:120px;}
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
.sit-pendente{background:#fef2f2;color:#dc2626;border:1px solid rgba(220,38,38,.25);}
.sit-peer-review{background:#faf5ff;color:#7c3aed;border:1px solid rgba(124,58,237,.3);}
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
.pedido-anexado-badge{display:inline-flex;align-items:center;gap:.22rem;background:rgba(16,185,129,.12);
    color:#047857;border:1px solid rgba(16,185,129,.25);border-radius:4px;padding:.12rem .35rem;
    font-size:.64rem;font-weight:700;white-space:nowrap;}
.pedido-consultar-link{display:inline-flex;align-items:center;gap:.22rem;margin-top:.15rem;color:var(--pacs-primary);
    font-size:.65rem;text-decoration:none;white-space:nowrap;}
.pedido-consultar-link:hover{text-decoration:underline;}
.wl-mode-badge{display:inline-flex;align-items:center;background:rgba(14,165,233,.12);color:#0369a1;
    border:1px solid rgba(14,165,233,.25);border-radius:999px;padding:.15rem .45rem;font-size:.63rem;
    font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
.pedido-modal-content{border:1px solid var(--pacs-border);border-radius:10px;overflow:hidden;}
.pedido-estudo-context{display:flex;flex-direction:column;gap:.15rem;background:rgba(14,165,233,.08);
    border:1px solid rgba(14,165,233,.18);border-radius:7px;padding:.65rem .75rem;margin-bottom:.75rem;}
.pedido-estudo-label{font-size:.65rem;color:var(--pacs-text-muted);text-transform:uppercase;letter-spacing:.04em;}
.pedido-estudo-context strong{font-size:.88rem;color:var(--pacs-text-primary);}
.pedido-modal-help{font-size:.75rem;color:var(--pacs-text-secondary);margin-bottom:.75rem;}
.pedido-file-options{display:grid;grid-template-columns:1fr 1fr;gap:.55rem;}
.pedido-file-option{display:flex;flex-direction:column;align-items:center;gap:.25rem;background:var(--pacs-surface);
    color:var(--pacs-text-primary);border:1px solid var(--pacs-border);border-radius:8px;padding:.8rem .55rem;cursor:pointer;}
.pedido-file-option:hover{border-color:var(--pacs-primary);background:rgba(14,165,233,.06);}
.pedido-file-option i{font-size:1.15rem;color:var(--pacs-primary);}
.pedido-file-option span{font-size:.78rem;font-weight:700;}
.pedido-file-option small{font-size:.65rem;color:var(--pacs-text-muted);text-align:center;}
.pedido-atual,.pedido-arquivo-selecionado{display:flex;align-items:center;gap:.45rem;border-radius:6px;padding:.5rem .6rem;font-size:.73rem;margin-bottom:.65rem;}
.pedido-atual{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.22);color:#047857;}
.pedido-arquivo-selecionado{background:rgba(14,165,233,.08);border:1px solid rgba(14,165,233,.2);color:#0369a1;}

/* ── Ações ──────────────────────────────────────────────────────────────── */
.wl-acoes-wrap{display:flex;flex-direction:column;gap:.25rem;align-items:center;}
.wl-btn-peer-review{background:linear-gradient(135deg,#a855f7,#7c3aed);color:#fff;border:none;border-radius:5px;
    padding:.25rem .65rem;font-size:.7rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;
    gap:.25rem;white-space:nowrap;width:100%;justify-content:center;transition:opacity .15s,transform .1s;text-decoration:none;}
.wl-btn-peer-review:hover{opacity:.88;transform:scale(1.02);color:#fff;}
.wl-btn-pedido{background:linear-gradient(135deg,#0ea5e9,#0284c7);color:#fff;border:none;border-radius:5px;
    padding:.25rem .65rem;font-size:.7rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;
    gap:.25rem;white-space:nowrap;width:100%;justify-content:center;transition:opacity .15s,transform .1s;}
.wl-btn-pedido:hover{opacity:.88;transform:scale(1.02);}
.wl-btn-gerenciar{background:linear-gradient(135deg,#475569,#334155);color:#fff;border:none;border-radius:5px;
    padding:.25rem .65rem;font-size:.7rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;
    gap:.25rem;white-space:nowrap;width:100%;justify-content:center;transition:opacity .15s,transform .1s;}
.wl-btn-gerenciar:hover{opacity:.88;transform:scale(1.02);}
.wl-btn-gerenciar:disabled{opacity:.45;cursor:not-allowed;transform:none;}
.gerenciar-estudo-context{display:flex;align-items:center;gap:.55rem;flex-wrap:wrap;padding:.7rem .8rem;margin-bottom:.8rem;border:1px solid rgba(14,165,233,.2);background:rgba(14,165,233,.06);border-radius:7px;}
.gerenciar-estudo-context span{font-size:.65rem;text-transform:uppercase;letter-spacing:.04em;color:var(--pacs-text-muted);font-weight:700;}
.gerenciar-estudo-context strong{font-size:.9rem;color:var(--pacs-text-primary);}
.gerenciar-estudo-context small{width:100%;font-size:.7rem;color:var(--pacs-text-muted);}
.gerenciar-submenu{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.6rem;}
.gerenciar-menu-item{min-height:74px;display:flex;align-items:center;gap:.65rem;text-align:left;padding:.75rem;border:1px solid var(--pacs-border);border-radius:8px;background:var(--pacs-surface);color:var(--pacs-text-primary);text-decoration:none;cursor:pointer;transition:border-color .15s,box-shadow .15s,transform .1s;}
.gerenciar-menu-item:hover{border-color:var(--pacs-primary);box-shadow:0 3px 10px rgba(15,23,42,.12);transform:translateY(-1px);color:var(--pacs-primary);}
.gerenciar-menu-item>i:first-child{font-size:1.1rem;color:var(--pacs-primary);width:22px;text-align:center;flex-shrink:0;}
.gerenciar-menu-item span:not(.gerenciar-menu-badge){display:flex;flex-direction:column;gap:.18rem;min-width:0;}
.gerenciar-menu-item strong{font-size:.78rem;}
.gerenciar-menu-item small{font-size:.66rem;color:var(--pacs-text-muted);font-weight:400;line-height:1.25;}
.gerenciar-menu-item:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none;}
.gerenciar-menu-badge{margin-left:auto;background:#fef3c7;color:#92400e;border-radius:999px;padding:.18rem .4rem;font-size:.58rem;font-weight:800;white-space:nowrap;}
.gerenciar-lock-notice{margin-top:.75rem;padding:.6rem .7rem;border-radius:6px;border:1px solid rgba(245,158,11,.3);background:rgba(245,158,11,.09);color:#92400e;font-size:.72rem;}
.gestao-gerenciar-modal,.gestao-chat-modal{border:0;box-shadow:0 16px 46px rgba(15,23,42,.25);}
.gestao-chat-modal .modal-body{padding:1rem;}
.gerenciar-chat-history{max-height:300px;overflow:auto;border:1px solid var(--pacs-border);border-radius:7px;padding:.7rem;background:rgba(248,250,252,.7);margin-bottom:.85rem;}
.gerenciar-chat-history .chat-empty{text-align:center;color:var(--pacs-text-muted);font-size:.75rem;padding:1.6rem .5rem;}
.gerenciar-chat-message{padding:.55rem .65rem;border-radius:7px;background:#fff;border:1px solid var(--pacs-border);margin-bottom:.5rem;}
.gerenciar-chat-message:last-child{margin-bottom:0;}
.gerenciar-chat-message.is-own{border-left:3px solid var(--pacs-primary);}
.gerenciar-chat-message header{display:flex;justify-content:space-between;gap:.5rem;font-size:.67rem;color:var(--pacs-text-muted);margin-bottom:.25rem;}
.gerenciar-chat-message p{white-space:pre-wrap;word-break:break-word;font-size:.76rem;color:var(--pacs-text-primary);margin:0;}
.gerenciar-chat-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.55rem;}
.gerenciar-chat-grid label,.gerenciar-chat-form>label{display:flex;flex-direction:column;gap:.25rem;font-size:.68rem;font-weight:700;color:var(--pacs-text-secondary);}
.gerenciar-chat-assunto{grid-column:1/-1;}
.gerenciar-chat-mensagem{margin-top:.6rem;}
.gerenciar-chat-footer{display:flex;justify-content:space-between;gap:.6rem;align-items:center;margin-top:.65rem;flex-wrap:wrap;}
.gerenciar-prioridade-atual{display:flex;align-items:center;gap:.55rem;flex-wrap:wrap;padding:.7rem;background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.18);border-radius:7px;margin-bottom:1rem;}
.gerenciar-prioridade-atual span{font-size:.68rem;color:var(--pacs-text-muted);font-weight:700;text-transform:uppercase;}
.gerenciar-prioridade-atual strong{font-size:.9rem;color:var(--pacs-primary);}
.gerenciar-prioridade-atual small{width:100%;font-size:.65rem;color:var(--pacs-text-muted);}
#gerenciarPrioridadeForm label{font-size:.7rem;font-weight:700;color:var(--pacs-text-secondary);}
@media (max-width: 720px){
    .gerenciar-submenu{grid-template-columns:1fr;}
    .gerenciar-chat-grid{grid-template-columns:1fr;}
    .gerenciar-chat-assunto{grid-column:auto;}
    .gerenciar-chat-history{max-height:38vh;}
}
.wl-btn-assumir{background:linear-gradient(135deg,#0ea5e9,#0284c7);color:#fff;
    border:none;border-radius:5px;padding:.22rem .55rem;font-size:.7rem;font-weight:600;
    cursor:pointer;display:inline-flex;align-items:center;gap:.22rem;white-space:nowrap;
    transition:opacity .15s,transform .1s;width:100%;justify-content:center;}
.wl-btn-assumir:hover{opacity:.88;transform:scale(1.02);}
.wl-btn-assumir:disabled{opacity:.4;cursor:not-allowed;}
.wl-btn-laudo{background:rgba(124,58,237,.12);color:#7c3aed;border:1px solid rgba(124,58,237,.3);
    border-radius:5px;padding:.22rem .55rem;font-size:.7rem;font-weight:600;cursor:pointer;
    display:inline-flex;align-items:center;gap:.22rem;white-space:nowrap;width:100%;justify-content:center;
    text-decoration:none;transition:opacity .15s,transform .1s;}
.wl-btn-laudo:hover{opacity:.88;transform:scale(1.02);color:#6d28d9;}
.wl-btn-laudo.is-disabled{opacity:.45;cursor:not-allowed;pointer-events:none;transform:none;}
.wl-viewer-wrap{position:relative;width:100%;}
.wl-btn-abrir{background:var(--pacs-primary);color:#fff;border:none;border-radius:5px;
    padding:.22rem .55rem;font-size:.7rem;font-weight:600;cursor:pointer;
    display:inline-flex;align-items:center;gap:.22rem;white-space:nowrap;width:100%;
    justify-content:center;transition:opacity .15s;}
.wl-btn-abrir:hover{opacity:.88;}
/* position:fixed (não absolute) — .wl-table-wrap tem overflow-x:auto, e pela
   regra de overflow computado do CSS (um eixo não-visible força o outro pra
   auto) isso recorta qualquer descendente absolute que ultrapasse a caixa da
   tabela. fixed escapa desse clipping; posição é calculada via JS (abaixo)
   a partir do botão .viewer-trigger no momento do clique. */
.wl-viewer-menu{display:none;position:fixed;z-index:1060;
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
.wl-pagination{display:flex;align-items:center;gap:.5rem;padding:.4rem 0 .15rem;flex-wrap:wrap;
    margin-top:auto;position:sticky;bottom:0;background:var(--pacs-bg);z-index:10;}
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
@media (max-width: 1100px) {
    .col-medico-laudo{width:90px;}
    .wl-medico-laudo-nome{max-width:75px;}
}
@media (max-width: 900px) {
    .wl-filters-row1{flex-wrap:wrap;}

    .wl-pac-nome{max-width:130px;}
}
@media (max-width: 640px) {
    .wl-resumo{flex-direction:column;align-items:flex-start;}
    .wl-table-wrap{max-height:none;}
}
/* ── Barra de Seleção / Download em Lote ─────────────────────────────── */
.wl-sel-bar{position:fixed;z-index:1050;left:calc(50% + 92px);bottom:12px;transform:translateX(-50%);
    width:min(860px,calc(100vw - 230px));background:var(--pacs-surface);border:1px solid var(--pacs-primary);border-radius:8px;
    padding:.5rem .85rem;margin:0;box-shadow:0 8px 24px rgba(14,165,233,.24);}
.wl-sel-bar-inner{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;}
@media (max-width:640px){.wl-sel-bar{left:12px;right:12px;bottom:10px;transform:none;width:auto;}}
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
.wl-chk-agrupar{display:flex;align-items:center;gap:.35rem;font-size:.75rem;color:var(--pacs-text-muted);
    cursor:pointer;user-select:none;}
.wl-chk-agrupar input{cursor:pointer;margin:0;}
.wl-chk-agrupar:hover{color:var(--pacs-text-primary);}
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

/* Cartões mobile: mantém todos os dados clínicos relevantes, sem ocultar colunas. */
@media (max-width:575px){
    .wl-table-wrap{overflow:visible;}
    .wl-table,.wl-table tbody,.wl-table tr,.wl-table td{display:block;width:100%;}
    .wl-table{min-width:0;}
    .wl-table thead{display:none;}
    .wl-table tbody{display:grid;gap:.75rem;}
    .wl-table tr.wl-row{position:relative;padding:.65rem .7rem;border:1px solid var(--pacs-border);border-radius:10px;background:var(--pacs-surface);box-shadow:0 2px 8px rgba(15,23,42,.10);}
    .wl-table td{min-height:0;padding:.34rem 0;border:0;}
    .wl-table td[data-label]{display:grid;grid-template-columns:106px minmax(0,1fr);align-items:center;gap:.45rem;}
    .wl-table td[data-label]::before{content:attr(data-label);color:var(--pacs-text-muted);font-size:.64rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase;}
    .wl-table td.col-check{position:absolute;top:.52rem;right:.55rem;width:auto;padding:0;}
    .wl-table td.col-check input{width:20px;height:20px;}
    .wl-table td.col-paciente,.wl-table td.col-dt{padding-right:2rem;}
    .wl-table td.col-paciente .wl-pac-nome{max-width:none;white-space:normal;}
    .wl-table td.col-acoes{padding-top:.55rem;border-top:1px solid var(--pacs-border);}
    .wl-acoes-wrap{flex-direction:row;align-items:stretch;flex-wrap:wrap;gap:.42rem;}
    .wl-acoes-wrap>a,.wl-acoes-wrap>button,.wl-acoes-wrap .wl-viewer-wrap>button{min-height:44px;}
    .wl-viewer-wrap{flex:1 1 124px;}
}
</style>

<!-- ═══════════════════════════════════════════════════════════ JAVASCRIPT -->
<script>
// Variáveis injetadas pelo PHP
window._workspaceLaudoHabilitado = <?= $workspaceLaudoHabilitado ? 'true' : 'false' ?>;
const I18N_DL = {
    baixandoIndividual: <?= json_encode(t('download_lote.baixando_individual')) ?>,
    erroParcial:        <?= json_encode(t('download_lote.erro_parcial')) ?>,
};
const I18N_WL_MOBILE = {
    abrir: <?= json_encode(t('worklist.mobile.filtros_abrir')) ?>,
    fechar: <?= json_encode(t('worklist.mobile.filtros_fechar')) ?>,
};

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
    // No celular, permite selecionar mais de uma modalidade e aplicar tudo no botão Buscar.
    if (window.matchMedia('(max-width: 575px)').matches) return;
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
    const mobileToggle = document.getElementById('wl-mobile-filters-toggle');
    if (!form) return;

    if (mobileToggle) {
        mobileToggle.addEventListener('click', function () {
            const aberto = form.classList.toggle('mobile-filters-open');
            mobileToggle.setAttribute('aria-expanded', aberto ? 'true' : 'false');
            mobileToggle.querySelector('span').textContent = aberto ? I18N_WL_MOBILE.fechar : I18N_WL_MOBILE.abrir;
        });
    }

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
    const chkAgrupar = document.getElementById('chk-agrupar-zip');
    if (chkAgrupar) chkAgrupar.checked = false;
    atualizarBarraSel();
}
// Listener nos checkboxes de linha
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('row-check')) atualizarBarraSel();
});

<?php if (!$modoGestao): ?>
// Duplo clique para abrir apenas na Worklist médica.
document.querySelectorAll('.wl-table tbody tr[data-id]').forEach(row => {
    row.addEventListener('dblclick', function(e) {
        if (e.target.closest('a,button,input')) return;
        window.open('/estudos/' + this.dataset.id + '/abrir', '_blank');
    });
});
<?php endif; ?>

// ── Menu Abrir (dropdown) ────────────────────────────────────────────────
// position:fixed calculado a partir do botão — .wl-viewer-menu não pode mais
// contar com o position:relative de .wl-viewer-wrap, porque .wl-table-wrap
// (overflow-x:auto) recortaria um menu absolute que abrisse perto do fim da
// tabela (ex.: última linha, ou tabela com poucos resultados). Fecha ao
// rolar em vez de reposicionar em tempo real — dropdown de vida curta, mesma
// simplicidade do fechar-ao-clicar-fora já existente.
(function () {
    let menuAberto = null;
    function fechar() { if (menuAberto) { menuAberto.classList.remove('show'); menuAberto = null; } }
    function posicionar(trigger, menu) {
        const rect = trigger.getBoundingClientRect();
        const menuH = menu.offsetHeight || 160; // fallback só se offsetHeight vier 0 por algum motivo
        const espacoAbaixo = window.innerHeight - rect.bottom;
        const abrirParaCima = espacoAbaixo < menuH + 8 && rect.top > menuH + 8;
        menu.style.left = 'auto';
        menu.style.right = Math.max(4, window.innerWidth - rect.right) + 'px';
        if (abrirParaCima) {
            menu.style.top = 'auto';
            menu.style.bottom = (window.innerHeight - rect.top + 3) + 'px';
        } else {
            menu.style.bottom = 'auto';
            menu.style.top = (rect.bottom + 3) + 'px';
        }
    }
    document.addEventListener('click', function(e) {
        const trigger = e.target.closest('.viewer-trigger');
        if (trigger) {
            e.preventDefault(); e.stopPropagation();
            const menu = trigger.parentElement.querySelector('.wl-viewer-menu');
            const jaAberto = menu === menuAberto;
            fechar();
            if (!jaAberto) {
                menu.classList.add('show');
                posicionar(trigger, menu);
                menuAberto = menu;
            }
            return;
        }
        if (!e.target.closest('.wl-viewer-menu')) fechar();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') fechar(); });
    // Fecha ao rolar (tabela internamente ou a página) — a posição fixed
    // ficaria desalinhada do botão sem isso; capture:true pega o scroll
    // interno de .wl-table-wrap também, que não borbulha por padrão em todo browser.
    window.addEventListener('scroll', fechar, true);
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
            // Atualiza célula MÉDICO com o nome retornado pela API
            const medicoCell = row ? row.querySelector('.col-medico-laudo') : null;
            if (medicoCell && data.assumido_por) {
                const nomeEsc = data.assumido_por.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                medicoCell.innerHTML = '<div class="wl-medico-laudo" title="' + nomeEsc + '"><i class="fa fa-user-doctor wl-medico-laudo-icon"></i><span class="wl-medico-laudo-nome">' + nomeEsc + '</span></div>';
            }
            // Substitui botão Assumir pelo botão Laudo correto
            // workspaceLaudoHabilitado é injetado pelo PHP na view
            // Nova lógica: desabilitado = Laudário Interno (botão ativo)
            //              habilitado  = VOXEL Copilot (botão oculto)
            const wlHabilitado = (typeof window._workspaceLaudoHabilitado !== 'undefined') ? window._workspaceLaudoHabilitado : false;
            const reportUrl = data.url || '';
            let novoBotao;
            if (!wlHabilitado && reportUrl) {
                // Laudário Interno: endpoint retorna URL com token opaco.
                novoBotao = `<a href="${reportUrl}" target="voxel-laudario" class="wl-btn-laudo" title="Abrir Laudário Interno VOXEL PACS"><i class="fa fa-file-medical"></i> Laudo</a>`;
            } else if (!wlHabilitado) {
                // Falha segura: mantém a ação disponível para recuperar o token
                // do report já assumido, em vez de fazer o botão desaparecer.
                novoBotao = `<button type="button" class="wl-btn-laudo wl-btn-laudo-recuperar" data-id="${estudoId}" title="Preparar Laudário Interno VOXEL PACS"><i class="fa fa-file-medical"></i> Laudo</button>`;
            } else {
                // VOXEL Copilot ativo: não exibe botão (médico lauda externamente)
                novoBotao = '';
            }
            btn.outerHTML = novoBotao;
            // Atualizar badges da topbar
            if (typeof window.atualizarBadgesTopbar === 'function') window.atualizarBadgesTopbar();
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

// ── Recuperar URL opaca de Laudo para estudos assumidos legados ────────────
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.wl-btn-laudo-recuperar');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();

    const estudoId = parseInt(btn.dataset.id || '0', 10);
    if (!estudoId) return;

    const aba = window.open('', 'voxel-laudario');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Preparando...';

    fetch('/api/estudos/laudo-url', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        body: JSON.stringify({estudo_id: estudoId})
    })
    .then(r => r.json().then(data => ({ok: r.ok, data})))
    .then(result => {
        if (!result.ok || !result.data.ok || !result.data.url) {
            throw new Error(result.data.msg || 'Não foi possível preparar o laudo.');
        }
        if (aba) {
            aba.location.href = result.data.url;
        } else {
            window.location.href = result.data.url;
        }
    })
    .catch(err => {
        if (aba) aba.close();
        alert(err.message || 'Não foi possível preparar o laudo.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-file-medical"></i> Laudo';
    });
});

<?php if ($modoGestao && $podeGerenciarPedido): ?>
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('pedidoModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;

    const modal       = new bootstrap.Modal(modalEl);
    const form        = document.getElementById('pedidoForm');
    const fileInput   = document.getElementById('pedidoFile');
    const cameraInput = document.getElementById('pedidoCameraFile');
    const importarBtn  = document.getElementById('btnPedidoImportar');
    const cameraBtn    = document.getElementById('btnPedidoCamera');
    const salvarBtn    = document.getElementById('btnPedidoSalvar');
    const removerBtn   = document.getElementById('btnPedidoRemover');
    const pacienteEl   = document.getElementById('pedidoPacienteNome');
    const atualEl      = document.getElementById('pedidoAtual');
    const selecionadoEl= document.getElementById('pedidoArquivoSelecionado');
    const erroEl       = document.getElementById('pedidoErro');
    const csrf         = form.querySelector('input[name="csrf"]');
    let estudoAtualId  = 0;
    let cameraFile     = null;

    const I18N_PEDIDO = {
        tamanho: <?= json_encode(t('pedido_medico.js.tamanho')) ?>,
        selecionado: <?= json_encode(t('pedido_medico.js.selecionado')) ?>,
        nenhumArquivo: <?= json_encode(t('pedido_medico.erro.arquivo_ausente')) ?>,
        removendo: <?= json_encode(t('pedido_medico.js.removendo')) ?>,
        confirmeRemover: <?= json_encode(t('pedido_medico.confirmar.remover')) ?>,
        comunicacao: <?= json_encode(t('pedido_medico.erro.comunicacao')) ?>,
    };

    function formatarTamanho(bytes) {
        if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(2).replace('.', ',') + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(1).replace('.', ',') + ' KB';
        return bytes + ' B';
    }

    function mostrarErro(msg) {
        erroEl.textContent = msg || I18N_PEDIDO.comunicacao;
        erroEl.style.display = 'block';
    }

    function limparErro() {
        erroEl.textContent = '';
        erroEl.style.display = 'none';
    }

    function mostrarPedidoAtual(button) {
        atualEl.innerHTML = '';
        if (!button.dataset.pedidoId || Number(button.dataset.pedidoId) <= 0) {
            atualEl.style.display = 'none';
            removerBtn.style.display = 'none';
            return;
        }

        const icon = document.createElement('i');
        icon.className = 'fa fa-circle-check';
        const texto = document.createElement('span');
        texto.textContent = button.dataset.pedidoNome + ' (' + formatarTamanho(Number(button.dataset.pedidoTamanho || 0)) + ')';
        const link = document.createElement('a');
        link.href = '/api/gestao-exames/pedidos/' + encodeURIComponent(button.dataset.pedidoId) + '/arquivo';
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = <?= json_encode(t('pedido_medico.acao.consultar')) ?>;
        atualEl.append(icon, texto, link);
        atualEl.style.display = 'flex';
        removerBtn.style.display = 'inline-flex';
    }

    document.querySelectorAll('.pedido-trigger').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            estudoAtualId = Number(button.dataset.id || 0);
            form.action = '/api/gestao-exames/estudos/' + encodeURIComponent(estudoAtualId) + '/pedido';
            pacienteEl.textContent = button.dataset.paciente || '—';
            fileInput.value = '';
            if (cameraInput) cameraInput.value = '';
            cameraFile = null;
            fileInput.removeAttribute('capture');
            selecionadoEl.textContent = '';
            selecionadoEl.style.display = 'none';
            salvarBtn.disabled = true;
            limparErro();
            mostrarPedidoAtual(button);
            modal.show();
        });
    });

    importarBtn.addEventListener('click', function () {
        cameraFile = null;
        if (cameraInput) cameraInput.value = '';
        fileInput.removeAttribute('capture');
        fileInput.click();
    });

    cameraBtn.addEventListener('click', function () {
        cameraFile = null;
        fileInput.value = '';
        if (!cameraInput) {
            fileInput.setAttribute('capture', 'environment');
            fileInput.click();
            return;
        }
        cameraInput.value = '';
        cameraInput.click();
    });

    fileInput.addEventListener('change', function () {
        cameraFile = null;
        if (cameraInput) cameraInput.value = '';
        const file = fileInput.files && fileInput.files[0];
        if (!file) {
            salvarBtn.disabled = true;
            selecionadoEl.style.display = 'none';
            return;
        }
        selecionadoEl.textContent = I18N_PEDIDO.selecionado + ': ' + file.name + ' (' + formatarTamanho(file.size) + ')';
        selecionadoEl.style.display = 'flex';
        salvarBtn.disabled = false;
        limparErro();
    });

    if (cameraInput) {
        cameraInput.addEventListener('change', function () {
            const file = cameraInput.files && cameraInput.files[0];
            if (!file) return;
            cameraFile = file;
            fileInput.value = '';
            selecionadoEl.textContent = I18N_PEDIDO.selecionado + ': ' + file.name + ' (' + formatarTamanho(file.size) + ')';
            selecionadoEl.style.display = 'flex';
            salvarBtn.disabled = false;
            limparErro();
        });
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        limparErro();
        if ((!fileInput.files || !fileInput.files.length) && !cameraFile) {
            mostrarErro(I18N_PEDIDO.nenhumArquivo);
            return;
        }

        salvarBtn.disabled = true;
        const textoOriginal = salvarBtn.innerHTML;
        salvarBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> ' + <?= json_encode(t('pedido_medico.js.salvando')) ?>;
        try {
            const body = new FormData(form);
            if (cameraFile) {
                body.set('pedido', cameraFile, cameraFile.name || 'pedido-camera.jpg');
            }
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: body
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.msg || I18N_PEDIDO.comunicacao);
            modal.hide();
            window.location.reload();
        } catch (error) {
            mostrarErro(error.message || I18N_PEDIDO.comunicacao);
            salvarBtn.disabled = false;
            salvarBtn.innerHTML = textoOriginal;
        }
    });

    removerBtn.addEventListener('click', async function () {
        if (!estudoAtualId || !window.confirm(I18N_PEDIDO.confirmeRemover)) return;
        removerBtn.disabled = true;
        const textoOriginal = removerBtn.innerHTML;
        removerBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> ' + I18N_PEDIDO.removendo;
        try {
            const response = await fetch('/api/gestao-exames/estudos/' + encodeURIComponent(estudoAtualId) + '/pedido/remover', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                body: JSON.stringify({csrf: csrf.value})
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.msg || I18N_PEDIDO.comunicacao);
            modal.hide();
            window.location.reload();
        } catch (error) {
            mostrarErro(error.message || I18N_PEDIDO.comunicacao);
            removerBtn.disabled = false;
            removerBtn.innerHTML = textoOriginal;
        }
    });
});
<?php endif; ?>

// ── Download em Lote ─────────────────────────────────────────────────────
// Dois modos, ambos reaproveitando os mesmos 3 endpoints do backend
// (iniciar → status → baixar-inteligente), que já são genéricos por job:
//  - Agrupado: 1 chamada de iniciar() com todos os IDs → 1 job → 1 zip.
//  - Individual (padrão): N chamadas de iniciar() com 1 ID cada → N jobs →
//    N zips, cada um disparado como download separado no navegador.
function coletarNomesPorId(ids) {
    const nomes = {};
    ids.forEach(id => {
        const row = document.querySelector('.row-check[value="' + id + '"]');
        nomes[id] = (row?.closest('tr')?.querySelector('.wl-pac-nome')?.textContent?.trim()) || 'PACIENTE';
    });
    return nomes;
}
function iniciarDownloadLote() {
    const ids = Array.from(document.querySelectorAll('.row-check:checked')).map(c => parseInt(c.value));
    if (ids.length === 0) { alert('Selecione ao menos 1 estudo.'); return; }
    if (ids.length > MAX_SEL) { alert('Máximo de ' + MAX_SEL + ' estudos por download.'); return; }
    const nomes   = coletarNomesPorId(ids);
    const agrupar = document.getElementById('chk-agrupar-zip')?.checked || false;
    // 1 único estudo: agrupado ou não dá exatamente no mesmo zip (sem pasta
    // extra), então sempre usa o caminho "grupo" (que aqui processa 1 item só).
    if (agrupar || ids.length === 1) {
        baixarComoGrupo(ids, nomes);
    } else {
        baixarIndividualmente(ids, nomes);
    }
}
// ── Modo Agrupado (comportamento histórico, inalterado) ─────────────────
function baixarComoGrupo(ids, nomes) {
    window._dlPaciente = nomes[ids[0]] || 'PACIENTE';
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
    lbl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Criando archive no ' + <?= json_encode(\App\Config\BrandConfig::PACS_SERVER_NAME) ?> + '...';
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
        alert('Timeout: o ' + <?= json_encode(\App\Config\BrandConfig::PACS_SERVER_NAME) ?> + ' demorou demais para gerar o arquivo.');
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
            throw new Error('O ' + <?= json_encode(\App\Config\BrandConfig::PACS_SERVER_NAME) ?> + ' falhou ao gerar o archive.');
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
// ── Modo Individual (novo padrão) ────────────────────────────────────────
// Roda o ciclo iniciar→poll→baixar uma vez por estudo, sequencialmente (não
// em paralelo) para não disparar o bloqueio de "múltiplos downloads
// automáticos" do Chrome/Edge. Falha em 1 estudo não interrompe os demais.
function sleep(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }
function pollJobPromise(jobId, logId, onProgress) {
    const MAX_TENTATIVAS = 120; // ~2 min
    return new Promise((resolve, reject) => {
        (function tick(tentativa) {
            if (tentativa > MAX_TENTATIVAS) {
                reject(new Error('Timeout: o ' + <?= json_encode(\App\Config\BrandConfig::PACS_SERVER_NAME) ?> + ' demorou demais para gerar o arquivo.'));
                return;
            }
            fetch('/api/download-lote/status?job_id=' + encodeURIComponent(jobId) + '&log_id=' + encodeURIComponent(logId), {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) throw new Error(data.msg || 'Erro no polling');
                if (typeof onProgress === 'function') onProgress(data.progress || 0);
                if (data.state === 'Success') resolve();
                else if (data.state === 'Failure') throw new Error('O ' + <?= json_encode(\App\Config\BrandConfig::PACS_SERVER_NAME) ?> + ' falhou ao gerar o archive.');
                else setTimeout(() => tick(tentativa + 1), 1000);
            })
            .catch(reject);
        })(0);
    });
}
function dispararDownloadIndividual(jobId, logId, nomePaciente, estudoId) {
    const patient = encodeURIComponent(nomePaciente || 'PACIENTE');
    const url = '/api/download-lote/baixar-inteligente?job_id=' + encodeURIComponent(jobId) +
        '&log_id=' + encodeURIComponent(logId) + '&patient=' + patient + '&suffix=' + encodeURIComponent(estudoId);
    const a = document.createElement('a');
    a.href = url;
    a.rel  = 'noopener';
    document.body.appendChild(a);
    a.click();
    a.remove();
}
async function baixarIndividualmente(ids, nomes) {
    const btn  = document.getElementById('btn-download-lote');
    const prog = document.getElementById('wl-dl-progress');
    const bar  = document.getElementById('wl-dl-prog-bar');
    const lbl  = document.getElementById('wl-dl-prog-label');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Iniciando...';
    prog.style.display = 'block';
    bar.style.background = '';
    const erros = [];
    for (let i = 0; i < ids.length; i++) {
        const id   = ids[i];
        const nome = nomes[id] || 'PACIENTE';
        const rotulo = I18N_DL.baixandoIndividual.replace('{atual}', i + 1).replace('{total}', ids.length);
        const base = Math.round((i / ids.length) * 90);
        lbl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> ' + rotulo;
        bar.style.width = base + '%';
        try {
            const iniciado = await fetch('/api/download-lote/iniciar', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                body: JSON.stringify({estudo_ids: [id]})
            }).then(r => r.json());
            if (!iniciado.ok) throw new Error(iniciado.msg || 'Erro ao iniciar job');
            await pollJobPromise(iniciado.job_id, iniciado.log_id, pct => {
                bar.style.width = (base + Math.round((pct / 100) * (90 / ids.length))) + '%';
            });
            dispararDownloadIndividual(iniciado.job_id, iniciado.log_id, nome, id);
            if (i < ids.length - 1) await sleep(700); // evita bloqueio de downloads múltiplos do navegador
        } catch (err) {
            erros.push({ id, nome, motivo: err.message });
        }
    }
    bar.style.width = '100%';
    if (erros.length > 0) {
        bar.style.background = '#ef4444';
        const msg = I18N_DL.erroParcial.replace('{n}', erros.length).replace('{total}', ids.length);
        lbl.innerHTML = '<i class="fa fa-triangle-exclamation"></i> ' + msg;
        alert(msg + '\n' + erros.map(e => '- ' + e.nome + ': ' + e.motivo).join('\n'));
    } else {
        bar.style.background = '#22c55e';
        lbl.innerHTML = '<i class="fa fa-check"></i> Concluído!';
    }
    setTimeout(resetDownloadUI, erros.length ? 4000 : 2000);
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
<?php if ($modoGestao && $podeGerenciarPedido): ?>
<script src="/assets/js/gestao-exames-gerenciar.js?v=20260826-tenant-context"></script>
<?php endif; ?>
