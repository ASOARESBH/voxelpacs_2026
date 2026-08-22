<?php
/** @var array<string,mixed> $tenant */
/** @var array<string,mixed>|null $integration */
/** @var array<int,array<string,mixed>> $logs */
?>
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-1"><i class="fa fa-link me-2"></i>Conector Imagiflow</h1>
        <p class="text-muted mb-0">Integração de apuração do negócio <strong><?= htmlspecialchars((string) ($tenant['nome'] ?? $tenant['razao_social'] ?? '')) ?></strong>.</p>
    </div>
    <a class="btn btn-outline-secondary" href="/platform/negocios/<?= (int) $tenant['id'] ?>/edit"><i class="fa fa-arrow-left me-1"></i>Voltar ao Negócio</a>
</div>

<div class="alert alert-info border-0 shadow-sm">
    <i class="fa fa-shield-halved me-2"></i>
    O Imagiflow consulta somente médicos ativos e laudos assinados ou liberados deste negócio. A chave é exibida uma única vez e deve ser armazenada como segredo no Imagiflow.
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white"><strong><i class="fa fa-key me-2"></i>Credencial da integração</strong></div>
            <div class="card-body">
                <dl class="row mb-4">
                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        <?php $status = (string) ($integration['status'] ?? 'inativo'); ?>
                        <span class="badge bg-<?= $status === 'ativo' ? 'success' : ($status === 'revogado' ? 'danger' : 'secondary') ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
                    </dd>
                    <dt class="col-sm-4">Código</dt>
                    <dd class="col-sm-8"><code id="integrationCode"><?= htmlspecialchars((string) ($integration['integration_code'] ?? 'Ainda não gerado')) ?></code></dd>
                    <dt class="col-sm-4">Último uso</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars((string) ($integration['last_used_at'] ?? 'Nunca')) ?></dd>
                </dl>
                <div id="secretPanel" class="alert alert-warning d-none">
                    <strong><i class="fa fa-triangle-exclamation me-1"></i>Copie agora a chave secreta.</strong>
                    <p class="small mb-2">Ela não será mostrada novamente após sair desta página ou regenerar a credencial.</p>
                    <code class="d-block text-break" id="integrationSecret"></code>
                </div>
                <button type="button" id="generate" class="btn btn-primary"><i class="fa fa-rotate me-1"></i><?= $integration ? 'Regenerar código e chave' : 'Gerar código e chave' ?></button>
                <?php if ($integration && $status === 'ativo'): ?>
                    <button type="button" id="revoke" class="btn btn-outline-danger ms-2"><i class="fa fa-ban me-1"></i>Revogar integração</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white"><strong><i class="fa fa-plug me-2"></i>Contrato disponível</strong></div>
            <div class="card-body small">
                <p><code>POST /api/integracoes/imagiflow/v1/medicos/consultar</code><br>Confirma médico ativo por CRM ou nome.</p>
                <p class="mb-0"><code>POST /api/integracoes/imagiflow/v1/apuracao/estudos</code><br>Retorna laudos assinados/liberados por período, com campos compatíveis com a importação manual.</p>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mt-4">
    <div class="card-header bg-white"><strong><i class="fa fa-clock-rotate-left me-2"></i>Auditoria recente</strong></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Data</th><th>Endpoint</th><th>Resultado</th><th>HTTP</th><th>Request ID</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $log['created_at']) ?></td>
                    <td><code><?= htmlspecialchars((string) $log['endpoint']) ?></code></td>
                    <td><span class="badge bg-<?= !empty($log['success']) ? 'success' : 'danger' ?>"><?= !empty($log['success']) ? 'Sucesso' : 'Falha' ?></span></td>
                    <td><?= (int) $log['http_status'] ?></td>
                    <td><code><?= htmlspecialchars((string) $log['request_id']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?><tr><td colspan="5" class="text-muted text-center py-3">Nenhuma chamada registrada.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(() => {
  const csrf = <?= json_encode($csrfToken ?? '') ?>;
  const request = async (path, confirmText) => {
    if (confirmText && !window.confirm(confirmText)) return;
    const body = new FormData(); body.append('_csrf_token', csrf);
    const response = await fetch(path, {method: 'POST', body, credentials: 'same-origin'});
    const data = await response.json();
    if (!data.success) { window.alert(data.message || 'Operação não concluída.'); return; }
    if (data.secret) {
      document.getElementById('integrationCode').textContent = data.integration_code;
      document.getElementById('integrationSecret').textContent = data.secret;
      document.getElementById('secretPanel').classList.remove('d-none');
    }
    window.alert(data.message || 'Operação concluída.');
    if (!data.secret) window.location.reload();
  };
  document.getElementById('generate')?.addEventListener('click', () => request('/platform/negocios/<?= (int) $tenant['id'] ?>/imagiflow/gerar', 'Gerar uma nova credencial revoga a chave anterior. Continuar?'));
  document.getElementById('revoke')?.addEventListener('click', () => request('/platform/negocios/<?= (int) $tenant['id'] ?>/imagiflow/revogar', 'Revogar impedirá novas consultas do Imagiflow. Continuar?'));
})();
</script>
