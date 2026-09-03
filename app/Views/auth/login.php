<!-- Logo VOXEL PACS -->
<div class="auth-logo">
    <img src="/assets/img/logo-voxel-pacs.png"
         alt="VOXEL PACS — Smart Imaging. Secure Data. Better Care.">
</div>

<?php $loginLocale = \App\Core\Translator::locale(); ?>
<div class="auth-title"><?= htmlspecialchars(t('auth.login.titulo')) ?></div>
<div class="auth-subtitle"><?= htmlspecialchars(t('auth.login.subtitulo')) ?></div>

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
        <label class="field-label" for="inputEmail"><?= htmlspecialchars(t('auth.login.email')) ?></label>

        <div class="field-wrap">
            <i class="fa fa-envelope field-icon"></i>
            <input type="email" id="inputEmail" name="email" class="field-input"
                   autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
        </div>
    </div>

    <div class="field-group">
        <label class="field-label" for="inputSenha"><?= htmlspecialchars(t('auth.login.senha')) ?></label>

        <div class="field-wrap">
            <i class="fa fa-lock field-icon"></i>
            <input type="password" id="inputSenha" name="password" class="field-input"
                   style="padding-right:42px" placeholder="••••••••" required>
            <button type="button" class="btn-eye" id="btnEye" onclick="toggleSenha()" title="<?= htmlspecialchars(t('auth.login.mostrar_senha')) ?>">

                <i class="fa fa-eye" id="iconEye"></i>
            </button>
        </div>
        <div style="text-align:right;margin-top:.4rem;">
            <a href="/esqueci-senha" style="font-size:.78rem;"><?= htmlspecialchars(t('auth.login.esqueceu_senha')) ?></a>

        </div>
    </div>

<button type="submit" class="btn-login" id="btnLogin" data-authenticating="<?= htmlspecialchars(t('auth.login.autenticando')) ?>">
        <i class="fa fa-arrow-right-to-bracket me-2"></i><?= htmlspecialchars(t('auth.login.entrar')) ?>
    </button>
</form>

<form class="auth-language-switcher" method="POST" action="/login/idioma" aria-label="<?= htmlspecialchars(t('auth.login.idioma_aria')) ?>">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <button type="submit" name="locale" value="pt_BR" class="auth-language-switcher__button<?= $loginLocale === 'pt_BR' ? ' is-active' : '' ?>" aria-pressed="<?= $loginLocale === 'pt_BR' ? 'true' : 'false' ?>" title="<?= htmlspecialchars(t('comum.idioma.pt_br')) ?>">PT</button>
    <button type="submit" name="locale" value="en" class="auth-language-switcher__button<?= $loginLocale === 'en' ? ' is-active' : '' ?>" aria-pressed="<?= $loginLocale === 'en' ? 'true' : 'false' ?>" title="<?= htmlspecialchars(t('comum.idioma.en')) ?>">EN</button>
    <button type="submit" name="locale" value="es" class="auth-language-switcher__button<?= $loginLocale === 'es' ? ' is-active' : '' ?>" aria-pressed="<?= $loginLocale === 'es' ? 'true' : 'false' ?>" title="<?= htmlspecialchars(t('comum.idioma.es')) ?>">ES</button>
</form>

<div class="auth-footer">
    &copy; <?= date('Y') ?> <span>VOXEL PACS</span> &mdash; <?= htmlspecialchars(t('auth.login.direitos_reservados')) ?>
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
        btn.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>' + btn.dataset.authenticating;

    btn.disabled = true;
});
</script>
