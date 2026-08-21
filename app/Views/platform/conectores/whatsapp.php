<?php
$config = $config ?? [];
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fa-brands fa-whatsapp text-success me-2"></i>Conector WhatsApp</h1>
            <p class="text-muted mb-0">Integração global com Evolution API para alertas administrativos de laudos.</p>
        </div>
        <a class="btn btn-outline-secondary" href="/platform/conectores"><i class="fa fa-arrow-left me-1"></i>Conectores</a>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><i class="fa fa-circle-check me-2"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><i class="fa fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <form method="post" action="/platform/conectores/whatsapp/salvar" class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) $csrfToken) ?>">
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" role="switch" id="ativo" name="ativo" value="1" <?= !empty($config['ativo']) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="ativo">Ativar notificações WhatsApp</label>
                    </div>
                    <div class="mb-3"><label class="form-label" for="evolution_api_url">URL da Evolution API</label><input class="form-control" id="evolution_api_url" name="evolution_api_url" type="url" maxlength="500" placeholder="https://evolution.exemplo.com" value="<?= htmlspecialchars((string) ($config['evolution_api_url'] ?? '')) ?>" required><div class="form-text">Informe a URL base sem o endpoint da mensagem.</div></div>
                    <div class="mb-3"><label class="form-label" for="evolution_instance">Nome da instância</label><input class="form-control" id="evolution_instance" name="evolution_instance" maxlength="120" placeholder="voxel-alertas" value="<?= htmlspecialchars((string) ($config['evolution_instance'] ?? '')) ?>" required></div>
                    <div class="mb-3"><label class="form-label" for="evolution_api_key">API Key</label><div class="input-group"><input class="form-control" id="evolution_api_key" name="evolution_api_key" type="password" autocomplete="new-password" placeholder="<?= !empty($config['tem_evolution_api_key']) ? 'Chave já cadastrada — deixe em branco para preservar' : 'Informe a API Key' ?>"><button class="btn btn-outline-secondary toggle-secret" type="button" data-target="evolution_api_key"><i class="fa fa-eye"></i></button></div><div class="form-text">A chave é cifrada antes de ser armazenada e nunca volta ao navegador.</div></div>
                    <div class="mb-4"><label class="form-label" for="whatsapp_destino">Número administrativo de destino</label><input class="form-control" id="whatsapp_destino" name="whatsapp_destino" inputmode="numeric" maxlength="20" placeholder="5511999999999" value="<?= htmlspecialchars((string) ($config['whatsapp_destino'] ?? '')) ?>"><div class="form-text">Use DDI + DDD + número, somente dígitos.</div></div>
                    <div class="d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit"><i class="fa fa-floppy-disk me-1"></i>Salvar configuração</button><button class="btn btn-outline-success" type="button" id="testarWhatsapp"><i class="fa fa-plug-circle-check me-1"></i>Testar conexão</button></div>
                    <div id="testResult" class="mt-3" aria-live="polite"></div>
                </div>
            </form>
        </div>
        <div class="col-lg-4"><div class="card border-0 bg-light"><div class="card-body"><h2 class="h6"><i class="fa fa-circle-info me-1"></i>Dados necessários</h2><p class="small mb-2">Na Evolution API, localize a URL do servidor, a API Key e o nome da instância conectada ao WhatsApp administrativo.</p><p class="small mb-0">O teste consulta o estado da instância; notificações só são enviadas quando estiver ativa e em estado <code>open</code>.</p></div></div></div>
    </div>
</div>
<script>
(() => {
    document.querySelectorAll('.toggle-secret').forEach((button) => button.addEventListener('click', () => {
        const field = document.getElementById(button.dataset.target);
        field.type = field.type === 'password' ? 'text' : 'password';
        button.querySelector('i').className = field.type === 'password' ? 'fa fa-eye' : 'fa fa-eye-slash';
    }));
    document.getElementById('testarWhatsapp').addEventListener('click', async () => {
        const target = document.getElementById('testResult');
        target.innerHTML = '<span class="text-muted"><i class="fa fa-spinner fa-spin me-1"></i>Testando conexão…</span>';
        try {
            const response = await fetch('/platform/conectores/whatsapp/testar', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: new URLSearchParams({_csrf_token: '<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES) ?>'})});
            const data = await response.json();
            target.innerHTML = `<div class="alert ${data.ok ? 'alert-success' : 'alert-danger'} mb-0">${data.ok ? '<i class="fa fa-circle-check me-1"></i>' : '<i class="fa fa-triangle-exclamation me-1"></i>'}${String(data.message || 'Sem resposta')}</div>`;
        } catch (_) { target.innerHTML = '<div class="alert alert-danger mb-0">Não foi possível concluir o teste.</div>'; }
    });
})();
</script>
