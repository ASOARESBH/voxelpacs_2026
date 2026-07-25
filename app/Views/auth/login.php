<!-- Logo VOXEL PACS -->
<div class="auth-logo">
    <img src="/assets/img/logo-voxel-pacs.png"
         alt="VOXEL PACS — Smart Imaging. Secure Data. Better Care.">
</div>

<div class="auth-title">Acesse sua conta</div>
<div class="auth-subtitle">Entre com suas credenciais para continuar</div>

<!-- Alertas dinâmicos -->
<?php if (!empty($error)): ?>
    <div class="auth-alert danger">
        <i class="fa fa-circle-exclamation mt-1"></i>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($_GET['error'])): ?>
    <?php if ($_GET['error'] === 'sessao_expirada'): ?>
        <div class="auth-alert warning">
            <i class="fa fa-clock mt-1"></i>
            <span>Sua sessão expirou. Faça login novamente.</span>
        </div>
    <?php elseif ($_GET['error'] === 'sem_acesso'): ?>
        <div class="auth-alert danger">
            <i class="fa fa-ban mt-1"></i>
            <span>Seu usuário não possui acesso a nenhuma empresa ativa.</span>
        </div>
    <?php elseif ($_GET['error'] === 'tenant_inativo'): ?>
        <div class="auth-alert warning">
            <i class="fa fa-pause-circle mt-1"></i>
            <span>A empresa selecionada está inativa. Contate o suporte.</span>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Formulário -->
<form method="POST" action="/login" autocomplete="on" id="loginForm">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

    <div class="field-group">
        <label class="field-label" for="inputEmail">E-mail</label>
        <div class="field-wrap">
            <i class="fa fa-envelope field-icon"></i>
            <input type="email" id="inputEmail" name="email" class="field-input"
                   placeholder="admin@voxelpacs.com.br" required autofocus
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
    </div>

    <div class="field-group">
        <label class="field-label" for="inputSenha">Senha</label>
        <div class="field-wrap">
            <i class="fa fa-lock field-icon"></i>
            <input type="password" id="inputSenha" name="password" class="field-input"
                   style="padding-right:42px" placeholder="••••••••" required>
            <button type="button" class="btn-eye" id="btnEye" onclick="toggleSenha()" title="Mostrar senha">
                <i class="fa fa-eye" id="iconEye"></i>
            </button>
        </div>
        <div style="text-align:right;margin-top:.4rem;">
            <a href="/esqueci-senha" style="font-size:.78rem;">Esqueceu a senha?</a>
        </div>
    </div>

    <button type="submit" class="btn-login" id="btnLogin">
        <i class="fa fa-arrow-right-to-bracket me-2"></i>Entrar no sistema
    </button>
</form>

<div class="auth-footer">
    &copy; <?= date('Y') ?> <span>VOXEL PACS</span> &mdash; Todos os direitos reservados
</div>

<script>
function toggleSenha() {
    const input = document.getElementById('inputSenha');
    const icon  = document.getElementById('iconEye');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// Feedback visual no submit
document.getElementById('loginForm').addEventListener('submit', function() {
    const btn = document.getElementById('btnLogin');
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>Autenticando…';
    btn.disabled = true;
});
</script>
