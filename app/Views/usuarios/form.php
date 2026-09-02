<?php
$usuario       = $usuario       ?? null;
$modulosAtivos = $modulosAtivos ?? [];
$medicos       = $medicos       ?? [];
$modulos       = $modulos       ?? [];
$modPadrao     = $modPadrao     ?? [];
$relatorioModulos = $relatorioModulos ?? [];
$relatorioSubmodulos = $relatorioSubmodulos ?? [];
$viewerCatalog = $viewerCatalog ?? [];
$viewerStates = $viewerStates ?? [];
$worklistPreference = $worklistPreference ?? ['enabled' => false, 'sort_mode' => 'recentes', 'priority_order' => 'urgencia_primeiro', 'medical_status_order' => []];
$title         = $title         ?? 'Usuário';
$error         = $error         ?? '';
$isEdit        = $usuario !== null;

$val = function (string $campo) use ($usuario): string {
    if (!$usuario) return '';
    $v = is_array($usuario) ? ($usuario[$campo] ?? '') : ($usuario->$campo ?? '');
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

$perfilAtual  = $isEdit ? ($usuario['perfil'] ?? 'viewer') : 'viewer';
$medicoAtual  = $isEdit ? (int)($usuario['medico_id'] ?? 0) : 0;
$action       = $isEdit ? '/usuarios/' . $val('id') . '/update' : '/usuarios';
$worklistStatusOrder = array_values(array_filter((array) ($worklistPreference['medical_status_order'] ?? []), static fn ($status): bool => in_array($status, ['pendente', 'a_laudar', 'em_laudo', 'rascunho', 'assinado', 'peer_review'], true)));
foreach (['pendente', 'a_laudar', 'em_laudo', 'rascunho', 'assinado', 'peer_review'] as $defaultStatus) {
    if (!in_array($defaultStatus, $worklistStatusOrder, true)) $worklistStatusOrder[] = $defaultStatus;
}

$errorMsgs = [
    'campos_obrigatorios' => 'Preencha todos os campos obrigatórios.',
    'email_invalido'      => 'O e-mail informado não é válido.',
    'email_ja_cadastrado' => 'Este e-mail já está cadastrado neste negócio.',
    'erro_interno'        => 'Ocorreu um erro interno. Tente novamente.',
];
?>

<style>
/* Mantém os controles de visualizador no mesmo padrão visual de permissões do PACS. */
.modulo-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.5rem;margin-top:.5rem; }
.modulo-item { display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;border-radius:6px;border:1px solid var(--pacs-border);cursor:pointer;transition:all .15s; }
.modulo-item:hover { border-color:var(--pacs-primary);background:rgba(79,195,247,.05); }
.modulo-item input[type=checkbox] { accent-color:var(--pacs-primary);width:15px;height:15px;flex-shrink:0; }
.modulo-item.checked { border-color:var(--pacs-primary);background:rgba(79,195,247,.08); }
.modulo-item.is-disabled { cursor:not-allowed; opacity:.45; }
.modulo-item.is-disabled:hover { border-color:var(--pacs-border); background:transparent; }
.modulo-item .mod-icon { color:var(--pacs-primary);font-size:.8rem;width:16px;text-align:center; }
.modulo-item .mod-label { font-size:.8rem;font-weight:500; }
.perfil-card { border:2px solid var(--pacs-border);border-radius:8px;padding:.75rem 1rem;cursor:pointer;transition:all .15s;display:flex;align-items:flex-start;gap:.6rem; }
.perfil-card:hover { border-color:var(--pacs-primary); }
.perfil-card.selected { border-color:var(--pacs-primary);background:rgba(79,195,247,.06); }
.perfil-card input[type=radio] { accent-color:var(--pacs-primary);margin-top:.15rem;flex-shrink:0; }
.perfil-card-title { font-size:.85rem;font-weight:700; }
.perfil-card-desc  { font-size:.72rem;color:var(--pacs-text-muted);margin-top:.15rem; }
.perfis-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:.5rem;margin-top:.5rem; }
.usuario-tabs { display:flex; gap:.35rem; border-bottom:1px solid var(--pacs-border); margin-bottom:1rem; }
.usuario-tab { border:0; border-bottom:2px solid transparent; padding:.65rem .8rem; color:var(--pacs-text-muted); background:transparent; font-size:.82rem; font-weight:700; cursor:pointer; }
.usuario-tab.is-active { color:var(--pacs-primary); border-bottom-color:var(--pacs-primary); }
.worklist-pref-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem; }
.worklist-choice { display:flex;align-items:flex-start;gap:.55rem;padding:.65rem;border:1px solid var(--pacs-border);border-radius:7px;cursor:pointer; }
.worklist-choice input { margin-top:.15rem;accent-color:var(--pacs-primary); }.worklist-choice strong,.worklist-choice small { display:block; }.worklist-choice strong{font-size:.8rem}.worklist-choice small{font-size:.7rem;color:var(--pacs-text-muted);margin-top:.12rem;line-height:1.35}
.worklist-status-order { list-style:none;margin:.5rem 0 0;padding:0;max-width:480px; }.worklist-status-order li{display:flex;align-items:center;gap:.5rem;padding:.48rem .6rem;margin-bottom:.35rem;border:1px solid var(--pacs-border);border-radius:6px;background:var(--pacs-bg);}.worklist-status-order .drag{color:var(--pacs-text-muted);cursor:grab}.worklist-status-order .label{flex:1;font-size:.78rem;font-weight:700}.worklist-status-order button{border:0;background:transparent;color:var(--pacs-primary);padding:.1rem .3rem}
</style>

