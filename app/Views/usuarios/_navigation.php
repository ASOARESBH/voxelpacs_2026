<?php
$activeTab = (string) ($activeTab ?? '');
$showAdminTabs = $showAdminTabs ?? true;
$tabs = [
    'usuarios' => ['/usuarios', 'fa-users', 'usuarios.tabs.usuarios'],
    'grupos' => ['/usuarios/grupos', 'fa-layer-group', 'usuarios.tabs.grupos'],
    'notificacoes' => ['/usuarios/notificacoes', 'fa-bell', 'usuarios.tabs.notificacoes'],
    'regras_acesso' => ['/usuarios/regras-acesso', 'fa-shield-halved', 'usuarios.tabs.regras_acesso'],
];
?>
<nav class="usuarios-tabs-bar">
    <?php foreach ($tabs as $key => [$href, $icon, $labelKey]): ?>
        <?php if ($key === 'usuarios' || $showAdminTabs): ?>
        <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
           class="usuarios-tab-btn<?= $activeTab === $key ? ' active' : '' ?>"
           <?= $activeTab === $key ? 'aria-current="page"' : '' ?>>
            <i class="fa <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
            <?= htmlspecialchars(t($labelKey), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
