<?php
$config = $config ?? [];
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fa-brands fa-telegram text-primary me-2"></i>Conector Telegram</h1>
            <p class="text-muted mb-0">Integração global com Telegram Bot API para alertas administrativos de laudos.</p>
        </div>
        <a class="btn btn-outline-secondary" href="/platform/conectores"><i class="fa fa-arrow-left me-1"></i>Conectores</a>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><i class="fa fa-circle-check me-2"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><i class="fa fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <form method="post" action="/platform/conectores/telegram/salvar" class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) $csrfToken) ?>">
                    <div class="form-check form-switch mb-4"><input class="form-check-input" type="checkbox" role="switch" id="ativo" name="ativo" value="1" <?= !empty($config['ativo']) ? 'checked' : '' ?>><label class="form-check-label fw-semibold" for="ativo">Ativar notificações Telegram</label></div>
                    <div class="mb-3"><label class="form-label" for="telegram_bot_token">Bot Token</label><div class="input-group"><input class="form-control" id="telegram_bot_token" name="telegram_bot_token" type="password" autocomplete="new-password" placeholder="<?= !empty($config['tem_telegram_bot_token']) ? 'Token já cadastrado — deixe em branco para preservar' : '123456:ABC...' ?>"><button class="btn btn-outline-secondary toggle-secret" type="button" data-target="telegram_bot_token"><i class="fa fa-eye"></i></button></div><div class="form-text">O token é cifrado antes de ser armazenado e nunca volta ao navegador.</div></div>
                    <div class="mb-4"><label class="form-label" for="telegram_chat_id">Chat ID administrativo</label><input class="form-control" id="telegram_chat_id" name="telegram_chat_id" inputmode="numeric" maxlength="20" placeholder="Ex.: 123456789 ou -1001234567890" value="<?= htmlspecialchars((string) ($config['telegram_chat_id'] ?? '')) ?>"><div class="form-text">Chats privados usam número positivo; grupos podem iniciar com sinal negativo.</div></div>
                    <div class="d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit"><i class="fa fa-floppy-disk me-1"></i>Salvar configuração</button><button class="btn btn-outline-primary" type="button" id="testarTelegram"><i class="fa fa-plug-circle-check me-1"></i>Testar conexão</button></div>
                    <div id="testResult" class="mt-3" aria-live="polite"></div>
                </div>
            </form>
        </div>
        <div class="col-lg-4"><div class="card border-0 bg-light"><div class="card-body"><h2 class="h6"><i class="fa fa-circle-info me-1"></i>Dados necessários</h2><p class="small mb-2">Crie o bot no <strong>@BotFather</strong>, copie o token e adicione o bot ao chat administrativo desejado.</p><p class="small mb-0">O teste usa <code>getMe</code> para validar somente o token. O envio real exige também um Chat ID válido.</p></div></div></div>
    </div>
</div>
<script>
(() => {
    document.querySelectorAll('.toggle-secret').forEach((button) => button.addEventListener('click', () => { const field = document.getElementById(button.dataset.target); field.type = field.type === 'password' ? 'text' : 'password'; button.querySelector('i').className = field.type === 'password' ? 'fa fa-eye' : 'fa fa-eye-slash'; }));
    document.getElementById('testarTelegram').addEventListener('click', async () => {
        const target = document.getElementById('testResult');
        target.innerHTML = '<span class="text-muted"><i class="fa fa-spinner fa-spin me-1"></i>Testando conexão…</span>';
        try {
            const response = await fetch('/platform/conectores/telegram/testar', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: new URLSearchParams({_csrf_token: '<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES) ?>'})});
            const data = await response.json();
            target.innerHTML = `<div class="alert ${data.ok ? 'alert-success' : 'alert-danger'} mb-0">${data.ok ? '<i class="fa fa-circle-check me-1"></i>' : '<i class="fa fa-triangle-exclamation me-1"></i>'}${String(data.message || 'Sem resposta')}</div>`;
        } catch (_) { target.innerHTML = '<div class="alert alert-danger mb-0">Não foi possível concluir o teste.</div>'; }
    });
})();
</script>
