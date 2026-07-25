<?php
$medico           = $medico ?? null;
$isEdit           = !empty($medico) && !empty($medico['id'] ?? $medico->id ?? null);
$medicoId         = $isEdit ? (int) (is_array($medico) ? ($medico['id'] ?? 0) : ($medico->id ?? 0)) : 0;
$action           = $isEdit ? '/medicos/' . $medicoId . '/update' : '/medicos';
$usuarios         = $usuarios ?? [];
$unidades         = $unidades ?? [];
$unidadesMarcadas = $unidadesMarcadas ?? [];
$erros            = $erros ?? [];
$estados          = \App\Core\Estados::all();

// Helper para preencher campos com valor do banco ou do POST anterior
$val = function (string $campo) use ($medico): string {
    if (!$medico) return '';
    $v = is_array($medico) ? ($medico[$campo] ?? '') : ($medico->$campo ?? '');
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$usuarioIdAtual = (int) (is_array($medico) ? ($medico['usuario_id'] ?? 0) : ($medico->usuario_id ?? 0));
?>

<!-- Cabeçalho -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">
            <i class="fa fa-user-doctor me-2 text-pacs-primary"></i>
            <?= $isEdit ? 'Editar Médico' : 'Novo Médico' ?>
        </h1>
        <p class="text-muted small mb-0 mt-1">
            <?= $isEdit ? 'Atualize os dados do médico cadastrado' : 'Preencha os dados para cadastrar um novo médico' ?>
        </p>
    </div>
    <a href="/medicos" class="btn-pacs-outline">
        <i class="fa fa-arrow-left me-1"></i> Voltar
    </a>
</div>

<!-- Alertas de erro -->
<?php if (!empty($erros)): ?>
<div class="pacs-alert pacs-alert-danger mb-4" id="alertErros">
    <i class="fa fa-triangle-exclamation me-2"></i>
    <strong>Corrija os erros abaixo antes de salvar:</strong>
    <ul class="mb-0 mt-2 ps-3">
        <?php foreach ($erros as $erro): ?>
            <li><?= htmlspecialchars($erro) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Formulário principal -->
<div class="pacs-card">
    <div class="pacs-card-body">
        <form method="POST" action="<?= $action ?>" id="formMedico" novalidate>

            <!-- ============================================================
                 SEÇÃO 1 — DADOS PESSOAIS
            ============================================================ -->
            <div class="form-section-title">
                <i class="fa fa-id-card me-2"></i>Dados Pessoais
            </div>

            <!-- Nome Completo — linha inteira -->
            <div class="mb-3">
                <label class="form-label-dark" for="medicoNome">
                    Nome Completo <span class="text-danger">*</span>
                </label>
                <input type="text"
                       id="medicoNome"
                       name="nome"
                       class="form-control-dark<?= !empty($erros) && empty($val('nome')) ? ' is-invalid' : '' ?>"
                       value="<?= $val('nome') ?>"
                       placeholder="Dr. João Silva"
                       required
                       minlength="3"
                       maxlength="200"
                       autofocus>
                <div class="invalid-feedback">O nome completo é obrigatório (mínimo 3 caracteres).</div>
            </div>

            <!-- CRM | UF CRM | Especialidade -->
            <div class="form-grid" style="grid-template-columns: 160px 100px 1fr;">
                <div>
                    <label class="form-label-dark" for="medicoCrm">CRM</label>
                    <input type="text"
                           id="medicoCrm"
                           name="crm"
                           class="form-control-dark"
                           value="<?= $val('crm') ?>"
                           placeholder="123456"
                           maxlength="8"
                           inputmode="numeric">
                    <small class="text-muted">Somente números</small>
                </div>
                <div>
                    <label class="form-label-dark" for="medicoCrmUf">UF CRM</label>
                    <select id="medicoCrmUf" name="crm_uf" class="form-control-dark">
                        <option value="">—</option>
                        <?php foreach ($estados as $uf => $nomeEstado): ?>
                            <option value="<?= $uf ?>"
                                <?= strtoupper($val('crm_uf')) === $uf ? 'selected' : '' ?>>
                                <?= $uf ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label-dark" for="medicoEspecialidade">Especialidade</label>
                    <input type="text"
                           id="medicoEspecialidade"
                           name="especialidade"
                           class="form-control-dark"
                           value="<?= $val('especialidade') ?>"
                           placeholder="Radiologia e Diagnóstico por Imagem"
                           maxlength="150">
                </div>
            </div>

            <!-- ============================================================
                 SEÇÃO 2 — CONTATO
            ============================================================ -->
            <div class="form-section-title mt-4">
                <i class="fa fa-envelope me-2"></i>Contato
            </div>

            <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                <div>
                    <label class="form-label-dark" for="medicoEmail">E-mail</label>
                    <input type="email"
                           id="medicoEmail"
                           name="email"
                           class="form-control-dark"
                           value="<?= $val('email') ?>"
                           placeholder="medico@clinica.com.br"
                           maxlength="255">
                </div>
                <div>
                    <label class="form-label-dark" for="medicoTelefone">Telefone</label>
                    <input type="tel"
                           id="medicoTelefone"
                           name="telefone"
                           class="form-control-dark"
                           value="<?= $val('telefone') ?>"
                           placeholder="(11) 99999-9999"
                           maxlength="20">
                </div>
            </div>

            <!-- ============================================================
                 SEÇÃO 3 — ENDEREÇO
            ============================================================ -->
            <div class="form-section-title mt-4">
                <i class="fa fa-map-marker-alt me-2"></i>Endereço
            </div>

            <!-- CEP + busca automática -->
            <div class="form-grid" style="grid-template-columns: 160px 1fr 120px;">
                <div>
                    <label class="form-label-dark" for="medicoCep">CEP</label>
                    <div style="display:flex;gap:.4rem;">
                        <input type="text"
                               id="medicoCep"
                               name="cep"
                               class="form-control-dark"
                               value="<?= $val('cep') ?>"
                               placeholder="00000-000"
                               maxlength="9"
                               inputmode="numeric"
                               onblur="buscarCepMedico()">
                    </div>
                    <small id="medicoCepStatus" style="color:var(--pacs-text-muted);font-size:.75rem;"></small>
                </div>
                <div>
                    <label class="form-label-dark" for="medicoLogradouro">Logradouro</label>
                    <input type="text"
                           id="medicoLogradouro"
                           name="logradouro"
                           class="form-control-dark"
                           value="<?= $val('logradouro') ?>"
                           placeholder="Rua, Avenida..."
                           maxlength="255">
                </div>
                <div>
                    <label class="form-label-dark" for="medicoNumero">Número</label>
                    <input type="text"
                           id="medicoNumero"
                           name="numero"
                           class="form-control-dark"
                           value="<?= $val('numero') ?>"
                           placeholder="123"
                           maxlength="20">
                </div>
            </div>

            <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr 100px;">
                <div>
                    <label class="form-label-dark" for="medicoComplemento">Complemento</label>
                    <input type="text"
                           id="medicoComplemento"
                           name="complemento"
                           class="form-control-dark"
                           value="<?= $val('complemento') ?>"
                           placeholder="Apto, Sala..."
                           maxlength="100">
                </div>
                <div>
                    <label class="form-label-dark" for="medicoBairro">Bairro</label>
                    <input type="text"
                           id="medicoBairro"
                           name="bairro"
                           class="form-control-dark"
                           value="<?= $val('bairro') ?>"
                           maxlength="100">
                </div>
                <div>
                    <label class="form-label-dark" for="medicoCidade">Cidade</label>
                    <input type="text"
                           id="medicoCidade"
                           name="cidade"
                           class="form-control-dark"
                           value="<?= $val('cidade') ?>"
                           maxlength="100">
                </div>
                <div>
                    <label class="form-label-dark" for="medicoEstado">UF</label>
                    <select id="medicoEstado" name="estado" class="form-control-dark">
                        <option value="">—</option>
                        <?php foreach ($estados as $uf => $nomeEstado): ?>
                            <option value="<?= $uf ?>"
                                <?= strtoupper($val('estado')) === $uf ? 'selected' : '' ?>>
                                <?= $uf ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- ============================================================
                 SEÇÃO 4 — VINCULAÇÃO
            ============================================================ -->
            <div class="form-section-title mt-4">
                <i class="fa fa-link me-2"></i>Vinculação
            </div>

            <!-- Conta de usuário -->
            <div class="mb-3">
                <label class="form-label-dark" for="medicoUsuario">Conta de Usuário</label>
                <select id="medicoUsuario" name="usuario_id" class="form-control-dark" style="max-width:420px;">
                    <option value="">— Sem vínculo —</option>
                    <?php foreach ($usuarios as $u): ?>
                        <?php
                        $uId   = is_array($u) ? ($u['id'] ?? 0) : ($u->id ?? 0);
                        $uNome = is_array($u) ? ($u['name'] ?? '') : ($u->name ?? '');
                        ?>
                        <option value="<?= (int) $uId ?>"
                            <?= $uId == $usuarioIdAtual ? 'selected' : '' ?>>
                            <?= htmlspecialchars($uNome) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted d-block mt-1">
                    Necessário para que este médico possa ser alvo das Regras de SLA.
                </small>
            </div>

            <!-- Unidades DICOM -->
            <?php if (!empty($unidades)): ?>
            <div class="mb-3">
                <label class="form-label-dark">Unidades (InstitutionName DICOM)</label>
                <div style="display:flex;flex-wrap:wrap;gap:.5rem;padding:.75rem;border:1px solid var(--pacs-border,#3a3f4b);border-radius:6px;background:var(--pacs-card-bg);">
                    <?php foreach ($unidades as $unidade): ?>
                        <label class="d-flex align-items-center gap-2 px-2 py-1 rounded"
                               style="font-size:.82rem;font-weight:400;background:var(--blue-50,#eff6ff);border:1px solid var(--blue-200,#bfdbfe);cursor:pointer;">
                            <input type="checkbox"
                                   name="unidades[]"
                                   value="<?= htmlspecialchars($unidade) ?>"
                                   <?= in_array($unidade, $unidadesMarcadas, true) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($unidade) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <small class="text-muted">Selecione as unidades onde este médico atua.</small>
            </div>
            <?php endif; ?>

            <!-- ============================================================
                 BOTÕES
            ============================================================ -->
            <div class="d-flex gap-3 justify-content-end mt-4 pt-3"
                 style="border-top:1px solid var(--pacs-border);">
                <a href="/medicos" class="btn-pacs-outline">
                    <i class="fa fa-arrow-left me-1"></i> Cancelar
                </a>
                <button type="submit" class="btn-pacs-primary" id="btnSalvar">
                    <i class="fa fa-floppy-disk me-1"></i>
                    <?= $isEdit ? 'Salvar Alterações' : 'Cadastrar Médico' ?>
                </button>
            </div>

        </form>
    </div>
</div>

<script>
// ─── Máscara de telefone ───────────────────────────────────────────────────
document.getElementById('medicoTelefone')?.addEventListener('input', function () {
    let v = this.value.replace(/\D/g, '').slice(0, 11);
    if (v.length > 10) {
        v = v.replace(/^(\d{2})(\d{5})(\d{4})$/, '($1) $2-$3');
    } else if (v.length > 6) {
        v = v.replace(/^(\d{2})(\d{4})(\d{0,4})$/, '($1) $2-$3');
    } else if (v.length > 2) {
        v = v.replace(/^(\d{2})(\d{0,5})$/, '($1) $2');
    }
    this.value = v;
});

// ─── Máscara de CEP ───────────────────────────────────────────────────────
document.getElementById('medicoCep')?.addEventListener('input', function () {
    let v = this.value.replace(/\D/g, '').slice(0, 8);
    if (v.length > 5) v = v.replace(/^(\d{5})(\d{0,3})$/, '$1-$2');
    this.value = v;
});

// ─── Máscara de CRM: apenas números ──────────────────────────────────────
document.getElementById('medicoCrm')?.addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '').slice(0, 8);
});

