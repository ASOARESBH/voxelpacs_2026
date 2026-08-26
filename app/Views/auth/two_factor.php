<?php $loginLocale = \App\Core\Translator::locale(); ?>
<div class="auth-logo"><img src="/assets/img/logo-voxel-pacs.png" alt="VOXEL PACS"></div>
<div class="auth-title"><i class="fa fa-shield-halved me-2"></i><?= htmlspecialchars(t('auth.2fa.titulo')) ?></div>
<div class="auth-subtitle"><?= htmlspecialchars(t('auth.2fa.subtitulo')) ?></div>
<?php if (!empty($error)): ?><div class="auth-alert danger"><i class="fa fa-circle-exclamation mt-1"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>
<?php if (!empty($success)): ?><div class="auth-alert success"><i class="fa fa-circle-check mt-1"></i><span><?= htmlspecialchars($success) ?></span></div><?php endif; ?>
<form method="POST" action="/login/2fa/verificar" id="twoFactorForm" autocomplete="one-time-code">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <div class="field-group"><label class="field-label" for="twoFactorCode"><?= htmlspecialchars(t('auth.2fa.codigo_label')) ?></label>
        <div class="field-wrap"><i class="fa fa-key field-icon"></i><input id="twoFactorCode" name="code" class="field-input" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus required style="letter-spacing:.35em;text-align:center;font-size:1.25rem;padding-left:2.6rem;" placeholder="••••••"></div>
    </div>
    <button type="submit" class="btn-login"><i class="fa fa-check me-2"></i><?= htmlspecialchars(t('auth.2fa.validar')) ?></button>
</form>
<div style="display:flex;justify-content:space-between;gap:.75rem;margin-top:1rem;">
    <form method="POST" action="/login/2fa/reenviar"><input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><button type="submit" class="btn btn-link p-0" style="font-size:.8rem;"><?= htmlspecialchars(t('auth.2fa.reenviar')) ?></button></form>
    <form method="POST" action="/login/2fa/cancelar"><input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><button type="submit" class="btn btn-link p-0" style="font-size:.8rem;"><?= htmlspecialchars(t('auth.2fa.cancelar')) ?></button></form>
</div>
<form class="auth-language-switcher" method="POST" action="/login/idioma" aria-label="<?= htmlspecialchars(t('auth.login.idioma_aria')) ?>">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <button type="submit" name="locale" value="pt_BR" class="auth-language-switcher__button<?= $loginLocale === 'pt_BR' ? ' is-active' : '' ?>">PT</button><button type="submit" name="locale" value="en" class="auth-language-switcher__button<?= $loginLocale === 'en' ? ' is-active' : '' ?>">EN</button><button type="submit" name="locale" value="es" class="auth-language-switcher__button<?= $loginLocale === 'es' ? ' is-active' : '' ?>">ES</button>
</form>
<div class="auth-footer">&copy; <?= date('Y') ?> <span>VOXEL PACS</span> &mdash; <?= htmlspecialchars(t('auth.login.direitos_reservados')) ?></div>
