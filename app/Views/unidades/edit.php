<?php
$u   = $unidade ?? [];
$id  = (int)($u['id'] ?? 0);
$cnpjFormatado = '';
$cnpjRaw = preg_replace('/\D/', '', $u['cnpj'] ?? '');
if (strlen($cnpjRaw) === 14) {
    $cnpjFormatado = substr($cnpjRaw,0,2).'.'.substr($cnpjRaw,2,3).'.'.substr($cnpjRaw,5,3).'/'.substr($cnpjRaw,8,4).'-'.substr($cnpjRaw,12,2);
}
$templatesLaudo  = $templatesLaudo ?? [];
$templateAtualId = (int) ($u['report_layout_template_id'] ?? 0);
?>

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="/unidades" class="btn btn-outline-secondary btn-sm py-1 px-2">
        <i class="fa fa-arrow-left me-1"></i>Voltar
    </a>
    <div>
        <h1 class="h4 mb-0 fw-bold"><i class="fa fa-hospital me-2 text-primary"></i>Editar Unidade</h1>
        <p class="text-muted small mb-0">
            <i class="fa fa-lock me-1 text-muted"></i>
            InstitutionName DICOM: <strong><?= htmlspecialchars($u['institution_name'] ?? '') ?></strong>
        </p>
    </div>
</div>

