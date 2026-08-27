<?php /** @var array<int,array{id:int,nome:string,slug:string}> $tenants */ ?>
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
      <h3 class="mb-1"><i class="bi bi-diagram-3"></i> Novo Servidor DICOM tenant</h3>
      <p class="text-muted mb-0">Provisionamento de célula Orthanc isolada, perfil <strong>VPN-only</strong>.</p>
    </div>
    <a class="btn btn-outline-secondary" href="/platform/servidor-pacs/novo-orthanc"><i class="bi bi-hdd-network"></i> Cadastrar Orthanc já existente</a>
  </div>

  <div class="alert alert-info border-0 shadow-sm">
    <strong>Fluxo controlado.</strong> Ao salvar com confirmação, o sistema reserva portas e IP VPN, cria a célula isolada no host híbrido, peer WireGuard, regras privadas, contrato/timer de backup desabilitado e rota exclusiva <strong>somente C-ECHO</strong>. C-STORE e backup clínico continuam bloqueados até confirmações separadas.
  </div>

  <form method="post" action="/platform/servidor-pacs/criar" id="tenantProvisionForm" class="card shadow-sm border-0">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="profile" value="vpn_only">
    <div class="card-body p-4">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" for="tenant_id">Negócio / tenant <span class="text-danger">*</span></label>
          <select class="form-select" id="tenant_id" name="tenant_id" required>
            <option value="">Selecione o negócio</option>
            <?php foreach ($tenants as $tenant): ?>
              <option value="<?= (int) $tenant['id'] ?>" data-slug="<?= htmlspecialchars($tenant['slug'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tenant['nome'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">A célula será vinculada exclusivamente a este negócio.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="display_name">Nome do Servidor DICOM tenant <span class="text-danger">*</span></label>
          <input class="form-control" id="display_name" name="display_name" maxlength="160" required placeholder="Orthanc Cliente B — célula isolada">
        </div>
        <div class="col-md-4">
          <label class="form-label" for="route_key">Chave de rota <span class="text-danger">*</span></label>
          <input class="form-control text-lowercase" id="route_key" name="route_key" pattern="[a-z][a-z0-9-]{1,30}" maxlength="31" required placeholder="cliente-b" autocomplete="off">
          <div class="form-text">Letras minúsculas, números e hífen; não pode ser alterada depois.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="calling_ae">Calling AE do cliente <span class="text-danger">*</span></label>
          <input class="form-control text-uppercase" id="calling_ae" name="calling_ae" pattern="[A-Z0-9_-]{1,16}" maxlength="16" required placeholder="CLIENTE_MODALIDADE" autocomplete="off">
          <div class="form-text">AE de origem efetivamente emitido pelo PACS/modalidade.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="called_ae">Called AE VOXEL <span class="text-danger">*</span></label>
          <input class="form-control text-uppercase" id="called_ae" name="called_ae" pattern="[A-Z0-9_-]{1,16}" maxlength="16" required placeholder="VOXEL_GW_B" autocomplete="off">
          <div class="form-text">Destino exclusivo que o cliente cadastrará no PACS.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="backend_ae">AE do Orthanc tenant <span class="text-danger">*</span></label>
          <input class="form-control text-uppercase" id="backend_ae" name="backend_ae" pattern="[A-Z0-9_-]{1,16}" maxlength="16" required placeholder="VOXEL_B_PACS" autocomplete="off">
          <div class="form-text">Identidade privada do backend; não é informada ao cliente.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="wireguard_public_key">Chave pública WireGuard recebida do cliente <span class="text-danger">*</span></label>
          <input class="form-control font-monospace" id="wireguard_public_key" name="wireguard_public_key" minlength="44" maxlength="64" required placeholder="Chave pública base64 do peer do cliente" autocomplete="off" spellcheck="false">
          <div class="form-text">Somente a chave pública. Não cole chave privada, senha, token ou arquivo de configuração completo.</div>
        </div>
      </div>

      <hr class="my-4">
      <div class="row g-3 align-items-center">
        <div class="col-md-6"><strong>Parâmetros alocados automaticamente</strong><br><span class="text-muted small">Perfil VPN-only; IP VPN reservado, porta DICOM/DICOMweb privadas e acesso por gateway compartilhado. Endpoints internos não serão exibidos no kit do cliente.</span></div>
        <div class="col-md-6">
          <div class="form-check border rounded p-3 bg-light">
            <input class="form-check-input ms-0 me-2" type="checkbox" value="1" id="confirm_provision" name="confirm_provision" required>
            <label class="form-check-label" for="confirm_provision">Confirmo a criação controlada de container, listener privado, peer WireGuard, rota C-ECHO e timer de backup inicialmente desabilitado.</label>
          </div>
        </div>
      </div>
    </div>
    <div class="card-footer bg-white d-flex justify-content-end gap-2">
      <a class="btn btn-light" href="/platform/servidor-pacs">Cancelar</a>
      <button class="btn btn-primary" type="submit"><i class="bi bi-shield-check"></i> Salvar e provisionar</button>
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
  form?.addEventListener('submit', event => { if (!confirm('Confirmar provisionamento da célula isolada? A rota será criada somente para C-ECHO; C-STORE continuará bloqueado.')) event.preventDefault(); });
})();
</script>
