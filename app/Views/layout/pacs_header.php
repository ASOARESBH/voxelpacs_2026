<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'VOXEL PACS') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php if (!empty($includeQuill)): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/pacs.css?v=<?= defined('ASSET_VERSION') ? ASSET_VERSION : '2.1.0' ?>">
    <!-- PWA: manifest + meta tags para instalação como app nativo -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#1d4ed8">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="VOXEL PACS">
    <link rel="apple-touch-icon" href="/assets/img/pwa-icon-192.png">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/sw.js')
                    .catch(function(err) { console.warn('[PWA] SW:', err); });
            });
        }
    </script>
</head>
<body>

<?php $medicoRestrito = \App\Core\Access\MedicoAccess::isRestricted(); ?>
<?php if (!empty($_SESSION['impersonating_tenant_id'])): ?>
<div class="alert alert-warning text-center mb-0 rounded-0 py-2" style="position:sticky;top:0;z-index:9999;">
    <strong><i class="fa fa-eye me-1"></i>Visualizando como: <?= htmlspecialchars(\App\Core\TenantContext::name()) ?></strong>
    <a href="/platform/impersonate/exit" class="btn btn-sm btn-dark ms-3">Sair da Impersonação</a>
</div>
<?php endif; ?>

<div id="pacs-wrapper">

    <!-- ═══════════════════════════════════════════════════════
         SIDEBAR
    ═══════════════════════════════════════════════════════ -->
    <nav id="pacs-sidebar">

        <!-- Logo -->
        <div class="sidebar-logo">
            <img src="/assets/img/logo-voxel-pacs.png" alt="VOXEL PACS" id="sidebar-logo-img">
            <div class="sidebar-logo-text">
                VOXEL PACS
                <small>Worklist &amp; Viewer</small>
            </div>
            <button id="sidebar-toggle" title="Recolher menu">
                <i class="fa fa-bars"></i>
            </button>
        </div>

        <!-- Navegação -->
        <div class="sidebar-nav">

            <!-- WORKLIST -->
            <div class="sidebar-section-title">Worklist</div>

            <a href="/estudos" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/estudos') ? 'active' : '' ?>">
                <i class="fa fa-list-check"></i>
                <span class="sidebar-label">Estudos</span>
            </a>

            <a href="/agendamentos" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/agendamentos') ? 'active' : '' ?>">
                <i class="fa fa-calendar-days"></i>
                <span class="sidebar-label">Agendamentos</span>
            </a>

            <?php if (empty($isMedicoLogado)): ?>
            <a href="/gestao-exames" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/gestao-exames') ? 'active' : '' ?>">
                <i class="fa fa-clipboard-list"></i>
                <span class="sidebar-label"><?= htmlspecialchars(t('gestao_exames.menu.titulo')) ?></span>
            </a>
            <?php endif; ?>

            <!-- PACS -->
            <div class="sidebar-section-title">PACS</div>

            <a href="#" class="nav-link has-submenu <?= (str_contains($_SERVER['REQUEST_URI'], '/pacs') || str_contains($_SERVER['REQUEST_URI'], '/dicom')) ? 'open' : '' ?>"
               onclick="toggleSubmenu(this, 'sub-pacs'); return false;">
                <i class="fa fa-x-ray"></i>
                <span class="sidebar-label">Imagens DICOM</span>
            </a>
            <div class="sidebar-submenu <?= (str_contains($_SERVER['REQUEST_URI'], '/pacs') || str_contains($_SERVER['REQUEST_URI'], '/dicom')) ? 'show' : '' ?>" id="sub-pacs">
                <a href="/pacs/exames" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/pacs/exames') ? 'active' : '' ?>">
                    <i class="fa fa-images"></i>
                    <span class="sidebar-label">Buscar Exames</span>
                </a>
                <a href="/pacs/modalidades" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/pacs/modalidades') ? 'active' : '' ?>">
                    <i class="fa fa-satellite-dish"></i>
                    <span class="sidebar-label">Modalidades</span>
                </a>
            </div>

            <!-- CADASTROS -->
            <div class="sidebar-section-title">Cadastros</div>

            <a href="#" class="nav-link has-submenu <?= (str_contains($_SERVER['REQUEST_URI'], '/medicos') || str_contains($_SERVER['REQUEST_URI'], '/unidades') || str_contains($_SERVER['REQUEST_URI'], '/modalidades') || str_contains($_SERVER['REQUEST_URI'], '/sla-regras')) ? 'open' : '' ?>"
               onclick="toggleSubmenu(this, 'sub-cad'); return false;">
                <i class="fa fa-database"></i>
                <span class="sidebar-label">Cadastros</span>
            </a>
            <div class="sidebar-submenu <?= (str_contains($_SERVER['REQUEST_URI'], '/medicos') || str_contains($_SERVER['REQUEST_URI'], '/unidades') || str_contains($_SERVER['REQUEST_URI'], '/modalidades') || str_contains($_SERVER['REQUEST_URI'], '/sla-regras')) ? 'show' : '' ?>" id="sub-cad">
                <a href="/medicos" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/medicos') ? 'active' : '' ?>">
                    <i class="fa fa-user-doctor"></i>
                    <span class="sidebar-label">Médicos</span>
                </a>
                <?php if (!$medicoRestrito): ?>
                <a href="/unidades" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/unidades') ? 'active' : '' ?>">
                    <i class="fa fa-hospital"></i>
                    <span class="sidebar-label">Unidades</span>
                </a>
                <?php endif; ?>
                <a href="/modalidades" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/modalidades') ? 'active' : '' ?>">
                    <i class="fa fa-satellite-dish"></i>
                    <span class="sidebar-label">Modalidades</span>
                </a>
                <?php if (\App\Core\Auth::can('manage_sla_regras')): ?>
                <a href="/sla-regras" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/sla-regras') ? 'active' : '' ?>">
                    <i class="fa fa-gauge-high"></i>
                    <span class="sidebar-label"><?= htmlspecialchars(t('sla_regras.menu.titulo')) ?></span>
                </a>
                <?php endif; ?>
            </div>

            <!-- RELATÓRIOS -->
            <div class="sidebar-section-title"><?= htmlspecialchars(t('relatorios.menu.secao')) ?></div>

            <a href="#" class="nav-link has-submenu <?= str_contains($_SERVER['REQUEST_URI'], '/relatorios') ? 'open' : '' ?>"
               onclick="toggleSubmenu(this, 'sub-rel'); return false;">
                <i class="fa fa-chart-column"></i>
                <span class="sidebar-label"><?= htmlspecialchars(t('relatorios.menu.titulo')) ?></span>
            </a>
            <div class="sidebar-submenu <?= str_contains($_SERVER['REQUEST_URI'], '/relatorios') ? 'show' : '' ?>" id="sub-rel">
                                <a href="/relatorios/exames" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/relatorios/exames') ? 'active' : '' ?>">
                    <i class="fa fa-file-medical"></i>
                    <span class="sidebar-label"><?= htmlspecialchars(t('relatorios.menu.exames')) ?></span>
                </a>
                <a href="/relatorios/medicos" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/relatorios/medicos') ? 'active' : '' ?>">
                    <i class="fa fa-user-doctor"></i>
                    <span class="sidebar-label">Médicos</span>
                </a>
                <a href="/relatorios/sla-medicos" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/relatorios/sla-medicos') ? 'active' : '' ?>">
                    <i class="fa fa-gauge-high"></i>
                    <span class="sidebar-label"><?= htmlspecialchars(t('relatorios.menu.sla_medicos')) ?></span>
                </a>
            </div>

            <!-- SISTEMA -->
            <div class="sidebar-section-title">Sistema</div>

            <a href="/usuarios" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/usuarios') ? 'active' : '' ?>">
                <i class="fa fa-users"></i>
                <span class="sidebar-label">Usuários</span>
            </a>

            <?php if (\App\Core\Auth::isPlatformAdmin() || \App\Core\Auth::can('manage_configuracoes')): ?>
            <a href="/configuracoes" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/configuracoes') ? 'active' : '' ?>">
                <i class="fa fa-gear"></i>
                <span class="sidebar-label">Configurações</span>
            </a>
            <?php endif; ?>

            <?php if (\App\Core\Auth::isPlatformAdmin()): ?>
            <!-- PLATAFORMA (só superadmin) -->
            <div class="sidebar-section-title">Plataforma</div>

            <a href="/platform/dashboard" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/platform') ? 'active' : '' ?>">
                <i class="fa fa-shield-halved"></i>
                <span class="sidebar-label">Admin Platform</span>
            </a>
            <?php endif; ?>

        </div><!-- /sidebar-nav -->

        <!-- Footer do sidebar -->
        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-user-avatar">
                    <?= strtoupper(substr(\App\Core\Auth::user()?->name ?? 'U', 0, 1)) ?>
                </div>
                <div class="sidebar-user-info">
                    <div class="sidebar-user-name"><?= htmlspecialchars(\App\Core\Auth::user()?->name ?? '') ?></div>
                    <div class="sidebar-user-role"><?= htmlspecialchars(\App\Core\TenantContext::name() ?: 'Plataforma') ?></div>
                </div>
            </div>
            <a href="/logout" class="btn-pacs-outline w-100 justify-content-center" style="font-size:.75rem;">
                <i class="fa fa-right-from-bracket"></i>
                <span class="sidebar-label">Sair</span>
            </a>
        </div>

    </nav><!-- /pacs-sidebar -->

    <!-- ═══════════════════════════════════════════════════════
         CONTEÚDO PRINCIPAL
    ═══════════════════════════════════════════════════════ -->
    <div id="pacs-content">

        <!-- TOP BAR -->
        <div id="pacs-topbar">
            <button class="btn-pacs-outline d-md-none" onclick="toggleMobileSidebar()" style="padding:.3rem .6rem;">
                <i class="fa fa-bars"></i>
            </button>

            <span class="topbar-title">
                <i class="fa fa-x-ray me-2 text-pacs-primary"></i>
                VOXEL PACS
            </span>

            <!-- Badges de status (contadores) — só para perfil Médico
                 (bi_user_tenants.perfil, ver Auth::perfilAtual()). Para os demais
                 perfis (Administrador/Secretaria/Analista/Visualizador) o bloco
                 inteiro fica fora do DOM, não só oculto via CSS. -->
            <?php if (\App\Core\Auth::perfilAtual() === 'medico'): ?>
            <div class="topbar-badges d-none d-lg-flex" id="topbar-badges-wrap">
                <span class="topbar-badge" style="background:#fef2f2;color:#dc2626;" title="Laudos com pendência aberta (CHAT)">
                    <span class="badge-count" id="cnt-pendente">0</span> PENDENTE
                </span>
                <span class="topbar-badge" style="background:#fff7ed;color:#ea580c;" title="Estudos aguardando laudo">
                    <span class="badge-count" id="cnt-a-laudar">0</span> A LAUDAR
                </span>
                <span class="topbar-badge" style="background:#eff6ff;color:#2563eb;" title="Laudos em andamento">
                    <span class="badge-count" id="cnt-em-laudo">0</span> EM LAUDO
                </span>
                <span class="topbar-badge" style="background:#f5f3ff;color:#7c3aed;" title="Laudos em rascunho">
                    <span class="badge-count" id="cnt-rascunho">0</span> RASCUNHO
                </span>
                <span class="topbar-badge" style="background:#f0fdfa;color:#0d9488;" title="Laudos assinados">
                    <span class="badge-count" id="cnt-assinado">0</span> ASSINADO
                </span>
                <span class="topbar-badge" style="background:#fdf4ff;color:#a21caf;" title="Laudos em Peer Review">
                    <span class="badge-count" id="cnt-peer-review">0</span> PEER REVIEW
                </span>
            </div>

            <script>
            (function() {
                function atualizarBadgesTopbar() {
                    fetch((function () { var url = new URL(window.location.href); var query = new URLSearchParams(); ['periodo', 'dt_inicio', 'dt_fim'].forEach(function (key) { if (url.searchParams.has(key)) query.set(key, url.searchParams.get(key)); }); return '/api/estudos/contadores' + (query.toString() ? '?' + query.toString() : ''); })(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.ok ? r.json() : null; })
                    .then(function(d) {
                        if (!d) return;
                        var m = {
                            'cnt-pendente':   (d.pendente   || 0),
                            'cnt-a-laudar':   (d.a_laudar   || 0),
                            'cnt-em-laudo':   (d.em_laudo   || 0),
                            'cnt-rascunho':   (d.rascunho   || 0),
                            'cnt-assinado':   (d.assinado || 0),
                            'cnt-peer-review':(d.peer_review || 0),
                        };
                        Object.keys(m).forEach(function(id) {
                            var el = document.getElementById(id);
                            if (el) el.textContent = m[id];
                        });
                    })
                    .catch(function() {});
                }
                // Carregar imediatamente e a cada 60 segundos
                document.addEventListener('DOMContentLoaded', atualizarBadgesTopbar);
                setInterval(atualizarBadgesTopbar, 60000);
                // Expor para atualização manual após ações (assumir, assinar, liberar)
                window.atualizarBadgesTopbar = atualizarBadgesTopbar;
            })();
            </script>
            <?php endif; ?>

            <!-- Usuário logado -->
            <div class="d-flex align-items-center gap-2 ms-auto">
                <div class="sidebar-user-avatar" style="width:28px;height:28px;font-size:.7rem;">
                    <?= strtoupper(substr(\App\Core\Auth::user()?->name ?? 'U', 0, 1)) ?>
                </div>
                <span style="font-size:.78rem;color:var(--pacs-text-muted);">
                    <?= htmlspecialchars(\App\Core\Auth::user()?->name ?? '') ?>
                </span>
                <a href="/logout" class="pacs-btn" title="Sair">
                    <i class="fa fa-right-from-bracket"></i>
                </a>
            </div>
        </div><!-- /topbar -->

        <!-- PAGE CONTENT -->
        <div id="pacs-page">
