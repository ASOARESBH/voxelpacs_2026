<?php
/** @var array<string,mixed> $tenant */
/** @var array<int,array<string,mixed>> $destinations */
/** @var array<int,array<string,mixed>> $jobs */
/** @var array<string,int> $stats */
/** @var string $csrfToken */
/** @var array<int,string> $transports */

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$transportLabels = [
    'dicom_pdf' => 'DICOM Encapsulated PDF',
    'dicom_sr' => 'DICOM Structured Report',
    'hl7_oru' => 'HL7 ORU^R01',
    'https_webhook' => 'HTTPS Webhook/API',
    'sftp' => 'SFTP/FTPS',
];
?>

<div class="container-fluid py-4" id="report-delivery-page">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted small mb-1">Integrações clínicas</p>
            <h1 class="h3 mb-1">VOXEL Report Delivery Hub</h1>
            <p class="text-muted mb-0">Devolutiva de laudos do negócio <strong><?= $escape($tenant['nome'] ?? $tenant['razao_social'] ?? ('#' . ($tenant['id'] ?? ''))) ?></strong>.</p>
        </div>
        <a class="btn btn-outline-secondary" href="/platform/negocios/<?= (int) $tenant['id'] ?>/edit">Voltar ao negócio</a>
    </div>

    <div class="alert alert-warning border-warning-subtle" role="alert">
        <strong>Modo seguro:</strong> destinos novos iniciam desativados e em homologação. A configuração não envia laudos por si só; a ativação depende do worker e de homologação técnica por cliente.
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Total de jobs</div><div class="h3 mb-0"><?= (int) ($stats['total'] ?? 0) ?></div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Na fila</div><div class="h3 mb-0 text-primary"><?= (int) ($stats['queued'] ?? 0) ?></div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Entregues</div><div class="h3 mb-0 text-success"><?= (int) ($stats['delivered'] ?? 0) ?></div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Falhas/DLQ</div><div class="h3 mb-0 text-danger"><?= (int) ($stats['failed'] ?? 0) ?></div></div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-xl-5">
            <div class="card shadow-sm">
                <div class="card-header bg-white"><h2 class="h5 mb-0" id="destination-form-title">Novo destino</h2></div>
                <div class="card-body">
                    <div id="delivery-feedback" class="d-none alert" role="alert"></div>
                    <form id="destination-form" method="post" action="/platform/negocios/<?= (int) $tenant['id'] ?>/report-delivery/destinations">
                        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="destination-name">Nome do destino</label>
                            <input class="form-control" id="destination-name" name="nome" maxlength="120" required placeholder="Ex.: PACS Cliente — Homologação">
                        </div>
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label" for="destination-transport">Canal</label>
                                <select class="form-select" id="destination-transport" name="transport" required>
                                    <?php foreach ($transports as $transport): ?>
                                        <option value="<?= $escape($transport) ?>"><?= $escape($transportLabels[$transport] ?? $transport) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="destination-environment">Ambiente</label>
                                <select class="form-select" id="destination-environment" name="ambiente">
                                    <option value="homologacao">Homologação</option>
                                    <option value="producao">Produção</option>
                                </select>
                            </div>
                        </div>
                        <input type="hidden" id="destination-config" name="configuration_json" value="{}">
                        <input type="hidden" id="destination-secret" name="configuration_secret" value="">

                        <div class="alert alert-light border mt-3 mb-0" id="destination-guide" role="status">
                            <i class="fa fa-circle-info me-1"></i> Selecione o canal para preencher somente os dados necessários. As configurações técnicas são geradas automaticamente.
                        </div>

                        <div class="destination-fields mt-3" data-transport-group="dicom_pdf,dicom_sr">
                            <h3 class="h6 border-bottom pb-2"><i class="fa fa-x-ray me-1"></i> Conexão com o PACS do cliente</h3>
                            <div class="row g-3">
                                <div class="col-md-8"><label class="form-label" for="dicom-host">Endereço do PACS</label><input class="form-control" id="dicom-host" data-field="host" data-required placeholder="Ex.: 10.0.0.20 ou pacs.cliente.com.br"><div class="form-text">Informe o IP privado/VPN ou o domínio fornecido pelo cliente.</div></div>
                                <div class="col-md-4"><label class="form-label" for="dicom-port">Porta DICOM</label><input class="form-control" id="dicom-port" data-field="port" type="number" min="1" max="65535" value="104" data-required></div>
                                <div class="col-md-6"><label class="form-label" for="dicom-called-ae">AE Title do PACS cliente</label><input class="form-control text-uppercase" id="dicom-called-ae" data-field="called_ae" maxlength="16" data-required placeholder="Ex.: CLIENTE_PACS"></div>
                                <div class="col-md-6"><label class="form-label" for="dicom-calling-ae">AE Title do VOXEL PACS</label><input class="form-control text-uppercase" id="dicom-calling-ae" data-field="calling_ae" maxlength="16" value="VOXEL_PACS" data-required></div>
                            </div>
                            <div class="form-check mt-3"><input class="form-check-input" id="dicom-tls" data-field="use_tls" type="checkbox"><label class="form-check-label" for="dicom-tls">Usar conexão DICOM TLS, quando disponibilizada pelo cliente</label></div>
                        </div>

                        <div class="destination-fields mt-3 d-none" data-transport-group="hl7_oru">
                            <h3 class="h6 border-bottom pb-2"><i class="fa fa-hospital me-1"></i> Conexão HL7 do HIS/RIS</h3>
                            <div class="row g-3">
                                <div class="col-md-8"><label class="form-label" for="hl7-host">Servidor HL7</label><input class="form-control" id="hl7-host" data-field="host" data-required placeholder="Ex.: hl7.cliente.com.br"></div>
                                <div class="col-md-4"><label class="form-label" for="hl7-port">Porta MLLP</label><input class="form-control" id="hl7-port" data-field="port" type="number" min="1" max="65535" value="2575" data-required></div>
                                <div class="col-md-6"><label class="form-label" for="hl7-sending-app">Aplicação remetente</label><input class="form-control" id="hl7-sending-app" data-field="sending_application" data-required value="VOXEL_PACS"></div>
                                <div class="col-md-6"><label class="form-label" for="hl7-sending-facility">Instituição remetente</label><input class="form-control" id="hl7-sending-facility" data-field="sending_facility" data-required placeholder="Ex.: VOXEL"></div>
                                <div class="col-md-6"><label class="form-label" for="hl7-receiving-app">Aplicação destinatária</label><input class="form-control" id="hl7-receiving-app" data-field="receiving_application" data-required placeholder="Ex.: RIS_CLIENTE"></div>
                                <div class="col-md-6"><label class="form-label" for="hl7-receiving-facility">Instituição destinatária</label><input class="form-control" id="hl7-receiving-facility" data-field="receiving_facility" data-required placeholder="Ex.: HOSPITAL_CLIENTE"></div>
                            </div>
                        </div>

                        <div class="destination-fields mt-3 d-none" data-transport-group="https_webhook">
                            <h3 class="h6 border-bottom pb-2"><i class="fa fa-link me-1"></i> Endpoint HTTPS do cliente</h3>
                            <div class="row g-3">
                                <div class="col-12"><label class="form-label" for="https-url">URL de recebimento</label><input class="form-control" id="https-url" data-field="url" type="url" data-required placeholder="https://integracao.cliente.com.br/laudos"><div class="form-text">Somente endereços HTTPS são aceitos.</div></div>
                                <div class="col-md-6"><label class="form-label" for="https-auth">Autenticação</label><select class="form-select" id="https-auth" data-field="auth_type"><option value="none">Sem autenticação</option><option value="bearer">Token Bearer</option></select></div>
                                <div class="col-md-6"><label class="form-label" for="https-token">Token Bearer</label><div class="input-group"><input class="form-control" id="https-token" data-secret-field="bearer_token" type="password" autocomplete="new-password" placeholder="Informe apenas se fornecido pelo cliente"><button class="btn btn-outline-secondary toggle-secret" type="button" data-target="https-token" aria-label="Mostrar ou ocultar token"><i class="fa fa-eye"></i></button></div><div class="form-text">O token é cifrado e não é exibido novamente após salvar.</div></div>
                            </div>
                        </div>

                        <div class="destination-fields mt-3 d-none" data-transport-group="sftp">
                            <h3 class="h6 border-bottom pb-2"><i class="fa fa-folder-open me-1"></i> Pasta segura do cliente</h3>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label" for="sftp-protocol">Protocolo</label><select class="form-select" id="sftp-protocol" data-field="protocol"><option value="sftp">SFTP (recomendado)</option><option value="ftps">FTPS</option></select></div>
                                <div class="col-md-5"><label class="form-label" for="sftp-host">Servidor</label><input class="form-control" id="sftp-host" data-field="host" data-required placeholder="sftp.cliente.com.br"></div>
                                <div class="col-md-3"><label class="form-label" for="sftp-port">Porta</label><input class="form-control" id="sftp-port" data-field="port" type="number" min="1" max="65535" value="22" data-required></div>
                                <div class="col-md-7"><label class="form-label" for="sftp-directory">Pasta de entrega</label><input class="form-control" id="sftp-directory" data-field="remote_directory" data-required placeholder="Ex.: /entrada/laudos"></div>
                                <div class="col-md-5"><label class="form-label" for="sftp-username">Usuário</label><input class="form-control" id="sftp-username" data-field="username" data-required autocomplete="off"></div>
                                <div class="col-12"><label class="form-label" for="sftp-password">Senha ou chave privada</label><div class="input-group"><input class="form-control" id="sftp-password" data-secret-field="password" type="password" autocomplete="new-password" placeholder="Informe somente a credencial fornecida pelo cliente"><button class="btn btn-outline-secondary toggle-secret" type="button" data-target="sftp-password" aria-label="Mostrar ou ocultar senha"><i class="fa fa-eye"></i></button></div><div class="form-text">A credencial é cifrada. O conteúdo nunca é mostrado novamente.</div></div>
                            </div>
                        </div>
                        <div class="row g-3 mt-0">
                            <div class="col-md-6"><label class="form-label" for="destination-timeout">Timeout (segundos)</label><input class="form-control" id="destination-timeout" type="number" name="timeout_seconds" min="5" max="120" value="30"></div>
                            <div class="col-md-6"><label class="form-label" for="destination-attempts">Tentativas máximas</label><input class="form-control" id="destination-attempts" type="number" name="max_attempts" min="1" max="10" value="5"></div>
                        </div>
                        <div class="form-check mt-3"><input class="form-check-input" id="destination-release" type="checkbox" name="disparar_na_liberacao" value="1" checked><label class="form-check-label" for="destination-release">Criar job quando o laudo for liberado</label></div>
                        <div class="form-check mt-2"><input class="form-check-input" id="destination-enabled" type="checkbox" name="enabled" value="1"><label class="form-check-label" for="destination-enabled">Habilitar destino de homologação</label></div>
                        <div class="d-flex gap-2 mt-4"><button type="submit" class="btn btn-primary">Salvar destino</button><button type="button" class="btn btn-outline-secondary d-none" id="destination-cancel">Cancelar edição</button></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Destinos configurados</h2><span class="badge text-bg-secondary"><?= count($destinations) ?></span></div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Nome</th><th>Canal</th><th>Ambiente</th><th>Status</th><th class="text-end">Ação</th></tr></thead>
                        <tbody>
                            <?php if (!$destinations): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">Nenhum destino cadastrado para este negócio.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($destinations as $destination): ?>
                                <?php $json = htmlspecialchars(json_encode($destination, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>
                                <tr>
                                    <td><strong><?= $escape($destination['nome']) ?></strong><div class="small text-muted">Timeout: <?= (int) $destination['timeout_seconds'] ?>s · <?= (int) $destination['max_attempts'] ?> tentativas</div></td>
                                    <td><?= $escape($transportLabels[$destination['transport']] ?? $destination['transport']) ?></td>
                                    <td><span class="badge <?= $destination['ambiente'] === 'producao' ? 'text-bg-dark' : 'text-bg-info' ?>"><?= $escape($destination['ambiente']) ?></span></td>
                                    <td><?= !empty($destination['enabled']) ? '<span class="badge text-bg-success">Habilitado</span>' : '<span class="badge text-bg-secondary">Desativado</span>' ?></td>
                                    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary edit-destination" data-destination="<?= $json ?>">Editar</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-white"><h2 class="h5 mb-0">Últimas entregas</h2></div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>Laudo</th><th>Destino</th><th>Status</th><th>Tentativas</th><th>Detalhe</th></tr></thead>
                        <tbody>
                            <?php if (!$jobs): ?><tr><td colspan="5" class="text-center text-muted py-4">Ainda não existem jobs de entrega.</td></tr><?php endif; ?>
                            <?php foreach ($jobs as $job): ?>
                                <tr>
                                    <td>#<?= (int) $job['report_id'] ?> · v<?= (int) $job['report_version'] ?></td>
                                    <td><?= $escape($job['destination_name']) ?></td>
                                    <td><span class="badge text-bg-<?= $job['status'] === 'delivered' ? 'success' : ($job['status'] === 'queued' ? 'primary' : ($job['status'] === 'processing' ? 'warning' : 'danger')) ?>"><?= $escape($job['status']) ?></span></td>
                                    <td><?= (int) $job['attempt_count'] ?></td>
                                    <td class="small text-muted"><?= $escape($job['remote_reference'] ?: $job['last_error'] ?: '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const form = document.getElementById('destination-form');
    const title = document.getElementById('destination-form-title');
    const cancel = document.getElementById('destination-cancel');
    const feedback = document.getElementById('delivery-feedback');
    const transport = document.getElementById('destination-transport');
    const environment = document.getElementById('destination-environment');
    const enabled = document.getElementById('destination-enabled');
    const guide = document.getElementById('destination-guide');
    const configInput = document.getElementById('destination-config');
    const secretInput = document.getElementById('destination-secret');
    const baseAction = form.action;
    const knownKeys = ['host', 'port', 'called_ae', 'calling_ae', 'use_tls', 'sending_application', 'sending_facility', 'receiving_application', 'receiving_facility', 'url', 'auth_type', 'protocol', 'remote_directory', 'username'];
    const guideText = {
        dicom_pdf: 'Informe os dados de rede e os AE Titles fornecidos pelo administrador do PACS do cliente.',
        dicom_sr: 'Informe os dados de rede e os AE Titles fornecidos pelo administrador do PACS do cliente.',
        hl7_oru: 'Informe os dados da interface HL7/MLLP fornecidos pelo HIS ou RIS do cliente.',
        https_webhook: 'Informe a URL HTTPS fornecida pela equipe de integração do cliente.',
        sftp: 'Informe a pasta segura e as credenciais fornecidas pelo cliente. FTP simples não é aceito.',
    };
    let currentConfig = {};

    function parseConfig(raw) {
        try {
            const parsed = JSON.parse(raw || '{}');
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (_) {
            return {};
        }
    }

    function isActiveGroup(group) {
        return group.dataset.transportGroup.split(',').includes(transport.value);
    }

    function renderTransportFields() {
        document.querySelectorAll('.destination-fields').forEach((group) => {
            const active = isActiveGroup(group);
            group.classList.toggle('d-none', !active);
            group.querySelectorAll('[data-required]').forEach((input) => { input.required = active; });
        });
        guide.innerHTML = '<i class="fa fa-circle-info me-1"></i>' + (guideText[transport.value] || 'Preencha os dados fornecidos pelo cliente.');
        populateActiveFields();
    }

    function populateActiveFields() {
        document.querySelectorAll('.destination-fields').forEach((group) => {
            if (!isActiveGroup(group)) return;
            group.querySelectorAll('[data-field]').forEach((input) => {
                const value = currentConfig[input.dataset.field];
                if (value === undefined || value === null) return;
                if (input.type === 'checkbox') input.checked = Boolean(value);
                else input.value = value;
            });
        });
    }

    function syncEnvironment() {
        const production = environment.value === 'producao';
        if (production) enabled.checked = false;
        enabled.disabled = production;
        enabled.parentElement.classList.toggle('text-muted', production);
    }

    function serializeConfiguration() {
        const config = { ...currentConfig };
        knownKeys.forEach((key) => delete config[key]);
        const secret = {};
        document.querySelectorAll('.destination-fields').forEach((group) => {
            if (!isActiveGroup(group)) return;
            group.querySelectorAll('[data-field]').forEach((input) => {
                const key = input.dataset.field;
                if (input.type === 'checkbox') config[key] = input.checked;
                else if (input.type === 'number') config[key] = Number(input.value);
                else if (input.value.trim() !== '') config[key] = input.value.trim();
            });
            group.querySelectorAll('[data-secret-field]').forEach((input) => {
                if (input.value.trim() !== '') secret[input.dataset.secretField] = input.value;
            });
        });
        configInput.value = JSON.stringify(config);
        secretInput.value = Object.keys(secret).length ? JSON.stringify(secret) : '';
    }

    function resetForm() {
        form.reset();
        currentConfig = {};
        form.action = baseAction;
        configInput.value = '{}';
        secretInput.value = '';
        title.textContent = 'Novo destino';
        cancel.classList.add('d-none');
        renderTransportFields();
        syncEnvironment();
    }

    transport.addEventListener('change', () => { currentConfig = {}; renderTransportFields(); });
    environment.addEventListener('change', syncEnvironment);
    document.querySelectorAll('.toggle-secret').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.target);
            input.type = input.type === 'password' ? 'text' : 'password';
            button.querySelector('i').className = input.type === 'password' ? 'fa fa-eye' : 'fa fa-eye-slash';
        });
    });

    document.querySelectorAll('.edit-destination').forEach((button) => {
        button.addEventListener('click', () => {
            const item = JSON.parse(button.dataset.destination);
            currentConfig = parseConfig(item.configuration_json);
            form.action = baseAction + '/' + encodeURIComponent(item.id);
            title.textContent = 'Editar destino: ' + item.nome;
            document.getElementById('destination-name').value = item.nome;
            transport.value = item.transport;
            environment.value = item.ambiente;
            secretInput.value = '';
            document.querySelectorAll('[data-secret-field]').forEach((input) => { input.value = ''; });
            document.getElementById('destination-timeout').value = item.timeout_seconds;
            document.getElementById('destination-attempts').value = item.max_attempts;
            document.getElementById('destination-release').checked = Number(item.disparar_na_liberacao) === 1;
            enabled.checked = Number(item.enabled) === 1;
            cancel.classList.remove('d-none');
            renderTransportFields();
            syncEnvironment();
            window.scrollTo({ top: form.getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth' });
        });
    });
    cancel.addEventListener('click', resetForm);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        serializeConfiguration();
        const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
        const result = await response.json().catch(() => ({ success: false, message: 'Resposta inválida do servidor.' }));
        feedback.className = 'alert ' + (result.success ? 'alert-success' : 'alert-danger');
        feedback.textContent = result.message || 'Operação concluída.';
        feedback.classList.remove('d-none');
        if (result.success) window.setTimeout(() => window.location.reload(), 700);
    });

    renderTransportFields();
    syncEnvironment();
})();
</script>
