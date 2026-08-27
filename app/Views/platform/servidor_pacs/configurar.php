<?php
// View: Servidor PACS — Cadastro/Configuração de um servidor Orthanc + Negócios associados (N:N)
$isNovo = $servidor === null;
$actionUrl = $isNovo ? '/platform/servidor-pacs/criar' : "/platform/servidor-pacs/{$servidor['id']}/salvar-config";
$testarUrl = $isNovo ? null : "/platform/servidor-pacs/{$servidor['id']}/testar";
$isTenantCell = !$isNovo && !empty($provisioning);
$csrf = $csrfToken ?? ($_SESSION['csrf_token'] ?? '');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="fa fa-cog me-2 text-primary"></i>
            <?= $isNovo ? htmlspecialchars(t('servidor_pacs.configurar.titulo_novo')) : htmlspecialchars(t('servidor_pacs.configurar.titulo_editar')) ?>
        </h1>
        <small class="text-muted"><?= htmlspecialchars(t('servidor_pacs.configurar.subtitulo')) ?></small>
    </div>
    <a href="/platform/servidor-pacs" class="btn btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i> <?= htmlspecialchars(t('comum.acoes.voltar')) ?>
    </a>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($_SESSION['success']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($_SESSION['error']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<div class="row">
    <div class="col-md-7">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h6 class="mb-0 fw-bold"><i class="fa fa-network-wired me-2"></i><?= htmlspecialchars(t('servidor_pacs.configurar.parametros_conexao')) ?></h6>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fa fa-info-circle me-2"></i>
                    <?= $isTenantCell ? 'Esta célula é gerenciada pelo onboarding DICOM VPN-only. Endpoints, credenciais e portas privadas são somente leitura nesta tela.' : htmlspecialchars(t('servidor_pacs.configurar.info_auth')) ?>
                </div>

                <form action="<?= $actionUrl ?>" method="POST" id="formConfig">
                    <input type="hidden" name="_csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= htmlspecialchars(t('servidor_pacs.configurar.label_nome')) ?></label>
                        <input type="text" name="nome" class="form-control"
                               value="<?= htmlspecialchars($servidor['nome'] ?? '') ?>"
                               placeholder="Ex: Orthanc Principal" <?= $isTenantCell ? 'readonly' : '' ?>>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= htmlspecialchars(t('servidor_pacs.configurar.label_url')) ?> <span class="text-danger">*</span></label>
                        <input type="url" name="url" id="urlInput" class="form-control" required
                               value="<?= htmlspecialchars($servidor['url'] ?? '') ?>"
                               placeholder="http://46.225.51.122:8042" <?= $isTenantCell ? 'readonly' : '' ?>>
                        <small class="text-muted"><?= htmlspecialchars(t('servidor_pacs.configurar.ajuda_url')) ?></small>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= htmlspecialchars(t('servidor_pacs.configurar.label_usuario')) ?></label>
                            <input type="text" name="usuario" class="form-control"
                                   value="<?= htmlspecialchars($servidor['usuario'] ?? '') ?>"
                               placeholder="Deixe em branco se sem autenticação"
                               autocomplete="off" <?= $isTenantCell ? 'readonly' : '' ?>>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= htmlspecialchars(t('servidor_pacs.configurar.label_senha')) ?></label>
                            <div class="input-group">
                                <input type="password" name="senha" id="senhaInput" class="form-control"
                                       placeholder="<?= !empty($servidor['tem_senha']) ? htmlspecialchars(t('servidor_pacs.configurar.senha_salva')) : 'Deixe em branco se sem autenticação' ?>"
                                       autocomplete="new-password" <?= $isTenantCell ? 'disabled' : '' ?>>
                                <button class="btn btn-outline-secondary" type="button" onclick="toggleSenha()">
                                    <i class="fa fa-eye" id="eyeIcon"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold"><?= htmlspecialchars(t('servidor_pacs.configurar.label_timeout')) ?></label>
                        <input type="number" name="timeout" class="form-control" style="max-width:120px;"
                               value="<?= (int)($servidor['timeout'] ?? 30) ?>" min="5" max="120" <?= $isTenantCell ? 'readonly' : '' ?>>
                    </div>

                    <div class="d-flex gap-2">
                        <?php if ($testarUrl && !$isTenantCell): ?>
                            <button type="button" class="btn btn-outline-primary" onclick="testarConexaoForm()">
                                <i class="fa fa-plug me-1"></i> <?= htmlspecialchars(t('servidor_pacs.configurar.botao_testar')) ?>
                            </button>
                        <?php endif; ?>
                        <?php if (!$isTenantCell): ?>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fa fa-save me-1"></i> <?= htmlspecialchars(t('servidor_pacs.configurar.botao_salvar')) ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </form>

                <div id="testeResult" class="mt-3 d-none"></div>
            </div>
        </div>

        <?php if (!$isNovo): ?>
        <!-- NEGÓCIOS ASSOCIADOS (N:N) -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0 fw-bold"><i class="fa fa-building me-2 text-primary"></i><?= htmlspecialchars(t('servidor_pacs.configurar.negocios_associados')) ?></h6>
            </div>
            <div class="card-body">
                <p class="small text-muted"><?= htmlspecialchars(t('servidor_pacs.configurar.negocios_explicacao')) ?></p>

                <div class="row g-2 align-items-end mb-3">
                    <div class="col">
                        <label class="form-label small fw-semibold"><?= htmlspecialchars(t('servidor_pacs.configurar.label_negocio')) ?></label>
                        <select id="selectNegocio" class="form-select form-select-sm">
                            <option value=""><?= htmlspecialchars(t('servidor_pacs.configurar.selecione_negocio')) ?></option>
                            <?php foreach ($todosNegocios as $n): ?>
                                <option value="<?= (int)$n['id'] ?>"><?= htmlspecialchars($n['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-primary" onclick="associarNegocio()">
                            <i class="fa fa-plus me-1"></i> <?= htmlspecialchars(t('servidor_pacs.configurar.botao_associar')) ?>
                        </button>
                    </div>
                </div>

                <div id="associarResult" class="alert d-none mb-3"></div>

                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><?= htmlspecialchars(t('servidor_pacs.configurar.coluna_negocio')) ?></th>
                            <th class="text-end"><?= htmlspecialchars(t('comum.acoes.titulo')) ?></th>
                        </tr>
                    </thead>
                    <tbody id="tbodyNegocios">
                        <?php if (empty($negociosAssociados)): ?>
                            <tr id="rowNenhumNegocio"><td colspan="2" class="text-center text-muted py-3"><?= htmlspecialchars(t('servidor_pacs.configurar.nenhum_negocio_associado')) ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($negociosAssociados as $n): ?>
                                <tr data-tenant-id="<?= (int)$n['tenant_id'] ?>">
                                    <td><i class="fa fa-building me-1 text-primary"></i><?= htmlspecialchars($n['nome']) ?></td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-danger" onclick="desassociarNegocio(<?= (int)$n['tenant_id'] ?>, '<?= htmlspecialchars(addslashes($n['nome'])) ?>')">
                                            <i class="fa fa-unlink"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-md-5">
        <?php if (!$isNovo): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0 fw-bold"><i class="fa fa-info-circle me-2"></i><?= htmlspecialchars(t('servidor_pacs.configurar.status_atual')) ?></h6>
            </div>
            <div class="card-body small">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted"><?= htmlspecialchars(t('servidor_pacs.configurar.status')) ?>:</td><td><span class="badge bg-<?= $servidor['status_ping'] === 'online' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($servidor['status_ping']) ?></span></td></tr>
                    <tr><td class="text-muted"><?= htmlspecialchars(t('servidor_pacs.configurar.versao')) ?>:</td><td><?= htmlspecialchars($servidor['versao'] ?? '—') ?></td></tr>
                    <tr><td class="text-muted">AETitle:</td><td><code><?= htmlspecialchars($servidor['dicom_aet'] ?? '—') ?></code></td></tr>
                    <tr><td class="text-muted"><?= htmlspecialchars(t('servidor_pacs.configurar.ultimo_ping')) ?>:</td><td><?= htmlspecialchars($servidor['ultimo_ping'] ?? '—') ?></td></tr>
                    <tr><td class="text-muted"><?= htmlspecialchars(t('servidor_pacs.configurar.ultima_sync_automatica')) ?>:</td><td><?= htmlspecialchars($servidor['sync_ultima_execucao'] ?? '—') ?></td></tr>
                </table>
                <?php if ($isTenantCell): ?>
                <hr><h6 class="fw-bold text-primary">Integração VPN-only</h6>
                <table class="table table-sm table-borderless mb-2">
                  <tr><td class="text-muted">Estado:</td><td><span id="tenantOperationBadge" class="badge bg-info"><?= htmlspecialchars($provisioning['status']) ?></span></td></tr>
                  <tr><td class="text-muted">Rota:</td><td><code><?= htmlspecialchars($provisioning['route_key']) ?></code></td></tr>
                  <tr><td class="text-muted">Called AE:</td><td><code><?= htmlspecialchars($provisioning['called_ae']) ?></code></td></tr>
                  <tr><td class="text-muted">Calling AE:</td><td><code><?= htmlspecialchars($provisioning['calling_ae']) ?></code></td></tr>
                  <tr><td class="text-muted">IP VPN do cliente:</td><td><code><?= htmlspecialchars($provisioning['vpn_client_ip']) ?></code></td></tr>
                </table>
                <label class="form-label small fw-semibold">Chave pública WireGuard recebida do cliente</label>
                <input class="form-control form-control-sm font-monospace" readonly value="<?= htmlspecialchars($provisioning['wireguard_public_key']) ?>">
                <div class="form-text mb-2">Apenas a chave pública é mantida no cadastro. Para trocar o peer, use o procedimento de rotação aprovado; não cole chave privada nesta página.</div>
                <?php if (!empty($provisioning['gateway_public_key'])): ?>
                  <a class="btn btn-sm btn-outline-primary w-100 mb-2" href="/platform/servidor-pacs/<?= (int)$servidor['id'] ?>/operacao/kit-vpn-only.pdf"><i class="fa fa-file-pdf me-1"></i> Baixar kit VPN-only do cliente</a>
                  <button class="btn btn-sm btn-primary w-100 mb-2" type="button" onclick="verificarEchoTenant()"><i class="fa fa-heartbeat me-1"></i> Verificar C-ECHO</button>
                  <?php if (($provisioning['status'] ?? '') === 'echo_validated'): ?>
                    <button class="btn btn-sm btn-outline-danger w-100" type="button" onclick="ativarCstoreTenant()"><i class="fa fa-lock-open me-1"></i> Liberar C-STORE após confirmação</button>
                  <?php endif; ?>
                <?php endif; ?>
                <div id="tenantOperationResult" class="alert d-none mt-2 mb-0"></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const SERVIDOR_ID = <?= $isNovo ? 'null' : (int)$servidor['id'] ?>;
const CSRF_TOKEN = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;
const IS_TENANT_CELL = <?= $isTenantCell ? 'true' : 'false' ?>;

function toggleSenha() {
    const input = document.getElementById('senhaInput');
    const icon  = document.getElementById('eyeIcon');
    if (input.type === 'password') { input.type = 'text'; icon.className = 'fa fa-eye-slash'; }
    else { input.type = 'password'; icon.className = 'fa fa-eye'; }
}

function testarConexaoForm() {
    const result = document.getElementById('testeResult');
    result.className = 'mt-3 alert alert-info';
    result.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>Testando conexão...';
    result.classList.remove('d-none');

    fetch(`/platform/servidor-pacs/${SERVIDOR_ID}/testar`, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(r => r.json())
        .then(data => {
            result.className = data.success ? 'mt-3 alert alert-success' : 'mt-3 alert alert-danger';
            result.innerHTML = `<i class="fa ${data.success ? 'fa-check-circle' : 'fa-times-circle'} me-2"></i>${data.message}`;
        })
        .catch(() => {
            result.className = 'mt-3 alert alert-danger';
            result.innerHTML = '<i class="fa fa-times-circle me-2"></i>Erro de comunicação.';
        });
}

function verificarEchoTenant() {
    const result = document.getElementById('tenantOperationResult');
    result.className = 'alert alert-info mt-2 mb-0';
    result.textContent = 'Consultando a auditoria técnica do gateway…';
    fetch(`/platform/servidor-pacs/${SERVIDOR_ID}/operacao/testar-echo`, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN}, body: `_csrf_token=${encodeURIComponent(CSRF_TOKEN)}`})
      .then(r => r.json()).then(data => { result.className = data.success ? 'alert alert-success mt-2 mb-0' : 'alert alert-warning mt-2 mb-0'; result.textContent = data.message; if (data.success) setTimeout(() => location.reload(), 800); })
      .catch(() => { result.className = 'alert alert-danger mt-2 mb-0'; result.textContent = 'Não foi possível consultar o gateway.'; });
}
function ativarCstoreTenant() {
    const confirmacao = prompt('Esta ação habilita o recebimento DICOM. Digite exatamente LIBERAR C-STORE para confirmar.');
    if (confirmacao !== 'LIBERAR C-STORE') return;
    const result = document.getElementById('tenantOperationResult');
    fetch(`/platform/servidor-pacs/${SERVIDOR_ID}/operacao/ativar-cstore`, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN}, body: `_csrf_token=${encodeURIComponent(CSRF_TOKEN)}&confirm=${encodeURIComponent(confirmacao)}`})
      .then(r => r.json()).then(data => { result.className = data.success ? 'alert alert-success mt-2 mb-0' : 'alert alert-danger mt-2 mb-0'; result.textContent = data.message; if (data.success) setTimeout(() => location.reload(), 900); })
      .catch(() => { result.className = 'alert alert-danger mt-2 mb-0'; result.textContent = 'Não foi possível liberar C-STORE.'; });
}

function associarNegocio() {
    const select = document.getElementById('selectNegocio');
    const tenantId = select.value;
    const nome = select.options[select.selectedIndex]?.text;
    if (!tenantId) return;

    const result = document.getElementById('associarResult');
    fetch(`/platform/servidor-pacs/${SERVIDOR_ID}/negocios/associar`, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: `tenant_id=${encodeURIComponent(tenantId)}`
    })
        .then(r => r.json())
        .then(data => {
            result.className = data.success ? 'alert alert-success' : 'alert alert-danger';
            result.classList.remove('d-none');
            result.textContent = data.message;
            if (data.success) setTimeout(() => location.reload(), 1000);
        });
}

function desassociarNegocio(tenantId, nome) {
    if (!confirm(`Remover a associação com "${nome}"? Estudos já roteados para este negócio permanecem roteados.`)) return;

    fetch(`/platform/servidor-pacs/${SERVIDOR_ID}/negocios/${tenantId}/desassociar`, {
        method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
        .then(r => r.json())
        .then(data => { if (data.success) location.reload(); });
}
</script>
