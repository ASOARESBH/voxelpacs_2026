<?php
// View: Servidor PACS — Configuração da conexão Orthanc
$cronBaseUrl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$cronToken   = $servidor['sync_cron_token'] ?? '';
$cronUrl     = $cronBaseUrl . '/api/servidor-pacs/cron-ping' . ($cronToken ? ('?token=' . $cronToken) : '');
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

        <div class="card border-0 shadow-sm mt-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fa fa-clock me-2"></i>Sincronização Automática (Ping Agendado)</h6>
                <span class="badge bg-<?= !empty($servidor['sync_auto_ativo']) ? 'success' : 'secondary' ?>">
                    <?= !empty($servidor['sync_auto_ativo']) ? 'Ativo' : 'Inativo' ?>
                </span>
            </div>
            <div class="card-body">
                <div class="alert alert-info small">
                    <i class="fa fa-info-circle me-2"></i>
                    Defina de quanto em quanto tempo o VOXEL PACS deve verificar (ping) se o servidor Orthanc está
                    disponível. Um serviço externo gratuito, o <a href="https://cron-job.org" target="_blank" rel="noopener">cron-job.org</a>,
                    é responsável por chamar a URL abaixo no intervalo configurado.
                </div>

                <div class="row mb-3">
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Intervalo de ping</label>
                        <div class="input-group">
                            <input type="number" name="sync_intervalo_minutos" form="formConfig" id="syncIntervalo"
                                   class="form-control" min="1" max="1440"
                                   value="<?= (int)($servidor['sync_intervalo_minutos'] ?? 60) ?>">
                            <span class="input-group-text">minutos</span>
                        </div>
                        <small class="text-muted">De 1 minuto até 1440 minutos (24 horas)</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Atalhos</label>
                        <select class="form-select" onchange="if(this.value) document.getElementById('syncIntervalo').value = this.value;">
                            <option value="">Selecione…</option>
                            <option value="1">1 minuto</option>
                            <option value="5">5 minutos</option>
                            <option value="15">15 minutos</option>
                            <option value="30">30 minutos</option>
                            <option value="60">1 hora</option>
                            <option value="360">6 horas</option>
                            <option value="720">12 horas</option>
                            <option value="1440">24 horas</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   name="sync_auto_ativo" form="formConfig" id="syncAtivo" value="1"
                                   <?= !empty($servidor['sync_auto_ativo']) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="syncAtivo">Ativar</label>
                        </div>
                    </div>
                </div>
                <small class="text-muted d-block mb-3">
                    <i class="fa fa-arrow-up me-1"></i>O intervalo e a ativação são salvos junto com o botão
                    <strong>Salvar Configurações</strong> do formulário acima.
                </small>

                <label class="form-label fw-semibold">URL para o cron-job.org</label>
                <div class="input-group mb-2">
                    <input type="text" id="cronUrlInput" class="form-control form-control-sm" readonly
                           value="<?= htmlspecialchars($cronUrl) ?>"
                           placeholder="Gere um token para obter a URL">
                    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="copiarCronUrl()">
                        <i class="fa fa-copy"></i>
                    </button>
                    <button class="btn btn-outline-primary btn-sm" type="button" onclick="gerarTokenCron()">
                        <i class="fa fa-key me-1"></i> Gerar Token
                    </button>
                </div>
                <div id="cronTokenResult" class="small mb-2"></div>

                <details class="small">
                    <summary class="text-primary" style="cursor:pointer;">Como configurar no cron-job.org?</summary>
                    <ol class="mt-2 mb-0 ps-3">
                        <li>Clique em <strong>Gerar Token</strong> e depois em <strong>Salvar Configurações</strong>.</li>
                        <li>Crie uma conta gratuita em <a href="https://cron-job.org" target="_blank" rel="noopener">cron-job.org</a>.</li>
                        <li>Clique em <strong>Create cronjob</strong>, cole a URL gerada acima em "URL" e defina o método <strong>GET</strong>.</li>
                        <li>Em "Execution schedule", configure o mesmo intervalo definido acima (ex: a cada 5 minutos).</li>
                        <li>Salve. O cron-job.org passará a chamar o VOXEL PACS automaticamente e cada execução aparecerá no histórico ao lado.</li>
                    </ol>
                </details>
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

        <div class="card border-0 shadow-sm mt-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fa fa-list-check me-2"></i>Histórico de Execuções</h6>
                <button class="btn btn-sm btn-outline-secondary" type="button" onclick="atualizarHistoricoCron()">
                    <i class="fa fa-rotate"></i>
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:320px;">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Data/Hora</th>
                                <th>Origem</th>
                                <th>Resultado</th>
                                <th>Tempo</th>
                            </tr>
                        </thead>
                        <tbody id="cronExecucoesBody">
                            <?php if (empty($execucoes)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">Nenhuma execução registrada ainda.</td></tr>
                            <?php else: foreach ($execucoes as $exec): ?>
                                <tr>
                                    <td class="small"><?= htmlspecialchars($exec['executado_em']) ?></td>
                                    <td class="small"><?= htmlspecialchars($exec['origem']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $exec['sucesso'] ? 'success' : 'danger' ?>">
                                            <?= $exec['sucesso'] ? 'Sucesso' : 'Falha' ?>
                                        </span>
                                    </td>
                                    <td class="small"><?= (int)$exec['tempo_resposta_ms'] ?> ms</td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
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

function gerarTokenCron() {
    const result = document.getElementById('cronTokenResult');
    result.className = 'small mb-2 text-muted';
    result.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>Gerando token...';

    fetch('/platform/servidor-pacs/cron/gerar-token', {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const base = window.location.origin + '/api/servidor-pacs/cron-ping?token=' + data.token;
                document.getElementById('cronUrlInput').value = base;
                result.className = 'small mb-2 text-success';
                result.innerHTML = '<i class="fa fa-check-circle me-1"></i>Novo token gerado! Atualize a URL cadastrada no cron-job.org.';
            } else {
                result.className = 'small mb-2 text-danger';
                result.innerHTML = '<i class="fa fa-times-circle me-1"></i>' + (data.message || 'Erro ao gerar token.');
            }
        })
        .catch(() => {
            result.className = 'small mb-2 text-danger';
            result.innerHTML = '<i class="fa fa-times-circle me-1"></i>Erro de comunicação.';
        });
}

function copiarCronUrl() {
    const input = document.getElementById('cronUrlInput');
    if (!input.value) return;
    input.select();
    navigator.clipboard?.writeText(input.value).catch(() => document.execCommand('copy'));
}

function atualizarHistoricoCron() {
    const tbody = document.getElementById('cronExecucoesBody');

    fetch('/platform/servidor-pacs/cron/execucoes', {headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            if (!data.execucoes.length) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Nenhuma execução registrada ainda.</td></tr>';
                return;
            }
            tbody.innerHTML = data.execucoes.map(exec => `
                <tr>
                    <td class="small">${exec.executado_em}</td>
                    <td class="small">${exec.origem}</td>
                    <td><span class="badge bg-${exec.sucesso == 1 ? 'success' : 'danger'}">${exec.sucesso == 1 ? 'Sucesso' : 'Falha'}</span></td>
                    <td class="small">${exec.tempo_resposta_ms ?? 0} ms</td>
                </tr>
            `).join('');
        })
        .catch(() => {});
}
</script>
