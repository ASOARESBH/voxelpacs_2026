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

<div class="auth-layout">
    <div class="auth-brand">
        <span class="auth-brand-tag">Plataforma Cloud PACS</span>
        <h1>Smart Imaging.<br><span>Secure Data.</span><br>Better Care.</h1>
        <p>PACS em nuvem para teleradiologia. Conecte clínicas, médicos laudadores e pacientes num único sistema seguro e rastreável.</p>

        <ul class="benefits-list">
            <li><i class="fa fa-bolt"></i> Worklist em tempo real, direto do Orthanc PACS</li>
            <li><i class="fa fa-file-medical"></i> Laudo estruturado com exportação em PDF</li>
            <li><i class="fa fa-users"></i> Multi-tenant: várias clínicas, um único acesso</li>
            <li><i class="fa fa-chart-line"></i> Analytics e SLA de laudo em tempo real</li>
        </ul>

        <div class="feature-pills">
            <span class="pill"><i class="fa fa-shield-halved"></i> Conformidade LGPD</span>
            <span class="pill"><i class="fa fa-lock"></i> Auditoria total</span>
        </div>

        <div class="compliance-cards">
            <div class="compliance-card">
                <i class="fa fa-shield-halved"></i>
                <span><strong>LGPD</strong>Dados protegidos</span>
            </div>
            <div class="compliance-card">
                <i class="fa fa-file-shield"></i>
                <span><strong>HIPAA</strong>Padrão internacional</span>
            </div>
        </div>
    </div>

    <div class="auth-panel">
        <div class="auth-box">
