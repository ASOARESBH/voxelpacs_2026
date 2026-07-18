<?php
$config = $config ?? [];
$token  = $config['token'] ?? null;
$ativo  = (int) ($config['ativo'] ?? 0);
$lockAdquiridoEm = $config['lock_adquirido_em'] ?? null;
$ultimaExecucaoEm = $config['ultima_execucao_em'] ?? null;
$resumo = $config['ultima_execucao_resumo'] ?? null;

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$urlRobo = $token ? $baseUrl . '/api/sla-regras/executar?token=' . $token : null;
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.3rem;font-weight:700;color:var(--pacs-text);margin-bottom:.25rem;">
            <i class="fa fa-robot me-2 text-pacs-primary"></i><?= htmlspecialchars(t('sla_regras.robo.titulo')) ?>
        </h1>
        <p style="color:var(--pacs-text-muted);font-size:.82rem;"><?= htmlspecialchars(t('sla_regras.robo.subtitulo')) ?></p>
    </div>
    <a href="/sla-regras" class="btn-pacs-outline"><i class="fa fa-arrow-left"></i></a>
</div>

<?php if (!empty($_GET['token_gerado'])): ?>
    <div class="pacs-alert pacs-alert-success mb-3"><i class="fa fa-check-circle"></i> Token gerado.</div>
<?php endif; ?>

<div class="pacs-card" style="max-width:820px;">
    <div class="pacs-card-body">

        <div style="margin-bottom:1rem;">
            <label class="form-label-dark"><?= htmlspecialchars(t('sla_regras.robo.campo_url')) ?></label>
            <input type="text" readonly class="form-control-dark" style="font-family:monospace;font-size:.8rem;"
                   value="<?= $urlRobo ? htmlspecialchars($urlRobo) : '—' ?>" onclick="this.select();">
        </div>

        <div style="display:flex;gap:.75rem;margin-bottom:1.5rem;">
            <form method="POST" action="/sla-regras/robo/gerar-token" onsubmit="return confirm('<?= htmlspecialchars(t('sla_regras.robo.confirma_gerar_token')) ?>');">
                <button type="submit" class="btn-pacs-outline"><i class="fa fa-key"></i> <?= htmlspecialchars(t('sla_regras.robo.botao_gerar_token')) ?></button>
            </form>
            <form method="POST" action="/sla-regras/robo/toggle">
                <button type="submit" class="btn-pacs-<?= $ativo ? 'outline' : 'primary' ?>">
                    <i class="fa fa-power-off"></i> <?= $ativo ? htmlspecialchars(t('comum.status.ativo')) : htmlspecialchars(t('comum.status.inativo')) ?>
                </button>
            </form>
        </div>

        <div style="border-top:1px solid var(--pacs-border,#3a3f4b);padding-top:1rem;font-size:.85rem;">
            <p>
                <strong><?= htmlspecialchars(t('sla_regras.robo.campo_status_lock')) ?>:</strong>
                <?= $lockAdquiridoEm ? htmlspecialchars(t('sla_regras.robo.status_lock_em_execucao')) . ' (' . htmlspecialchars($lockAdquiridoEm) . ')' : htmlspecialchars(t('sla_regras.robo.status_lock_livre')) ?>
            </p>
            <p>
                <strong><?= htmlspecialchars(t('sla_regras.robo.ultima_execucao')) ?>:</strong>
                <?= $ultimaExecucaoEm ? htmlspecialchars($ultimaExecucaoEm) : htmlspecialchars(t('sla_regras.robo.nunca_executado')) ?>
            </p>
            <?php if ($resumo): ?>
                <pre style="background:rgba(0,0,0,.2);padding:.75rem;border-radius:6px;font-size:.75rem;overflow-x:auto;"><?= htmlspecialchars($resumo) ?></pre>
            <?php endif; ?>
        </div>
    </div>
</div>
