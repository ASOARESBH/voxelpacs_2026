<?php
// View: Servidor PACS — Configuração da conexão Orthanc
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="fa fa-cog me-2 text-primary"></i>Configurar Servidor PACS</h1>
        <small class="text-muted">Conexão com o Orthanc PACS (servidor único global)</small>
    </div>
    <a href="/platform/servidor-pacs" class="btn btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row">
    <div class="col-md-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0 fw-bold"><i class="fa fa-network-wired me-2"></i>Parâmetros de Conexão</h6>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fa fa-info-circle me-2"></i>
                    <strong>Orthanc REST API</strong> — O Orthanc utiliza autenticação <strong>HTTP Basic Auth</strong> (usuário/senha).
                    Se o servidor não tiver autenticação configurada, deixe os campos em branco.
                    A URL padrão é <code>http://IP:8042</code>.
                </div>

                <form action="/platform/servidor-pacs/salvar-config" method="POST" id="formConfig">
                    <input type="hidden" name="_csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nome do Servidor</label>
                        <input type="text" name="nome" class="form-control"
                               value="<?= htmlspecialchars($servidor['nome'] ?? 'Orthanc VOXEL (Hetzner)') ?>"
                               placeholder="Ex: Orthanc Principal">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">URL do Servidor Orthanc <span class="text-danger">*</span></label>
                        <input type="url" name="url" id="urlInput" class="form-control" required
                               value="<?= htmlspecialchars($servidor['url'] ?? 'http://46.225.51.122:8042') ?>"
                               placeholder="http://46.225.51.122:8042">
                        <small class="text-muted">Inclua o protocolo (http:// ou https://) e a porta (padrão: 8042)</small>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Usuário (HTTP Basic Auth)</label>
                            <input type="text" name="usuario" class="form-control"
                                   value="<?= htmlspecialchars($servidor['usuario'] ?? '') ?>"
                                   placeholder="Deixe em branco se sem autenticação"
                                   autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Senha</label>
                            <div class="input-group">
                                <input type="password" name="senha" id="senhaInput" class="form-control"
                                       placeholder="<?= $servidor['senha'] ? '(senha salva — deixe em branco para manter)' : 'Deixe em branco se sem autenticação' ?>"
                                       autocomplete="new-password">
                                <button class="btn btn-outline-secondary" type="button" onclick="toggleSenha()">
                                    <i class="fa fa-eye" id="eyeIcon"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Timeout (segundos)</label>
                        <input type="number" name="timeout" class="form-control" style="max-width:120px;"
                               value="<?= (int)($servidor['timeout'] ?? 30) ?>" min="5" max="120">
                    </div>

                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary" onclick="testarConexaoForm()">
                            <i class="fa fa-plug me-1"></i> Testar Conexão
                        </button>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fa fa-save me-1"></i> Salvar Configurações
                        </button>
                    </div>
                </form>

                <div id="testeResult" class="mt-3 d-none"></div>
            </div>
        </div>
    </div>

    <div class="col-md-5">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h6 class="mb-0 fw-bold"><i class="fa fa-question-circle me-2 text-info"></i>Como funciona a autenticação?</h6>
            </div>
            <div class="card-body small">
                <p>O Orthanc suporta dois modos de autenticação:</p>
                <ul>
                    <li><strong>Sem autenticação</strong> — Padrão. Qualquer cliente na rede pode acessar. Recomendado apenas em redes internas seguras.</li>
                    <li><strong>HTTP Basic Auth</strong> — Configure usuário/senha no arquivo <code>orthanc.json</code> do servidor.</li>
                </ul>
                <p class="mb-0">O VOXEL B.I envia as credenciais via cabeçalho <code>Authorization: Basic</code> em cada requisição REST.</p>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h6 class="mb-0 fw-bold"><i class="fa fa-link me-2 text-success"></i>Links Úteis</h6>
            </div>
            <div class="card-body small">
                <ul class="list-unstyled mb-0">
                    <li class="mb-2">
                        <a href="<?= htmlspecialchars($servidor['url'] ?? 'http://46.225.51.122:8042') ?>/app/explorer.html" target="_blank" class="btn btn-sm btn-outline-success w-100">
                            <i class="fa fa-external-link-alt me-1"></i> Abrir Orthanc Explorer
                        </a>
                    </li>
                    <li class="mb-2">
                        <a href="<?= htmlspecialchars($servidor['url'] ?? 'http://46.225.51.122:8042') ?>/system" target="_blank" class="btn btn-sm btn-outline-secondary w-100">
                            <i class="fa fa-code me-1"></i> API /system (JSON)
                        </a>
                    </li>
                    <li>
                        <a href="<?= htmlspecialchars($servidor['url'] ?? 'http://46.225.51.122:8042') ?>/statistics" target="_blank" class="btn btn-sm btn-outline-secondary w-100">
                            <i class="fa fa-chart-bar me-1"></i> API /statistics (JSON)
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <?php if ($servidor): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0 fw-bold"><i class="fa fa-info-circle me-2"></i>Status Atual</h6>
            </div>
            <div class="card-body small">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted">Status:</td><td><span class="badge bg-<?= $servidor['status_ping'] === 'online' ? 'success' : 'secondary' ?>"><?= $servidor['status_ping'] ?></span></td></tr>
                    <tr><td class="text-muted">Versão:</td><td><?= htmlspecialchars($servidor['versao'] ?? '—') ?></td></tr>
                    <tr><td class="text-muted">AETitle:</td><td><code><?= htmlspecialchars($servidor['dicom_aet'] ?? '—') ?></code></td></tr>
                    <tr><td class="text-muted">Porta DICOM:</td><td><?= $servidor['dicom_port'] ?? '4242' ?></td></tr>
                    <tr><td class="text-muted">Último ping:</td><td><?= $servidor['ultimo_ping'] ?? '—' ?></td></tr>
                    <tr><td class="text-muted">Estudos:</td><td><?= number_format($servidor['total_estudos'] ?? 0) ?></td></tr>
                    <tr><td class="text-muted">Disco:</td><td><?= number_format($servidor['disk_size_mb'] ?? 0) ?> MB</td></tr>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleSenha() {
    const input = document.getElementById('senhaInput');
    const icon  = document.getElementById('eyeIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa fa-eye';
    }
}

function testarConexaoForm() {
    const result = document.getElementById('testeResult');
    result.className = 'mt-3 alert alert-info';
    result.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>Testando conexão...';
    result.classList.remove('d-none');

    fetch('/platform/servidor-pacs/testar', {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                result.className = 'mt-3 alert alert-success';
                result.innerHTML = `<i class="fa fa-check-circle me-2"></i><strong>Conexão OK!</strong> ${data.message}`;
            } else {
                result.className = 'mt-3 alert alert-danger';
                result.innerHTML = `<i class="fa fa-times-circle me-2"></i><strong>Falha!</strong> ${data.message}`;
            }
        })
        .catch(() => {
            result.className = 'mt-3 alert alert-danger';
            result.innerHTML = '<i class="fa fa-times-circle me-2"></i>Erro de comunicação.';
        });
}
</script>