<form method="POST" action="/unidades/<?= $id ?>/update" enctype="multipart/form-data" id="formUnidade">
<div class="row g-3">

    <!-- ── COLUNA ESQUERDA ─────────────────────────────────────────────── -->
    <div class="col-12 col-lg-8">

        <!-- Card: Identificação -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white py-2 border-bottom">
                <span class="fw-semibold small"><i class="fa fa-info-circle me-2 text-primary"></i>Identificação</span>
            </div>
            <div class="card-body p-3">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Nome de Exibição</label>
                        <input type="text" name="descricao" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($u['descricao'] ?? '') ?>"
                               placeholder="Nome amigável para exibição no sistema">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Responsável</label>
                        <input type="text" name="responsavel" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($u['responsavel'] ?? '') ?>"
                               placeholder="Nome do responsável pela unidade">
                    </div>
                </div>
            </div>
        </div>

        <!-- Card: CNPJ + Dados Fiscais -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white py-2 border-bottom d-flex align-items-center justify-content-between">
                <span class="fw-semibold small"><i class="fa fa-id-card me-2 text-primary"></i>CNPJ e Dados Fiscais</span>
                <span class="badge bg-info-subtle text-info border border-info-subtle" style="font-size:10px">
                    <i class="fa fa-magic me-1"></i>Preenchimento automático via CNPJ
                </span>
            </div>
            <div class="card-body p-3">
                <div class="row g-3">
                    <!-- CNPJ com botão de busca -->
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">CNPJ</label>
                        <div class="input-group input-group-sm">
                            <input type="text" name="cnpj" id="cnpj" class="form-control form-control-sm"
                                   value="<?= htmlspecialchars($cnpjFormatado) ?>"
                                   placeholder="00.000.000/0001-00" maxlength="18"
                                   autocomplete="off">
                            <button type="button" class="btn btn-primary btn-sm" id="btnBuscarCnpj"
                                    title="Buscar dados do CNPJ automaticamente">
                                <i class="fa fa-search me-1"></i>Buscar
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="btnBuscarCnpjForce"
                                    title="Forçar nova consulta (ignorar cache)">
                                <i class="fa fa-sync-alt"></i>
                            </button>
                        </div>
                        <div id="cnpjStatus" class="form-text mt-1"></div>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label small fw-semibold">Razão Social</label>
                        <input type="text" name="razao_social" id="razao_social" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($u['razao_social'] ?? '') ?>"
                               placeholder="Razão social conforme CNPJ">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label small fw-semibold">Nome Fantasia</label>
                        <input type="text" name="nome_fantasia" id="nome_fantasia" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($u['nome_fantasia'] ?? '') ?>"
                               placeholder="Nome fantasia (opcional)">
                    </div>
                </div>
            </div>
        </div>

        <!-- Card: Endereço -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white py-2 border-bottom">
                <span class="fw-semibold small"><i class="fa fa-map-marker-alt me-2 text-primary"></i>Endereço</span>
            </div>
            <div class="card-body p-3">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">CEP</label>
                        <input type="text" name="cep" id="cep" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($u['cep'] ?? '') ?>"
                               placeholder="00000-000" maxlength="9">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label small fw-semibold">Logradouro</label>
                        <input type="text" name="logradouro" id="logradouro" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($u['logradouro'] ?? '') ?>"
                               placeholder="Rua, Avenida, etc.">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Número</label>
                        <input type="text" name="numero" id="numero" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($u['numero'] ?? '') ?>"
                               placeholder="Nº">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Complemento</label>
                        <input type="text" name="complemento" id="complemento" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($u['complemento'] ?? '') ?>"
                               placeholder="Sala, Andar, etc.">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Bairro</label>
                        <input type="text" name="bairro" id="bairro" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($u['bairro'] ?? '') ?>"
                               placeholder="Bairro">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Cidade</label>
                        <input type="text" name="cidade" id="cidade" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($u['cidade'] ?? '') ?>"
                               placeholder="Cidade">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">UF</label>
                        <input type="text" name="estado" id="estado" class="form-control form-control-sm text-uppercase"
                               value="<?= htmlspecialchars($u['estado'] ?? '') ?>"
                               maxlength="2" placeholder="UF">
                    </div>
                </div>
            </div>
        </div>

        <!-- Card: Contato e Operação -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white py-2 border-bottom">
                <span class="fw-semibold small"><i class="fa fa-phone me-2 text-primary"></i>Contato e Operação</span>
            </div>
            <div class="card-body p-3">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Telefone</label>
                        <input type="text" name="telefone" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($u['telefone'] ?? '') ?>"
                               placeholder="(00) 0000-0000">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">E-mail</label>
                        <input type="email" name="email" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($u['email'] ?? '') ?>"
                               placeholder="contato@unidade.com.br">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Horário de Funcionamento</label>
                        <input type="text" name="horario" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($u['horario'] ?? '') ?>"
                               placeholder="Ex: Seg-Sex 07h-19h">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">SLA Específico (minutos)</label>
                        <input type="number" name="sla_minutos" class="form-control form-control-sm"
                               value="<?= (int)($u['sla_minutos'] ?? 0) ?: '' ?>"
                               placeholder="Ex: 1440 (24h)" min="0">
                        <div class="form-text">Vazio = usa SLA padrão do negócio.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Modalidades Permitidas</label>
                        <input type="text" name="modalidades_permitidas" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($u['modalidades_permitidas'] ?? '') ?>"
                               placeholder="CT,MR,US,CR">
                        <div class="form-text">Separadas por vírgula.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Status</label>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="ativo" id="ativo"
                                   <?= ($u['ativo'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="ativo">Unidade Ativa</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Observações</label>
                        <textarea name="observacoes" class="form-control form-control-sm" rows="2"
                                  placeholder="Observações internas..."><?= htmlspecialchars($u['observacoes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card: Personalizado — canais institucionais do Template de Laudo -->
        <div class="card shadow-sm mb-3" id="canaisPersonalizadosCard">
            <div class="card-header bg-white py-2 border-bottom">
                <span class="fw-semibold small"><i class="fa fa-wand-magic-sparkles me-2 text-primary"></i>Personalizado</span>
            </div>
            <div class="card-body p-3">
                <p class="small text-muted mb-3">Habilite somente os itens que devem aparecer no Template de Laudo Personalizado. O QR Code aponta para a URL definida abaixo.</p>
                <div class="row g-3">
                    <?php foreach ([
                        'qrcode' => ['rótulo' => 'QR Code institucional', 'ícone' => 'fa-qrcode', 'placeholder' => 'https://site-da-unidade.com.br/resultado'],
                        'site' => ['rótulo' => 'Site institucional', 'ícone' => 'fa-globe', 'placeholder' => 'https://site-da-unidade.com.br'],
                        'instagram' => ['rótulo' => 'Instagram', 'ícone' => 'fa-instagram', 'placeholder' => 'https://instagram.com/unidade'],
                        'facebook' => ['rótulo' => 'Facebook', 'ícone' => 'fa-facebook', 'placeholder' => 'https://facebook.com/unidade'],
                    ] as $canal => $meta):
                        $habilitado = !empty($u['personalizado_' . $canal . '_habilitado']);
                    ?>
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100 custom-channel" data-custom-channel>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input custom-channel-toggle" type="checkbox"
                                       name="personalizado_<?= htmlspecialchars($canal) ?>_habilitado"
                                       id="personalizado_<?= htmlspecialchars($canal) ?>_habilitado"
                                       <?= $habilitado ? 'checked' : '' ?>>
                                <label class="form-check-label small fw-semibold" for="personalizado_<?= htmlspecialchars($canal) ?>_habilitado">
                                    <i class="fa <?= htmlspecialchars($meta['ícone']) ?> me-1 text-primary"></i><?= htmlspecialchars($meta['rótulo']) ?>
                                </label>
                            </div>
                            <label class="form-label small mb-1" for="personalizado_<?= htmlspecialchars($canal) ?>_url">URL de destino</label>
                            <input type="url" class="form-control form-control-sm custom-channel-url"
                                   name="personalizado_<?= htmlspecialchars($canal) ?>_url"
                                   id="personalizado_<?= htmlspecialchars($canal) ?>_url"
                                   value="<?= htmlspecialchars($u['personalizado_' . $canal . '_url'] ?? '') ?>"
                                   placeholder="<?= htmlspecialchars($meta['placeholder']) ?>"
                                   inputmode="url" maxlength="500">
                            <div class="form-text">Disponível no editor como <code>{{unidade.<?= htmlspecialchars($canal) ?>}}</code>.</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div><!-- /col-lg-8 -->

    <!-- ── COLUNA DIREITA ──────────────────────────────────────────────── -->
    <div class="col-12 col-lg-4">

        <!-- Card: Logo -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white py-2 border-bottom">
                <span class="fw-semibold small"><i class="fa fa-image me-2 text-primary"></i>Logo da Unidade</span>
            </div>
            <div class="card-body p-3 text-center">
                <div id="logoPreviewWrap" class="mb-3">
                    <?php if (!empty($u['logo_path'])): ?>
                    <img src="/<?= htmlspecialchars($u['logo_path']) ?>" id="logoPreview"
                         class="img-fluid rounded border" style="max-height:120px;max-width:100%;object-fit:contain;">
                    <?php else: ?>
                    <div id="logoPlaceholder" class="d-flex align-items-center justify-content-center rounded border bg-light"
                         style="height:120px;">
                        <div class="text-center text-muted">
                            <i class="fa fa-hospital fa-2x mb-2"></i>
                            <p class="small mb-0">Sem logo</p>
                        </div>
                    </div>
                    <img src="" id="logoPreview" class="img-fluid rounded border d-none"
                         style="max-height:120px;max-width:100%;object-fit:contain;">
                    <?php endif; ?>
                </div>
                <label for="logo" class="btn btn-outline-primary btn-sm w-100">
                    <i class="fa fa-upload me-1"></i>Selecionar Logo
                </label>
                <input type="file" name="logo" id="logo" class="d-none" accept=".png,.jpg,.jpeg,.svg">
                <p class="text-muted small mt-2 mb-0">PNG, JPG ou SVG — máx. 2MB</p>
                <div id="logoError" class="text-danger small mt-1 d-none"></div>
            </div>
        </div>

        <!-- Card: InstitutionName (somente leitura) -->
        <div class="card shadow-sm mb-3 border-warning-subtle">
            <div class="card-header bg-warning-subtle py-2 border-bottom border-warning-subtle">
                <span class="fw-semibold small text-warning-emphasis"><i class="fa fa-lock me-2"></i>InstitutionName DICOM</span>
            </div>
            <div class="card-body p-3">
                <p class="small text-muted mb-2">Este campo é gerado automaticamente pelo equipamento DICOM e não pode ser alterado.</p>
                <div class="bg-light rounded p-2 border font-monospace small text-break">
                    <?= htmlspecialchars($u['institution_name'] ?? '—') ?>
                </div>
            </div>
        </div>

        <!-- Card: Template de Laudo -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white py-2 border-bottom">
                <span class="fw-semibold small"><i class="fa fa-file-medical me-2 text-primary"></i>Template de Laudo</span>
            </div>
            <div class="card-body p-3">
                <?php if (empty($templatesLaudo)): ?>
                <p class="text-muted small mb-0">
                    <i class="fa fa-info-circle me-1"></i>Nenhum template disponível.
                </p>
                <?php else: ?>
                <p class="text-muted small mb-2">Layout aplicado à tela, impressão e PDF do laudo desta unidade.</p>
                <input type="hidden" name="report_layout_template_id" id="reportLayoutTemplateId"
                       value="<?= $templateAtualId ?: '' ?>">
                <div class="d-flex flex-column gap-2" id="templateLaudoGrid">
                    <?php foreach ($templatesLaudo as $tpl):
                        $tplId = (int) $tpl['id'];
                        $isPersonalizado = ($tpl['codigo'] ?? '') === 'personalizado';
                        $selecionado = $templateAtualId === $tplId || (!$templateAtualId && $tpl['codigo'] === 'classico_centralizado');
                    ?>
                    <div class="template-laudo-card-sm <?= $selecionado ? 'selected' : '' ?>"
                         data-template-id="<?= $tplId ?>" role="button" tabindex="0">
                        <div class="template-laudo-nome-sm">
                            <?= htmlspecialchars($tpl['nome']) ?>
                            <i class="fa fa-check-circle template-laudo-check-sm"></i>
                        </div>
                        <div class="template-laudo-desc-sm"><?= htmlspecialchars($tpl['descricao'] ?? '') ?></div>
                        <?php if ($isPersonalizado): ?>
                        <a href="/unidades/<?= (int) $id ?>/template-personalizado" class="btn btn-outline-primary btn-sm mt-2 w-100" onclick="event.stopPropagation();">
                            <i class="fa fa-pen-to-square me-1"></i>Editar layout
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="text-muted mt-2 mb-0" style="font-size:10px">
                    <i class="fa fa-info-circle me-1"></i>Sem escolha, usa o template padrão (Clássico Centralizado).
                </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Botões -->
        <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="fa fa-save me-2"></i>Salvar Alterações
            </button>
            <a href="/unidades" class="btn btn-outline-secondary">
                <i class="fa fa-times me-2"></i>Cancelar
            </a>
        </div>

    </div><!-- /col-lg-4 -->

</div><!-- /row -->
</form>

<style>
.template-laudo-card-sm {
    border: 2px solid #e5e7eb; border-radius: 6px; padding: .5rem .65rem; cursor: pointer;
    transition: border-color .15s, background .15s;
}
.template-laudo-card-sm:hover { border-color: #93c5fd; }
.template-laudo-card-sm.selected { border-color: #0d6efd; background: #eff6ff; }
.template-laudo-nome-sm { font-size: .78rem; font-weight: 600; display: flex; align-items: center; gap: .3rem; }
.template-laudo-check-sm { color: #0d6efd; font-size: .78rem; visibility: hidden; margin-left: auto; }
.template-laudo-card-sm.selected .template-laudo-check-sm { visibility: visible; }
.template-laudo-desc-sm { font-size: 10px; color: #6b7280; margin-top: .1rem; line-height: 1.35; }
</style>

<script>
(function () {
    const unitId = <?= $id ?>;

    // ── Seleção de Template de Laudo (único, via input hidden) ───────────
    const templateInput = document.getElementById('reportLayoutTemplateId');
    document.querySelectorAll('.template-laudo-card-sm').forEach(function (card) {
        function selecionar() {
            document.querySelectorAll('.template-laudo-card-sm').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            if (templateInput) templateInput.value = card.dataset.templateId;
        }
        card.addEventListener('click', selecionar);
        card.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selecionar(); }
        });
    });

    // ── Máscara CNPJ ──────────────────────────────────────────────────────
    document.getElementById('cnpj').addEventListener('input', function () {
        let v = this.value.replace(/\D/g, '').slice(0, 14);
        if (v.length > 12) v = v.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2})/, '$1.$2.$3/$4-$5');
        else if (v.length > 8) v = v.replace(/^(\d{2})(\d{3})(\d{3})(\d{0,4})/, '$1.$2.$3/$4');
        else if (v.length > 5) v = v.replace(/^(\d{2})(\d{3})(\d{0,3})/, '$1.$2.$3');
        else if (v.length > 2) v = v.replace(/^(\d{2})(\d{0,3})/, '$1.$2');
        this.value = v;
    });

    // ── Busca CNPJ ────────────────────────────────────────────────────────
    function buscarCnpj(force) {
        const cnpjRaw  = document.getElementById('cnpj').value.replace(/\D/g, '');
        const statusEl = document.getElementById('cnpjStatus');
        const btnBuscar = document.getElementById('btnBuscarCnpj');
        const btnForce  = document.getElementById('btnBuscarCnpjForce');

        if (cnpjRaw.length !== 14) {
            statusEl.innerHTML = '<span class="text-danger"><i class="fa fa-exclamation-circle me-1"></i>CNPJ deve ter 14 dígitos.</span>';
            return;
        }

        statusEl.innerHTML = '<span class="text-muted"><i class="fa fa-spinner fa-spin me-1"></i>Consultando...</span>';
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
                // Preencher campos
                const fill = (id, val) => { const el = document.getElementById(id); if (el && val) el.value = val; };
                fill('razao_social',  d.razao_social);
                fill('nome_fantasia', d.nome_fantasia);
                fill('logradouro',    d.logradouro);
                fill('numero',        d.numero);
                fill('complemento',   d.complemento);
                fill('bairro',        d.bairro);
                fill('cidade',        d.cidade);
                fill('estado',        d.uf);
                // CEP com máscara
                if (d.cep && d.cep.length === 8) {
                    document.getElementById('cep').value = d.cep.slice(0,5) + '-' + d.cep.slice(5);
                }
                const fonte = d.fonte_utilizada || 'api';
                const cache = res.data._from_cache ? ' (cache)' : '';
                statusEl.innerHTML = `<span class="text-success"><i class="fa fa-check-circle me-1"></i>Dados preenchidos via ${fonte}${cache}.</span>`;
                btnForce.classList.remove('d-none');
            })
            .catch(() => {
                btnBuscar.disabled = false;
                statusEl.innerHTML = '<span class="text-danger"><i class="fa fa-times-circle me-1"></i>Erro de conexão. Preencha manualmente.</span>';
            });
    }

    document.getElementById('btnBuscarCnpj').addEventListener('click', () => buscarCnpj(false));
    document.getElementById('btnBuscarCnpjForce').addEventListener('click', () => buscarCnpj(true));

    // Buscar ao sair do campo CNPJ se tiver 14 dígitos
    document.getElementById('cnpj').addEventListener('blur', function () {
        if (this.value.replace(/\D/g, '').length === 14) buscarCnpj(false);
    });

    // ── Preview de logo ───────────────────────────────────────────────────
    document.getElementById('logo').addEventListener('change', function () {
        const file    = this.files[0];
        const errEl   = document.getElementById('logoError');
        const preview = document.getElementById('logoPreview');
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

    // ── Máscara CEP ───────────────────────────────────────────────────────
    document.getElementById('cep').addEventListener('input', function () {
        let v = this.value.replace(/\D/g, '').slice(0, 8);
        if (v.length > 5) v = v.slice(0, 5) + '-' + v.slice(5);
        this.value = v;
    });
})();
</script>
