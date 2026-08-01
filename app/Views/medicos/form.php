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
                 SEÇÃO 5 — TOKEN COPILOT
            ============================================================ -->
            <?php if ($isEdit): ?>
            <div class="form-section-title mt-4" id="secaoCopilot">
                <i class="fa fa-plug me-2" style="color:#6366f1;"></i>TOKEN Copilot
            </div>

            <div class="pacs-card" style="border:1px solid #4f46e5;background:rgba(79,70,229,.06);margin-bottom:1rem;">
                <div class="pacs-card-body" style="padding:1.25rem;">

                    <!-- Descrição -->
                    <p class="text-muted small mb-3">
                        <i class="fa fa-circle-info me-1 text-pacs-primary"></i>
                        O <strong>Token Copilot</strong> vincula este médico ao <strong>VOXEL Copilot</strong>.
                        Ao gerar o token, o médico recebe autorização para laudar nesta unidade e todos os
                        webhooks de comunicação bidirecional (exames assumidos, laudos finalizados) ficam ativos.
                    </p>

                    <!-- Estado atual do token -->
                    <div id="copilotTokenStatus">
                        <?php
                        // Busca token existente para este médico
                        $copilotToken  = $copilotToken  ?? null;
                        $copilotUnidade = $copilotUnidade ?? null;
                        ?>
                        <?php if ($copilotToken): ?>
                        <!-- Token já gerado -->
                        <div class="pacs-alert" style="background:rgba(34,197,94,.1);border:1px solid #22c55e;border-radius:6px;padding:.75rem 1rem;margin-bottom:1rem;">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="fa fa-circle-check" style="color:#22c55e;"></i>
                                <strong style="color:#22c55e;">Token ativo</strong>
                                <span class="badge" style="background:#22c55e;color:#fff;font-size:.7rem;padding:.2rem .5rem;border-radius:4px;">
                                    <?= htmlspecialchars($copilotToken['status'] ?? 'ativo') ?>
                                </span>
                            </div>
                            <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:.75rem;">
                                <div>
                                    <label class="form-label-dark" style="font-size:.75rem;margin-bottom:.25rem;">Código da Unidade</label>
                                    <div class="d-flex gap-2 align-items-center">
                                        <input type="text"
                                               id="copilotCodigoUnidade"
                                               class="form-control-dark"
                                               value="<?= htmlspecialchars($copilotUnidade['codigo_unidade'] ?? '') ?>"
                                               readonly
                                               style="font-family:monospace;font-size:.85rem;background:var(--pacs-card-bg);">
                                        <button type="button"
                                                class="btn-pacs-outline"
                                                style="white-space:nowrap;padding:.35rem .7rem;font-size:.78rem;"
                                                onclick="copiarCopilot('copilotCodigoUnidade')">
                                            <i class="fa fa-copy"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted">Informe ao médico para usar no Copilot</small>
                                </div>
                                <div>
                                    <label class="form-label-dark" style="font-size:.75rem;margin-bottom:.25rem;">Token de Integração</label>
                                    <div class="d-flex gap-2 align-items-center">
                                        <input type="text"
                                               id="copilotTokenValor"
                                               class="form-control-dark"
                                               value="<?= htmlspecialchars($copilotToken['token_integracao'] ?? '') ?>"
                                               readonly
                                               style="font-family:monospace;font-size:.78rem;background:var(--pacs-card-bg);">
                                        <button type="button"
                                                class="btn-pacs-outline"
                                                style="white-space:nowrap;padding:.35rem .7rem;font-size:.78rem;"
                                                onclick="copiarCopilot('copilotTokenValor')">
                                            <i class="fa fa-copy"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted">Token único deste médico nesta unidade</small>
                                </div>
                            </div>
                            <!-- Instruções de uso -->
                            <div class="mt-3 p-3" style="background:rgba(0,0,0,.15);border-radius:6px;font-size:.8rem;">
                                <strong><i class="fa fa-book-open me-1"></i>Como usar no VOXEL Copilot:</strong>
                                <ol class="mb-0 mt-1 ps-4" style="line-height:1.8;">
                                    <li>Acesse <a href="https://demo.voxelpacs.com.br/configuracoes?tab=autorizacao" target="_blank" style="color:#818cf8;"><strong>Configurações &rarr; Autorização</strong></a> no VOXEL Copilot</li>
                                    <li>No campo <strong>Código da Unidade</strong>, informe exatamente: <code style="background:rgba(255,255,255,.1);padding:.1rem .3rem;border-radius:3px;"><?= htmlspecialchars($copilotUnidade['codigo_unidade'] ?? '') ?></code></li>
                                    <li>No campo <strong>Token de Integração</strong>, informe o token acima (começa com <code style="background:rgba(255,255,255,.1);padding:.1rem .3rem;border-radius:3px;">CPLT-</code>)</li>
                                    <li>Clique em <strong>Vincular Unidade</strong></li>
                                </ol>
                                <div class="mt-2 p-2" style="background:rgba(245,158,11,.15);border:1px solid #f59e0b;border-radius:4px;color:#fcd34d;">
                                    <i class="fa fa-triangle-exclamation me-1"></i>
                                    <strong>Atenção:</strong> No campo "Código da Unidade" informe o código acima (<code style="background:rgba(0,0,0,.2);padding:.1rem .3rem;border-radius:3px;"><?= htmlspecialchars($copilotUnidade['codigo_unidade'] ?? '') ?></code>),
                                    <strong>NÃO</strong> o e-mail nem o nome do médico.
                                </div>
                                <p class="mb-0 mt-2" style="color:#a0aec0;">
                                    Após vincular, exames assumidos por este médico aparecerão automaticamente
                                    no Workspace do Copilot.
                                </p>
                            </div>
                            <!-- Estatísticas -->
                            <div class="d-flex gap-4 mt-3" style="font-size:.8rem;color:var(--pacs-text-muted);">
                                <span><i class="fa fa-stethoscope me-1"></i>Exames: <strong><?= (int)($copilotToken['total_exames'] ?? 0) ?></strong></span>
                                <span><i class="fa fa-file-medical me-1"></i>Laudos: <strong><?= (int)($copilotToken['total_laudos'] ?? 0) ?></strong></span>
                                <?php if (!empty($copilotToken['ultimo_uso'])): ?>
                                <span><i class="fa fa-clock me-1"></i>Último uso: <strong><?= date('d/m/Y H:i', strtotime($copilotToken['ultimo_uso'])) ?></strong></span>
                                <?php endif; ?>
                                <span><i class="fa fa-calendar me-1"></i>Gerado em: <strong><?= date('d/m/Y', strtotime($copilotToken['created_at'])) ?></strong></span>
                            </div>
                        </div>
                        <!-- Botões de ação -->
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button"
                                    class="btn-pacs-outline"
                                    style="font-size:.82rem;border-color:#f59e0b;color:#f59e0b;"
                                    onclick="regenerarTokenCopilot(<?= $medicoId ?>)">
                                <i class="fa fa-rotate me-1"></i> Regenerar Token
                            </button>
                            <button type="button"
                                    class="btn-pacs-outline"
                                    style="font-size:.82rem;border-color:#e05252;color:#e05252;"
                                    onclick="revogarTokenCopilot(<?= $medicoId ?>)">
                                <i class="fa fa-ban me-1"></i> Revogar
                            </button>
                        </div>
                        <?php else: ?>
                        <!-- Sem token ainda -->
                        <div class="pacs-alert" style="background:rgba(99,102,241,.1);border:1px solid #6366f1;border-radius:6px;padding:.75rem 1rem;margin-bottom:1rem;">
                            <i class="fa fa-key me-2" style="color:#6366f1;"></i>
                            <strong>Nenhum token gerado.</strong>
                            Clique em <strong>Gerar Token Copilot</strong> para criar a integração.
                        </div>
                        <div id="copilotTokenGerado" style="display:none;" class="mb-3">
                            <!-- Preenchido via JS após geração -->
                        </div>
                        <button type="button"
                                class="btn-pacs-primary"
                                style="background:#6366f1;border-color:#6366f1;"
                                onclick="gerarTokenCopilot(<?= $medicoId ?>)">
                            <i class="fa fa-key me-1"></i> Gerar Token Copilot
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Feedback AJAX -->
                    <div id="copilotFeedback" style="display:none;margin-top:.75rem;"></div>

                </div>
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

