<?php
$groups = $groups ?? [];
$selectedGroup = $selected_group ?? null;
$policy = $policy ?? [];
$modalities = $modalities ?? [];
$priorities = $priorities ?? [];
$sucesso = $sucesso ?? '';
$erro = $erro ?? '';
$selectedId = (int) ($selectedGroup['id'] ?? 0);
$checked = static fn(string $key): string => !empty($policy[$key]) ? 'checked' : '';
$in = static fn(string $value, array $list): string => in_array($value, $list, true) ? 'checked' : '';
$priorityLabels = [
    'STAT'    => t('gestao_gerenciar.prioridade.stat'),
    'HIGH'    => t('gestao_gerenciar.prioridade.high'),
    'ROUTINE' => t('gestao_gerenciar.prioridade.routine'),
    'MEDIUM'  => t('gestao_gerenciar.prioridade.medium'),
    'LOW'     => t('gestao_gerenciar.prioridade.low'),
];
$priorityIcons = [
    'STAT'    => 'fa-triangle-exclamation text-danger',
    'HIGH'    => 'fa-bolt text-warning',
    'ROUTINE' => 'fa-clock text-primary',
    'MEDIUM'  => 'fa-calendar-day text-info',
    'LOW'     => 'fa-building text-success',
];
?>
<style>
.notify-layout{display:grid;grid-template-columns:300px minmax(0,1fr);gap:1.25rem}.notify-card{background:var(--pacs-card-bg,#1e2330);border:1px solid var(--pacs-border,#2d3244);border-radius:10px}.notify-side{padding:.6rem}.notify-group{display:block;padding:.85rem .9rem;border-radius:8px;color:var(--pacs-text,#e2e8f0);text-decoration:none;margin:.25rem 0}.notify-group:hover,.notify-group.active{background:rgba(26,86,219,.14);color:var(--pacs-primary,#60a5fa)}.notify-meta{display:flex;gap:.35rem;margin-top:.35rem;font-size:.7rem;color:var(--pacs-text-muted,#8892a4)}.notify-panel{padding:1.4rem}.notify-section{padding:1.25rem 0;border-bottom:1px solid var(--pacs-border,#2d3244)}.notify-section:last-child{border-bottom:0}.notify-section h2{font-size:.9rem;font-weight:700;margin:0 0 .35rem}.notify-help{font-size:.78rem;color:var(--pacs-text-muted,#8892a4);margin:0 0 1rem}.notify-options{display:flex;flex-wrap:wrap;gap:.55rem}.notify-option{display:flex;align-items:center;gap:.45rem;border:1px solid var(--pacs-border,#3a3f4b);border-radius:6px;padding:.45rem .6rem;font-size:.8rem;cursor:pointer}.notify-option input{accent-color:var(--pacs-primary,#1a56db)}.notify-priority-label{display:inline-flex;align-items:center;gap:.4rem}.notify-status{display:inline-flex;align-items:center;gap:.35rem;font-size:.72rem;border-radius:99px;padding:.18rem .55rem;background:rgba(100,116,139,.15);color:var(--pacs-text-muted)}.notify-status.on{background:rgba(16,185,129,.15);color:#34d399}.notify-empty{padding:2rem;text-align:center;color:var(--pacs-text-muted,#8892a4)}@media(max-width:880px){.notify-layout{grid-template-columns:1fr}.notify-side{display:flex;overflow:auto;gap:.35rem}.notify-group{white-space:nowrap;min-width:190px}}
</style>
<div class="d-flex justify-content-between align-items-center mb-4"><div><h1 class="h3 mb-1"><i class="fa fa-bell me-2 text-pacs-primary"></i><?= htmlspecialchars(t('usuarios.notificacoes.titulo')) ?></h1><p class="mb-0 text-muted small"><?= htmlspecialchars(t('usuarios.notificacoes.subtitulo')) ?></p></div><a href="/usuarios" class="btn-pacs-outline"><i class="fa fa-arrow-left me-1"></i><?= htmlspecialchars(t('comum.acoes.voltar')) ?></a></div>
<div class="usuarios-tabs-bar mb-3"><a href="/usuarios" class="usuarios-tab-btn"><i class="fa fa-users"></i><?= htmlspecialchars(t('usuarios.tabs.usuarios')) ?></a><a href="/usuarios/grupos" class="usuarios-tab-btn"><i class="fa fa-layer-group"></i><?= htmlspecialchars(t('usuarios.tabs.grupos')) ?></a><a href="/usuarios/notificacoes" class="usuarios-tab-btn active"><i class="fa fa-bell"></i><?= htmlspecialchars(t('usuarios.tabs.notificacoes')) ?></a></div>
<?php if ($sucesso === 'salvo'): ?><div class="pacs-alert pacs-alert-success mb-3"><i class="fa fa-check-circle me-2"></i><?= htmlspecialchars(t('usuarios.notificacoes.sucesso')) ?></div><?php endif; ?>
<?php if ($erro !== ''): ?><div class="pacs-alert pacs-alert-danger mb-3"><i class="fa fa-triangle-exclamation me-2"></i><?= htmlspecialchars(t('usuarios.notificacoes.erro.' . $erro)) ?></div><?php endif; ?>
<div class="notify-layout">
<aside class="notify-card notify-side">
<?php foreach ($groups as $group): $id=(int)$group['id']; ?>
<a class="notify-group <?= $id === $selectedId ? 'active' : '' ?>" href="/usuarios/notificacoes?grupo=<?= $id ?>"><strong><?= htmlspecialchars((string)$group['nome']) ?></strong><span class="notify-meta"><span><i class="fa fa-users"></i> <?= (int)$group['total_membros'] ?></span><span class="notify-status <?= !empty($group['notificacao_ativa']) ? 'on' : '' ?>"><i class="fa <?= !empty($group['notificacao_ativa']) ? 'fa-bell' : 'fa-bell-slash' ?>"></i><?= htmlspecialchars(t(!empty($group['notificacao_ativa']) ? 'usuarios.notificacoes.ativo' : 'usuarios.notificacoes.inativo')) ?></span></span></a>
<?php endforeach; ?>
</aside>
<main class="notify-card">
<?php if (!$selectedGroup): ?><div class="notify-empty"><i class="fa fa-layer-group fa-2x mb-3"></i><p><?= htmlspecialchars(t('usuarios.notificacoes.sem_grupo')) ?></p></div>
<?php else: ?><form method="post" action="/usuarios/notificacoes/<?= $selectedId ?>/salvar" class="notify-panel">
<div class="d-flex align-items-start justify-content-between gap-3"><div><h2 class="h5 mb-1"><?= htmlspecialchars((string)$selectedGroup['nome']) ?></h2><p class="notify-help mb-0"><?= htmlspecialchars(t('usuarios.notificacoes.escopo_grupo')) ?></p></div><label class="notify-option"><input type="checkbox" name="ativo" value="1" <?= $checked('ativo') ?>><span><?= htmlspecialchars(t('usuarios.notificacoes.habilitar')) ?></span></label></div>
<section class="notify-section"><h2><i class="fa fa-triangle-exclamation text-warning me-2"></i><?= htmlspecialchars(t('usuarios.notificacoes.prioridades_titulo')) ?></h2><p class="notify-help"><?= htmlspecialchars(t('usuarios.notificacoes.prioridades_ajuda')) ?></p><div class="notify-options"><?php foreach($priorities as $priority): ?><label class="notify-option"><input type="checkbox" name="prioridades[]" value="<?= htmlspecialchars($priority) ?>" <?= $in($priority,(array)$policy['prioridades']) ?>><strong class="notify-priority-label"><i class="fa <?= htmlspecialchars($priorityIcons[$priority] ?? 'fa-flag') ?>"></i><?= htmlspecialchars($priorityLabels[$priority] ?? $priority) ?></strong></label><?php endforeach; ?></div></section>
<section class="notify-section"><h2><i class="fa fa-paper-plane me-2 text-pacs-primary"></i><?= htmlspecialchars(t('usuarios.notificacoes.canais_titulo')) ?></h2><p class="notify-help"><?= htmlspecialchars(t('usuarios.notificacoes.canais_ajuda')) ?></p><div class="notify-options"><label class="notify-option"><input type="checkbox" name="canal_email" value="1" <?= $checked('canal_email') ?>><i class="fa fa-envelope"></i><?= htmlspecialchars(t('usuarios.notificacoes.canal_email')) ?></label><label class="notify-option"><input type="checkbox" name="canal_whatsapp" value="1" <?= $checked('canal_whatsapp') ?>><i class="fa fa-whatsapp"></i><?= htmlspecialchars(t('usuarios.notificacoes.canal_whatsapp')) ?></label><label class="notify-option"><input type="checkbox" name="canal_telegram" value="1" <?= $checked('canal_telegram') ?>><i class="fa fa-paper-plane"></i><?= htmlspecialchars(t('usuarios.notificacoes.canal_telegram')) ?></label></div></section>
<section class="notify-section"><h2><i class="fa fa-radiology me-2 text-pacs-primary"></i><?= htmlspecialchars(t('usuarios.notificacoes.modalidades_alerta_titulo')) ?></h2><p class="notify-help"><?= htmlspecialchars(t('usuarios.notificacoes.modalidades_alerta_ajuda')) ?></p><div class="notify-options"><?php foreach($modalities as $modality): ?><label class="notify-option"><input type="checkbox" name="modalidades_notificacao[]" value="<?= htmlspecialchars($modality) ?>" <?= $in($modality,(array)$policy['modalidades_notificacao']) ?>><?= htmlspecialchars($modality) ?></label><?php endforeach; ?></div></section>
<section class="notify-section"><h2><i class="fa fa-eye-slash me-2 text-pacs-primary"></i><?= htmlspecialchars(t('usuarios.notificacoes.modalidades_worklist_titulo')) ?></h2><p class="notify-help"><?= htmlspecialchars(t('usuarios.notificacoes.modalidades_worklist_ajuda')) ?></p><div class="notify-options"><?php foreach($modalities as $modality): ?><label class="notify-option"><input type="checkbox" name="modalidades_worklist[]" value="<?= htmlspecialchars($modality) ?>" <?= $in($modality,(array)$policy['modalidades_worklist']) ?>><?= htmlspecialchars($modality) ?></label><?php endforeach; ?></div></section>
<div class="pt-3 d-flex gap-2"><button type="submit" class="btn-pacs-primary"><i class="fa fa-save me-1"></i><?= htmlspecialchars(t('usuarios.notificacoes.salvar')) ?></button><a class="btn-pacs-outline" href="/usuarios/grupos/<?= $selectedId ?>/editar"><i class="fa fa-users me-1"></i><?= htmlspecialchars(t('usuarios.notificacoes.editar_membros')) ?></a></div>
</form><?php endif; ?>
</main></div>
