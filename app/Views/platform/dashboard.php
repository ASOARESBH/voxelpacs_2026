<?php $stats = $stats ?? []; ?>

<div style="margin-bottom:1.5rem;">
    <h1 style="font-size:1.3rem;font-weight:700;color:var(--pacs-text);margin-bottom:.25rem;">
        <i class="fa fa-gauge-high me-2 text-pacs-primary"></i>Dashboard da Plataforma
    </h1>
    <p style="color:var(--pacs-text-muted);font-size:.82rem;">Visão geral do VOXEL PACS</p>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(79,195,247,.12);"><i class="fa fa-building text-pacs-primary"></i></div>
        <div><div class="stat-value"><?= number_format($stats['total_negocios'] ?? 0) ?></div><div class="stat-label">Total Negócios</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(105,240,174,.12);"><i class="fa fa-circle-check" style="color:var(--pacs-success);"></i></div>
        <div><div class="stat-value"><?= number_format($stats['negocios_ativos'] ?? 0) ?></div><div class="stat-label">Negócios Ativos</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(255,202,40,.12);"><i class="fa fa-users" style="color:var(--pacs-warning);"></i></div>
        <div><div class="stat-value"><?= number_format($stats['total_usuarios'] ?? 0) ?></div><div class="stat-label">Usuários Ativos</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(171,71,188,.12);"><i class="fa fa-x-ray" style="color:#ab47bc;"></i></div>
        <div><div class="stat-value"><?= number_format($stats['total_estudos'] ?? 0) ?></div><div class="stat-label">Estudos no PACS</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(255,112,67,.12);"><i class="fa fa-tags" style="color:#ff7043;"></i></div>
        <div><div class="stat-value"><?= number_format($stats['total_planos'] ?? 0) ?></div><div class="stat-label">Planos Ativos</div></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:1rem;">
    <div class="pacs-card">
        <div class="pacs-card-header">
            <i class="fa fa-clock-rotate-left text-pacs-primary"></i>
            <span style="font-weight:600;color:var(--pacs-text);">Últimos Negócios Cadastrados</span>
            <a href="/platform/negocios" class="btn-pacs-outline ms-auto" style="font-size:.72rem;padding:.2rem .6rem;">Ver todos</a>
        </div>
        <table class="platform-table">
            <thead><tr><th>Nome</th><th>Plano</th><th>Status</th><th>Cadastro</th></tr></thead>
            <tbody>
            <?php if (empty($ultimosNegocios)): ?>
                <tr><td colspan="4" style="text-align:center;padding:1.5rem;color:var(--pacs-text-muted);">Nenhum negócio cadastrado.</td></tr>
            <?php else: ?>
                <?php foreach ($ultimosNegocios as $n): ?>
                <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($n['nome']) ?></td>
                    <td><?= htmlspecialchars($n['plano'] ?? '—') ?></td>
                    <td><span class="badge badge-<?= $n['status'] === 'ativo' ? 'ativo' : ($n['status'] === 'suspenso' ? 'suspenso' : 'inativo') ?>"><?= ucfirst($n['status']) ?></span></td>
                    <td style="color:var(--pacs-text-muted);font-size:.75rem;"><?php try { echo (new DateTime($n['created_at']))->format('d/m/Y'); } catch (\Throwable $t) { echo '—'; } ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="pacs-card">
        <div class="pacs-card-header">
            <i class="fa fa-bolt text-pacs-primary"></i>
            <span style="font-weight:600;color:var(--pacs-text);">Ações Rápidas</span>
        </div>
        <div class="pacs-card-body" style="display:flex;flex-direction:column;gap:.6rem;">
            <a href="/platform/negocios/create" class="btn-pacs-primary"><i class="fa fa-plus"></i> Novo Negócio</a>
            <a href="/platform/plans/create" class="btn-pacs-outline"><i class="fa fa-tags"></i> Novo Plano</a>
            <a href="/platform/servidor-pacs" class="btn-pacs-outline"><i class="fa fa-server"></i> Servidor PACS</a>
            <a href="/platform/reports" class="btn-pacs-outline"><i class="fa fa-chart-line"></i> Relatórios</a>
            <hr style="border-color:var(--pacs-border);margin:.25rem 0;">
            <a href="/estudos" class="btn-pacs-outline"><i class="fa fa-x-ray"></i> Worklist PACS</a>
        </div>
    </div>
</div>