<!-- Cabeçalho -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">
            <i class="fa fa-user-plus me-2 text-pacs-primary"></i><?= htmlspecialchars($title) ?>
        </h1>
        <p class="text-muted small mb-0 mt-1">
            <?= $isEdit ? 'Atualize os dados, perfil e permissões do usuário' : 'Preencha os dados para criar um novo usuário' ?>
        </p>
    </div>
    <a href="/usuarios" class="btn-pacs-outline">
        <i class="fa fa-arrow-left me-1"></i> Voltar
    </a>
</div>

<!-- Alerta de erro -->
<?php if ($error && isset($errorMsgs[$error])): ?>
<div class="pacs-alert pacs-alert-danger mb-4">
    <i class="fa fa-triangle-exclamation me-2"></i> <?= $errorMsgs[$error] ?>
</div>
<?php endif; ?>

<form method="POST" action="<?= $action ?>" id="formUsuario" novalidate>
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <div class="usuario-tabs" role="tablist">
        <button type="button" class="usuario-tab is-active" data-user-tab-trigger="dados" role="tab"><?= htmlspecialchars(t('worklist_preferencias.usuario.dados')) ?></button>
        <button type="button" class="usuario-tab" data-user-tab-trigger="estudos" role="tab"><?= htmlspecialchars(t('worklist_preferencias.usuario.aba')) ?></button>
    </div>
    <div data-user-tab-panel="dados">

<!-- ════════════════════════════════════════════════════════
     SEÇÃO 1 — DADOS BÁSICOS
════════════════════════════════════════════════════════ -->
<div class="pacs-card mb-3">
    <div class="pacs-card-body">
        <div class="form-section-title"><i class="fa fa-id-card me-2"></i>Dados do Usuário</div>

        <div class="form-grid" style="grid-template-columns:1fr 1fr;">
            <div>
                <label class="form-label-dark" for="userName">
                    Nome completo <span class="text-danger">*</span>
                </label>
                <input type="text" id="userName" name="name" class="form-control-dark"
                       value="<?= $val('name') ?>" placeholder="João da Silva"
                       required minlength="3" maxlength="200" autofocus>
            </div>
            <div>
                <label class="form-label-dark" for="userEmail">
                    E-mail <?= !$isEdit ? '<span class="text-danger">*</span>' : '' ?>
                </label>
                <?php if ($isEdit): ?>
                    <input type="email" class="form-control-dark" value="<?= $val('email') ?>" disabled
                           style="opacity:.6;cursor:not-allowed;" title="O e-mail não pode ser alterado">
                    <small style="color:var(--pacs-text-muted);font-size:.7rem;">
                        <i class="fa fa-lock me-1"></i>E-mail não pode ser alterado
                    </small>
                <?php else: ?>
                    <input type="email" id="userEmail" name="email" class="form-control-dark"
                           value="<?= $val('email') ?>" placeholder="medico@clinica.com.br"
                           required maxlength="255">
                    <small style="color:var(--pacs-text-muted);font-size:.7rem;">
                        <i class="fa fa-envelope me-1"></i>Um link para criar a senha será enviado para este e-mail
                    </small>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     SEÇÃO 2 — PERFIL DE ACESSO
