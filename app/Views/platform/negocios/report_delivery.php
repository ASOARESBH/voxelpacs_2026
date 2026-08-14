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
                        <div class="mt-3">
                            <label class="form-label" for="destination-config">Configuração pública (JSON)</label>
                            <textarea class="form-control font-monospace small" id="destination-config" name="configuration_json" rows="6" required>{}</textarea>
                            <div class="form-text">Exemplo DICOM: <code>{"host":"10.0.0.20","port":104,"called_ae":"CLIENTE_PACS","calling_ae":"VOXEL_PACS"}</code>. Não informe senhas aqui.</div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label" for="destination-secret">Configuração sensível (JSON)</label>
                            <textarea class="form-control font-monospace small" id="destination-secret" name="configuration_secret" rows="3" placeholder='{"password":"..."}'></textarea>
                            <div class="form-text">Opcional. O valor é cifrado com a chave do ambiente e não volta a ser exibido.</div>
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
    const baseAction = form.action;

    function resetForm() {
        form.reset();
        form.action = baseAction;
        document.getElementById('destination-config').value = '{}';
        title.textContent = 'Novo destino';
        cancel.classList.add('d-none');
    }

    document.querySelectorAll('.edit-destination').forEach((button) => {
        button.addEventListener('click', () => {
            const item = JSON.parse(button.dataset.destination);
            form.action = baseAction + '/' + encodeURIComponent(item.id);
            title.textContent = 'Editar destino: ' + item.nome;
            document.getElementById('destination-name').value = item.nome;
            document.getElementById('destination-transport').value = item.transport;
            document.getElementById('destination-environment').value = item.ambiente;
            document.getElementById('destination-config').value = item.configuration_json || '{}';
            document.getElementById('destination-secret').value = '';
            document.getElementById('destination-timeout').value = item.timeout_seconds;
            document.getElementById('destination-attempts').value = item.max_attempts;
            document.getElementById('destination-release').checked = Number(item.disparar_na_liberacao) === 1;
            document.getElementById('destination-enabled').checked = Number(item.enabled) === 1;
            cancel.classList.remove('d-none');
            window.scrollTo({ top: form.getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth' });
        });
    });
    cancel.addEventListener('click', resetForm);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
        const result = await response.json().catch(() => ({ success: false, message: 'Resposta inválida do servidor.' }));
        feedback.className = 'alert ' + (result.success ? 'alert-success' : 'alert-danger');
        feedback.textContent = result.message || 'Operação concluída.';
        feedback.classList.remove('d-none');
        if (result.success) window.setTimeout(() => window.location.reload(), 700);
    });
})();
</script>
