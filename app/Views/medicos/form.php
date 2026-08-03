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

<style>
/* ── Formulário de Médico — Layout Profissional ─────────────────────────── */
.medico-form-card {
    background: var(--pacs-card-bg, #1e2330);
    border: 1px solid var(--pacs-border, #2d3244);
    border-radius: 10px;
    overflow: hidden;
}

.medico-section {
    padding: 1.5rem 1.75rem;
    border-bottom: 1px solid var(--pacs-border, #2d3244);
}
.medico-section:last-child {
    border-bottom: none;
}

.medico-section-header {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--pacs-text-muted, #8892a4);
    margin-bottom: 1.25rem;
    padding-bottom: .6rem;
    border-bottom: 1px solid var(--pacs-border, #2d3244);
}
.medico-section-header i {
    color: var(--pacs-primary, #1a56db);
    font-size: .8rem;
}

/* Linha de campos */
.medico-row {
    display: grid;
    gap: 1rem;
    margin-bottom: 1rem;
}
.medico-row:last-child { margin-bottom: 0; }

/* Label */
.medico-label {
    display: block;
    font-size: .78rem;
    font-weight: 600;
    color: var(--pacs-text-muted, #8892a4);
    margin-bottom: .35rem;
    letter-spacing: .02em;
}
.medico-label .req {
    color: #e05252;
    margin-left: .15rem;
}

/* Input base */
.medico-input,
.medico-select {
    width: 100%;
    height: 38px;
    padding: 0 .75rem;
    font-size: .875rem;
    color: var(--pacs-text, #e2e8f0);
    background: var(--pacs-input-bg, #252b3b);
    border: 1px solid var(--pacs-border, #3a3f4b);
    border-radius: 6px;
    transition: border-color .15s, box-shadow .15s;
    outline: none;
    box-sizing: border-box;
}
.medico-input::placeholder { color: var(--pacs-text-muted, #6b7280); }
.medico-input:focus,
.medico-select:focus {
    border-color: var(--pacs-primary, #1a56db);
    box-shadow: 0 0 0 3px rgba(26,86,219,.18);
}
.medico-input.is-invalid { border-color: #e05252; }
.medico-input.is-invalid:focus { box-shadow: 0 0 0 3px rgba(224,82,82,.18); }

.medico-select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238892a4' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right .75rem center;
    padding-right: 2.25rem;
    cursor: pointer;
}

.medico-hint {
    font-size: .72rem;
    color: var(--pacs-text-muted, #6b7280);
    margin-top: .3rem;
    display: block;
}

/* Botões de ação do formulário */
.medico-form-footer {
    padding: 1.25rem 1.75rem;
    background: var(--pacs-card-bg, #1e2330);
    border-top: 1px solid var(--pacs-border, #2d3244);
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: .75rem;
}

/* Checkbox de unidades */
.medico-unidade-chip {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .3rem .75rem;
    font-size: .8rem;
    font-weight: 500;
    background: rgba(26,86,219,.08);
    border: 1px solid rgba(26,86,219,.25);
    border-radius: 20px;
    cursor: pointer;
    transition: background .15s, border-color .15s;
    color: var(--pacs-text, #e2e8f0);
}
.medico-unidade-chip:hover {
    background: rgba(26,86,219,.18);
    border-color: rgba(26,86,219,.5);
}
.medico-unidade-chip input[type="checkbox"] {
    accent-color: var(--pacs-primary, #1a56db);
    width: 13px;
    height: 13px;
}

/* Copilot card */
.copilot-card {
    background: rgba(99,102,241,.06);
    border: 1px solid rgba(99,102,241,.3);
    border-radius: 8px;
    padding: 1.25rem;
}
.copilot-token-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-top: .75rem;
}
@media (max-width: 640px) {
    .copilot-token-row { grid-template-columns: 1fr; }
}
.copilot-input-wrap {
    display: flex;
    gap: .4rem;
    align-items: center;
}
.copilot-input-wrap .medico-input {
    font-family: monospace;
    font-size: .82rem;
    background: var(--pacs-card-bg, #1e2330);
}
</style>

<!-- ── Cabeçalho da página ─────────────────────────────────────────────── -->
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

<!-- ── Alertas de erro ────────────────────────────────────────────────── -->
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

<!-- ── Formulário principal ───────────────────────────────────────────── -->
<form method="POST" action="<?= $action ?>" id="formMedico" novalidate>
<div class="medico-form-card">

    <!-- ══════════════════════════════════════════════════════════════════
         SEÇÃO 1 — DADOS PESSOAIS
    ══════════════════════════════════════════════════════════════════ -->
    <div class="medico-section">
        <div class="medico-section-header">
            <i class="fa fa-id-card"></i> Dados Pessoais
        </div>

        <!-- Nome Completo — linha inteira -->
        <div class="medico-row" style="grid-template-columns: 1fr;">
            <div>
                <label class="medico-label" for="medicoNome">
                    Nome Completo <span class="req">*</span>
                </label>
                <input type="text"
                       id="medicoNome"
                       name="nome"
                       class="medico-input<?= !empty($erros) && empty($val('nome')) ? ' is-invalid' : '' ?>"
                       value="<?= $val('nome') ?>"
                       placeholder="Dr. João Silva"
                       required
                       minlength="3"
                       maxlength="200"
                       autofocus>
                <div class="invalid-feedback" style="font-size:.75rem;color:#e05252;margin-top:.25rem;display:none;" id="erroNome">
                    O nome completo é obrigatório (mínimo 3 caracteres).
                </div>
            </div>
        </div>

        <!-- CRM | UF CRM | Especialidade | CPF -->
        <div class="medico-row" style="grid-template-columns: 180px 120px 1fr 180px;">
            <div>
                <label class="medico-label" for="medicoCrm">CRM</label>
                <input type="text"
                       id="medicoCrm"
                       name="crm"
                       class="medico-input"
                       value="<?= $val('crm') ?>"
                       placeholder="123456"
                       maxlength="8"
                       inputmode="numeric">
                <span class="medico-hint">Somente números</span>
            </div>
            <div>
                <label class="medico-label" for="medicoCrmUf">UF CRM</label>
                <select id="medicoCrmUf" name="crm_uf" class="medico-select">
                    <option value="">— UF —</option>
                    <?php foreach ($estados as $uf => $nomeEstado): ?>
                        <option value="<?= $uf ?>"
                            <?= strtoupper($val('crm_uf')) === $uf ? 'selected' : '' ?>>
                            <?= $uf ?> — <?= $nomeEstado ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="medico-label" for="medicoEspecialidade">Especialidade</label>
                <input type="text"
                       id="medicoEspecialidade"
                       name="especialidade"
                       class="medico-input"
                       value="<?= $val('especialidade') ?>"
                       placeholder="Radiologia e Diagnóstico por Imagem"
                       maxlength="150">
            </div>
            <div>
                <label class="medico-label" for="medicoCpf">CPF</label>
                <input type="text"
                       id="medicoCpf"
                       name="cpf"
                       class="medico-input"
                       value="<?= $val('cpf') ?>"
                       placeholder="000.000.000-00"
                       maxlength="14"
                       inputmode="numeric">
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════
         SEÇÃO 2 — CONTATO
    ══════════════════════════════════════════════════════════════════ -->
    <div class="medico-section">
        <div class="medico-section-header">
            <i class="fa fa-envelope"></i> Contato
        </div>

        <!-- E-mail | Telefone | WhatsApp -->
        <div class="medico-row" style="grid-template-columns: 1fr 200px 200px;">
            <div>
                <label class="medico-label" for="medicoEmail">E-mail</label>
                <input type="email"
                       id="medicoEmail"
                       name="email"
                       class="medico-input"
                       value="<?= $val('email') ?>"
                       placeholder="medico@clinica.com.br"
                       maxlength="255">
            </div>
            <div>
                <label class="medico-label" for="medicoTelefone">Telefone</label>
                <input type="tel"
                       id="medicoTelefone"
                       name="telefone"
                       class="medico-input"
                       value="<?= $val('telefone') ?>"
                       placeholder="(31) 99999-9999"
                       maxlength="20">
            </div>
            <div>
                <label class="medico-label" for="medicoWhatsapp">WhatsApp</label>
                <input type="tel"
                       id="medicoWhatsapp"
                       name="whatsapp"
                       class="medico-input"
                       value="<?= $val('whatsapp') ?>"
                       placeholder="(31) 99999-9999"
                       maxlength="20">
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════
         SEÇÃO 3 — ENDEREÇO
    ══════════════════════════════════════════════════════════════════ -->
    <div class="medico-section">
        <div class="medico-section-header">
            <i class="fa fa-map-marker-alt"></i> Endereço
        </div>

        <!-- Linha 1: CEP | Logradouro | Número | Complemento -->
        <div class="medico-row" style="grid-template-columns: 140px 1fr 120px 160px;">
            <div>
                <label class="medico-label" for="medicoCep">CEP</label>
                <input type="text"
                       id="medicoCep"
                       name="cep"
                       class="medico-input"
                       value="<?= $val('cep') ?>"
                       placeholder="00000-000"
                       maxlength="9"
                       inputmode="numeric"
                       onblur="buscarCepMedico()">
                <span class="medico-hint" id="medicoCepStatus"></span>
            </div>
            <div>
                <label class="medico-label" for="medicoLogradouro">Logradouro</label>
                <input type="text"
                       id="medicoLogradouro"
                       name="logradouro"
                       class="medico-input"
                       value="<?= $val('logradouro') ?>"
                       placeholder="Rua, Avenida, Travessa..."
                       maxlength="255">
            </div>
            <div>
                <label class="medico-label" for="medicoNumero">Número</label>
                <input type="text"
                       id="medicoNumero"
                       name="numero"
                       class="medico-input"
                       value="<?= $val('numero') ?>"
                       placeholder="123"
                       maxlength="20">
            </div>
            <div>
                <label class="medico-label" for="medicoComplemento">Complemento</label>
                <input type="text"
                       id="medicoComplemento"
                       name="complemento"
                       class="medico-input"
                       value="<?= $val('complemento') ?>"
                       placeholder="Apto, Sala, Bloco..."
                       maxlength="100">
            </div>
        </div>

        <!-- Linha 2: Bairro | Cidade | UF -->
        <div class="medico-row" style="grid-template-columns: 1fr 1fr 160px;">
            <div>
                <label class="medico-label" for="medicoBairro">Bairro</label>
                <input type="text"
                       id="medicoBairro"
                       name="bairro"
                       class="medico-input"
                       value="<?= $val('bairro') ?>"
                       placeholder="Nome do bairro"
                       maxlength="100">
            </div>
            <div>
                <label class="medico-label" for="medicoCidade">Cidade</label>
                <input type="text"
                       id="medicoCidade"
                       name="cidade"
                       class="medico-input"
                       value="<?= $val('cidade') ?>"
                       placeholder="Nome da cidade"
                       maxlength="100">
            </div>
            <div>
                <label class="medico-label" for="medicoEstado">Estado (UF)</label>
                <select id="medicoEstado" name="estado" class="medico-select">
                    <option value="">— UF —</option>
                    <?php foreach ($estados as $uf => $nomeEstado): ?>
                        <option value="<?= $uf ?>"
                            <?= strtoupper($val('estado')) === $uf ? 'selected' : '' ?>>
                            <?= $uf ?> — <?= $nomeEstado ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════
         SEÇÃO 4 — VINCULAÇÃO
    ══════════════════════════════════════════════════════════════════ -->
    <div class="medico-section">
        <div class="medico-section-header">
            <i class="fa fa-link"></i> Vinculação
        </div>

        <!-- Conta de usuário | Situação -->
        <div class="medico-row" style="grid-template-columns: 1fr 200px;">
            <div>
                <label class="medico-label" for="medicoUsuario">Conta de Usuário</label>
                <select id="medicoUsuario" name="usuario_id" class="medico-select">
                    <option value="">— Sem vínculo —</option>
                    <?php foreach ($usuarios as $u): ?>
                        <?php
                        $uId   = is_array($u) ? ($u['id'] ?? 0) : ($u->id ?? 0);
                        $uNome = is_array($u) ? ($u['name'] ?? '') : ($u->name ?? '');
                        $uEmail = is_array($u) ? ($u['email'] ?? '') : ($u->email ?? '');
                        ?>
                        <option value="<?= (int) $uId ?>"
                            <?= $uId == $usuarioIdAtual ? 'selected' : '' ?>>
                            <?= htmlspecialchars($uNome) ?><?= $uEmail ? ' — ' . htmlspecialchars($uEmail) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="medico-hint">
                    <i class="fa fa-circle-info me-1"></i>
                    Necessário para Regras de SLA e acesso ao sistema.
                </span>
            </div>
            <div>
                <label class="medico-label" for="medicoSituacao">Situação</label>
                <select id="medicoSituacao" name="situacao" class="medico-select">
                    <option value="ativo"   <?= $val('situacao') !== 'inativo' ? 'selected' : '' ?>>Ativo</option>
                    <option value="inativo" <?= $val('situacao') === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                </select>
            </div>
        </div>

        <!-- Unidades DICOM -->
        <?php if (!empty($unidades)): ?>
        <div>
            <label class="medico-label">Unidades (InstitutionName DICOM)</label>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;padding:.85rem;background:var(--pacs-input-bg,#252b3b);border:1px solid var(--pacs-border,#3a3f4b);border-radius:6px;">
                <?php foreach ($unidades as $unidade): ?>
                    <label class="medico-unidade-chip">
                        <input type="checkbox"
                               name="unidades[]"
                               value="<?= htmlspecialchars($unidade) ?>"
                               <?= in_array($unidade, $unidadesMarcadas, true) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($unidade) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <span class="medico-hint">Selecione as unidades onde este médico atua.</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════
         SEÇÃO 5 — TOKEN COPILOT (somente edição)
    ══════════════════════════════════════════════════════════════════ -->
    <?php if ($isEdit): ?>
    <div class="medico-section">
        <div class="medico-section-header">
            <i class="fa fa-plug" style="color:#6366f1;"></i>
            <span style="color:#6366f1;">TOKEN Copilot</span>
        </div>

        <div class="copilot-card">
            <p class="text-muted small mb-3">
                <i class="fa fa-circle-info me-1 text-pacs-primary"></i>
                O <strong>Token Copilot</strong> vincula este médico ao <strong>VOXEL Copilot</strong>.
                Ao gerar o token, o médico recebe autorização para laudar nesta unidade e todos os
                webhooks de comunicação bidirecional ficam ativos.
            </p>

            <div id="copilotTokenStatus">
                <?php
                $copilotToken   = $copilotToken  ?? null;
                $copilotUnidade = $copilotUnidade ?? null;
                ?>
                <?php if ($copilotToken): ?>
                <div style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.3);border-radius:6px;padding:.85rem 1rem;margin-bottom:.85rem;">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <i class="fa fa-circle-check" style="color:#22c55e;"></i>
                        <strong style="color:#22c55e;">Token ativo</strong>
                        <span class="badge" style="background:#22c55e;color:#fff;font-size:.68rem;padding:.2rem .5rem;border-radius:4px;">
                            <?= htmlspecialchars($copilotToken['status'] ?? 'ativo') ?>
                        </span>
                    </div>
                    <div class="copilot-token-row">
                        <div>
                            <label class="medico-label">Código da Unidade</label>
                            <div class="copilot-input-wrap">
                                <input type="text"
                                       id="copilotCodigoUnidade"
                                       class="medico-input"
                                       value="<?= htmlspecialchars($copilotUnidade['codigo_unidade'] ?? '') ?>"
                                       readonly>
                                <button type="button"
                                        class="btn-pacs-outline"
                                        style="white-space:nowrap;padding:.35rem .7rem;font-size:.78rem;flex-shrink:0;"
                                        onclick="copiarCopilot('copilotCodigoUnidade')">
                                    <i class="fa fa-copy"></i>
                                </button>
                            </div>
                            <span class="medico-hint">Informe ao médico para usar no Copilot</span>
                        </div>
                        <div>
                            <label class="medico-label">Token de Integração</label>
                            <div class="copilot-input-wrap">
                                <input type="text"
                                       id="copilotTokenValor"
                                       class="medico-input"
                                       value="<?= htmlspecialchars($copilotToken['token_integracao'] ?? '') ?>"
                                       readonly>
                                <button type="button"
                                        class="btn-pacs-outline"
                                        style="white-space:nowrap;padding:.35rem .7rem;font-size:.78rem;flex-shrink:0;"
                                        onclick="copiarCopilot('copilotTokenValor')">
                                    <i class="fa fa-copy"></i>
                                </button>
                            </div>
                            <span class="medico-hint">Token único deste médico nesta unidade</span>
                        </div>
                    </div>

                    <!-- Instruções de uso -->
                    <div class="mt-3 p-3" style="background:rgba(0,0,0,.15);border-radius:6px;font-size:.8rem;">
                        <strong><i class="fa fa-book-open me-1"></i>Como usar no VOXEL Copilot:</strong>
                        <ol class="mb-0 mt-1 ps-4" style="line-height:1.8;">
                            <li>Acesse <a href="https://demo.voxelpacs.com.br/configuracoes?tab=autorizacao" target="_blank" style="color:#818cf8;"><strong>Configurações &rarr; Autorização</strong></a> no VOXEL Copilot</li>
                            <li>No campo <strong>Código da Unidade</strong>, informe: <code style="background:rgba(255,255,255,.1);padding:.1rem .3rem;border-radius:3px;"><?= htmlspecialchars($copilotUnidade['codigo_unidade'] ?? '') ?></code></li>
                            <li>No campo <strong>Token de Integração</strong>, informe o token acima</li>
                            <li>Clique em <strong>Vincular Unidade</strong></li>
                        </ol>
                        <div class="mt-2 p-2" style="background:rgba(245,158,11,.12);border:1px solid #f59e0b;border-radius:4px;color:#fcd34d;font-size:.78rem;">
                            <i class="fa fa-triangle-exclamation me-1"></i>
                            <strong>Atenção:</strong> Use o <strong>Código da Unidade</strong> acima — <strong>NÃO</strong> o e-mail nem o nome do médico.
                        </div>
                    </div>

                    <!-- Estatísticas -->
                    <div class="d-flex gap-4 mt-3" style="font-size:.78rem;color:var(--pacs-text-muted);">
                        <span><i class="fa fa-stethoscope me-1"></i>Exames: <strong><?= (int)($copilotToken['total_exames'] ?? 0) ?></strong></span>
                        <span><i class="fa fa-file-medical me-1"></i>Laudos: <strong><?= (int)($copilotToken['total_laudos'] ?? 0) ?></strong></span>
                        <?php if (!empty($copilotToken['ultimo_uso'])): ?>
                        <span><i class="fa fa-clock me-1"></i>Último uso: <strong><?= date('d/m/Y H:i', strtotime($copilotToken['ultimo_uso'])) ?></strong></span>
                        <?php endif; ?>
                        <span><i class="fa fa-calendar me-1"></i>Gerado em: <strong><?= date('d/m/Y', strtotime($copilotToken['created_at'])) ?></strong></span>
                    </div>
                </div>

                <!-- Botões de ação do token -->
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
                <div style="background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.3);border-radius:6px;padding:.75rem 1rem;margin-bottom:.85rem;font-size:.85rem;">
                    <i class="fa fa-key me-2" style="color:#6366f1;"></i>
                    <strong>Nenhum token gerado.</strong>
                    Clique em <strong>Gerar Token Copilot</strong> para criar a integração.
                </div>
                <div id="copilotTokenGerado" style="display:none;" class="mb-3"></div>
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

    <!-- ══════════════════════════════════════════════════════════════════
         RODAPÉ — BOTÕES
    ══════════════════════════════════════════════════════════════════ -->
    <div class="medico-form-footer">
        <a href="/medicos" class="btn-pacs-outline">
            <i class="fa fa-arrow-left me-1"></i> Cancelar
        </a>
        <button type="submit" class="btn-pacs-primary" id="btnSalvar">
            <i class="fa fa-floppy-disk me-1"></i>
            <?= $isEdit ? 'Salvar Alterações' : 'Cadastrar Médico' ?>
        </button>
    </div>

</div>
</form>

<script>
// ─── Máscara de telefone ───────────────────────────────────────────────────
function mascaraTelefone(input) {
    let v = input.value.replace(/\D/g, '').slice(0, 11);
    if (v.length > 10) {
        v = v.replace(/^(\d{2})(\d{5})(\d{4})$/, '($1) $2-$3');
    } else if (v.length > 6) {
        v = v.replace(/^(\d{2})(\d{4})(\d{0,4})$/, '($1) $2-$3');
    } else if (v.length > 2) {
        v = v.replace(/^(\d{2})(\d{0,5})$/, '($1) $2');
    }
    input.value = v;
}
document.getElementById('medicoTelefone')?.addEventListener('input', function () { mascaraTelefone(this); });
document.getElementById('medicoWhatsapp')?.addEventListener('input', function () { mascaraTelefone(this); });

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

// ─── Máscara de CPF ───────────────────────────────────────────────────────
document.getElementById('medicoCpf')?.addEventListener('input', function () {
    let v = this.value.replace(/\D/g, '').slice(0, 11);
    if (v.length > 9) v = v.replace(/^(\d{3})(\d{3})(\d{3})(\d{0,2})$/, '$1.$2.$3-$4');
    else if (v.length > 6) v = v.replace(/^(\d{3})(\d{3})(\d{0,3})$/, '$1.$2.$3');
    else if (v.length > 3) v = v.replace(/^(\d{3})(\d{0,3})$/, '$1.$2');
    this.value = v;
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
            status.innerHTML = '<span style="color:#22c55e;"><i class="fa fa-check me-1"></i>Endereço encontrado.</span>';
            if (data.logradouro) document.getElementById('medicoLogradouro').value = data.logradouro;
            if (data.bairro)     document.getElementById('medicoBairro').value     = data.bairro;
            if (data.cidade)     document.getElementById('medicoCidade').value     = data.cidade;
            if (data.estado)     document.getElementById('medicoEstado').value     = data.estado;
            // Foca no campo Número após preencher automaticamente
            document.getElementById('medicoNumero')?.focus();
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
            const div = document.getElementById('copilotTokenGerado');
            div.style.display = 'block';
            div.innerHTML = `
                <div style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.3);border-radius:6px;padding:.85rem 1rem;">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <i class="fa fa-circle-check" style="color:#22c55e;"></i>
                        <strong style="color:#22c55e;">Token gerado com sucesso!</strong>
                    </div>
                    <div class="copilot-token-row">
                        <div>
                            <label class="medico-label">Código da Unidade</label>
                            <div class="copilot-input-wrap">
                                <input type="text" id="newCodigoUnidade" class="medico-input"
                                    value="${data.codigo_unidade}" readonly>
                                <button type="button" class="btn-pacs-outline" style="padding:.35rem .7rem;flex-shrink:0;"
                                    onclick="copiarCopilot('newCodigoUnidade')">
                                    <i class="fa fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="medico-label">Token de Integração</label>
                            <div class="copilot-input-wrap">
                                <input type="text" id="newTokenValor" class="medico-input"
                                    value="${data.token_integracao}" readonly>
                                <button type="button" class="btn-pacs-outline" style="padding:.35rem .7rem;flex-shrink:0;"
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

// ─── Loading no botão ao submeter ─────────────────────────────────────────
document.getElementById('formMedico')?.addEventListener('submit', function (e) {
    const btn  = document.getElementById('btnSalvar');
    const nome = document.getElementById('medicoNome').value.trim();
    if (!nome || nome.length < 3) {
        e.preventDefault();
        const input = document.getElementById('medicoNome');
        input.classList.add('is-invalid');
        input.focus();
        const erroEl = document.getElementById('erroNome');
        if (erroEl) erroEl.style.display = 'block';
        return;
    }
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Salvando...';
});

// Remove is-invalid ao digitar
document.getElementById('medicoNome')?.addEventListener('input', function () {
    this.classList.remove('is-invalid');
    const erroEl = document.getElementById('erroNome');
    if (erroEl) erroEl.style.display = 'none';
});
</script>