// ─── TOKEN COPILOT ─────────────────────────────────────────────────────────
function gerarTokenCopilot(medicoId) {
    const btn = event.currentTarget;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Gerando...';
    const fb = document.getElementById('copilotFeedback');
    fb.style.display = 'none';

    fetch('/api/medicos/' + medicoId + '/copilot-token', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ acao: 'gerar' })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-key me-1"></i> Gerar Token Copilot';
        if (data.ok) {
            // Exibe o resultado sem recarregar
            const div = document.getElementById('copilotTokenGerado');
            div.style.display = 'block';
            div.innerHTML = `
                <div class="pacs-alert" style="background:rgba(34,197,94,.1);border:1px solid #22c55e;border-radius:6px;padding:.75rem 1rem;">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="fa fa-circle-check" style="color:#22c55e;"></i>
                        <strong style="color:#22c55e;">Token gerado com sucesso!</strong>
                    </div>
                    <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:.75rem;">
                        <div>
                            <label class="form-label-dark" style="font-size:.75rem;">Código da Unidade</label>
                            <div class="d-flex gap-2">
                                <input type="text" id="newCodigoUnidade" class="form-control-dark"
                                    value="${data.codigo_unidade}" readonly
                                    style="font-family:monospace;font-size:.85rem;">
                                <button type="button" class="btn-pacs-outline" style="padding:.35rem .7rem;"
                                    onclick="copiarCopilot('newCodigoUnidade')">
                                    <i class="fa fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="form-label-dark" style="font-size:.75rem;">Token de Integração</label>
                            <div class="d-flex gap-2">
                                <input type="text" id="newTokenValor" class="form-control-dark"
                                    value="${data.token_integracao}" readonly
                                    style="font-family:monospace;font-size:.78rem;">
                                <button type="button" class="btn-pacs-outline" style="padding:.35rem .7rem;"
                                    onclick="copiarCopilot('newTokenValor')">
                                    <i class="fa fa-copy"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 p-3" style="background:rgba(0,0,0,.15);border-radius:6px;font-size:.8rem;">
                        <strong><i class="fa fa-book-open me-1"></i>Como usar no VOXEL Copilot:</strong>
                        <ol class="mb-0 mt-1 ps-4" style="line-height:1.8;">
                            <li>Acesse <strong>Configurações → Autorização</strong> no VOXEL Copilot</li>
                            <li>Informe o <strong>Código da Unidade</strong> acima</li>
                            <li>Informe o <strong>Token de Integração</strong> acima</li>
                            <li>Clique em <strong>Vincular Unidade</strong></li>
                        </ol>
                    </div>
                </div>
            `;
            // Recarrega a página após 3s para mostrar o estado persistido
            setTimeout(() => location.reload(), 3000);
        } else {
            mostrarFeedbackCopilot('erro', data.msg || 'Erro ao gerar token.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-key me-1"></i> Gerar Token Copilot';
        mostrarFeedbackCopilot('erro', 'Erro de comunicação com o servidor.');
    });
}

