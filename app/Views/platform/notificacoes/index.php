<?php
// Materialização controlada do runtime de notificações.
$success = $_SESSION['success'] ?? null; $error = $_SESSION['error'] ?? null; unset($_SESSION['success'], $_SESSION['error']);
$statusClass = static fn(string $status): string => match ($status) { 'ativa' => 'success', 'agendada' => 'primary', 'pausada' => 'warning', 'arquivada' => 'secondary', default => 'light text-dark' };
?>
<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div><h1 class="h3 mb-1"><i class="fa fa-bell text-primary me-2"></i><?= htmlspecialchars(t('platform_notifications.title')) ?></h1><p class="text-muted mb-0"><?= htmlspecialchars(t('platform_notifications.subtitle')) ?></p></div>
    <a class="btn btn-primary" href="/platform/notificacoes/nova"><i class="fa fa-plus me-1"></i><?= htmlspecialchars(t('platform_notifications.new')) ?></a>
  </div>
  <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <div class="alert alert-info small"><i class="fa fa-circle-info me-2"></i><?= htmlspecialchars(t('platform_notifications.note')) ?></div>
  <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th><?= htmlspecialchars(t('platform_notifications.name')) ?></th><th><?= htmlspecialchars(t('platform_notifications.scope')) ?></th><th><?= htmlspecialchars(t('platform_notifications.period')) ?></th><th><?= htmlspecialchars(t('platform_notifications.status')) ?></th><th class="text-end"><?= htmlspecialchars(t('platform_notifications.actions')) ?></th></tr></thead>
    <tbody><?php foreach ($notifications as $item): ?><tr>
      <td><div class="fw-semibold"><?= htmlspecialchars((string) $item['nome_alerta']) ?></div><small class="text-muted"><?= htmlspecialchars((string) $item['assunto']) ?></small></td>
      <td><span class="badge text-bg-light border"><?= htmlspecialchars((string) $item['escopo'] === 'global' ? t('platform_notifications.global') : ((int) $item['tenants_alvo'] . ' ' . t('platform_notifications.tenants'))) ?></span></td>
      <td><small><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $item['data_inicio']))) ?> — <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $item['data_fim']))) ?></small></td>
      <td><span class="badge text-bg-<?= $statusClass((string) $item['status']) ?>"><?= htmlspecialchars(t('platform_notifications.status.' . $item['status'])) ?></span></td>
      <td class="text-end"><div class="btn-group btn-group-sm"><a class="btn btn-outline-secondary" href="/platform/notificacoes/<?= (int) $item['id'] ?>/editar"><i class="fa fa-pen"></i></a><a class="btn btn-outline-primary" href="/platform/notificacoes/<?= (int) $item['id'] ?>/estatisticas"><i class="fa fa-chart-simple"></i></a>
      <?php if (($item['status'] ?? '') === 'pausada'): ?><form method="post" action="/platform/notificacoes/<?= (int) $item['id'] ?>/reativar"><input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><button class="btn btn-outline-success" title="<?= htmlspecialchars(t('platform_notifications.resume')) ?>"><i class="fa fa-play"></i></button></form><?php elseif (in_array(($item['status'] ?? ''), ['ativa','agendada'], true)): ?><form method="post" action="/platform/notificacoes/<?= (int) $item['id'] ?>/pausar"><input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><button class="btn btn-outline-warning" title="<?= htmlspecialchars(t('platform_notifications.pause')) ?>"><i class="fa fa-pause"></i></button></form><?php endif; ?>
      <form method="post" action="/platform/notificacoes/<?= (int) $item['id'] ?>/arquivar" onsubmit="return confirm('<?= htmlspecialchars(t('platform_notifications.archive_confirm'), ENT_QUOTES) ?>')"><input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><button class="btn btn-outline-danger" title="<?= htmlspecialchars(t('platform_notifications.archive')) ?>"><i class="fa fa-box-archive"></i></button></form></div></td>
    </tr><?php endforeach; if (!$notifications): ?><tr><td colspan="5" class="text-center text-muted py-5"><?= htmlspecialchars(t('platform_notifications.empty')) ?></td></tr><?php endif; ?></tbody>
  </table></div></div>
</div>
