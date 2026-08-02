<?php
/**
 * View: unidades/nova.php
 * Cadastro e edição de bi_unidades (entidade rica com CNPJ, endereço, logo).
 * Reutilizada para criação ($unidade === null) e edição ($unidade = array).
 */
$unidade          = $unidade          ?? null;
$institutionNames = $institutionNames ?? [];
$vinculados       = $vinculados       ?? [];
$isEdit           = $unidade !== null;
$formAction       = $isEdit ? "/unidades/{$unidade['id']}/editar" : '/unidades/nova';
$titulo           = $isEdit ? 'Editar Unidade' : 'Nova Unidade';
$cnpjFmt = '';
if ($isEdit && !empty($unidade['cnpj'])) {
    $c = $unidade['cnpj'];
    if (strlen($c) === 14) $cnpjFmt = substr($c,0,2).'.'.substr($c,2,3).'.'.substr($c,5,3).'/'.substr($c,8,4).'-'.substr($c,12,2);
    else $cnpjFmt = $c;
}
$logoUrl = ($isEdit && !empty($unidade['logo_path'])) ? '/' . $unidade['logo_path'] : null;
?>

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="/unidades" class="btn btn-outline-secondary btn-sm py-1 px-2">
        <i class="fa fa-arrow-left me-1"></i>Voltar
    </a>
    <h1 class="h4 mb-0 fw-bold"><i class="fa fa-hospital me-2 text-primary"></i><?= htmlspecialchars($titulo) ?></h1>
</div>