function regenerarTokenCopilot(medicoId) {
    if (!confirm('Regenerar o token irá invalidar o token atual. O médico precisará vincular novamente no Copilot. Continuar?')) return;
    const fb = document.getElementById('copilotFeedback');
    fb.style.display = 'none';

    fetch('/api/medicos/' + medicoId + '/copilot-token', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ acao: 'regenerar' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            mostrarFeedbackCopilot('sucesso', 'Token regenerado! Recarregando...');
            setTimeout(() => location.reload(), 1500);
        } else {
            mostrarFeedbackCopilot('erro', data.msg || 'Erro ao regenerar token.');
        }
    })
    .catch(() => mostrarFeedbackCopilot('erro', 'Erro de comunicação.'));
}

function revogarTokenCopilot(medicoId) {
    if (!confirm('Revogar o token irá desativar a integração com o Copilot. Continuar?')) return;

    fetch('/api/medicos/' + medicoId + '/copilot-token', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ acao: 'revogar' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            mostrarFeedbackCopilot('sucesso', 'Token revogado. Recarregando...');
            setTimeout(() => location.reload(), 1500);
        } else {
            mostrarFeedbackCopilot('erro', data.msg || 'Erro ao revogar.');
        }
    })
    .catch(() => mostrarFeedbackCopilot('erro', 'Erro de comunicação.'));
}

function copiarCopilot(inputId) {
    const el = document.getElementById(inputId);
    if (!el) return;
    el.select();
    document.execCommand('copy');
    mostrarFeedbackCopilot('sucesso', 'Copiado para a área de transferência!');
    setTimeout(() => {
        const fb = document.getElementById('copilotFeedback');
        if (fb) fb.style.display = 'none';
    }, 2000);
}

function mostrarFeedbackCopilot(tipo, msg) {
    const fb = document.getElementById('copilotFeedback');
    if (!fb) return;
    const cor = tipo === 'sucesso' ? '#22c55e' : '#e05252';
    const ico = tipo === 'sucesso' ? 'fa-circle-check' : 'fa-triangle-exclamation';
    fb.style.display = 'block';
    fb.innerHTML = `<div style="padding:.6rem .9rem;border-radius:6px;background:rgba(0,0,0,.2);border:1px solid ${cor};color:${cor};font-size:.82rem;">
        <i class="fa ${ico} me-1"></i>${msg}
    </div>`;
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
