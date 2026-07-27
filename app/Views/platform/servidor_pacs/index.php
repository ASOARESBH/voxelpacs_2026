<?php
// View: Servidor PACS — Lista de servidores (N:N com Negócios)
$statusClass = [
    'online'         => 'success',
    'offline'        => 'danger',
    'erro'           => 'danger',
    'nunca_testado'  => 'secondary',
];
$statusLabel = [
    'online'         => t('servidor_pacs.status.online'),
    'offline'        => t('servidor_pacs.status.offline'),
    'erro'           => t('servidor_pacs.status.erro'),
    'nunca_testado'  => t('servidor_pacs.status.nunca_testado'),
];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="fa fa-server me-2 text-primary"></i><?= htmlspecialchars(t('servidor_pacs.index.titulo')) ?></h1>
        <small class="text-muted"><?= htmlspecialchars(t('servidor_pacs.index.subtitulo')) ?></small>
    </div>
    <div class="d-flex gap-2">
        <a href="/platform/servidor-pacs/estudos" class="btn btn-outline-info">
            <i class="fa fa-x-ray me-1"></i> <?= htmlspecialchars(t('servidor_pacs.index.botao_estudos')) ?>
        </a>
        <a href="/platform/servidor-pacs/novo" class="btn btn-primary">
            <i class="fa fa-plus me-1"></i> <?= htmlspecialchars(t('servidor_pacs.index.botao_novo_servidor')) ?>
        </a>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= htmlspecialchars($_SESSION['success']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= htmlspecialchars($_SESSION['error']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<div id="syncStatus" class="alert d-none mb-4"></div>

<!-- ROBÔ DE SINCRONIZAÇÃO AUTOMÁTICA (global, a cada 2 minutos, todos os servidores) -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="fa fa-robot me-2 text-primary"></i><?= htmlspecialchars(t('servidor_pacs.robo.titulo')) ?></h6>
        <span class="badge bg-<?= ($roboConfig['ativo'] ?? 0) ? 'success' : 'secondary' ?>">
            <?= ($roboConfig['ativo'] ?? 0) ? htmlspecialchars(t('servidor_pacs.robo.ativo')) : htmlspecialchars(t('servidor_pacs.robo.inativo')) ?>
        </span>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-3"><?= htmlspecialchars(t('servidor_pacs.robo.descricao')) ?></p>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <form action="/platform/servidor-pacs/sync-robo/toggle" method="POST" class="d-inline">
                <button type="submit" class="btn btn-sm btn-outline-<?= ($roboConfig['ativo'] ?? 0) ? 'secondary' : 'success' ?>">
                    <?= ($roboConfig['ativo'] ?? 0) ? htmlspecialchars(t('servidor_pacs.robo.desativar')) : htmlspecialchars(t('servidor_pacs.robo.ativar')) ?>
                </button>
            </form>
            <form action="/platform/servidor-pacs/sync-robo/gerar-token" method="POST" class="d-inline">
                <button type="submit" class="btn btn-sm btn-outline-primary"><?= htmlspecialchars(t('servidor_pacs.robo.gerar_token')) ?></button>
            </form>
            <?php if (!empty($roboConfig['token'])): ?>
                <div class="input-group input-group-sm" style="max-width:520px;">
                    <input type="text" class="form-control" readonly
                           value="<?= htmlspecialchars((($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/api/servidor-pacs/sync-robo?token=' . $roboConfig['token'])) ?>"
                           id="syncRoboUrl">
                    <button class="btn btn-outline-secondary" type="button" onclick="copiarUrlRobo()">
                        <i class="fa fa-copy"></i>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <details class="mt-3 small text-muted">
            <summary><?= htmlspecialchars(t('servidor_pacs.robo.como_configurar')) ?></summary>
            <p class="mt-2 mb-0"><?= htmlspecialchars(t('servidor_pacs.robo.instrucoes')) ?></p>
        </details>
    </div>
</div>

<!-- LISTA DE SERVIDORES -->
<div class="row g-3">
    <?php if (empty($servidores)): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-5 text-center text-muted">
                    <i class="fa fa-server fa-2x mb-3"></i>
                    <p class="mb-3"><?= htmlspecialchars(t('servidor_pacs.index.nenhum_servidor')) ?></p>
                    <a href="/platform/servidor-pacs/novo" class="btn btn-primary"><?= htmlspecialchars(t('servidor_pacs.index.botao_novo_servidor')) ?></a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($servidores as $srv): ?>
        <?php
            $pingStatus = $srv['status_ping'] ?? 'nunca_testado';
            $badgeClass = $statusClass[$pingStatus] ?? 'secondary';
            $badgeLabel = $statusLabel[$pingStatus] ?? $pingStatus;
        ?>
        <div class="col-md-6 col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center bg-<?= $badgeClass ?> bg-opacity-10" style="width:48px;height:48px;">
                            <i class="fa fa-server text-<?= $badgeClass ?>"></i>
                        </div>
                        <div>
                            <div class="fw-bold"><?= htmlspecialchars($srv['nome']) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars($srv['url']) ?></div>
                        </div>
                    </div>

                    <span class="badge bg-<?= $badgeClass ?> mb-2"><?= htmlspecialchars($badgeLabel) ?></span>
                    <?php if ($srv['ultimo_ping']): ?>
                        <div class="small text-muted mb-2"><i class="fa fa-clock me-1"></i><?= htmlspecialchars($srv['ultimo_ping']) ?></div>
                    <?php endif; ?>

                    <div class="row text-center g-1 mb-2">
                        <div class="col">
                            <div class="fw-bold text-primary"><?= number_format($srv['total_estudos']) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars(t('servidor_pacs.index.estudos')) ?></div>
                        </div>
                        <div class="col">
                            <div class="fw-bold text-warning"><?= number_format($srv['nao_identificados']) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars(t('servidor_pacs.status.nao_identificado')) ?></div>
                        </div>
                        <div class="col">
                            <div class="fw-bold text-danger"><?= number_format($srv['conflitos']) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars(t('servidor_pacs.status.conflito')) ?></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="small text-muted mb-1"><?= htmlspecialchars(t('servidor_pacs.index.negocios_associados')) ?>:</div>
                        <?php if (empty($srv['negocios'])): ?>
                            <span class="badge bg-light text-dark border"><?= htmlspecialchars(t('servidor_pacs.index.nenhum_negocio_associado')) ?></span>
                        <?php else: ?>
                            <?php foreach ($srv['negocios'] as $n): ?>
                                <span class="badge bg-secondary bg-opacity-25 text-dark border me-1 mb-1"><?= htmlspecialchars($n['nome']) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <a href="/platform/servidor-pacs/<?= (int)$srv['id'] ?>/configurar" class="btn btn-sm btn-outline-secondary">
                            <i class="fa fa-cog me-1"></i> <?= htmlspecialchars(t('servidor_pacs.index.botao_configurar')) ?>
                        </a>
                        <a href="/platform/servidor-pacs/estudos?servidor=<?= (int)$srv['id'] ?>" class="btn btn-sm btn-outline-info">
                            <i class="fa fa-x-ray me-1"></i> <?= htmlspecialchars(t('servidor_pacs.index.botao_ver_estudos')) ?>
                        </a>
                        <button class="btn btn-sm btn-outline-primary btn-sincronizar" data-id="<?= (int)$srv['id'] ?>">
                            <i class="fa fa-sync me-1"></i> <?= htmlspecialchars(t('servidor_pacs.index.botao_sincronizar')) ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
function copiarUrlRobo() {
    const input = document.getElementById('syncRoboUrl');
    input.select();
    navigator.clipboard.writeText(input.value);
}

document.querySelectorAll('.btn-sincronizar').forEach(btn => {
    btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        const status = document.getElementById('syncStatus');
        btn.disabled = true;
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> <?= htmlspecialchars(t('servidor_pacs.index.sincronizando')) ?>';
        status.className = 'alert alert-info';
        status.classList.remove('d-none');
        status.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i><?= htmlspecialchars(t('servidor_pacs.index.sincronizando')) ?>';

        fetch(`/platform/servidor-pacs/${id}/sincronizar`, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(r => r.json())
            .then(data => {
                status.className = data.success ? 'alert alert-success' : 'alert alert-danger';
                status.innerHTML = `<i class="fa ${data.success ? 'fa-check-circle' : 'fa-times-circle'} me-2"></i>${data.message}`;
                if (data.success) setTimeout(() => location.reload(), 2000);
            })
            .catch(() => {
                status.className = 'alert alert-danger';
                status.innerHTML = '<i class="fa fa-times-circle me-2"></i>Erro de comunicação.';
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = original;
            });
    });
});
</script>