<?php if (!empty($_SESSION['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
    <i class="fa fa-exclamation-triangle me-2"></i><?= htmlspecialchars($_SESSION['error']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['error']); endif; ?>

<form method="POST" action="<?= $formAction ?>" enctype="multipart/form-data" id="formUnidade">

<!-- ═══════════════════════════════════════════════════════════════════
     SEÇÃO 1 — IDENTIFICAÇÃO LEGAL (CNPJ)
═══════════════════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-3">
    <div class="card-header py-2 d-flex align-items-center gap-2">
        <i class="fa fa-id-card text-primary"></i>
        <span class="fw-semibold small">Identificação Legal</span>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-auto" style="font-size:10px">
            Busca automática via CNPJ
        </span>
    </div>
    <div class="card-body p-3">
        <!-- CNPJ + Botão de busca -->
        <div class="row g-2 mb-3">
            <div class="col-12 col-md-5">
                <label class="form-label small fw-semibold mb-1">CNPJ</label>
                <div class="input-group input-group-sm">
                    <input type="text" id="cnpj" name="cnpj"
                           class="form-control"
                           placeholder="00.000.000/0000-00"
                           value="<?= htmlspecialchars($cnpjFmt) ?>"
                           maxlength="18"
                           autocomplete="off">
                    <button type="button" class="btn btn-primary" id="btnBuscarCnpj">
                        <i class="fa fa-search me-1"></i>Buscar
                    </button>
                    <button type="button" class="btn btn-outline-secondary d-none" id="btnBuscarCnpjForce" title="Forçar nova consulta (ignorar cache)">
                        <i class="fa fa-sync"></i>
                    </button>
                </div>
                <div id="cnpjStatus" class="mt-1 small"></div>
                <div class="text-muted" style="font-size:10px;margin-top:2px">
                    Fallback automático: BrasilAPI → ReceitaWS → OpenCNPJ
                </div>
            </div>
        </div>

        <!-- Dados preenchidos automaticamente -->
        <div class="row g-2">
            <div class="col-12 col-md-6">
                <label class="form-label small fw-semibold mb-1">Razão Social</label>
                <input type="text" id="razao_social" name="razao_social"
                       class="form-control form-control-sm"
                       value="<?= htmlspecialchars($unidade['razao_social'] ?? '') ?>"
                       placeholder="Preenchido automaticamente pelo CNPJ">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label small fw-semibold mb-1">Nome Fantasia</label>
                <input type="text" id="nome_fantasia" name="nome_fantasia"
                       class="form-control form-control-sm"
                       value="<?= htmlspecialchars($unidade['nome_fantasia'] ?? '') ?>"
                       placeholder="Nome comercial da unidade">
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     SEÇÃO 2 — ENDEREÇO
═══════════════════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-3">
    <div class="card-header py-2 d-flex align-items-center gap-2">
        <i class="fa fa-map-marker-alt text-primary"></i>
        <span class="fw-semibold small">Endereço</span>
    </div>
    <div class="card-body p-3">
        <div class="row g-2">
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold mb-1">CEP</label>
                <input type="text" id="cep" name="cep"
                       class="form-control form-control-sm"
                       placeholder="00000-000" maxlength="9"
                       value="<?php
                           $cepVal = $unidade['cep'] ?? '';
                           if (strlen($cepVal) === 8) $cepVal = substr($cepVal,0,5).'-'.substr($cepVal,5);
                           echo htmlspecialchars($cepVal);
                       ?>">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label small fw-semibold mb-1">Logradouro</label>
                <input type="text" id="logradouro" name="logradouro"
                       class="form-control form-control-sm"
                       value="<?= htmlspecialchars($unidade['logradouro'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold mb-1">Número</label>
                <input type="text" id="numero" name="numero"
                       class="form-control form-control-sm"
                       value="<?= htmlspecialchars($unidade['numero'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label small fw-semibold mb-1">Complemento</label>
                <input type="text" id="complemento" name="complemento"
                       class="form-control form-control-sm"
                       value="<?= htmlspecialchars($unidade['complemento'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label small fw-semibold mb-1">Bairro</label>
                <input type="text" id="bairro" name="bairro"
                       class="form-control form-control-sm"
                       value="<?= htmlspecialchars($unidade['bairro'] ?? '') ?>">
            </div>
            <div class="col-8 col-md-3">
                <label class="form-label small fw-semibold mb-1">Cidade</label>
                <input type="text" id="cidade" name="cidade"
                       class="form-control form-control-sm"
                       value="<?= htmlspecialchars($unidade['cidade'] ?? '') ?>">
            </div>
            <div class="col-4 col-md-1">
                <label class="form-label small fw-semibold mb-1">UF</label>
                <input type="text" id="estado" name="estado"
                       class="form-control form-control-sm text-uppercase"
                       maxlength="2"
                       value="<?= htmlspecialchars($unidade['estado'] ?? '') ?>">
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     SEÇÃO 3 — CONTATO
═══════════════════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-3">
    <div class="card-header py-2 d-flex align-items-center gap-2">
        <i class="fa fa-phone text-primary"></i>
        <span class="fw-semibold small">Contato</span>
    </div>
    <div class="card-body p-3">
        <div class="row g-2">
            <div class="col-12 col-md-4">
                <label class="form-label small fw-semibold mb-1">Telefone</label>
                <input type="text" name="telefone"
                       class="form-control form-control-sm"
                       value="<?= htmlspecialchars($unidade['telefone'] ?? '') ?>"
                       placeholder="(00) 00000-0000">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label small fw-semibold mb-1">E-mail</label>
                <input type="email" name="email"
                       class="form-control form-control-sm"
                       value="<?= htmlspecialchars($unidade['email'] ?? '') ?>"
                       placeholder="contato@unidade.com.br">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label small fw-semibold mb-1">Site</label>
                <input type="url" name="site"
                       class="form-control form-control-sm"
                       value="<?= htmlspecialchars($unidade['site'] ?? '') ?>"
                       placeholder="https://www.unidade.com.br">
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     SEÇÃO 4 — LOGO DA UNIDADE
═══════════════════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-3">
    <div class="card-header py-2 d-flex align-items-center gap-2">
        <i class="fa fa-image text-primary"></i>
        <span class="fw-semibold small">Logo da Unidade</span>
        <span class="text-muted ms-auto" style="font-size:10px">
            PNG, JPG ou SVG · máx. 2MB · Usada pelo VoxelCopilot nos laudos
        </span>
    </div>
    <div class="card-body p-3">
        <div class="d-flex align-items-start gap-3">
            <!-- Preview -->
            <div class="logo-upload-preview flex-shrink-0">
                <?php if ($logoUrl): ?>
                <img id="logoPreview" src="<?= htmlspecialchars($logoUrl) ?>"
                     alt="Logo atual" class="logo-preview-img rounded">
                <?php else: ?>
                <div id="logoPlaceholder" class="logo-preview-placeholder rounded d-flex align-items-center justify-content-center bg-light border">
                    <i class="fa fa-hospital fa-2x text-muted"></i>
                </div>
                <img id="logoPreview" src="" alt="Preview" class="logo-preview-img rounded d-none">
                <?php endif; ?>
            </div>
            <!-- Input -->
            <div class="flex-grow-1">
                <input type="file" id="logo" name="logo"
                       class="form-control form-control-sm"
                       accept=".png,.jpg,.jpeg,.svg">
                <div id="logoError" class="text-danger small mt-1 d-none"></div>
                <?php if ($isEdit && !empty($unidade['copilot_logo_url'])): ?>
                <div class="mt-2 p-2 bg-success-subtle rounded border border-success-subtle">
                    <i class="fa fa-check-circle text-success me-1"></i>
                    <span class="small text-success fw-semibold">Logo sincronizada com VoxelCopilot</span>
                    <div class="text-muted mt-1" style="font-size:10px;word-break:break-all">
                        URL pública: <?= htmlspecialchars($unidade['copilot_logo_url']) ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="mt-2 text-muted small">
                    <i class="fa fa-info-circle me-1"></i>
                    Após salvar, a URL pública será gerada automaticamente para uso no VoxelCopilot.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     SEÇÃO 5 — VÍNCULOS COM INSTITUTION NAMES DICOM
═══════════════════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-3">
    <div class="card-header py-2 d-flex align-items-center gap-2">
        <i class="fa fa-link text-primary"></i>
        <span class="fw-semibold small">Vínculos com InstitutionName DICOM</span>
        <span class="text-muted ms-auto" style="font-size:10px">
            Selecione quais nomes DICOM correspondem a esta unidade
        </span>
    </div>
    <div class="card-body p-3">
        <?php if (empty($institutionNames)): ?>
        <div class="text-muted small text-center py-3">
            <i class="fa fa-info-circle me-1"></i>
            Nenhum InstitutionName disponível. Aguarde a chegada de estudos DICOM.
        </div>
        <?php else: ?>
        <div class="row g-2">
            <?php foreach ($institutionNames as $inst): ?>
            <?php $checked = in_array((string)$inst['id'], array_map('strval', $vinculados)); ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="form-check border rounded p-2 <?= $checked ? 'bg-primary-subtle border-primary' : 'bg-light border-secondary-subtle' ?>">
                    <input class="form-check-input" type="checkbox"
                           name="institution_names[]"
                           value="<?= (int)$inst['id'] ?>"
                           id="inst_<?= (int)$inst['id'] ?>"
                           <?= $checked ? 'checked' : '' ?>>
                    <label class="form-check-label small fw-semibold w-100 text-truncate"
                           for="inst_<?= (int)$inst['id'] ?>"
                           title="<?= htmlspecialchars($inst['institution_name']) ?>">
                        <?= htmlspecialchars($inst['institution_name']) ?>
                    </label>
                    <?php if (!empty($inst['unidade_id']) && $inst['unidade_id'] != ($unidade['id'] ?? 0)): ?>
                    <div class="text-warning" style="font-size:10px">
                        <i class="fa fa-exclamation-triangle me-1"></i>Vinculado a outra unidade
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="text-muted mt-2" style="font-size:10px">
            <i class="fa fa-info-circle me-1"></i>
            Uma unidade pode ter múltiplos InstitutionNames (ex: variações de nome no equipamento).
            Um InstitutionName só pode ser vinculado a uma unidade por vez.
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     SEÇÃO 6 — OBSERVAÇÕES E STATUS
═══════════════════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-3">
    <div class="card-header py-2 d-flex align-items-center gap-2">
        <i class="fa fa-cog text-primary"></i>
        <span class="fw-semibold small">Configurações</span>
    </div>
    <div class="card-body p-3">
        <div class="row g-2">
            <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Observações</label>
                <textarea name="observacoes" class="form-control form-control-sm" rows="2"
                          placeholder="Informações adicionais sobre esta unidade"><?= htmlspecialchars($unidade['observacoes'] ?? '') ?></textarea>
            </div>
            <?php if ($isEdit): ?>
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="ativo" id="ativo" value="1"
                           <?= ($unidade['ativo'] ?? 1) ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="ativo">Unidade ativa</label>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Botões de ação -->
<div class="d-flex gap-2 justify-content-end mb-4">
    <a href="/unidades" class="btn btn-outline-secondary">
        <i class="fa fa-times me-1"></i>Cancelar
    </a>
    <?php if ($isEdit): ?>
    <button type="button" class="btn btn-outline-danger btn-sm"
            onclick="if(confirm('Desativar esta unidade?')) { document.getElementById('formExcluir').submit(); }">
        <i class="fa fa-trash me-1"></i>Desativar
    </button>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">
        <i class="fa fa-save me-1"></i><?= $isEdit ? 'Salvar Alterações' : 'Cadastrar Unidade' ?>
    </button>
</div>

<?php if ($isEdit): ?>
<form id="formExcluir" method="POST" action="/unidades/<?= (int)$unidade['id'] ?>/excluir" style="display:none"></form>
<?php endif; ?>

</form><!-- #formUnidade -->

<style>
.logo-upload-preview { width: 80px; height: 80px; }
.logo-preview-img    { width: 80px; height: 80px; object-fit: contain; border: 1px solid #e5e7eb; background: #f9fafb; }
.logo-preview-placeholder { width: 80px; height: 80px; }
</style>

<script>
(function () {
    'use strict';

    const unitId = <?= $isEdit ? (int)$unidade['id'] : 0 ?>;

    // ── Máscara CNPJ ─────────────────────────────────────────────────────
    document.getElementById('cnpj').addEventListener('input', function () {
        let v = this.value.replace(/\D/g, '').slice(0, 14);
        if (v.length > 12) v = v.slice(0,2)+'.'+v.slice(2,5)+'.'+v.slice(5,8)+'/'+v.slice(8,12)+'-'+v.slice(12);
        else if (v.length > 8) v = v.slice(0,2)+'.'+v.slice(2,5)+'.'+v.slice(5,8)+'/'+v.slice(8);
        else if (v.length > 5) v = v.slice(0,2)+'.'+v.slice(2,5)+'.'+v.slice(5);
        else if (v.length > 2) v = v.slice(0,2)+'.'+v.slice(2);
        this.value = v;
    });

    // ── Máscara CEP ──────────────────────────────────────────────────────
    document.getElementById('cep').addEventListener('input', function () {
        let v = this.value.replace(/\D/g, '').slice(0, 8);
        if (v.length > 5) v = v.slice(0, 5) + '-' + v.slice(5);
        this.value = v;
    });

    // ── Busca CNPJ ───────────────────────────────────────────────────────
    function buscarCnpj(force) {
        const cnpjRaw   = document.getElementById('cnpj').value.replace(/\D/g, '');
        const statusEl  = document.getElementById('cnpjStatus');
        const btnBuscar = document.getElementById('btnBuscarCnpj');
        const btnForce  = document.getElementById('btnBuscarCnpjForce');

        if (cnpjRaw.length !== 14) {
            statusEl.innerHTML = '<span class="text-danger"><i class="fa fa-exclamation-circle me-1"></i>CNPJ deve ter 14 dígitos.</span>';
            return;
        }
        statusEl.innerHTML = '<span class="text-muted"><i class="fa fa-spinner fa-spin me-1"></i>Consultando (BrasilAPI → ReceitaWS → OpenCNPJ)...</span>';
        btnBuscar.disabled = true;

        const url = `/api/unidades/cnpj?cnpj=${cnpjRaw}&unit_id=${unitId}&force=${force ? 1 : 0}`;
        fetch(url)
            .then(r => r.json())
            .then(res => {
                btnBuscar.disabled = false;
                if (!res.ok) {
                    statusEl.innerHTML = `<span class="text-danger"><i class="fa fa-times-circle me-1"></i>${res.msg}</span>`;
                    return;
                }
                const d = res.data;
                const fill = (id, val) => { const el = document.getElementById(id); if (el && val) el.value = val; };
                fill('razao_social',  d.razao_social);
                fill('nome_fantasia', d.nome_fantasia);
                fill('logradouro',    d.logradouro);
                fill('numero',        d.numero);
                fill('complemento',   d.complemento);
                fill('bairro',        d.bairro);
                fill('cidade',        d.cidade);
                fill('estado',        d.uf);
                if (d.cep && d.cep.length === 8) {
                    document.getElementById('cep').value = d.cep.slice(0,5) + '-' + d.cep.slice(5);
                }
                const fonte = d.fonte_utilizada || 'api';
                const cache = res.data._from_cache ? ' (cache)' : '';
                statusEl.innerHTML = `<span class="text-success"><i class="fa fa-check-circle me-1"></i>Dados preenchidos via <strong>${fonte}</strong>${cache}.</span>`;
                btnForce.classList.remove('d-none');
            })
            .catch(() => {
                btnBuscar.disabled = false;
                statusEl.innerHTML = '<span class="text-danger"><i class="fa fa-times-circle me-1"></i>Erro de conexão. Preencha manualmente.</span>';
            });
    }

    document.getElementById('btnBuscarCnpj').addEventListener('click', () => buscarCnpj(false));
    document.getElementById('btnBuscarCnpjForce').addEventListener('click', () => buscarCnpj(true));
    document.getElementById('cnpj').addEventListener('blur', function () {
        if (this.value.replace(/\D/g, '').length === 14) buscarCnpj(false);
    });

    // ── Preview de logo ──────────────────────────────────────────────────
    document.getElementById('logo').addEventListener('change', function () {
        const file        = this.files[0];
        const errEl       = document.getElementById('logoError');
        const preview     = document.getElementById('logoPreview');
        const placeholder = document.getElementById('logoPlaceholder');
        errEl.classList.add('d-none');
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) {
            errEl.textContent = 'Arquivo muito grande. Máximo 2MB.';
            errEl.classList.remove('d-none');
            this.value = '';
            return;
        }
        const allowed = ['image/png', 'image/jpeg', 'image/svg+xml'];
        if (!allowed.includes(file.type)) {
            errEl.textContent = 'Tipo não permitido. Use PNG, JPG ou SVG.';
            errEl.classList.remove('d-none');
            this.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = e => {
            preview.src = e.target.result;
            preview.classList.remove('d-none');
            if (placeholder) placeholder.classList.add('d-none');
        };
        reader.readAsDataURL(file);
    });

    // ── Highlight dos checkboxes de institution_names ────────────────────
    document.querySelectorAll('input[name="institution_names[]"]').forEach(cb => {
        cb.addEventListener('change', function () {
            const wrap = this.closest('.form-check');
            if (this.checked) {
                wrap.classList.add('bg-primary-subtle', 'border-primary');
                wrap.classList.remove('bg-light', 'border-secondary-subtle');
            } else {
                wrap.classList.remove('bg-primary-subtle', 'border-primary');
                wrap.classList.add('bg-light', 'border-secondary-subtle');
            }
        });
    });
})();
</script>
