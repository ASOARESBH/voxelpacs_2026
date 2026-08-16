<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($title ?? 'Portal de Resultados — VOXEL PACS', ENT_QUOTES) ?></title>
    <link rel="stylesheet" href="/assets/css/portal.css?v=<?= defined('ASSET_VERSION') ? ASSET_VERSION : '1' ?>">
</head>
<body class="portal-body">
<header class="portal-topbar">
    <a class="portal-brand" href="/" aria-label="Portal de Resultados VOXEL PACS">
        <span class="portal-brand-mark">V</span><span>VOXEL <small>PORTAL DE RESULTADOS</small></span>
    </a>
    <?php if (!empty($patientName)): ?>
        <form method="post" action="/sair">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf ?? '', ENT_QUOTES) ?>">
            <button class="portal-logout" type="submit">Sair</button>
        </form>
    <?php endif; ?>
</header>
<main class="portal-main">