// ─── Busca de CEP via ViaCEP ──────────────────────────────────────────────
function buscarCepMedico() {
    const cepInput = document.getElementById('medicoCep');
    const status   = document.getElementById('medicoCepStatus');
    const cep      = cepInput.value.replace(/\D/g, '');
    if (cep.length !== 8) {
        if (cep.length > 0) status.innerHTML = '<span style="color:#e05252;">CEP inválido (8 dígitos).</span>';
        return;
    }
    status.innerHTML = '<span>Buscando endereço...</span>';
    fetch('/api/medicos/cep/' + cep)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                status.innerHTML = '<span style="color:#e05252;">' + data.error + '</span>';
                return;
            }
            status.innerHTML = '';
            if (data.logradouro) document.getElementById('medicoLogradouro').value = data.logradouro;
            if (data.bairro)     document.getElementById('medicoBairro').value     = data.bairro;
            if (data.cidade)     document.getElementById('medicoCidade').value     = data.cidade;
            if (data.estado)     document.getElementById('medicoEstado').value     = data.estado;
        })
        .catch(() => {
            status.innerHTML = '<span style="color:#e05252;">Erro ao consultar o CEP.</span>';
        });
}

// ─── Loading no botão ao submeter ────────────────────────────────────────
document.getElementById('formMedico')?.addEventListener('submit', function (e) {
    const btn  = document.getElementById('btnSalvar');
    const nome = document.getElementById('medicoNome').value.trim();
    if (!nome) {
        e.preventDefault();
        document.getElementById('medicoNome').classList.add('is-invalid');
        document.getElementById('medicoNome').focus();
        return;
    }
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Salvando...';
});
</script>
