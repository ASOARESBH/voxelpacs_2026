<?php
/** Estilo: página administrativa PACS tenant-scoped; resumos sanitizados, sem expor lista de IPs. */
$days = [1 => t('usuarios.regras_acesso.seg'), 2 => t('usuarios.regras_acesso.ter'), 3 => t('usuarios.regras_acesso.qua'), 4 => t('usuarios.regras_acesso.qui'), 5 => t('usuarios.regras_acesso.sex'), 6 => t('usuarios.regras_acesso.sab'), 7 => t('usuarios.regras_acesso.dom')];
$translate = static function (string $key, array $replacements = []): string { return strtr(t($key), $replacements); };
$formatSchedule = static function (array $user) use ($days): string {
    if ((int) ($user['horario_restricao_ativa'] ?? 0) !== 1) return t('usuarios.regras_acesso.padrao');
    $selected = array_filter(array_map('intval', explode(',', (string) ($user['horario_dias_semana'] ?? ''))));
    $label = count($selected) === 7 ? t('usuarios.regras_acesso.todos_dias') : implode(', ', array_map(static fn(int $day): string => $days[$day] ?? '', $selected));
    return htmlspecialchars(substr((string) $user['horario_inicio'], 0, 5) . '–' . substr((string) $user['horario_fim'], 0, 5) . ' · ' . $label);
};
$countIps = static function (array $user): int { return count(array_filter(preg_split('/\R/', (string) ($user['ip_lista_permitida'] ?? '')) ?: [])); };
?>
<style>
/* Estilo: superfície clara e contrastada para leitura de regras de acesso. */
.regras-acesso-table { background: #ffffff; color: #1f2937; border: 1px solid #d9e2ec; }
.regras-acesso-table thead th { background: #eef4fa; color: #334155; border-color: #d9e2ec; font-size: .72rem; letter-spacing: .04em; }
.regras-acesso-table tbody td { background: #ffffff; color: #243447; border-color: #e5eaf0; }
.regras-acesso-table tbody tr:nth-child(even) td { background: #f8fafc; }
.regras-acesso-table tbody tr:hover td { background: #edf6ff; color: #172554; }
.regras-acesso-table .text-muted { color: #64748b !important; }
.regras-acesso-table strong { color: #172554; }
</style>
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
    <div><h1 class="h3 mb-1"><?= htmlspecialchars(t('usuarios.regras_acesso.titulo')) ?></h1><p class="text-muted mb-0"><?= htmlspecialchars(t('usuarios.regras_acesso.subtitulo')) ?></p></div>
  </div>
  <div class="usuarios-tabs-bar">
    <a href="/usuarios" class="usuarios-tab-btn"><i class="fa fa-users"></i> <?= htmlspecialchars(t('usuarios.tabs.usuarios')) ?></a>
    <a href="/usuarios/grupos" class="usuarios-tab-btn"><i class="fa fa-layer-group"></i> <?= htmlspecialchars(t('usuarios.tabs.grupos')) ?></a>
    <a href="/usuarios/notificacoes" class="usuarios-tab-btn"><i class="fa fa-bell"></i> <?= htmlspecialchars(t('usuarios.tabs.notificacoes')) ?></a>
    <a href="/usuarios/regras-acesso" class="usuarios-tab-btn active"><i class="fa fa-shield-halved"></i> <?= htmlspecialchars(t('usuarios.tabs.regras_acesso')) ?></a>
  </div>
  <?php if ($sucesso === 'salvo'): ?><div class="pacs-alert pacs-alert-success mb-3"><i class="fa fa-check-circle me-2"></i><?= htmlspecialchars(t('usuarios.regras_acesso.salvo')) ?></div><?php endif; ?>
  <?php if ($erro !== ''): ?><div class="pacs-alert pacs-alert-danger mb-3"><i class="fa fa-exclamation-triangle me-2"></i><?= htmlspecialchars(t('usuarios.regras_acesso.' . $erro)) ?></div><?php endif; ?>
  <div class="pacs-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0 regras-acesso-table"><thead><tr><th><?= htmlspecialchars(t('usuarios.regras_acesso.usuario')) ?></th><th><?= htmlspecialchars(t('usuarios.regras_acesso.perfil')) ?></th><th><?= htmlspecialchars(t('usuarios.regras_acesso.sessao')) ?></th><th><?= htmlspecialchars(t('usuarios.regras_acesso.ip')) ?></th><th><?= htmlspecialchars(t('usuarios.regras_acesso.horario')) ?></th><th class="text-end"><?= htmlspecialchars(t('usuarios.regras_acesso.acoes')) ?></th></tr></thead><tbody>
  <?php foreach ($usuarios as $user): ?>
    <tr><td><strong><?= htmlspecialchars((string) $user['name']) ?></strong><div class="small text-muted"><?= htmlspecialchars((string) $user['email']) ?></div></td><td><?= htmlspecialchars((string) ($user['perfil'] ?? '—')) ?></td><td><?= (int) ($user['sessao_timeout_ativo'] ?? 0) === 1 ? htmlspecialchars($translate('usuarios.regras_acesso.minutos', [':minutos' => (string) (int) $user['sessao_timeout_minutos']])) : htmlspecialchars(t('usuarios.regras_acesso.padrao')) ?></td><td><?= (int) ($user['ip_restricao_ativa'] ?? 0) === 1 ? htmlspecialchars($translate('usuarios.regras_acesso.ips_configurados', [':quantidade' => (string) $countIps($user)])) : htmlspecialchars(t('usuarios.regras_acesso.padrao')) ?></td><td><?= $formatSchedule($user) ?></td><td class="text-end"><?php if ($user['can_edit']): ?><a class="btn btn-sm btn-pacs-primary" href="/usuarios/regras-acesso/<?= (int) $user['id'] ?>/editar"><i class="fa fa-pen"></i> <?= htmlspecialchars(t('usuarios.regras_acesso.editar')) ?></a><?php else: ?><span class="text-muted small"><i class="fa fa-lock"></i> <?= htmlspecialchars(t('usuarios.regras_acesso.protegido')) ?></span><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
</div>
