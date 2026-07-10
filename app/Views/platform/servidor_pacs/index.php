<?php
// View: Servidor PACS — Dashboard principal
$statusClass = [
    'online'         => 'success',
    'offline'        => 'danger',
    'erro'           => 'danger',
    'nunca_testado'  => 'secondary',
];
$statusLabel = [
    'online'         => 'Online',
    'offline'        => 'Offline',
    'erro'           => 'Erro',
    'nunca_testado'  => 'Nunca testado',
];
$pingStatus  = $servidor['status_ping'] ?? 'nunca_testado';
$badgeClass  = $statusClass[$pingStatus] ?? 'secondary';
$badgeLabel  = $statusLabel[$pingStatus] ?? 'Desconhecido';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="fa fa-server me-2 text-primary"></i>Servidor PACS</h1>
        <small class="text-muted">Gerenciamento do servidor Orthanc global da plataforma</small>
    </div>
    <div class="d-flex gap-2">
        <a href="/platform/servidor-pacs/configurar" class="btn btn-outline-secondary">
            <i class="fa fa-cog me-1"></i> Configurar
        </a>
        <button class="btn btn-outline-primary" onclick="testarConexao()" id="btnTestar">
            <i class="fa fa-plug me-1"></i> Testar Conexão
        </button>
        <button class="btn btn-primary" onclick="sincronizar()" id="btnSync">
            <i class="fa fa-sync me-1"></i> Sincronizar Estudos
        </button>
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

<!-- STATUS DO SERVIDOR -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-<?= $badgeClass ?> bg-opacity-10" style="width:56px;height:56px;">
                        <i class="fa fa-server fa-lg text-<?= $badgeClass ?>"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-6"><?= htmlspecialchars($servidor['nome'] ?? 'Não configurado') ?></div>
                        <div class="small text-muted"><?= htmlspecialchars($servidor['url'] ?? '—') ?></div>
                        <span class="badge bg-<?= $badgeClass ?> mt-1"><?= $badgeLabel ?></span>
                        <?php if ($servidor['versao'] ?? ''): ?>
                            <span class="badge bg-light text-dark ms-1">v<?= htmlspecialchars($servidor['versao']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($servidor['ultimo_ping'] ?? ''): ?>
                    <div class="mt-2 small text-muted"><i class="fa fa-clock me-1"></i>Último ping: <?= $servidor['ultimo_ping'] ?></div>
                <?php endif; ?>
                <?php if ($servidor['dicom_aet'] ?? ''): ?>
                    <div class="small text-muted"><i class="fa fa-network-wired me-1"></i>AETitle: <strong><?= htmlspecialchars($servidor['dicom_aet']) ?></strong>:<?= $servidor['dicom_port'] ?? 4242 ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-2">
        <div class="card border-0 shadow-sm h-100 text-center">
            <div class="card-body d-flex flex-column justify-content-center">
                <div class="fs-2 fw-bold text-primary"><?= number_format($servidor['total_estudos'] ?? 0) ?></div>
                <div class="small text-muted">Estudos no PACS</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm h-100 text-center">
            <div class="card-body d-flex flex-column justify-content-center">
                <div class="fs-2 fw-bold text-success"><?= number_format($totalRoteados) ?></div>
                <div class="small text-muted">Estudos Roteados</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm h-100 text-center">
            <div class="card-body d-flex flex-column justify-content-center">
                <div class="fs-2 fw-bold text-warning"><?= number_format($naoRoteados) ?></div>
                <div class="small text-muted">Não Roteados</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm h-100 text-center">
            <div class="card-body d-flex flex-column justify-content-center">
                <div class="fs-2 fw-bold text-info"><?= number_format($servidor['disk_size_mb'] ?? 0) ?> MB</div>
                <div class="small text-muted">Armazenamento</div>
            </div>
        </div>
    </div>
</div>

<!-- STATUS DA SINCRONIZAÇÃO -->
<div id="syncStatus" class="alert d-none mb-4"></div>

<!-- ROTEAMENTOS ATIVOS -->
<div class="row g-3 mb-4">
    <div class="col-md-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fa fa-route me-2 text-primary"></i>Roteamentos Ativos (InstitutionName → Negócio)</h6>
                <a href="/platform/servidor-pacs/roteamento" class="btn btn-sm btn-outline-primary">
                    <i class="fa fa-cog me-1"></i> Gerenciar
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($roteamentos)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fa fa-exclamation-triangle fa-2x mb-2 text-warning"></i>
                        <p class="mb-2">Nenhum roteamento configurado.</p>
                        <a href="/platform/servidor-pacs/roteamento" class="btn btn-sm btn-warning">Configurar Roteamentos</a>
                    </div>
                <?php else: ?>
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>InstitutionName (DICOM)</th>
                                <th>Negócio</th>
                                <th class="text-center">Estudos</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roteamentos as $r): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($r['institution_name']) ?></code></td>
                                    <td>
                                        <i class="fa fa-building me-1 text-primary"></i>
                                        <?= htmlspecialchars($r['negocio_nome']) ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary"><?= number_format($r['total_estudos']) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $r['ativo'] ? 'success' : 'secondary' ?>">
                                            <?= $r['ativo'] ? 'Ativo' : 'Inativo' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fa fa-hospital me-2 text-info"></i>InstitutionNames no PACS</h6>
                <a href="/platform/servidor-pacs/estudos" class="btn btn-sm btn-outline-info">Ver Estudos</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($institutionStats)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fa fa-database fa-2x mb-2"></i>
                        <p class="mb-0">Nenhum estudo importado ainda.<br>Clique em <strong>Sincronizar Estudos</strong>.</p>
                    </div>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>InstitutionName</th>
                                <th class="text-center">Estudos</th>
                                <th class="text-center">Roteado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($institutionStats as $inst): ?>
                                <tr>
                                    <td class="small"><code><?= htmlspecialchars($inst['institution_name'] ?? '(vazio)') ?></code></td>
                                    <td class="text-center"><span class="badge bg-secondary"><?= $inst['total'] ?></span></td>
                                    <td class="text-center">
                                        <?php if ($inst['tenant_id']): ?>
                                            <i class="fa fa-check-circle text-success"></i>
                                        <?php else: ?>
                                            <i class="fa fa-times-circle text-danger"></i>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ÚLTIMO SYNC -->
