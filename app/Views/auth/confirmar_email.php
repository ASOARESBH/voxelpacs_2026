<?php
$state = (string) ($state ?? 'invalid_token');
$messages = [
    'ready' => ['auth.email_change.ready_title', 'auth.email_change.ready_message', true],
    'confirmed' => ['auth.email_change.confirmed_title', 'auth.email_change.confirmed_message', false],
    'confirmed_notice_failed' => ['auth.email_change.confirmed_notice_failed_title', 'auth.email_change.confirmed_notice_failed_message', false],
    'csrf_error' => ['auth.email_change.error_title', 'auth.email_change.csrf_error', false],
    'used_token' => ['auth.email_change.error_title', 'auth.email_change.used_token', false],
    'expired_token' => ['auth.email_change.error_title', 'auth.email_change.expired_token', false],
    'stale_token' => ['auth.email_change.error_title', 'auth.email_change.stale_token', false],
    'email_in_use' => ['auth.email_change.error_title', 'auth.email_change.email_in_use', false],
    'invalid_token' => ['auth.email_change.error_title', 'auth.email_change.invalid_token', false],
    'internal' => ['auth.email_change.error_title', 'auth.email_change.internal', false],
];
[$titleKey, $messageKey, $ready] = $messages[$state] ?? $messages['invalid_token'];
?>
<div class="auth-logo">
    <img src="/assets/img/logo-voxel-pacs.png" alt="VOXEL PACS — Smart Imaging. Secure Data. Better Care.">
</div>
<div class="auth-title"><?= htmlspecialchars(t($titleKey), ENT_QUOTES, 'UTF-8') ?></div>
<div class="auth-subtitle"><?= htmlspecialchars(t($messageKey), ENT_QUOTES, 'UTF-8') ?></div>
<?php if ($ready): ?>
<form method="POST" action="" id="formConfirmarEmail">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) $csrf_token, ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit" class="btn-login" id="btnConfirmarEmail">
        <i class="fa fa-envelope-circle-check me-2"></i><?= htmlspecialchars(t('auth.email_change.confirm_button'), ENT_QUOTES, 'UTF-8') ?>
    </button>
</form>
<?php else: ?>
<div class="auth-footer" style="margin-top:1.25rem;">
    <a href="/login"><i class="fa fa-arrow-left me-1"></i><?= htmlspecialchars(t('auth.email_change.back_login'), ENT_QUOTES, 'UTF-8') ?></a>
</div>
<?php endif; ?>
<script>
const form = document.getElementById('formConfirmarEmail');
if (form) form.addEventListener('submit', () => {
    const button = document.getElementById('btnConfirmarEmail');
    button.disabled = true;
    button.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i><?= htmlspecialchars(t('auth.email_change.confirming'), ENT_QUOTES, 'UTF-8') ?>';
});
</script>
