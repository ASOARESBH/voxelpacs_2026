<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($title ?? 'VOXEL PACS — Plataforma') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/pacs.css?v=<?= defined('ASSET_VERSION') ? ASSET_VERSION : '2.1.0' ?>">
    <link rel="stylesheet" href="/assets/css/mobile-responsive.css?v=<?= defined('ASSET_VERSION') ? ASSET_VERSION : '2.1.0' ?>">
</head>
<body>
<div class="platform-wrapper">
    <nav class="platform-sidebar-nav">
        <div class="sidebar-logo" style="padding:.85rem 1rem;">
            <img src="/assets/img/logo-voxel-pacs.png" alt="VOXEL PACS" style="height:32px;">
            <div class="sidebar-logo-text">VOXEL PACS<small>Painel da Plataforma</small></div>
        </div>
        <div class="sidebar-nav" style="flex:1;">
            <div class="sidebar-section-title">Plataforma</div>
            <a href="/platform/dashboard" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'],'/platform/dashboard')?'active':'' ?>"><i class="fa fa-gauge-high"></i><span>Dashboard</span></a>
            <a href="/platform/negocios" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'],'/platform/negocios')?'active':'' ?>"><i class="fa fa-building"></i><span>Negócios</span></a>
            <a href="/platform/plans" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'],'/platform/plans')?'active':'' ?>"><i class="fa fa-tags"></i><span>Planos</span></a>
            <a href="/platform/reports" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'],'/platform/reports')?'active':'' ?>"><i class="fa fa-chart-line"></i><span>Relatórios</span></a>
            <div class="sidebar-section-title">Infraestrutura</div>
            <a href="/platform/servidor-pacs" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'],'/platform/servidor-pacs')?'active':'' ?>"><i class="fa fa-server"></i><span>Servidor PACS</span></a>
            <div class="sidebar-section-title">Acesso</div>
            <a href="/estudos" class="nav-link"><i class="fa fa-arrow-left"></i><span>Voltar ao PACS</span></a>
        </div>
        <div class="sidebar-footer">
            <div class="sidebar-user" style="margin-bottom:.5rem;">
                <div class="sidebar-user-avatar"><?= strtoupper(substr(\App\Core\Auth::user()?->name ?? 'A', 0, 1)) ?></div>
                <div class="sidebar-user-info">
                    <div class="sidebar-user-name"><?= htmlspecialchars(\App\Core\Auth::user()?->name ?? '') ?></div>
                    <div class="sidebar-user-role" style="color:#4fc3f7;">Superadmin</div>
                </div>
            </div>
            <a href="/logout" class="btn-pacs-outline w-100 justify-content-center" style="font-size:.75rem;"><i class="fa fa-right-from-bracket"></i> Sair</a>
        </div>
    </nav>
    <div class="platform-main">