<?php if ($ultimoSync): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white">
        <h6 class="mb-0 fw-bold"><i class="fa fa-history me-2"></i>Última Sincronização</h6>
    </div>
    <div class="card-body">
        <div class="row text-center">
            <div class="col">
                <div class="fw-bold text-success"><?= $ultimoSync['estudos_novos'] ?></div>
                <small class="text-muted">Novos</small>
            </div>
            <div class="col">
                <div class="fw-bold text-info"><?= $ultimoSync['estudos_atualizados'] ?></div>
                <small class="text-muted">Atualizados</small>
            </div>
            <div class="col">
                <div class="fw-bold text-primary"><?= $ultimoSync['estudos_roteados'] ?></div>
                <small class="text-muted">Roteados</small>
            </div>
            <div class="col">
                <div class="fw-bold text-danger"><?= $ultimoSync['erros'] ?></div>
                <small class="text-muted">Erros</small>
            </div>
            <div class="col-auto text-muted small">
                <i class="fa fa-clock me-1"></i><?= $ultimoSync['iniciado_em'] ?>
                <span class="badge bg-<?= $ultimoSync['status'] === 'concluido' ? 'success' : ($ultimoSync['status'] === 'erro' ? 'danger' : 'warning') ?> ms-2">
                    <?= ucfirst($ultimoSync['status']) ?>
                </span>
            </div>
        </div>
        <?php if ($ultimoSync['mensagem']): ?>
            <div class="mt-2 small text-muted"><?= htmlspecialchars($ultimoSync['mensagem']) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
function testarConexao() {
    const btn = document.getElementById('btnTestar');
    const status = document.getElementById('syncStatus');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Testando...';
    status.className = 'alert alert-info';
    status.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>Conectando ao servidor Orthanc...';
    status.classList.remove('d-none');

    safeFetchJson('/platform/servidor-pacs/testar', {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(data => {
            if (data.success) {
                status.className = 'alert alert-success';
                status.innerHTML = `<i class="fa fa-check-circle me-2"></i><strong>Conexão OK!</strong> ${data.message}
                    <div class="mt-2 small">
                        <span class="badge bg-primary me-1">${data.studies ?? 0} estudos</span>
                        <span class="badge bg-info me-1">${data.patients ?? 0} pacientes</span>
                        <span class="badge bg-secondary">${data.disk_mb ?? 0} MB</span>
                    </div>`;
                setTimeout(() => location.reload(), 3000);
            } else {
                status.className = 'alert alert-danger';
                status.innerHTML = `<i class="fa fa-times-circle me-2"></i><strong>Falha!</strong> ${data.message}`;
            }
        })
        .catch(err => {
            status.className = 'alert alert-danger';
            status.innerHTML = '<i class="fa fa-times-circle me-2"></i>Erro de comunicação: ' + (err?.message ?? 'verifique o log PHP.');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-plug me-1"></i> Testar Conexão';
        });
}

function sincronizar() {
    const btn = document.getElementById('btnSync');
    const status = document.getElementById('syncStatus');
    
    if (!confirm('Iniciar sincronização de estudos do Orthanc? Isso pode levar alguns segundos.')) return;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Sincronizando...';
    status.className = 'alert alert-info';
    status.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>Importando estudos do Orthanc PACS...';
    status.classList.remove('d-none');

    safeFetchJson('/platform/servidor-pacs/sincronizar', {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(data => {
            if (data.success) {
                status.className = 'alert alert-success';
                status.innerHTML = `<i class="fa fa-check-circle me-2"></i><strong>Sincronização concluída!</strong> ${data.message}
                    <div class="mt-2 small">
                        <span class="badge bg-success me-1">${data.novos ?? 0} novos</span>
                        <span class="badge bg-info me-1">${data.atualizados ?? 0} atualizados</span>
                        <span class="badge bg-primary me-1">${data.roteados ?? 0} roteados</span>
                        ${(data.erros ?? 0) > 0 ? `<span class="badge bg-danger">${data.erros} erros</span>` : ''}
                    </div>`;
                setTimeout(() => location.reload(), 3000);
            } else {
                status.className = 'alert alert-danger';
                status.innerHTML = `<i class="fa fa-times-circle me-2"></i><strong>Erro na sincronização!</strong> ${data.message}`;
            }
        })
        .catch(err => {
            status.className = 'alert alert-danger';
            status.innerHTML = '<i class="fa fa-times-circle me-2"></i><strong>Erro:</strong> ' + (err?.message ?? 'Falha de comunicação. Verifique o log PHP do servidor.');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-sync me-1"></i> Sincronizar Estudos';
        });
}

// Captura respostas não-JSON (ex: PHP Fatal Error em HTML)
function safeFetchJson(url, options) {
    return fetch(url, options).then(r => {
        const ct = r.headers.get('content-type') || '';
        if (!ct.includes('json')) {
            return r.text().then(txt => {
                // Tenta extrair mensagem de erro PHP do HTML
                const m = txt.match(/(?:Fatal error|Parse error|Warning|Notice)[^<\n]*/i);
                throw new Error(m ? m[0].trim() : 'Resposta inválida do servidor. Verifique o log PHP.');
            });
        }
        return r.json();
    });
}
</script>
