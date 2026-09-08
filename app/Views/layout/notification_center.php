<!-- Materialização controlada do runtime de notificações. -->
<div class="notification-center" data-notification-center
     data-empty="<?= htmlspecialchars(t('platform_notifications.empty_bell'), ENT_QUOTES) ?>"
     data-confirm="<?= htmlspecialchars(t('platform_notifications.confirm_continue'), ENT_QUOTES) ?>"
     data-close="<?= htmlspecialchars(t('platform_notifications.close'), ENT_QUOTES) ?>">
  <button type="button" class="notification-bell" data-notification-bell aria-label="<?= htmlspecialchars(t('platform_notifications.bell')) ?>" aria-expanded="false"><i class="fa fa-bell"></i><span class="notification-badge d-none" data-notification-count>0</span></button>
  <div class="notification-panel d-none" data-notification-panel><div class="notification-panel-head"><strong><?= htmlspecialchars(t('platform_notifications.bell')) ?></strong><button type="button" class="btn-close" data-notification-close aria-label="<?= htmlspecialchars(t('platform_notifications.close')) ?>"></button></div><div data-notification-list><div class="small text-muted p-3 text-center"><?= htmlspecialchars(t('platform_notifications.loading')) ?></div></div></div>
</div>