════════════════════════════════════════════════════════ -->
<div class="pacs-card mb-3">
    <div class="pacs-card-body">
        <div class="form-section-title"><i class="fa fa-shield-halved me-2"></i>Perfil de Acesso</div>
        <p style="font-size:.8rem;color:var(--pacs-text-muted);margin-bottom:.75rem;">
            O perfil define o nível de acesso padrão. Os módulos podem ser ajustados individualmente abaixo.
        </p>

        <div class="perfis-grid" id="perfisList">
            <?php
            $perfisInfo = [
                'admin'      => ['Administrador',  'fa-shield-halved',   'Acesso total: gerencia usuários, configurações e todos os módulos.'],
                'medico'     => ['Médico',          'fa-stethoscope',     'Acessa worklist e laudos. Vê apenas seus próprios estudos.'],
                'secretaria' => ['Secretaria',      'fa-clipboard-user',  'Acessa worklist e agendamentos. Sem acesso a laudos ou financeiro.'],
                'analista'   => ['Analista',        'fa-chart-bar',       'Leitura de todos os módulos. Pode exportar dados.'],
                'viewer'     => ['Visualizador',    'fa-eye',             'Somente leitura básica da worklist.'],
            ];
            foreach ($perfisInfo as $pVal => [$pName, $pIcon, $pDesc]):
                $sel = $perfilAtual === $pVal;
            ?>
            <label class="perfil-card <?= $sel ? 'selected' : '' ?>" id="card_<?= $pVal ?>">
                <input type="radio" name="perfil" value="<?= $pVal ?>"
                       <?= $sel ? 'checked' : '' ?>
                       onchange="onPerfilChange('<?= $pVal ?>')">
                <div>
                    <div class="perfil-card-title">
                        <i class="fa <?= $pIcon ?> me-1" style="color:var(--pacs-primary);"></i>
                        <?= $pName ?>
                    </div>
                    <div class="perfil-card-desc"><?= $pDesc ?></div>
                </div>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     SEÇÃO 3 — MÓDULOS HABILITADOS
