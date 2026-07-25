<!-- Logo VOXEL PACS -->
<div class="auth-logo">
    <img src="/assets/img/logo-voxel-pacs.png"
         alt="VOXEL PACS — Smart Imaging. Secure Data. Better Care.">
</div>

<div class="auth-title">Esqueci minha senha</div>
<div class="auth-subtitle">Informe seu e-mail de acesso para receber um link de redefinição</div>

<?php if (!empty($sucesso)): ?>

    <div class="auth-alert" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534;">
        <i class="fa fa-circle-check mt-1"></i>
        <span><?= htmlspecialchars($sucesso) ?></span>
    </div>

    <div class="auth-footer" style="margin-top:1.25rem;">
        <a href="/login"><i class="fa fa-arrow-left me-1"></i> Voltar para o login</a>
    </div>

<?php else: ?>

    <form method="POST" action="/esqueci-senha" autocomplete="on" id="formEsqueciSenha">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <div class="field-group">
            <label class="field-label" for="inputEmailReset">E-mail</label>
            <div class="field-wrap">
                <i class="fa fa-envelope field-icon"></i>
                <input type="email" id="inputEmailReset" name="email" class="field-input"
                       placeholder="voce@clinica.com.br" required autofocus>
            </div>
        </div>

        <button type="submit" class="btn-login" id="btnEnviarReset">
            <i class="fa fa-paper-plane me-2"></i>Enviar link de redefinição
        </button>
    </form>

    <div class="auth-footer" style="margin-top:1.25rem;">
        <a href="/login"><i class="fa fa-arrow-left me-1"></i> Voltar para o login</a>
    </div>

    <script>
    document.getElementById('formEsqueciSenha').addEventListener('submit', function() {
        const btn = document.getElementById('btnEnviarReset');
        btn.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>Enviando…';
        btn.disabled = true;
    });
    </script>

<?php endif; ?>
