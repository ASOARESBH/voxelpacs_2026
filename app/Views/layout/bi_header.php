<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'VOXEL B.I') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/bi.css">
</head>
<body>

<?php if (!empty($_SESSION['impersonating_tenant_id'])): ?>
<div class="alert alert-warning text-center mb-0 rounded-0 py-2" style="position:sticky;top:0;z-index:9999;">
    <strong><i class="fa fa-eye me-1"></i>Visualizando como: <?= htmlspecialchars(\App\Core\TenantContext::name()) ?></strong>
    <a href="/platform/impersonate/exit" class="btn btn-sm btn-dark ms-3">Sair da Impersonação</a>
</div>
<?php endif; ?>

<div class="d-flex" id="wrapper">
    <nav id="sidebar" class="bg-dark text-white d-flex flex-column" style="min-width:250px;min-height:100vh;">
        <div class="p-3 border-bottom border-secondary">
            <div class="d-flex align-items-center gap-2">
                <i class="fa fa-cube text-primary fs-4"></i>
                <span class="fw-bold fs-5">VOXEL B.I</span>
            </div>
            <small class="text-muted"><?= htmlspecialchars(\App\Core\TenantContext::name()) ?></small>
        </div>
        <ul class="nav flex-column p-2 flex-grow-1">
            <li class="nav-item">
                <a href="/dashboard" class="nav-link text-white <?= str_contains($_SERVER['REQUEST_URI'],'/dashboard')?'active bg-primary rounded':'' ?>">
                    <i class="fa fa-chart-bar me-2"></i>Dashboard
                </a>
            </li>
            <li class="nav-item mt-2"><small class="text-muted px-2 text-uppercase fw-semibold" style="font-size:.7rem;">Análise</small></li>
            <li class="nav-item"><a href="/exames" class="nav-link text-white <?= str_contains($_SERVER['REQUEST_URI'],'/exames')?'active bg-primary rounded':'' ?>"><i class="fa fa-x-ray me-2"></i>Exames</a></li>
            <li class="nav-item"><a href="/medicos" class="nav-link text-white <?= str_contains($_SERVER['REQUEST_URI'],'/medicos')?'active bg-primary rounded':'' ?>"><i class="fa fa-user-md me-2"></i>Médicos</a></li>
            <li class="nav-item"><a href="/unidades" class="nav-link text-white <?= str_contains($_SERVER['REQUEST_URI'],'/unidades')?'active bg-primary rounded':'' ?>"><i class="fa fa-hospital me-2"></i>Unidades</a></li>
            <li class="nav-item"><a href="/modalidades" class="nav-link text-white <?= str_contains($_SERVER['REQUEST_URI'],'/modalidades')?'active bg-primary rounded':'' ?>"><i class="fa fa-satellite-dish me-2"></i>Modalidades</a></li>
            <li class="nav-item mt-2"><small class="text-muted px-2 text-uppercase fw-semibold" style="font-size:.7rem;">Inteligência</small></li>
            <li class="nav-item"><a href="/financeiro" class="nav-link text-white <?= str_contains($_SERVER['REQUEST_URI'],'/financeiro')?'active bg-primary rounded':'' ?>"><i class="fa fa-dollar-sign me-2"></i>Financeiro</a></li>
            <li class="nav-item"><a href="/sla" class="nav-link text-white <?= str_contains($_SERVER['REQUEST_URI'],'/sla')?'active bg-primary rounded':'' ?>"><i class="fa fa-clock me-2"></i>SLA &amp; Performance</a></li>
            <li class="nav-item"><a href="/preditivo" class="nav-link text-white <?= str_contains($_SERVER['REQUEST_URI'],'/preditivo')?'active bg-primary rounded':'' ?>"><i class="fa fa-magic me-2"></i>Análise Preditiva</a></li>
            <li class="nav-item"><a href="/benchmark" class="nav-link text-white <?= str_contains($_SERVER['REQUEST_URI'],'/benchmark')?'active bg-primary rounded':'' ?>"><i class="fa fa-trophy me-2"></i>Benchmark <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem;">PRO</span></a></li>
            <li class="nav-item mt-2"><small class="text-muted px-2 text-uppercase fw-semibold" style="font-size:.7rem;">Dados</small></li>
            <li class="nav-item"><a href="/relatorios" class="nav-link text-white <?= str_contains($_SERVER['REQUEST_URI'],'/relatorios')?'active bg-primary rounded':'' ?>"><i class="fa fa-file-alt me-2"></i>Relatórios</a></li>
            <li class="nav-item"><a href="/importacao" class="nav-link text-white <?= str_contains($_SERVER['REQUEST_URI'],'/importacao')?'active bg-primary rounded':'' ?>"><i class="fa fa-upload me-2"></i>Importação</a></li>
            <li class="nav-item"><a href="/pacs" class="nav-link text-white <?= str_contains($_SERVER['REQUEST_URI'],'/pacs')?'active bg-primary rounded':'' ?>"><i class="fa fa-plug me-2"></i>PACS Conectados</a></li>
            <li class="nav-item mt-2"><small class="text-muted px-2 text-uppercase fw-semibold" style="font-size:.7rem;">Sistema</small></li>
            <li class="nav-item"><a href="/servidor" class="nav-link text-white <?= str_contains($_SERVER['REQUEST_URI'],'/servidor')?'active bg-primary rounded':'' ?>"><i class="fa fa-server me-2"></i>Servidor <span class="badge bg-info text-dark ms-1" style="font-size:.65rem;">PACS</span></a></li>
            <li class="nav-item"><a href="/configuracoes" class="nav-link text-white <?= str_contains($_SERVER['REQUEST_URI'],'/configuracoes')?'active bg-primary rounded':'' ?>"><i class="fa fa-cog me-2"></i>Configurações</a></li>
            <li class="nav-item"><a href="/usuarios" class="nav-link text-white <?= str_contains($_SERVER['REQUEST_URI'],'/usuarios')?'active bg-primary rounded':'' ?>"><i class="fa fa-users me-2"></i>Usuários</a></li>
        </ul>
        <div class="p-3 border-top border-secondary">
            <div class="d-flex align-items-center gap-2 mb-2">
                <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center" style="width:32px;height:32px;">
                    <i class="fa fa-user text-white" style="font-size:.8rem;"></i>
                </div>
                <div>
                    <div class="fw-semibold" style="font-size:.85rem;"><?= htmlspecialchars(\App\Core\Auth::user()?->name ?? '') ?></div>
                    <small class="text-muted"><a href="/selecionar-empresa" class="text-muted text-decoration-none"><?= htmlspecialchars(\App\Core\TenantContext::name()) ?></a></small>
                </div>
            </div>
            <a href="/logout" class="btn btn-outline-secondary btn-sm w-100"><i class="fa fa-sign-out-alt me-1"></i>Sair</a>
        </div>
    </nav>
    <div id="page-content-wrapper" class="flex-grow-1 bg-light">
        <div class="container-fluid p-4">