════════════════════════════════════════════════════════ -->
<div class="pacs-card mb-3">
    <div class="pacs-card-body">
        <div class="d-flex justify-content-between align-items-center">
            <div class="form-section-title" style="margin-bottom:0;">
                <i class="fa fa-puzzle-piece me-2"></i>Módulos Habilitados
            </div>
            <div style="display:flex;gap:.5rem;">
                <button type="button" class="btn-pacs-outline" style="padding:.2rem .6rem;font-size:.72rem;"
                        onclick="toggleTodosModulos(true)">
                    <i class="fa fa-check-double me-1"></i>Todos
                </button>
                <button type="button" class="btn-pacs-outline" style="padding:.2rem .6rem;font-size:.72rem;"
                        onclick="toggleTodosModulos(false)">
                    <i class="fa fa-xmark me-1"></i>Nenhum
                </button>
            </div>
        </div>
        <p style="font-size:.8rem;color:var(--pacs-text-muted);margin:.5rem 0 0;">
            Selecione quais módulos este usuário pode acessar. O perfil define os padrões, mas você pode personalizar.
        </p>

        <div class="modulo-grid" id="moduloGrid">
            <?php foreach ($modulos as $mKey => $mInfo):
                $checked = $isEdit
                    ? in_array($mKey, $modulosAtivos)
                    : in_array($mKey, $modPadrao[$perfilAtual] ?? []);
            ?>
            <label class="modulo-item <?= $checked ? 'checked' : '' ?>" id="modItem_<?= $mKey ?>">
                <input type="checkbox" name="modulos[]" value="<?= $mKey ?>"
                       <?= $checked ? 'checked' : '' ?>
                       onchange="this.closest('.modulo-item').classList.toggle('checked', this.checked)">
                <i class="fa <?= $mInfo['icon'] ?> mod-icon"></i>
                <span class="mod-label"><?= $mInfo['label'] ?></span>
            </label>
            <?php endforeach; ?>
        </div>

        <div id="relatorioSubmodulos" style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--pacs-border);">
            <div class="form-section-title" style="font-size:.92rem;margin-bottom:.25rem;"><i class="fa fa-chart-line me-2"></i>Submódulos de Relatórios</div>
            <p style="font-size:.78rem;color:var(--pacs-text-muted);margin:.25rem 0 .65rem;">Disponíveis somente quando o módulo Relatórios estiver habilitado.</p>
            <div class="modulo-grid">
                <?php foreach ($relatorioSubmodulos as $rKey => $rInfo): $checked = in_array($rKey, $relatorioModulos, true); ?>
                <label class="modulo-item <?= $checked ? 'checked' : '' ?>" data-report-module>
                    <input type="checkbox" name="relatorio_modulos[]" value="<?= htmlspecialchars($rKey) ?>" <?= $checked ? 'checked' : '' ?> onchange="this.closest('.modulo-item').classList.toggle('checked', this.checked)">
                    <i class="fa <?= htmlspecialchars($rInfo['icon']) ?> mod-icon"></i>
                    <span class="mod-label"><?= htmlspecialchars($rInfo['label']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     SEÇÃO 4 — VISUALIZADORES HABILITADOS
════════════════════════════════════════════════════════ -->
<div class="pacs-card mb-3">
    <div class="pacs-card-body">
        <div class="d-flex justify-content-between align-items-center">
            <div class="form-section-title" style="margin-bottom:0;"><i class="fa fa-eye me-2"></i><?= htmlspecialchars(t('viewer_access.form.titulo')) ?></div>
            <div style="display:flex;gap:.5rem;">
                <button type="button" class="btn-pacs-outline" data-viewer-toggle style="padding:.2rem .6rem;font-size:.72rem;" onclick="toggleTodosVisualizadores(true)"><i class="fa fa-check-double me-1"></i><?= htmlspecialchars(t('viewer_access.form.todos')) ?></button>
                <button type="button" class="btn-pacs-outline" data-viewer-toggle style="padding:.2rem .6rem;font-size:.72rem;" onclick="toggleTodosVisualizadores(false)"><i class="fa fa-xmark me-1"></i><?= htmlspecialchars(t('viewer_access.form.nenhum')) ?></button>
            </div>
        </div>
        <p style="font-size:.8rem;color:var(--pacs-text-muted);margin:.5rem 0 0;"><?= htmlspecialchars(t('viewer_access.form.descricao')) ?></p>
        <p id="viewerAdminFullNotice" style="display:none;font-size:.78rem;color:var(--pacs-text-muted);margin:.45rem 0 0;"><i class="fa fa-shield-halved me-1"></i><?= htmlspecialchars(t('viewer_access.form.admin_integral')) ?></p>
        <div class="modulo-grid" id="viewerGrid">
            <input type="hidden" name="visualizadores_present" value="1">
            <?php foreach ($viewerCatalog as $viewerKey => $viewerInfo):
                $viewerState = $viewerStates[$viewerKey] ?? ['enabled' => true, 'tenant_available' => true, 'editable' => true];
                $checked = !empty($viewerState['enabled']);
                $tenantAvailable = !empty($viewerState['tenant_available']);
                $editable = !empty($viewerState['editable']);
            ?>
            <label class="modulo-item <?= $checked ? 'checked' : '' ?><?= !$tenantAvailable ? ' is-disabled' : '' ?>" data-viewer-module data-tenant-available="<?= $tenantAvailable ? '1' : '0' ?>">
                <input type="checkbox" name="visualizadores[]" value="<?= htmlspecialchars($viewerKey, ENT_QUOTES) ?>" <?= $checked ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?> onchange="this.closest('.modulo-item').classList.toggle('checked', this.checked)">
                <i class="fa <?= htmlspecialchars((string) ($viewerInfo['icon'] ?? 'fa-eye')) ?> mod-icon"></i>
                <span class="mod-label"><?= htmlspecialchars(t((string) $viewerInfo['label_key'])) ?></span>
                <?php if (!$tenantAvailable): ?><small class="ms-auto" style="font-size:.68rem;color:var(--pacs-text-muted);"><?= htmlspecialchars(t('viewer_access.form.indisponivel_tenant')) ?></small><?php endif; ?>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     SEÇÃO 5 — VÍNCULO COM MÉDICO
════════════════════════════════════════════════════════ -->
<div class="pacs-card mb-3" id="cardMedico">
    <div class="pacs-card-body">
        <div class="form-section-title"><i class="fa fa-user-doctor me-2"></i>Vínculo com Médico</div>
        <p style="font-size:.8rem;color:var(--pacs-text-muted);margin-bottom:.75rem;">
            Opcional. Vincule este usuário a um médico cadastrado para que a worklist filtre automaticamente seus estudos.
        </p>

        <div style="max-width:400px;">
            <label class="form-label-dark" for="medicoId">Médico cadastrado</label>
            <select name="medico_id" id="medicoId" class="form-control-dark">
                <option value="0">— Nenhum vínculo —</option>
                <?php foreach ($medicos as $med): ?>
                    <option value="<?= $med['id'] ?>"
                        <?= $medicoAtual === (int)$med['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($med['nome']) ?>
                        <?php if (!empty($med['crm'])): ?>
                            — CRM <?= htmlspecialchars($med['crm']) ?>
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($medicos) && !$medicoAtual): ?>
            <small style="color:var(--pacs-text-muted);font-size:.72rem;">
                <i class="fa fa-circle-info me-1"></i>
                Todos os médicos cadastrados já possuem usuário vinculado.
                <a href="/medicos/create" style="color:var(--pacs-primary);">Cadastrar novo médico</a>
            </small>
            <?php elseif ($medicoAtual && !empty($usuario['medico_nome'])): ?>
            <small style="color:#34d399;font-size:.72rem;">
                <i class="fa fa-check-circle me-1"></i>
                Vinculado a: <strong><?= htmlspecialchars($usuario['medico_nome']) ?></strong>
            </small>
            <?php endif; ?>
        </div>
    </div>
</div>

    </div>

    <section data-user-tab-panel="estudos" hidden>
        <div class="pacs-card mb-3">
            <div class="pacs-card-body">
                <div class="form-section-title"><i class="fa fa-arrow-down-wide-short me-2"></i><?= htmlspecialchars(t('worklist_preferencias.usuario.titulo')) ?></div>
                <p style="font-size:.8rem;color:var(--pacs-text-muted);margin-bottom:.75rem;"><?= htmlspecialchars(t('worklist_preferencias.usuario.ajuda')) ?></p>
                <?php if (!$isEdit): ?>
                    <div class="pacs-alert pacs-alert-info"><i class="fa fa-circle-info me-2"></i><?= htmlspecialchars(t('worklist_preferencias.usuario.apos_criacao')) ?></div>
                <?php else: ?>
                    <label class="worklist-choice mb-3"><input type="checkbox" name="worklist_preferences[enabled]" value="1" <?= !empty($worklistPreference['enabled']) ? 'checked' : '' ?>><span><strong><?= htmlspecialchars(t('worklist_preferencias.usuario.ativar')) ?></strong><small><?= htmlspecialchars(t('worklist_preferencias.usuario.fallback')) ?></small></span></label>
                    <div class="form-section-title" style="font-size:.9rem;"><?= htmlspecialchars(t('worklist_preferencias.usuario.ordenacao')) ?></div>
                    <div class="worklist-pref-grid">
                        <?php foreach (['recentes', 'prioridade', 'situacao_medica'] as $mode): ?>
                        <label class="worklist-choice" <?= $mode === 'situacao_medica' ? 'data-medical-order-option' : '' ?>><input type="radio" name="worklist_preferences[sort_mode]" value="<?= $mode ?>" <?= ($worklistPreference['sort_mode'] ?? 'recentes') === $mode ? 'checked' : '' ?>><span><strong><?= htmlspecialchars(t('worklist_preferencias.ordem.' . $mode)) ?></strong><small><?= htmlspecialchars(t('worklist_preferencias.ordem.' . $mode . '_ajuda')) ?></small></span></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-section-title mt-3" style="font-size:.9rem;"><?= htmlspecialchars(t('worklist_preferencias.usuario.prioridade')) ?></div>
                    <div class="worklist-pref-grid">
                        <?php foreach (['urgencia_primeiro', 'rotina_primeiro'] as $priority): ?>
                        <label class="worklist-choice"><input type="radio" name="worklist_preferences[priority_order]" value="<?= $priority ?>" <?= ($worklistPreference['priority_order'] ?? 'urgencia_primeiro') === $priority ? 'checked' : '' ?>><span><strong><?= htmlspecialchars(t('worklist_preferencias.prioridade.' . $priority)) ?></strong><small><?= htmlspecialchars(t('worklist_preferencias.prioridade.' . $priority . '_ajuda')) ?></small></span></label>
                        <?php endforeach; ?>
                    </div>
                    <div id="worklistMedicalPreferences" class="mt-3">
                        <div class="form-section-title" style="font-size:.9rem;"><?= htmlspecialchars(t('worklist_preferencias.usuario.status_medico')) ?></div>
                        <p style="font-size:.76rem;color:var(--pacs-text-muted);margin:.2rem 0 .5rem;"><?= htmlspecialchars(t('worklist_preferencias.usuario.status_medico_ajuda')) ?></p>
                        <ol class="worklist-status-order" data-worklist-status-order>
                        <?php foreach ($worklistStatusOrder as $status): ?>
                            <li draggable="true"><i class="fa fa-grip-vertical drag" aria-hidden="true"></i><input type="hidden" name="worklist_preferences[medical_status_order][]" value="<?= htmlspecialchars($status) ?>"><span class="label"><?= htmlspecialchars(t('worklist_preferencias.status.' . $status)) ?></span><button type="button" data-order-up aria-label="<?= htmlspecialchars(t('worklist_preferencias.acao.subir')) ?>"><i class="fa fa-arrow-up"></i></button><button type="button" data-order-down aria-label="<?= htmlspecialchars(t('worklist_preferencias.acao.descer')) ?>"><i class="fa fa-arrow-down"></i></button></li>
                        <?php endforeach; ?>
                        </ol>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

<!-- ════════════════════════════════════════════════════════
     BOTÕES
════════════════════════════════════════════════════════ -->
<div style="display:flex;gap:.75rem;align-items:center;margin-top:1rem;">
    <button type="submit" class="btn-pacs-primary">
        <i class="fa fa-save me-1"></i>
        <?= $isEdit ? 'Salvar alterações' : 'Criar usuário e enviar link' ?>
    </button>
    <a href="/usuarios" class="btn-pacs-outline">Cancelar</a>
    <?php if ($isEdit): ?>
    <form method="POST" action="/usuarios/<?= $val('id') ?>/reenviar-link"
          style="display:inline;margin-left:auto;"
          onsubmit="return confirm('Reenviar link de acesso para este usuário?')">
        <button type="submit" class="btn-pacs-outline" style="font-size:.8rem;">
            <i class="fa fa-envelope me-1"></i> Reenviar link de acesso
        </button>
    </form>
    <?php endif; ?>
</div>

</form>

<script>
function atualizarSubmodulosRelatorios() {
    const enabled = document.querySelector('input[name="modulos[]"][value="relatorios"]')?.checked;
    document.querySelectorAll('[data-report-module] input').forEach((input) => {
        input.disabled = !enabled;
        input.closest('[data-report-module]').style.opacity = enabled ? '1' : '.45';
    });
}
document.addEventListener('DOMContentLoaded', () => {
    atualizarSubmodulosRelatorios();
    document.querySelector('input[name="modulos[]"][value="relatorios"]')?.addEventListener('change', atualizarSubmodulosRelatorios);
    const showTab = (tab) => {
        document.querySelectorAll('[data-user-tab-panel]').forEach((panel) => panel.hidden = panel.dataset.userTabPanel !== tab);
        document.querySelectorAll('[data-user-tab-trigger]').forEach((button) => button.classList.toggle('is-active', button.dataset.userTabTrigger === tab));
    };
    document.querySelectorAll('[data-user-tab-trigger]').forEach((button) => button.addEventListener('click', () => showTab(button.dataset.userTabTrigger)));
    const list = document.querySelector('[data-worklist-status-order]');
    if (list) {
        let dragged = null;
        list.querySelectorAll('li').forEach((item) => {
            item.addEventListener('dragstart', () => { dragged = item; });
            item.addEventListener('dragover', (event) => event.preventDefault());
            item.addEventListener('drop', (event) => { event.preventDefault(); if (dragged && dragged !== item) list.insertBefore(dragged, item); });
        });
        list.addEventListener('click', (event) => {
            const button = event.target.closest('button'); if (!button) return;
            const item = button.closest('li'); if (!item) return;
            if (button.hasAttribute('data-order-up') && item.previousElementSibling) list.insertBefore(item, item.previousElementSibling);
            if (button.hasAttribute('data-order-down') && item.nextElementSibling) list.insertBefore(item.nextElementSibling, item);
        });
    }
    atualizarPreferenciasMedicas();
    atualizarVisualizadoresPorPerfil();
});
// Módulos padrão por perfil (espelha PHP)
const modPadrao = <?= json_encode($modPadrao, JSON_UNESCAPED_UNICODE) ?>;

function onPerfilChange(perfil) {
    // Atualiza visual dos cards de perfil
    document.querySelectorAll('.perfil-card').forEach(c => c.classList.remove('selected'));
    const card = document.getElementById('card_' + perfil);
    if (card) card.classList.add('selected');

    // Atualiza módulos para o padrão do perfil
    const defaults = modPadrao[perfil] || [];
    document.querySelectorAll('#moduloGrid input[type=checkbox]').forEach(cb => {
        const checked = defaults.includes(cb.value);
        cb.checked = checked;
        cb.closest('.modulo-item').classList.toggle('checked', checked);
    });

    // Exibe/oculta card de médico conforme perfil
    const cardMedico = document.getElementById('cardMedico');
    if (cardMedico) {
        cardMedico.style.display = (perfil === 'medico') ? '' : '';
    }
    atualizarPreferenciasMedicas();
    atualizarVisualizadoresPorPerfil();
}

function atualizarPreferenciasMedicas() {
    const perfil = document.querySelector('input[name="perfil"]:checked')?.value;
    const preferencias = document.getElementById('worklistMedicalPreferences');
    if (preferencias) preferencias.style.display = perfil === 'medico' ? '' : 'none';
    document.querySelectorAll('[data-medical-order-option]').forEach((option) => option.style.display = perfil === 'medico' ? '' : 'none');
    if (perfil !== 'medico') {
        const medicalSort = document.querySelector('input[name="worklist_preferences[sort_mode]"][value="situacao_medica"]');
        const recentSort = document.querySelector('input[name="worklist_preferences[sort_mode]"][value="recentes"]');
        if (medicalSort?.checked && recentSort) recentSort.checked = true;
    }
}

function atualizarVisualizadoresPorPerfil() {
    const isAdmin = document.querySelector('input[name="perfil"]:checked')?.value === 'admin';
    const notice = document.getElementById('viewerAdminFullNotice');
    if (notice) notice.style.display = isAdmin ? '' : 'none';
    document.querySelectorAll('[data-viewer-toggle]').forEach((button) => { button.disabled = isAdmin; });
    document.querySelectorAll('[data-viewer-module]').forEach((item) => {
        const input = item.querySelector('input[type=checkbox]');
        const tenantAvailable = item.dataset.tenantAvailable === '1';
        if (!input) return;
        if (isAdmin) {
            input.checked = true;
            input.disabled = true;
            item.classList.add('checked');
            item.style.opacity = '.6';
        } else {
            input.disabled = !tenantAvailable;
            item.style.opacity = tenantAvailable ? '1' : '.45';
        }
    });
}

function toggleTodosModulos(state) {
    document.querySelectorAll('#moduloGrid input[type=checkbox]').forEach(cb => {
        cb.checked = state;
        cb.closest('.modulo-item').classList.toggle('checked', state);
    });
}

function toggleTodosVisualizadores(state) {
    document.querySelectorAll('#viewerGrid [data-viewer-module] input[type=checkbox]:not(:disabled)').forEach((checkbox) => {
        checkbox.checked = state;
        checkbox.closest('.modulo-item').classList.toggle('checked', state);
    });
}
</script>
