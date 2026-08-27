<?php
/** @var array<int,array{id:int,nome:string,slug:string}> $tenants */
$tx = static fn(string $key): string => htmlspecialchars(t($key), ENT_QUOTES, 'UTF-8');
?>
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
      <h3 class="mb-1"><i class="bi bi-diagram-3"></i> <?= $tx('servidor_pacs.tenant_novo.titulo') ?></h3>
      <p class="text-muted mb-0"><?= $tx('servidor_pacs.tenant_novo.subtitulo') ?></p>
    </div>
    <a class="btn btn-outline-secondary" href="/platform/servidor-pacs/novo-orthanc"><i class="bi bi-hdd-network"></i> <?= $tx('servidor_pacs.tenant_novo.orthanc_existente') ?></a>
  </div>

  <div class="alert alert-info border-0 shadow-sm">
    <strong><?= $tx('servidor_pacs.tenant_novo.fluxo_titulo') ?></strong> <?= $tx('servidor_pacs.tenant_novo.fluxo_descricao') ?>
  </div>

  <form method="post" action="/platform/servidor-pacs/criar" id="tenantProvisionForm" class="card shadow-sm border-0">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="profile" value="vpn_only">
    <div class="card-body p-4">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" for="tenant_id"><?= $tx('servidor_pacs.tenant_novo.negocio') ?> <span class="text-danger">*</span></label>
          <select class="form-select" id="tenant_id" name="tenant_id" required>
            <option value=""><?= $tx('servidor_pacs.tenant_novo.selecione_negocio') ?></option>
            <?php foreach ($tenants as $tenant): ?>
              <option value="<?= (int) $tenant['id'] ?>" data-slug="<?= htmlspecialchars($tenant['slug'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tenant['nome'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text"><?= $tx('servidor_pacs.tenant_novo.negocio_ajuda') ?></div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="display_name"><?= $tx('servidor_pacs.tenant_novo.nome') ?> <span class="text-danger">*</span></label>
          <input class="form-control" id="display_name" name="display_name" maxlength="160" required placeholder="Orthanc Cliente B — célula isolada">
        </div>
        <div class="col-md-4">
          <label class="form-label" for="route_key"><?= $tx('servidor_pacs.tenant_novo.chave_rota') ?> <span class="text-danger">*</span></label>
          <input class="form-control text-lowercase" id="route_key" name="route_key" pattern="[a-z][a-z0-9-]{1,30}" maxlength="31" required placeholder="cliente-b" autocomplete="off">
          <div class="form-text"><?= $tx('servidor_pacs.tenant_novo.chave_rota_ajuda') ?></div>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="calling_ae"><?= $tx('servidor_pacs.tenant_novo.calling_ae') ?> <span class="text-danger">*</span></label>
          <input class="form-control text-uppercase" id="calling_ae" name="calling_ae" pattern="[A-Z0-9_-]{1,16}" maxlength="16" required placeholder="CLIENTE_MODALIDADE" autocomplete="off">
          <div class="form-text"><?= $tx('servidor_pacs.tenant_novo.calling_ae_ajuda') ?></div>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="called_ae"><?= $tx('servidor_pacs.tenant_novo.called_ae') ?> <span class="text-danger">*</span></label>
          <input class="form-control text-uppercase" id="called_ae" name="called_ae" pattern="[A-Z0-9_-]{1,16}" maxlength="16" required placeholder="VOXEL_GW_B" autocomplete="off">
          <div class="form-text"><?= $tx('servidor_pacs.tenant_novo.called_ae_ajuda') ?></div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="backend_ae"><?= $tx('servidor_pacs.tenant_novo.backend_ae') ?> <span class="text-danger">*</span></label>
          <input class="form-control text-uppercase" id="backend_ae" name="backend_ae" pattern="[A-Z0-9_-]{1,16}" maxlength="16" required placeholder="VOXEL_B_PACS" autocomplete="off">
          <div class="form-text"><?= $tx('servidor_pacs.tenant_novo.backend_ae_ajuda') ?></div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="wireguard_public_key"><?= $tx('servidor_pacs.tenant_novo.wireguard_chave') ?> <span class="text-danger">*</span></label>
          <input class="form-control font-monospace" id="wireguard_public_key" name="wireguard_public_key" minlength="44" maxlength="64" required placeholder="<?= $tx('servidor_pacs.tenant_novo.wireguard_placeholder') ?>" autocomplete="off" spellcheck="false">
          <div class="form-text"><?= $tx('servidor_pacs.tenant_novo.wireguard_ajuda') ?></div>
        </div>
      </div>

      <hr class="my-4">
      <div class="row g-3 align-items-center">
        <div class="col-md-6"><strong><?= $tx('servidor_pacs.tenant_novo.alocado_titulo') ?></strong><br><span class="text-muted small"><?= $tx('servidor_pacs.tenant_novo.alocado_ajuda') ?></span></div>
        <div class="col-md-6">
          <div class="form-check border rounded p-3 bg-light">
            <input class="form-check-input ms-0 me-2" type="checkbox" value="1" id="confirm_provision" name="confirm_provision" required>
            <label class="form-check-label" for="confirm_provision"><?= $tx('servidor_pacs.tenant_novo.confirmacao') ?></label>
          </div>
        </div>
      </div>
    </div>
    <div class="card-footer bg-white d-flex justify-content-end gap-2">
      <a class="btn btn-light" href="/platform/servidor-pacs"><?= $tx('servidor_pacs.tenant_novo.cancelar') ?></a>
      <button class="btn btn-primary" type="submit"><i class="bi bi-shield-check"></i> <?= $tx('servidor_pacs.tenant_novo.salvar') ?></button>
    </div>
  </form>
</div>
<script>
(() => {
  const form = document.getElementById('tenantProvisionForm');
  const tenant = document.getElementById('tenant_id');
  const route = document.getElementById('route_key');
  const upperIds = ['calling_ae', 'called_ae', 'backend_ae'];
  tenant?.addEventListener('change', () => { if (!route.value) route.value = (tenant.selectedOptions[0]?.dataset.slug || '').replace(/[^a-z0-9-]/g, '-'); });
  upperIds.forEach(id => document.getElementById(id)?.addEventListener('input', event => { event.target.value = event.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, ''); }));
  route?.addEventListener('input', event => { event.target.value = event.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-'); });
  form?.addEventListener('submit', event => { if (!confirm(<?= json_encode(t('servidor_pacs.tenant_novo.confirmar_js'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>)) event.preventDefault(); });
})();
</script>
