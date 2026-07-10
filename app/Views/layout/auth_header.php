<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'VOXEL PACS') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>
<div id="auth-bg"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>

<div class="auth-layout">
    <div class="auth-brand">
        <span class="auth-brand-tag">Plataforma Cloud PACS</span>
        <h1>Smart Imaging.<br><span>Secure Data.</span><br>Better Care.</h1>
        <p>PACS em nuvem para teleradiologia. Conecte clínicas, médicos laudadores e pacientes num único sistema seguro e rastreável.</p>
        <div class="feature-pills">
            <span class="pill"><i class="fa fa-bolt"></i> Worklist em tempo real</span>
            <span class="pill"><i class="fa fa-shield-halved"></i> Conformidade LGPD</span>
            <span class="pill"><i class="fa fa-users"></i> Multi-tenant</span>
            <span class="pill"><i class="fa fa-file-medical"></i> Laudo + PDF</span>
            <span class="pill"><i class="fa fa-chart-line"></i> Analytics PACS</span>
            <span class="pill"><i class="fa fa-lock"></i> Auditoria total</span>
        </div>
    </div>

    <div class="auth-panel">
        <div class="auth-box">
