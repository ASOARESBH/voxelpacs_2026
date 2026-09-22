<?php
/** Configuração global de módulos — layout Plataforma, acesso exclusivo de superadmin. */
$modules = $modules ?? [];
$states = $states ?? [];
$refresh = $refresh ?? ['ativo' => true, 'segundos' => 60];
$worklistDefaults = $worklistDefaults ?? ['sort_mode' => 'recentes', 'priority_order' => 'urgencia_primeiro', 'medical_status_order' => ['pendente', 'a_laudar', 'em_laudo', 'rascunho', 'assinado', 'peer_review']];
$csrfToken = (string) ($csrfToken ?? '');
$worklistStatusOrder = (array) ($worklistDefaults['medical_status_order'] ?? []);
$sectionLabels = [
    'worklist' => t('config_modulos.secao.worklist'),
    'pacs' => t('config_modulos.secao.pacs'),
    'cadastros' => t('config_modulos.secao.cadastros'),
    'relatorios' => t('config_modulos.secao.relatorios'),
    'sistema' => t('config_modulos.secao.sistema'),
];
$grouped = [];
foreach ($modules as $key => $module) {
    $grouped[$module['section']][$key] = $module;
}
?>

<div class="config-modules-page">
    <div class="config-modules-hero">
        <div>
            <p class="config-modules-kicker"><i class="fa fa-shield-halved"></i> <?= htmlspecialchars(t('config_modulos.kicker')) ?></p>
            <h1><i class="fa fa-sliders"></i> <?= htmlspecialchars(t('config_modulos.titulo')) ?></h1>
            <p><?= htmlspecialchars(t('config_modulos.subtitulo')) ?></p>
        </div>
        <a href="/platform/dashboard" class="btn-pacs-secondary"><i class="fa fa-arrow-left"></i> <?= htmlspecialchars(t('comum.acoes.voltar')) ?></a>
    </div>

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="pacs-alert pacs-alert-success"><i class="fa fa-check-circle"></i> <?= htmlspecialchars((string) $_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div class="pacs-alert pacs-alert-error"><i class="fa fa-triangle-exclamation"></i> <?= htmlspecialchars((string) $_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <div class="config-modules-rule">
        <i class="fa fa-circle-info"></i>
        <span><?= htmlspecialchars(t('config_modulos.precedencia')) ?></span>
    </div>

    <section class="pacs-card config-module-card config-module-global">
        <div class="pacs-card-header">
            <span><i class="fa fa-globe text-pacs-primary"></i> <?= htmlspecialchars(t('config_modulos.global.titulo')) ?></span>
            <small><?= htmlspecialchars(t('config_modulos.global.badge')) ?></small>
        </div>
        <div class="pacs-card-body">
            <p class="config-module-help"><?= htmlspecialchars(t('config_modulos.global.ajuda')) ?></p>
            <form method="post" action="/platform/configuracao-modulos/salvar">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="config-module-grid">
                <?php foreach ($grouped as $section => $items): ?>
                    <div class="config-module-section">
                        <div class="config-module-section-title"><?= htmlspecialchars($sectionLabels[$section] ?? $section) ?></div>
                        <?php foreach ($items as $key => $module): $state = $states[$key] ?? ['global' => true]; ?>
                            <label class="config-module-toggle <?= !empty($state['global']) ? 'is-enabled' : 'is-disabled' ?>">
                                <input type="checkbox" name="global[<?= htmlspecialchars($key) ?>]" value="1" <?= !empty($state['global']) ? 'checked' : '' ?>>
                                <span class="config-module-icon"><i class="fa <?= htmlspecialchars($module['icon']) ?>"></i></span>
                                <span class="config-module-copy"><strong><?= htmlspecialchars(t($module['label_key'])) ?></strong><small><?= !empty($state['global']) ? htmlspecialchars(t('config_modulos.status.habilitado')) : htmlspecialchars(t('config_modulos.status.bloqueado_global')) ?></small></span>
                                <span class="config-module-switch" aria-hidden="true"></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                </div>
                <button class="btn-pacs-primary" type="submit"><i class="fa fa-floppy-disk"></i> <?= htmlspecialchars(t('config_modulos.global.salvar')) ?></button>
            </form>
        </div>
    </section>

    <section class="pacs-card config-module-card config-refresh-card">
        <div class="pacs-card-header"><span><i class="fa fa-rotate text-pacs-primary"></i> <?= htmlspecialchars(t('config_modulos.refresh.titulo')) ?></span></div>
        <div class="pacs-card-body">
            <p class="config-module-help"><?= htmlspecialchars(t('config_modulos.refresh.ajuda')) ?></p>
            <form method="post" action="/platform/configuracao-modulos/estudos/salvar">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <label class="config-refresh-enable">
                    <input type="checkbox" name="estudos_auto_refresh_ativo" value="1" <?= !empty($refresh['ativo']) ? 'checked' : '' ?>>
                    <span><strong><?= htmlspecialchars(t('config_modulos.refresh.ativo')) ?></strong><small><?= htmlspecialchars(t('config_modulos.refresh.ativo_ajuda')) ?></small></span>
                </label>
                <div class="config-refresh-field">
                    <label class="form-label-dark" for="estudos_auto_refresh_segundos"><?= htmlspecialchars(t('config_modulos.refresh.intervalo')) ?></label>
                    <div class="config-refresh-input">
                        <input id="estudos_auto_refresh_segundos" class="form-control-dark" type="number" min="<?= (int) $refreshMin ?>" max="<?= (int) $refreshMax ?>" step="5" name="estudos_auto_refresh_segundos" value="<?= (int) $refresh['segundos'] ?>">
                        <span><?= htmlspecialchars(t('config_modulos.refresh.segundos')) ?></span>
                    </div>
                    <small><?= htmlspecialchars(t('config_modulos.refresh.intervalo_ajuda')) ?></small>
                </div>
                <div class="config-refresh-safety"><i class="fa fa-hand"></i> <?= htmlspecialchars(t('config_modulos.refresh.pausa')) ?></div>
                <div class="config-worklist-defaults">
                    <div class="form-section-title"><i class="fa fa-arrow-down-wide-short me-2"></i><?= htmlspecialchars(t('worklist_preferencias.global.titulo')) ?></div>
                    <p class="config-module-help"><?= htmlspecialchars(t('worklist_preferencias.global.ajuda')) ?></p>
                    <div class="config-worklist-grid">
                        <?php foreach (['recentes', 'prioridade', 'situacao_medica'] as $mode): ?>
                        <label class="config-worklist-choice"><input type="radio" name="worklist[sort_mode]" value="<?= $mode ?>" <?= ($worklistDefaults['sort_mode'] ?? 'recentes') === $mode ? 'checked' : '' ?>><span><strong><?= htmlspecialchars(t('worklist_preferencias.ordem.' . $mode)) ?></strong><small><?= htmlspecialchars(t('worklist_preferencias.ordem.' . $mode . '_ajuda')) ?></small></span></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="config-worklist-grid mt-2">
                        <?php foreach (['urgencia_primeiro', 'rotina_primeiro'] as $priority): ?>
                        <label class="config-worklist-choice"><input type="radio" name="worklist[priority_order]" value="<?= $priority ?>" <?= ($worklistDefaults['priority_order'] ?? 'urgencia_primeiro') === $priority ? 'checked' : '' ?>><span><strong><?= htmlspecialchars(t('worklist_preferencias.prioridade.' . $priority)) ?></strong><small><?= htmlspecialchars(t('worklist_preferencias.prioridade.' . $priority . '_ajuda')) ?></small></span></label>
                        <?php endforeach; ?>
                    </div>
                    <p class="config-worklist-status-title"><?= htmlspecialchars(t('worklist_preferencias.global.status_medico')) ?></p>
                    <ol class="config-worklist-status-order" data-worklist-status-order>
                    <?php foreach ($worklistStatusOrder as $status): ?>
                        <li draggable="true"><i class="fa fa-grip-vertical" aria-hidden="true"></i><input type="hidden" name="worklist[medical_status_order][]" value="<?= htmlspecialchars((string) $status) ?>"><span><?= htmlspecialchars(t('worklist_preferencias.status.' . $status)) ?></span><button type="button" data-order-up aria-label="<?= htmlspecialchars(t('worklist_preferencias.acao.subir')) ?>"><i class="fa fa-arrow-up"></i></button><button type="button" data-order-down aria-label="<?= htmlspecialchars(t('worklist_preferencias.acao.descer')) ?>"><i class="fa fa-arrow-down"></i></button></li>
                    <?php endforeach; ?>
                    </ol>
                </div>
                <button class="btn-pacs-primary" type="submit"><i class="fa fa-floppy-disk"></i> <?= htmlspecialchars(t('config_modulos.refresh.salvar')) ?></button>
            </form>
        </div>
    </section>

    <section class="config-module-note">
        <i class="fa fa-user-gear"></i>
        <div><strong><?= htmlspecialchars(t('config_modulos.usuario.titulo')) ?></strong><p><?= htmlspecialchars(t('config_modulos.usuario.ajuda')) ?></p></div>
    </section>
</div>

<style>
.config-modules-page{max-width:1240px;margin:0 auto;padding-bottom:2rem}.config-modules-hero{display:flex;justify-content:space-between;gap:1.5rem;align-items:flex-start;margin-bottom:1.25rem}.config-modules-kicker{font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;color:var(--pacs-primary);font-weight:700;margin:0 0 .35rem}.config-modules-hero h1{font-size:1.35rem;margin:0;color:var(--pacs-text)}.config-modules-hero>div>p:last-child{color:var(--pacs-text-muted);margin:.35rem 0 0;font-size:.84rem;max-width:720px}.config-modules-rule{display:flex;gap:.55rem;align-items:flex-start;background:color-mix(in srgb,var(--pacs-primary) 9%,transparent);border:1px solid color-mix(in srgb,var(--pacs-primary) 30%,transparent);border-radius:8px;padding:.75rem .9rem;color:var(--pacs-text-muted);font-size:.78rem;margin-bottom:1rem}.config-modules-rule i{color:var(--pacs-primary);margin-top:.1rem}.config-module-card{margin-bottom:1rem}.config-module-global{border-color:color-mix(in srgb,var(--pacs-primary) 35%,var(--pacs-border))}.pacs-card-header{display:flex;justify-content:space-between;align-items:center}.pacs-card-header small{font-size:.66rem;background:var(--pacs-bg);color:var(--pacs-text-muted);border-radius:999px;padding:.2rem .5rem}.config-module-help{font-size:.78rem;color:var(--pacs-text-muted);margin:0 0 1rem;line-height:1.5}.config-module-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.85rem;margin-bottom:1rem}.config-module-section{background:var(--pacs-bg);border:1px solid var(--pacs-border);border-radius:8px;padding:.65rem}.config-module-section-title{font-size:.65rem;text-transform:uppercase;letter-spacing:.07em;color:var(--pacs-text-muted);font-weight:700;margin:0 0 .4rem}.config-module-toggle{display:flex;align-items:center;gap:.55rem;padding:.5rem;border-radius:6px;cursor:pointer;transition:background .15s;position:relative}.config-module-toggle:hover{background:color-mix(in srgb,var(--pacs-primary) 7%,transparent)}.config-module-toggle input{position:absolute;opacity:0;pointer-events:none}.config-module-icon{width:24px;height:24px;display:grid;place-items:center;border-radius:6px;background:var(--pacs-card);color:var(--pacs-text-muted);font-size:.72rem;border:1px solid var(--pacs-border)}.config-module-copy{flex:1;min-width:0;display:flex;flex-direction:column;gap:.06rem}.config-module-copy strong{font-size:.76rem;color:var(--pacs-text)}.config-module-copy small{font-size:.65rem;color:var(--pacs-text-muted)}.config-module-switch{width:27px;height:15px;border-radius:20px;background:var(--pacs-border);position:relative;flex:0 0 auto}.config-module-switch:after{content:"";width:11px;height:11px;top:2px;left:2px;border-radius:50%;position:absolute;background:#fff;transition:transform .16s}.config-module-toggle.is-enabled .config-module-switch{background:var(--pacs-primary)}.config-module-toggle.is-enabled .config-module-switch:after{transform:translateX(12px)}.config-module-toggle.is-enabled .config-module-icon{color:var(--pacs-primary);border-color:color-mix(in srgb,var(--pacs-primary) 45%,var(--pacs-border))}.config-refresh-enable{display:flex;gap:.6rem;align-items:flex-start;padding:.65rem;background:var(--pacs-bg);border:1px solid var(--pacs-border);border-radius:8px;margin-bottom:1rem}.config-refresh-enable input{margin-top:.18rem}.config-refresh-enable span{display:flex;flex-direction:column;gap:.1rem}.config-refresh-enable strong{font-size:.78rem;color:var(--pacs-text)}.config-refresh-enable small,.config-refresh-field small{font-size:.68rem;color:var(--pacs-text-muted);line-height:1.35}.config-refresh-field{margin-bottom:.9rem}.config-refresh-input{display:flex;align-items:center;gap:.55rem}.config-refresh-input input{max-width:120px}.config-refresh-input span{font-size:.75rem;color:var(--pacs-text-muted)}.config-refresh-safety{font-size:.7rem;color:var(--pacs-text-muted);padding:.6rem;border-left:3px solid var(--pacs-primary);background:var(--pacs-bg);margin-bottom:1rem;line-height:1.4}.config-refresh-safety i{color:var(--pacs-primary);margin-right:.25rem}.config-worklist-defaults{margin:1rem 0;padding:1rem;border:1px solid var(--pacs-border);border-radius:8px;background:var(--pacs-bg)}.config-worklist-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.55rem}.config-worklist-choice{display:flex;gap:.45rem;padding:.55rem;border:1px solid var(--pacs-border);border-radius:6px;cursor:pointer}.config-worklist-choice input{accent-color:var(--pacs-primary);margin-top:.1rem}.config-worklist-choice strong,.config-worklist-choice small{display:block}.config-worklist-choice strong{font-size:.74rem}.config-worklist-choice small{font-size:.65rem;color:var(--pacs-text-muted);line-height:1.3;margin-top:.1rem}.config-worklist-status-title{font-size:.75rem;font-weight:700;color:var(--pacs-text);margin:.85rem 0 .4rem}.config-worklist-status-order{list-style:none;margin:0;padding:0;max-width:510px}.config-worklist-status-order li{display:flex;gap:.5rem;align-items:center;padding:.4rem .5rem;margin-bottom:.25rem;border:1px solid var(--pacs-border);border-radius:5px}.config-worklist-status-order li span{flex:1;font-size:.72rem;font-weight:700}.config-worklist-status-order li i{color:var(--pacs-text-muted)}.config-worklist-status-order button{border:0;background:transparent;color:var(--pacs-primary);padding:.08rem .26rem}.config-module-note{display:flex;gap:.75rem;padding:1rem;border:1px dashed var(--pacs-border);border-radius:8px;color:var(--pacs-text-muted)}.config-module-note>i{font-size:1rem;color:var(--pacs-primary);margin-top:.12rem}.config-module-note strong{font-size:.8rem;color:var(--pacs-text)}.config-module-note p{font-size:.74rem;line-height:1.45;margin:.15rem 0 0}@media(max-width:960px){.config-module-grid,.config-worklist-grid{grid-template-columns:repeat(2,minmax(0,1fr)}}@media(max-width:620px){.config-modules-hero{flex-direction:column}.config-module-grid,.config-worklist-grid{grid-template-columns:1fr}.config-modules-page{padding-bottom:1rem}}
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-worklist-status-order]').forEach((list) => {
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
    });
});
</script>
