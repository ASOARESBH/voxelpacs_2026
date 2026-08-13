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

// Aba inicial (só relevante em modo edição, onde a tela usa abas) — aceita
// ?aba=dados|copilot|mascaras, caindo em 'dados' para qualquer valor inválido/ausente.
$abasValidas = ['dados', 'copilot', 'mascaras', 'assinatura'];
$abaAtiva    = in_array($_GET['aba'] ?? '', $abasValidas, true) ? $_GET['aba'] : 'dados';

// Helper para preencher campos com valor do banco ou do POST anterior
$val = function (string $campo) use ($medico): string {
    if (!$medico) return '';
    $v = is_array($medico) ? ($medico[$campo] ?? '') : ($medico->$campo ?? '');
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$usuarioIdAtual = (int) (is_array($medico) ? ($medico['usuario_id'] ?? 0) : ($medico->usuario_id ?? 0));
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css">
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

/* ── Card de máscara ─────────────────────────────────────────────────────────────────── */
.mascara-card {
    background: var(--pacs-card-bg, #1e2330);
    border: 1px solid var(--pacs-border, #2d3244);
    border-radius: 8px;
    padding: .85rem 1rem;
    display: flex;
    align-items: center;
    gap: .75rem;
    cursor: pointer;
    transition: border-color .15s, background .15s;
    margin-bottom: .5rem;
}
.mascara-card:hover { border-color: var(--pacs-primary, #1a56db); background: rgba(26,86,219,.04); }
.mascara-card-icon { font-size: 1.1rem; color: var(--pacs-primary, #1a56db); flex-shrink: 0; }
.mascara-card-info { flex: 1; min-width: 0; }
.mascara-card-nome { font-size: .88rem; font-weight: 600; color: var(--pacs-text, #e2e8f0); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mascara-card-meta { font-size: .72rem; color: var(--pacs-text-muted, #8892a4); margin-top: .15rem; }
.mascara-card-actions { display: flex; gap: .35rem; flex-shrink: 0; }

/* Toolbar de ações da aba Máscaras: botões textuais, sem sobreposição. */
.medico-mascaras-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
}
.medico-mascaras-toolbar {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: .6rem;
    flex: 0 0 auto;
    flex-wrap: wrap;
}
.medico-mascaras-toolbar .btn-pacs-outline,
.medico-mascaras-toolbar .btn-pacs-primary {
    align-items: center;
    justify-content: center;
    white-space: nowrap;
}
@media (max-width: 680px) {
    .medico-mascaras-header {
        align-items: flex-start;
        flex-wrap: wrap;
    }
    .medico-mascaras-toolbar {
        width: 100%;
        justify-content: flex-start;
    }
}
@media (max-width: 420px) {
    .medico-mascaras-toolbar .btn-pacs-outline,
    .medico-mascaras-toolbar .btn-pacs-primary {
        flex: 1 1 100%;
    }
}

.mascara-badge { display: inline-flex; align-items: center; gap: .25rem; padding: .15rem .5rem; border-radius: 4px; font-size: .68rem; font-weight: 600; }
.mascara-badge-modal { background: rgba(26,86,219,.15); color: #60a5fa; }
.mascara-badge-shared { background: rgba(34,197,94,.12); color: #4ade80; }
.mascara-badge-auto { background: rgba(168,85,247,.12); color: #c084fc; }

/* ── Editor Quill da máscara: somente negrito por requisito clínico ───────── */
.mascara-editor-section { margin-bottom: .9rem; }
.mascara-editor-label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--pacs-text-muted, #8892a4); margin-bottom: .3rem; display: block; }
.mascara-editor-section .ql-toolbar.ql-snow {
    padding: .35rem .5rem;
    background: var(--pacs-card-bg, #1e2330);
    border: 1px solid var(--pacs-border, #3a3f4b);
    border-bottom: 0;
    border-radius: 6px 6px 0 0;
}
.mascara-editor-section .ql-toolbar .ql-stroke { stroke: var(--pacs-text-muted, #8892a4); }
.mascara-editor-section .ql-toolbar .ql-fill { fill: var(--pacs-text-muted, #8892a4); }
.mascara-editor-section .ql-toolbar button:hover .ql-stroke,
.mascara-editor-section .ql-toolbar button.ql-active .ql-stroke { stroke: var(--pacs-primary, #60a5fa); }
.mascara-editor-section .ql-toolbar button:hover .ql-fill,
.mascara-editor-section .ql-toolbar button.ql-active .ql-fill { fill: var(--pacs-primary, #60a5fa); }
.mascara-editor-body.ql-container.ql-snow {
    min-height: 104px;
    background: var(--pacs-input-bg, #252b3b);
    border: 1px solid var(--pacs-border, #3a3f4b);
    border-radius: 0 0 6px 6px;
    color: var(--pacs-text, #e2e8f0);
    font-size: .875rem;
    line-height: 1.6;
}
.mascara-editor-body .ql-editor { min-height: 102px; padding: .6rem .75rem; }
.mascara-editor-body .ql-editor.ql-blank::before { color: var(--pacs-text-muted, #6b7280); font-style: normal; }
.mascara-editor-body:focus-within { box-shadow: 0 0 0 3px rgba(26,86,219,.15); }

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

/* ── Abas (Dados do Médico / Copilot do Laudo / Máscaras) ────────────────── */
.medico-tabs-bar {
    background: var(--pacs-card-bg, #1e2330);
    border-bottom: 1px solid var(--pacs-border, #2d3244);
    padding: 0 .75rem;
}
.medico-tabs {
    display: flex;
    align-items: center;
    gap: .25rem;
    list-style: none;
    margin: 0;
    padding: 0;
    overflow-x: auto;
    flex-wrap: nowrap;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
}
.medico-tab-item { flex-shrink: 0; }
.medico-tab-btn {
    display: flex;
    align-items: center;
    gap: .45rem;
    white-space: nowrap;
    padding: .85rem 1rem;
    font-size: .82rem;
    font-weight: 600;
    color: var(--pacs-text-muted, #8892a4);
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    transition: color .15s, border-color .15s;
}
.medico-tab-btn:hover { color: var(--pacs-text, #e2e8f0); }
.medico-tab-btn.active {
    color: var(--pacs-primary, #1a56db);
    border-bottom-color: var(--pacs-primary, #1a56db);
}
.medico-tab-badge {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #e05252;
    flex-shrink: 0;
}

/* Estado vazio — aba Máscaras */
.medico-mascaras-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 3.5rem 1.5rem;
    color: var(--pacs-text-muted, #8892a4);
}
.medico-mascaras-empty i {
    font-size: 2.25rem;
    color: var(--pacs-border, #3a3f4b);
    margin-bottom: 1rem;
}
.medico-mascaras-empty h3 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--pacs-text, #e2e8f0);
    margin: 0 0 .4rem;
}
.medico-mascaras-empty p {
    font-size: .85rem;
    max-width: 380px;
    margin: 0;
}

/* ── Aba Assinatura ───────────────────────────────────────────────────── */
.ass-bloco-conteudo { display: grid; grid-template-columns: 220px 1fr; gap: 1.5rem; align-items: start; }
@media (max-width: 720px) { .ass-bloco-conteudo { grid-template-columns: 1fr; } }
.ass-preview {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: .5rem; height: 140px; border: 1px dashed var(--pacs-border, #3a3f4b); border-radius: 8px;
    background: #fff; color: #9ca3af; font-size: .75rem; overflow: hidden; padding: .5rem;
}
.ass-preview i { font-size: 1.5rem; color: var(--pacs-border, #3a3f4b); }
.ass-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
.ass-controles { display: flex; flex-direction: column; gap: .5rem; }
.ass-botoes { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .25rem; }
.ass-canvas { background: #fff; border: 1px solid var(--pacs-border, #3a3f4b); border-radius: 6px; cursor: crosshair; touch-action: none; max-width: 100%; }
.ass-badge-ativa {
    display: inline-flex; align-items: center; gap: .3rem; background: rgba(34,197,94,.12); color: #22c55e;
    border: 1px solid rgba(34,197,94,.3); border-radius: 20px; padding: .15rem .65rem; font-size: .72rem; font-weight: 600;
}
.ass-badge-inativa {
    display: inline-flex; align-items: center; gap: .3rem; background: rgba(245,158,11,.12); color: #f59e0b;
    border: 1px solid rgba(245,158,11,.3); border-radius: 20px; padding: .15rem .65rem; font-size: .72rem; font-weight: 600;
}
.ass-badge-em-breve {
    display: inline-flex; margin-left: .6rem; background: rgba(148,163,184,.15); color: #94a3b8;
    border-radius: 20px; padding: .1rem .6rem; font-size: .68rem; font-weight: 600; text-transform: none; letter-spacing: 0;
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

<?php if ($isEdit): ?>
    <!-- ══════════════════════════════════════════════════════════════════
         ABAS — Dados do Médico / Copilot do Laudo / Máscaras
    ══════════════════════════════════════════════════════════════════ -->
    <div class="medico-tabs-bar">
        <ul class="medico-tabs" id="medicoTabs" role="tablist">
            <li class="medico-tab-item" role="presentation">
                <button class="medico-tab-btn <?= $abaAtiva === 'dados' ? 'active' : '' ?>" id="tab-dados"
                        data-bs-toggle="tab" data-bs-target="#aba-dados" type="button"
                        role="tab" aria-controls="aba-dados">
                    <i class="fa fa-id-card"></i> <?= htmlspecialchars(t('medicos.form.aba_dados')) ?>
                    <span class="medico-tab-badge" id="badge-tab-dados" style="display:none;"></span>
                </button>
            </li>
            <li class="medico-tab-item" role="presentation">
                <button class="medico-tab-btn <?= $abaAtiva === 'copilot' ? 'active' : '' ?>" id="tab-copilot"
                        data-bs-toggle="tab" data-bs-target="#aba-copilot" type="button"
                        role="tab" aria-controls="aba-copilot">
                    <i class="fa fa-robot"></i> <?= htmlspecialchars(t('medicos.form.aba_copilot')) ?>
                    <span class="medico-tab-badge" id="badge-tab-copilot" style="display:none;"></span>
                </button>
            </li>
            <li class="medico-tab-item" role="presentation">
                <button class="medico-tab-btn <?= $abaAtiva === 'mascaras' ? 'active' : '' ?>" id="tab-mascaras"
                        data-bs-toggle="tab" data-bs-target="#aba-mascaras" type="button"
                        role="tab" aria-controls="aba-mascaras">
                    <i class="fa fa-layer-group"></i> <?= htmlspecialchars(t('medicos.form.aba_mascaras')) ?>
                    <span class="medico-tab-badge" id="badge-tab-mascaras" style="display:none;"></span>
                </button>
            </li>
            <li class="medico-tab-item" role="presentation">
                <button class="medico-tab-btn <?= $abaAtiva === 'assinatura' ? 'active' : '' ?>" id="tab-assinatura"
                        data-bs-toggle="tab" data-bs-target="#aba-assinatura" type="button"
                        role="tab" aria-controls="aba-assinatura">
                    <i class="fa fa-signature"></i> <?= htmlspecialchars(t('medicos.form.aba_assinatura')) ?>
                    <span class="medico-tab-badge" id="badge-tab-assinatura" style="display:none;"></span>
                </button>
            </li>
        </ul>
    </div>
    <div class="tab-content" id="medicoTabsContent">
    <div class="tab-pane fade<?= $abaAtiva === 'dados' ? ' show active' : '' ?>" id="aba-dados" role="tabpanel">
<?php endif; ?>

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

<?php if ($isEdit): ?>
    </div><!-- fecha #aba-dados -->

    <div class="tab-pane fade<?= $abaAtiva === 'copilot' ? ' show active' : '' ?>" id="aba-copilot" role="tabpanel">
<?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════════════
         SEÇÃO 5 — WORKSPACE LAUDO VOXEL (somente edição)
    ══════════════════════════════════════════════════════════════════ -->
    <?php if ($isEdit): ?>
    <?php
    $workspaceHabilitado = !empty($medico['workspace_laudo_habilitado']);
    ?>
    <div class="medico-section" id="workspaceLaudoSection">
        <div class="medico-section-header">
            <i class="fa fa-robot" style="color:#7c3aed;"></i>
            <span style="color:#7c3aed;">VOXEL Copilot — Modo de Laudário</span>
        </div>

        <!-- Descrição do modo ativo -->
        <div style="display:flex;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;">
            <div style="flex:1;min-width:220px;padding:.75rem 1rem;
                        background:<?= $workspaceHabilitado ? 'rgba(124,58,237,.1)' : 'rgba(14,165,233,.08)' ?>;
                        border:1px solid <?= $workspaceHabilitado ? 'rgba(124,58,237,.35)' : 'rgba(14,165,233,.3)' ?>;
                        border-radius:8px;">
                <div style="font-size:.78rem;font-weight:700;color:<?= $workspaceHabilitado ? '#a78bfa' : '#38bdf8' ?>;margin-bottom:.3rem;">
                    <?php if ($workspaceHabilitado): ?>
                    <i class="fa fa-robot me-1"></i> MODO ATIVO: VOXEL Copilot
                    <?php else: ?>
                    <i class="fa fa-file-medical me-1"></i> MODO ATIVO: Laudário Interno VOXEL PACS
                    <?php endif; ?>
                </div>
                <div style="font-size:.82rem;color:var(--pacs-text-muted);">
                    <?php if ($workspaceHabilitado): ?>
                    O médico lauda externamente em <strong style="color:#a78bfa;">demo.voxelpacs.com.br</strong>.
                    O botão Laudo na worklist fica <strong>oculto</strong>.
                    <?php else: ?>
                    O médico lauda internamente em <strong style="color:#38bdf8;">/reports/</strong>.
                    O botão Laudo na worklist fica <strong>ativo</strong>.
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;">
            <!-- Toggle switch -->
            <label class="wl-toggle-wrap" style="display:flex;align-items:center;gap:.75rem;cursor:pointer;user-select:none;">
                <div class="wl-toggle" id="workspaceLaudoToggle"
                     data-medico-id="<?= (int)($medicoId ?? 0) ?>"
                     data-ativo="<?= $workspaceHabilitado ? '1' : '0' ?>"
                     style="position:relative;width:52px;height:28px;border-radius:14px;
                            background:<?= $workspaceHabilitado ? '#7c3aed' : '#0ea5e9' ?>;
                            transition:background .25s;cursor:pointer;"
                     onclick="toggleWorkspaceLaudo(this)">
                    <span style="position:absolute;top:3px;left:<?= $workspaceHabilitado ? '27px' : '3px' ?>;
                                 width:22px;height:22px;border-radius:50%;background:#fff;
                                 box-shadow:0 1px 4px rgba(0,0,0,.2);transition:left .25s;
                                 display:block;" id="workspaceLaudoThumb"></span>
                </div>
                <span style="font-size:.9rem;font-weight:600;color:<?= $workspaceHabilitado ? '#a78bfa' : '#38bdf8' ?>;"
                      id="workspaceLaudoLabel">
                    <?= $workspaceHabilitado ? 'VOXEL Copilot' : 'Laudário Interno' ?>
                </span>
            </label>
            <!-- Status badge -->
            <?php if ($workspaceHabilitado): ?>
            <span style="background:rgba(124,58,237,.12);color:#a78bfa;border:1px solid rgba(124,58,237,.3);
                         border-radius:20px;padding:.2rem .75rem;font-size:.78rem;font-weight:600;">
                <i class="fa fa-robot me-1"></i> Copilot ativo — lauda em demo.voxelpacs.com.br
            </span>
            <?php else: ?>
            <span style="background:rgba(14,165,233,.1);color:#38bdf8;border:1px solid rgba(14,165,233,.3);
                         border-radius:20px;padding:.2rem .75rem;font-size:.78rem;font-weight:600;">
                <i class="fa fa-file-medical me-1"></i> Laudário Interno ativo — botão Laudo visível na worklist
            </span>
            <?php endif; ?>
        </div>

        <p style="margin-top:.75rem;font-size:.82rem;color:var(--pacs-text-muted);">
            <i class="fa fa-circle-info me-1"></i>
            <strong>Habilitado (VOXEL Copilot):</strong> o médico lauda no sistema externo
            <a href="https://demo.voxelpacs.com.br" target="_blank" style="color:#a78bfa;">demo.voxelpacs.com.br</a>.
            O botão Laudo fica oculto na worklist e o Token Copilot permanece ativo.
            &nbsp;|&nbsp;
            <strong>Desabilitado (Laudário Interno):</strong> o médico lauda diretamente no
            <a href="/reports/" target="_blank" style="color:#38bdf8;">Laudário VOXEL PACS</a>.
            O botão Laudo fica ativo na worklist após assumir o estudo.
        </p>
        <div id="workspaceLaudoFeedback" style="display:none;margin-top:.5rem;"></div>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════════════
         SEÇÃO 6 — PERMISSÕES DE WORKLIST (somente edição)
    ══════════════════════════════════════════════════════════════════ -->
    <?php if ($isEdit): ?>
    <?php
    $verMedicoLaudo = !empty($medico['ver_medico_laudo']);
    ?>
    <div class="medico-section" id="permissoesWorklist">
        <div class="medico-section-header">
            <i class="fa fa-shield-halved" style="color:#0ea5e9;"></i>
            <span style="color:#0ea5e9;">Permissões de Worklist</span>
        </div>
        <p style="font-size:.82rem;color:var(--pacs-text-muted);margin-bottom:1rem;">
            <i class="fa fa-circle-info me-1"></i>
            Controle o que este médico pode visualizar na coluna <strong>Médico</strong> da Gestão de Exames.
            Por padrão, cada médico vê apenas o próprio nome quando é o responsável pelo laudo.
        </p>
        <!-- Permissão: ver_medico_laudo -->
        <div style="display:flex;align-items:flex-start;gap:1rem;padding:.85rem 1rem;
                    background:rgba(14,165,233,.06);border:1px solid rgba(14,165,233,.2);
                    border-radius:8px;margin-bottom:.75rem;">
            <div style="flex:1;">
                <div style="font-size:.88rem;font-weight:700;color:var(--pacs-text);margin-bottom:.2rem;">
                    <i class="fa fa-eye me-1" style="color:#0ea5e9;"></i>
                    Ver médico responsável de outros laudos
                </div>
                <div style="font-size:.78rem;color:var(--pacs-text-muted);">
                    Quando habilitado, este médico pode ver o nome do médico responsável
                    pelo laudo de <em>outros</em> médicos na coluna <strong>Médico</strong> da worklist.
                    Quando desabilitado, vê apenas o próprio nome (quando é o responsável).
                </div>
            </div>
            <label class="wl-toggle-wrap" style="display:flex;align-items:center;gap:.6rem;cursor:pointer;user-select:none;flex-shrink:0;">
                <div class="wl-toggle" id="verMedicoLaudoToggle"
                     data-medico-id="<?= (int)($medicoId ?? 0) ?>"
                     data-ativo="<?= $verMedicoLaudo ? '1' : '0' ?>"
                     style="position:relative;width:44px;height:24px;border-radius:12px;
                            background:<?= $verMedicoLaudo ? '#0ea5e9' : '#374151' ?>;
                            transition:background .25s;cursor:pointer;"
                     onclick="toggleVerMedicoLaudo(this)">
                    <span style="position:absolute;top:2px;left:<?= $verMedicoLaudo ? '22px' : '2px' ?>;
                                 width:20px;height:20px;border-radius:50%;background:#fff;
                                 box-shadow:0 1px 4px rgba(0,0,0,.2);transition:left .25s;
                                 display:block;" id="verMedicoLaudoThumb"></span>
                </div>
                <span style="font-size:.82rem;font-weight:600;color:<?= $verMedicoLaudo ? '#0ea5e9' : 'var(--pacs-text-muted)' ?>;"
                      id="verMedicoLaudoLabel">
                    <?= $verMedicoLaudo ? 'Habilitado' : 'Desabilitado' ?>
                </span>
            </label>
        </div>
        <div id="verMedicoLaudoFeedback" style="display:none;margin-top:.5rem;"></div>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════════════
         SEÇÃO 7 — TOKEN COPILOT (somente edição)
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

<?php if ($isEdit): ?>
    </div><!-- fecha #aba-copilot -->

    <!-- ══════════════════════════════════════════════════════════════════
         ABA — MÁSCARAS (CRUD completo — reconectado aqui após o bug de
         duplicação de abas; conteúdo vinha renderizando fora do form e
         nunca ficava visível, ver modules/editar-medico.md)
    ══════════════════════════════════════════════════════════════════ -->
    <div class="tab-pane fade<?= $abaAtiva === 'mascaras' ? ' show active' : '' ?>" id="aba-mascaras" role="tabpanel">
        <div class="medico-section">
            <div class="medico-section-header medico-mascaras-header">
                <span><i class="fa fa-layer-group"></i> Máscaras de Laudo</span>
                <div class="medico-mascaras-toolbar">
                    <button type="button" class="btn-pacs-outline" onclick="abrirImportarMascara()" title="Importar máscaras de um arquivo DOCX">
                        <i class="fa fa-file-import"></i><span>Importar DOCX</span>
                    </button>
                    <button type="button" class="btn-pacs-primary" onclick="abrirNovaMascara()">
                        <i class="fa fa-plus"></i><span>Nova Máscara</span>
                    </button>
                </div>
            </div>

            <!-- Filtros -->
            <div style="display:flex;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center;">
                <input type="text" id="mascaraBusca" class="medico-input" style="max-width:280px;"
                       placeholder="Buscar por nome ou descrição..." oninput="filtrarMascaras()">
                <select id="mascaraFiltroModal" class="medico-select" style="max-width:160px;" onchange="filtrarMascaras()">
                    <option value="">Todas as modalidades</option>
                    <option value="CR">CR — Radiografia</option>
                    <option value="CT">CT — Tomografia</option>
                    <option value="MR">MR — Ressonância</option>
                    <option value="US">US — Ultrassom</option>
                    <option value="DX">DX — Digital</option>
                    <option value="MG">MG — Mamografia</option>
                    <option value="NM">NM — Nuclear</option>
                    <option value="PT">PT — PET-CT</option>
                    <option value="OT">OT — Outros</option>
                </select>
                <label style="display:flex;align-items:center;gap:.4rem;font-size:.8rem;color:var(--pacs-text-muted);cursor:pointer;">
                    <input type="checkbox" id="mascaraFiltroMeus" onchange="filtrarMascaras()" style="accent-color:#1a56db;">
                    Somente minhas
                </label>
            </div>

            <!-- Lista de máscaras -->
            <div id="mascarasLista">
                <div style="text-align:center;padding:2rem;color:var(--pacs-text-muted);">
                    <i class="fa fa-layer-group fa-2x mb-2" style="opacity:.3;"></i>
                    <p class="mb-0">Carregando máscaras...</p>
                </div>
            </div>
        </div>
    </div><!-- fecha #aba-mascaras -->

    <!-- ══════════════════════════════════════════════════════════════════
         ABA — ASSINATURA (imagem / livre / certificado provisionado)
    ══════════════════════════════════════════════════════════════════ -->
    <div class="tab-pane fade<?= $abaAtiva === 'assinatura' ? ' show active' : '' ?>" id="aba-assinatura" role="tabpanel">
        <div id="assinaturaFeedback" style="display:none;margin-bottom:1rem;"></div>

        <!-- Bloco 1 — Imagem -->
        <div class="medico-section">
            <div class="medico-section-header" style="justify-content:space-between;">
                <span><i class="fa fa-image"></i> <?= htmlspecialchars(t('medicos.form.assinatura_bloco_imagem')) ?></span>
                <span id="assStatusImagem"></span>
            </div>
            <div class="ass-bloco-conteudo">
                <div class="ass-preview" id="assPreviewImagem">
                    <i class="fa fa-signature"></i>
                    <span><?= htmlspecialchars(t('medicos.form.assinatura_sem_preview')) ?></span>
                </div>
                <div class="ass-controles">
                    <label class="medico-label"><?= htmlspecialchars(t('medicos.form.assinatura_upload_label')) ?></label>
                    <input type="file" id="assinaturaImagemInput" class="medico-input" accept=".jpg,.jpeg,image/jpeg">
                    <span class="medico-hint"><?= htmlspecialchars(t('medicos.form.assinatura_upload_hint')) ?></span>
                    <div class="ass-botoes">
                        <button type="button" class="btn-pacs-primary" onclick="uploadAssinaturaImagem()" id="btnUploadImagem">
                            <i class="fa fa-upload me-1"></i> <?= htmlspecialchars(t('medicos.form.assinatura_enviar')) ?>
                        </button>
                        <button type="button" class="btn-pacs-outline" onclick="ativarAssinatura('imagem')" id="btnAtivarImagem" style="display:none;">
                            <?= htmlspecialchars(t('medicos.form.assinatura_ativar')) ?>
                        </button>
                        <button type="button" class="btn-pacs-outline" onclick="desativarAssinatura('imagem')" id="btnDesativarImagem" style="display:none;">
                            <?= htmlspecialchars(t('medicos.form.assinatura_desativar')) ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bloco 2 — Livre (canvas) -->
        <div class="medico-section">
            <div class="medico-section-header" style="justify-content:space-between;">
                <span><i class="fa fa-pen-nib"></i> <?= htmlspecialchars(t('medicos.form.assinatura_bloco_livre')) ?></span>
                <span id="assStatusLivre"></span>
            </div>
            <div class="ass-bloco-conteudo">
                <div class="ass-preview" id="assPreviewLivre">
                    <i class="fa fa-signature"></i>
                    <span><?= htmlspecialchars(t('medicos.form.assinatura_sem_preview')) ?></span>
                </div>
                <div class="ass-controles">
                    <label class="medico-label"><?= htmlspecialchars(t('medicos.form.assinatura_desenhar_label')) ?></label>
                    <canvas id="assinaturaCanvas" class="ass-canvas" width="360" height="140"></canvas>
                    <div class="ass-botoes">
                        <button type="button" class="btn-pacs-outline" onclick="limparCanvasAssinatura()">
                            <i class="fa fa-eraser me-1"></i> <?= htmlspecialchars(t('medicos.form.assinatura_limpar')) ?>
                        </button>
                        <button type="button" class="btn-pacs-primary" onclick="salvarAssinaturaLivre()" id="btnSalvarLivre">
                            <i class="fa fa-floppy-disk me-1"></i> <?= htmlspecialchars(t('medicos.form.assinatura_salvar')) ?>
                        </button>
                        <button type="button" class="btn-pacs-outline" onclick="ativarAssinatura('livre')" id="btnAtivarLivre" style="display:none;">
                            <?= htmlspecialchars(t('medicos.form.assinatura_ativar')) ?>
                        </button>
                        <button type="button" class="btn-pacs-outline" onclick="desativarAssinatura('livre')" id="btnDesativarLivre" style="display:none;">
                            <?= htmlspecialchars(t('medicos.form.assinatura_desativar')) ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bloco 3 — Certificado digital (provisionado, não funcional) -->
        <div class="medico-section">
            <div class="medico-section-header">
                <i class="fa fa-certificate"></i> <?= htmlspecialchars(t('medicos.form.assinatura_bloco_certificado')) ?>
                <span class="ass-badge-em-breve"><?= htmlspecialchars(t('medicos.form.assinatura_em_breve')) ?></span>
            </div>
            <p class="medico-hint" style="margin-bottom:1rem;"><?= htmlspecialchars(t('medicos.form.assinatura_certificado_texto')) ?></p>
            <div class="ass-controles">
                <label class="medico-label"><?= htmlspecialchars(t('medicos.form.assinatura_certificado_provedor')) ?></label>
                <select class="medico-select" disabled>
                    <option><?= htmlspecialchars(t('medicos.form.assinatura_certificado_provedor')) ?></option>
                    <option>CFM</option>
                    <option>certSign</option>
                </select>
                <div class="ass-botoes">
                    <button type="button" class="btn-pacs-outline" disabled><?= htmlspecialchars(t('medicos.form.assinatura_ativar')) ?></button>
                </div>
            </div>
        </div>
    </div><!-- fecha #aba-assinatura -->

    </div><!-- fecha .tab-content -->
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

<!-- ── MODAL EDITOR DE MÁSCARA ─────────────────────────────────────────────────────────────────── -->
<div id="modalMascara" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.65);overflow-y:auto;">
    <div style="max-width:860px;margin:2rem auto;background:var(--pacs-card-bg,#1e2330);border:1px solid var(--pacs-border,#2d3244);border-radius:12px;overflow:hidden;">
        <!-- Header do modal -->
        <div style="padding:1rem 1.5rem;border-bottom:1px solid var(--pacs-border,#2d3244);display:flex;justify-content:space-between;align-items:center;">
            <div>
                <h5 style="margin:0;font-size:1rem;font-weight:700;" id="modalMascaraTitulo">Nova Máscara</h5>
                <p style="margin:0;font-size:.75rem;color:var(--pacs-text-muted);">Criar máscara de laudo personalizada</p>
            </div>
            <button type="button" onclick="fecharModalMascara()" style="background:transparent;border:none;color:var(--pacs-text-muted);font-size:1.2rem;cursor:pointer;"><i class="fa fa-xmark"></i></button>
        </div>
        <!-- Body do modal -->
        <div style="padding:1.5rem;">
            <input type="hidden" id="mascaraEditId" value="">

            <!-- Configurações -->
            <div style="background:rgba(26,86,219,.05);border:1px solid rgba(26,86,219,.2);border-radius:8px;padding:1.1rem;margin-bottom:1.25rem;">
                <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--pacs-primary,#1a56db);margin-bottom:.9rem;">
                    <i class="fa fa-gear me-1"></i> Configurações
                </div>
                <div style="display:grid;grid-template-columns:1fr 180px;gap:.9rem;margin-bottom:.9rem;">
                    <div>
                        <label class="medico-label">Nome do Template <span class="req">*</span></label>
                        <input type="text" id="mascaraNome" class="medico-input" placeholder="Ex: TC Tórax com Contraste — Padrão" maxlength="255">
                    </div>
                    <div>
                        <label class="medico-label">Modalidade <span class="req">*</span></label>
                        <select id="mascaraModalidade" class="medico-select">
                            <option value="">Selecione...</option>
                            <option value="CR">CR — Radiografia</option>
                            <option value="CT">CT — Tomografia</option>
                            <option value="MR">MR — Ressonância</option>
                            <option value="US">US — Ultrassom</option>
                            <option value="DX">DX — Digital</option>
                            <option value="MG">MG — Mamografia</option>
                            <option value="NM">NM — Nuclear</option>
                            <option value="PT">PT — PET-CT</option>
                            <option value="OT">OT — Outros</option>
                        </select>
                    </div>
                </div>
                <div style="margin-bottom:.9rem;">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.83rem;">
                        <input type="checkbox" id="mascaraCompartilhar" style="accent-color:#1a56db;width:15px;height:15px;">
                        <span>Compartilhar com outros médicos da clínica</span>
                    </label>
                </div>
                <div>
                    <label class="medico-label" style="color:#a78bfa;">
                        <i class="fa fa-tag me-1"></i> TAG DICOM Study Description
                        <span style="font-size:.65rem;font-weight:400;margin-left:.4rem;opacity:.7;">(0008,1030)</span>
                    </label>
                    <input type="text" id="mascaraStudyDesc" class="medico-input"
                           placeholder="Ex: CT ABDOMEN E PELVE C/CONTRASTE"
                           maxlength="255"
                           style="text-transform:uppercase;">
                    <span class="medico-hint" style="color:#a78bfa;">
                        Quando um laudo for aberto com este valor na TAG DICOM, este template será sugerido automaticamente.
                        Deixe em branco para não vincular.
                    </span>
                </div>
            </div>

            <!-- Editor de conteúdo -->
            <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--pacs-text-muted);margin-bottom:.9rem;">
                <i class="fa fa-file-medical me-1"></i> Conteúdo do Laudo
            </div>

            <div class="mascara-editor-section">
                <span class="mascara-editor-label">Técnica</span>
                <div class="mascara-editor-body" id="mEd-tecnica"
                     data-placeholder="Descreva a técnica utilizada..."></div>
            </div>
            <div class="mascara-editor-section">
                <span class="mascara-editor-label">Achados</span>
                <div class="mascara-editor-body" id="mEd-achados"
                     data-placeholder="Descreva os achados do exame..."></div>
            </div>
            <div class="mascara-editor-section">
                <span class="mascara-editor-label">Impressão</span>
                <div class="mascara-editor-body" id="mEd-conclusao"
                     data-placeholder="Impressão diagnóstica..."></div>
            </div>
        </div>
        <!-- Footer do modal -->
        <div style="padding:1rem 1.5rem;border-top:1px solid var(--pacs-border,#2d3244);display:flex;justify-content:flex-end;gap:.75rem;">
            <button type="button" class="btn-pacs-outline" onclick="fecharModalMascara()">Cancelar</button>
            <button type="button" class="btn-pacs-primary" onclick="salvarMascara()" id="btnSalvarMascara">
                <i class="fa fa-floppy-disk me-1"></i> Salvar Máscara
            </button>
        </div>
    </div>
</div>

<!-- ── MODAL IMPORTAR DOCX ─────────────────────────────────────────────────────────────────── -->
<div id="modalImportar" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.65);">
    <div style="max-width:520px;margin:4rem auto;background:var(--pacs-card-bg,#1e2330);border:1px solid var(--pacs-border,#2d3244);border-radius:12px;overflow:hidden;">
        <div style="padding:1rem 1.5rem;border-bottom:1px solid var(--pacs-border,#2d3244);display:flex;justify-content:space-between;align-items:center;">
            <h5 style="margin:0;font-size:1rem;font-weight:700;"><i class="fa fa-file-import me-2"></i> Importar Máscaras (DOCX)</h5>
            <button type="button" onclick="fecharModalImportar()" style="background:transparent;border:none;color:var(--pacs-text-muted);font-size:1.2rem;cursor:pointer;"><i class="fa fa-xmark"></i></button>
        </div>
        <div style="padding:1.5rem;">
            <p style="font-size:.85rem;color:var(--pacs-text-muted);margin-bottom:1rem;">
                Selecione um arquivo <strong>.docx</strong> com máscaras de laudo. Cada título (Heading) será importado como uma nova máscara.
            </p>
            <input type="file" id="importarArquivo" accept=".docx" class="medico-input" style="height:auto;padding:.5rem;">
            <div id="importarStatus" style="margin-top:.75rem;font-size:.82rem;"></div>
        </div>
        <div style="padding:1rem 1.5rem;border-top:1px solid var(--pacs-border,#2d3244);display:flex;justify-content:flex-end;gap:.75rem;">
            <button type="button" class="btn-pacs-outline" onclick="fecharModalImportar()">Cancelar</button>
            <button type="button" class="btn-pacs-primary" onclick="executarImportar()" id="btnExecutarImportar">
                <i class="fa fa-magnifying-glass me-1"></i> Analisar e Revisar
            </button>
        </div>
    </div>
</div>

<!-- ── MODAL REVISAR IMPORTAÇÃO DOCX ───────────────────────────────────────── -->
<div id="modalRevisarImportacao" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.72);overflow-y:auto;">
    <div style="max-width:860px;margin:3rem auto;background:var(--pacs-card-bg,#1e2330);border:1px solid var(--pacs-border,#2d3244);border-radius:12px;overflow:hidden;">
        <div style="padding:1rem 1.5rem;border-bottom:1px solid var(--pacs-border,#2d3244);display:flex;justify-content:space-between;align-items:center;gap:1rem;">
            <div>
                <h5 id="revisarImportacaoTitulo" style="margin:0;font-size:1rem;font-weight:700;"><i class="fa fa-list-check me-2"></i> Revisar Máscaras Importadas</h5>
                <small id="revisarImportacaoSubtitulo" style="color:var(--pacs-text-muted);"></small>
            </div>
            <button type="button" onclick="cancelarRevisaoImportacao()" style="background:transparent;border:none;color:var(--pacs-text-muted);font-size:1.2rem;cursor:pointer;" title="Cancelar revisão"><i class="fa fa-xmark"></i></button>
        </div>
        <div style="padding:1rem 1.5rem .5rem;">
            <div style="padding:.65rem .8rem;border-radius:7px;background:rgba(79,195,247,.09);color:var(--pacs-text-muted);font-size:.8rem;">
                <i class="fa fa-circle-info me-1" style="color:#4fc3f7;"></i>
                Revise a seleção e a modalidade sugerida. Máscaras destacadas em laranja precisam de atenção antes do uso.
            </div>
        </div>
        <div id="revisarImportacaoLista" style="padding:.5rem 1.5rem 1.25rem;max-height:55vh;overflow-y:auto;"></div>
        <div style="padding:1rem 1.5rem;border-top:1px solid var(--pacs-border,#2d3244);display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;">
            <span id="revisarImportacaoContador" style="font-size:.82rem;color:var(--pacs-text-muted);"></span>
            <div style="display:flex;gap:.75rem;">
                <button type="button" class="btn-pacs-outline" onclick="cancelarRevisaoImportacao()">Cancelar</button>
                <button type="button" class="btn-pacs-primary" onclick="confirmarImportacaoRevisada()" id="btnConfirmarImportacao">
                    <i class="fa fa-file-import me-1"></i> Importar selecionadas
                </button>
            </div>
        </div>
    </div>
</div>

<?php if ($isEdit): ?>
<!-- Bibliotecas do formulário em edição — versões fixadas no CDN jsdelivr. -->
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
<?php endif; ?>

<script>
const I18N_ASSINATURA = {
    ativa:            <?= json_encode(t('medicos.form.assinatura_ativa')) ?>,
    inativa:          <?= json_encode(t('medicos.form.assinatura_inativa')) ?>,
    selecioneArquivo: <?= json_encode(t('medicos.form.assinatura_selecione_arquivo')) ?>,
    enviando:         <?= json_encode(t('medicos.form.assinatura_enviando')) ?>,
    enviar:           <?= json_encode(t('medicos.form.assinatura_enviar')) ?>,
    desenheAntes:     <?= json_encode(t('medicos.form.assinatura_desenhe_antes')) ?>,
    salvando:         <?= json_encode(t('medicos.form.assinatura_salvando')) ?>,
    salvar:           <?= json_encode(t('medicos.form.assinatura_salvar')) ?>,
    erroConexao:      <?= json_encode(t('medicos.form.assinatura_erro_conexao')) ?>,
};

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

// ─── Abas: mostra automaticamente a aba que contém um campo com erro ──────
// Garante que nenhum erro de validação fique escondido numa aba não ativa
// (tela só tem abas em modo edição — em /medicos/create os campos estão
// soltos na página e esta função simplesmente não encontra .tab-pane).
function ativarAbaComErro(campoInvalido) {
    const pane = campoInvalido.closest('.tab-pane');
    if (!pane) return;
    const btn = document.querySelector('.medico-tab-btn[data-bs-target="#' + pane.id + '"]');
    if (!btn) return;
    if (window.bootstrap && bootstrap.Tab) {
        bootstrap.Tab.getOrCreateInstance(btn).show();
    } else {
        btn.click();
    }
    const badge = btn.querySelector('.medico-tab-badge');
    if (badge) badge.style.display = 'inline-block';
}
// Ao carregar a página: se o servidor devolveu algum campo com .is-invalid
// (após redirect de erro de validação), troca para a aba correspondente.
document.addEventListener('DOMContentLoaded', function () {
    const campoInvalido = document.querySelector('.tab-pane .is-invalid');
    if (campoInvalido) ativarAbaComErro(campoInvalido);
});

// ─── Loading no botão ao submeter ─────────────────────────────────────────
document.getElementById('formMedico')?.addEventListener('submit', function (e) {
    const btn  = document.getElementById('btnSalvar');
    const nome = document.getElementById('medicoNome').value.trim();
    if (!nome || nome.length < 3) {
        e.preventDefault();
        const input = document.getElementById('medicoNome');
        input.classList.add('is-invalid');
        ativarAbaComErro(input);
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

// ─── Toggle Workspace Laudo VOXEL ─────────────────────────────────────────────
function toggleWorkspaceLaudo(el) {
    const medicoId  = el.dataset.medicoId;
    const ativoAtual = el.dataset.ativo === '1';
    const novoEstado = !ativoAtual;
    const thumb      = document.getElementById('workspaceLaudoThumb');
    const label      = document.getElementById('workspaceLaudoLabel');
    const feedback   = document.getElementById('workspaceLaudoFeedback');

    // Feedback visual imediato
    // novoEstado=true = VOXEL Copilot (roxo) | novoEstado=false = Laudário Interno (azul)
    el.style.background   = novoEstado ? '#7c3aed' : '#0ea5e9';
    thumb.style.left      = novoEstado ? '27px' : '3px';
    label.textContent     = novoEstado ? 'VOXEL Copilot' : 'Laudário Interno';
    label.style.color     = novoEstado ? '#a78bfa' : '#38bdf8';
    el.dataset.ativo      = novoEstado ? '1' : '0';

    fetch(`/api/medicos/${medicoId}/workspace-laudo`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ habilitar: novoEstado })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) throw new Error(data.msg || 'Erro desconhecido');
        const cor = novoEstado ? '#a78bfa' : '#38bdf8';
        const ico = novoEstado ? 'fa-robot' : 'fa-file-medical';
        const msg = novoEstado
            ? 'Modo VOXEL Copilot ativado. Botão Laudo oculto na worklist.'
            : 'Laudário Interno ativado. Botão Laudo visível na worklist após assumir.';
        feedback.style.display = 'block';
        feedback.innerHTML = `<div style="padding:.5rem .9rem;border-radius:6px;background:rgba(0,0,0,.08);
            border:1px solid ${cor};color:${cor};font-size:.82rem;">
            <i class="fa ${ico} me-1"></i>${msg}
        </div>`;
        setTimeout(() => { feedback.style.display = 'none'; }, 5000);
    })
    .catch(err => {
        // Reverte em caso de erro
        el.style.background   = ativoAtual ? '#7c3aed' : '#0ea5e9';
        thumb.style.left      = ativoAtual ? '27px' : '3px';
        label.textContent     = ativoAtual ? 'VOXEL Copilot' : 'Laudário Interno';
        label.style.color     = ativoAtual ? '#a78bfa' : '#38bdf8';
        el.dataset.ativo      = ativoAtual ? '1' : '0';
        feedback.style.display = 'block';
        feedback.innerHTML = `<div style="padding:.5rem .9rem;border-radius:6px;background:rgba(224,82,82,.1);
            border:1px solid #e05252;color:#e05252;font-size:.82rem;">
            <i class="fa fa-triangle-exclamation me-1"></i>Erro: ${err.message}
        </div>`;
        setTimeout(() => { feedback.style.display = 'none'; }, 5000);
    });
}

// --- PERMISSOES DE WORKLIST: toggleVerMedicoLaudo ----------------------------
function toggleVerMedicoLaudo(el) {
    const medicoId   = el.dataset.medicoId;
    const ativoAtual = el.dataset.ativo === '1';
    const novoEstado = !ativoAtual;
    const thumb      = document.getElementById('verMedicoLaudoThumb');
    const label      = document.getElementById('verMedicoLaudoLabel');
    const feedback   = document.getElementById('verMedicoLaudoFeedback');
    // Feedback visual imediato
    el.style.background = novoEstado ? '#0ea5e9' : '#374151';
    thumb.style.left    = novoEstado ? '22px' : '2px';
    label.textContent   = novoEstado ? 'Habilitado' : 'Desabilitado';
    label.style.color   = novoEstado ? '#0ea5e9' : 'var(--pacs-text-muted)';
    el.dataset.ativo    = novoEstado ? '1' : '0';
    fetch(`/api/medicos/${medicoId}/permissao-ver-medico-laudo`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ habilitar: novoEstado })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) throw new Error(data.msg || 'Erro desconhecido');
        const cor = novoEstado ? '#0ea5e9' : '#6b7280';
        const ico = novoEstado ? 'fa-eye' : 'fa-eye-slash';
        const msg = novoEstado
            ? 'Permissao habilitada. Este medico pode ver o nome de outros medicos na worklist.'
            : 'Permissao desabilitada. Este medico ve apenas o proprio nome.';
        feedback.style.display = 'block';
        feedback.innerHTML = `<div style="padding:.5rem .9rem;border-radius:6px;background:rgba(0,0,0,.08);
            border:1px solid ${cor};color:${cor};font-size:.82rem;">
            <i class="fa ${ico} me-1"></i>${msg}
        </div>`;
        setTimeout(() => { feedback.style.display = 'none'; }, 5000);
    })
    .catch(err => {
        // Reverte em caso de erro
        el.style.background = ativoAtual ? '#0ea5e9' : '#374151';
        thumb.style.left    = ativoAtual ? '22px' : '2px';
        label.textContent   = ativoAtual ? 'Habilitado' : 'Desabilitado';
        label.style.color   = ativoAtual ? '#0ea5e9' : 'var(--pacs-text-muted)';
        el.dataset.ativo    = ativoAtual ? '1' : '0';
        feedback.style.display = 'block';
        feedback.innerHTML = `<div style="padding:.5rem .9rem;border-radius:6px;background:rgba(224,82,82,.1);
            border:1px solid #e05252;color:#e05252;font-size:.82rem;">
            <i class="fa fa-triangle-exclamation me-1"></i>Erro: ${err.message}
        </div>`;
        setTimeout(() => { feedback.style.display = 'none'; }, 5000);
    });
}
// ─── MÓDULO DE MÁSCARAS ─────────────────────────────────────────────────────
const MEDICO_ID_MASCARAS = <?= (int) $medicoId ?>;
const MASCARA_SECOES_EDITAVEIS = ['tecnica', 'achados', 'conclusao'];
let _mascarasAll = [];
let _mascaraEditors = {};
let _mascaraLegacySecoes = { exame: '', recomendacao: '' };
let _mascarasImportacao = [];
let _arquivoOrigemImportacao = '';

// Carrega a lista quando a aba "Máscaras" (Bootstrap tabs) é aberta — em vez
// de recarregar toda vez, evita chamada redundante se o médico só olhar e
// voltar pra outra aba sem nunca abrir Máscaras.
document.getElementById('tab-mascaras')?.addEventListener('shown.bs.tab', function () {
    carregarMascaras();
});

function carregarMascaras() {
    if (!MEDICO_ID_MASCARAS) return;
    fetch('/api/medicos/' + MEDICO_ID_MASCARAS + '/templates')
    .then(r => r.json())
    .then(data => {
        if (!data.ok) throw new Error(data.msg);
        _mascarasAll = data.templates || [];
        renderizarMascaras(_mascarasAll);
    })
    .catch(err => {
        document.getElementById('mascarasLista').innerHTML =
            '<div style="color:#e05252;padding:1rem;"><i class="fa fa-triangle-exclamation me-1"></i>' + err.message + '</div>';
    });
}

function renderizarMascaras(lista) {
    const el = document.getElementById('mascarasLista');
    if (!lista.length) {
        el.innerHTML = `<div style="text-align:center;padding:2.5rem;color:var(--pacs-text-muted);">
            <i class="fa fa-layer-group fa-2x mb-3" style="opacity:.25;"></i>
            <p class="mb-1" style="font-size:.9rem;">Nenhuma máscara cadastrada ainda</p>
            <p class="mb-0" style="font-size:.78rem;">Clique em <strong>Nova Máscara</strong> para criar ou <strong>Importar DOCX</strong> para importar.</p>
        </div>`;
        return;
    }
    el.innerHTML = lista.map(t => {
        const isMeu = t.medico_id == MEDICO_ID_MASCARAS;
        const badges = [
            t.modalidade ? `<span class="mascara-badge mascara-badge-modal">${escHtml(t.modalidade)}</span>` : '',
            t.compartilhar == 1 ? '<span class="mascara-badge mascara-badge-shared"><i class="fa fa-share-nodes"></i> Compartilhado</span>' : '',
            t.study_description_tag ? '<span class="mascara-badge mascara-badge-auto"><i class="fa fa-tag"></i> Auto</span>' : '',
            t.origem === 'importado' ? '<span class="mascara-badge" style="background:rgba(79,195,247,.15);color:#4fc3f7;font-size:.65rem;" title="Importada de ' + escHtml(t.arquivo_origem || '') + '"><i class="fa fa-file-import"></i> Importada</span>' : '',
            t.revisar == 1 ? '<span class="mascara-badge" style="background:rgba(255,152,0,.15);color:#ff9800;font-size:.65rem;" title="Revise o conteúdo antes de usar"><i class="fa fa-triangle-exclamation"></i> Revisar</span>' : '',
        ].filter(Boolean).join(' ');
        const meta = [
            t.study_description_tag ? t.study_description_tag : null,
            t.uso_count > 0 ? t.uso_count + 'x usado' : null,
            !isMeu && t.medico_nome ? 'de ' + escHtml(t.medico_nome) : null,
        ].filter(Boolean).join(' · ');
        const templateId = Number(t.id) || 0;
        const visualizar = templateId ? `
            <a class="pacs-btn" style="padding:.3rem .6rem;font-size:.75rem;" href="/medicos/${MEDICO_ID_MASCARAS}/mascaras/${templateId}/visualizar" target="_blank" rel="noopener" title="Visualizar Laudo">
                <i class="fa fa-eye"></i>
            </a>` : '';
        const acoes = visualizar + (isMeu ? `
            <button type="button" class="pacs-btn" style="padding:.3rem .6rem;font-size:.75rem;" onclick="editarMascara(${templateId})" title="Editar">
                <i class="fa fa-pen"></i>
            </button>
            <button type="button" class="pacs-btn" style="padding:.3rem .6rem;font-size:.75rem;color:#e05252;" onclick="excluirMascara(${templateId})" title="Excluir">
                <i class="fa fa-trash"></i>
            </button>` : '');
        return `<div class="mascara-card">
            <div class="mascara-card-icon"><i class="fa fa-file-medical"></i></div>
            <div class="mascara-card-info">
                <div class="mascara-card-nome">${escHtml(t.nome)}</div>
                <div class="mascara-card-meta">${badges} ${meta ? '· ' + meta : ''}</div>
            </div>
            <div class="mascara-card-actions">${acoes}</div>
        </div>`;
    }).join('');
}

function filtrarMascaras() {
    const q     = (document.getElementById('mascaraBusca')?.value || '').toLowerCase();
    const modal = document.getElementById('mascaraFiltroModal')?.value || '';
    const meus  = document.getElementById('mascaraFiltroMeus')?.checked;
    let lista = _mascarasAll.filter(t => {
        if (q && !t.nome.toLowerCase().includes(q) && !(t.study_description_tag || '').toLowerCase().includes(q)) return false;
        if (modal && t.modalidade !== modal) return false;
        if (meus && t.medico_id != MEDICO_ID_MASCARAS) return false;
        return true;
    });
    renderizarMascaras(lista);
}

function inicializarEditoresMascara() {
    if (Object.keys(_mascaraEditors).length) return true;
    if (!window.Quill) {
        alert('Não foi possível carregar o editor de texto. Verifique sua conexão e recarregue a página.');
        return false;
    }

    MASCARA_SECOES_EDITAVEIS.forEach(secao => {
        const el = document.getElementById('mEd-' + secao);
        if (!el) return;
        _mascaraEditors[secao] = new Quill(el, {
            theme: 'snow',
            placeholder: el.dataset.placeholder || '',
            modules: { toolbar: [['bold']] }
        });
    });
    return MASCARA_SECOES_EDITAVEIS.every(secao => !!_mascaraEditors[secao]);
}

function definirConteudoMascara(secao, html) {
    const editor = _mascaraEditors[secao];
    if (editor) editor.clipboard.dangerouslyPasteHTML(html || '');
}

function obterConteudoMascara(secao) {
    const editor = _mascaraEditors[secao];
    return editor ? editor.root.innerHTML : '';
}

function abrirNovaMascara() {
    if (!inicializarEditoresMascara()) return;
    document.getElementById('mascaraEditId').value = '';
    document.getElementById('mascaraNome').value = '';
    document.getElementById('mascaraModalidade').value = '';
    document.getElementById('mascaraCompartilhar').checked = false;
    document.getElementById('mascaraStudyDesc').value = '';
    _mascaraLegacySecoes = { exame: '', recomendacao: '' };
    MASCARA_SECOES_EDITAVEIS.forEach(secao => definirConteudoMascara(secao, ''));
    document.getElementById('modalMascaraTitulo').textContent = 'Nova Máscara';
    document.getElementById('modalMascara').style.display = 'block';
    document.getElementById('mascaraNome').focus();
}

function editarMascara(id) {
    const t = _mascarasAll.find(x => x.id == id);
    if (!t || !inicializarEditoresMascara()) return;
    document.getElementById('mascaraEditId').value = t.id;
    document.getElementById('mascaraNome').value = t.nome || '';
    document.getElementById('mascaraModalidade').value = t.modalidade || '';
    document.getElementById('mascaraCompartilhar').checked = t.compartilhar == 1;
    document.getElementById('mascaraStudyDesc').value = t.study_description_tag || '';
    _mascaraLegacySecoes = {
        exame: t.secao_exame || '',
        recomendacao: t.secao_recomendacao || ''
    };
    MASCARA_SECOES_EDITAVEIS.forEach(secao => definirConteudoMascara(secao, t['secao_' + secao] || ''));
    document.getElementById('modalMascaraTitulo').textContent = 'Editar Máscara';
    document.getElementById('modalMascara').style.display = 'block';
}

function fecharModalMascara() {
    document.getElementById('modalMascara').style.display = 'none';
}

function salvarMascara() {
    const nome = document.getElementById('mascaraNome').value.trim();
    if (!nome || !inicializarEditoresMascara()) {
        if (!nome) alert('Informe o nome do template.');
        return;
    }
    const btn = document.getElementById('btnSalvarMascara');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Salvando...';
    const payload = {
        id:                   parseInt(document.getElementById('mascaraEditId').value) || 0,
        nome:                 nome,
        modalidade:           document.getElementById('mascaraModalidade').value,
        compartilhar:         document.getElementById('mascaraCompartilhar').checked ? 1 : 0,
        study_description_tag: document.getElementById('mascaraStudyDesc').value.trim().toUpperCase(),
        secao_exame:          _mascaraLegacySecoes.exame,
        secao_tecnica:        obterConteudoMascara('tecnica'),
        secao_achados:        obterConteudoMascara('achados'),
        secao_conclusao:      obterConteudoMascara('conclusao'),
        secao_recomendacao:   _mascaraLegacySecoes.recomendacao,
    };
    fetch('/api/medicos/' + MEDICO_ID_MASCARAS + '/templates', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-floppy-disk me-1"></i> Salvar Máscara';
        if (data.ok) {
            fecharModalMascara();
            carregarMascaras();
        } else {
            alert('Erro: ' + (data.msg || 'Tente novamente.'));
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-floppy-disk me-1"></i> Salvar Máscara';
        alert('Erro de conexão.');
    });
}

function excluirMascara(id) {
    if (!confirm('Excluir esta máscara? Esta ação não pode ser desfeita.')) return;
    fetch('/api/medicos/' + MEDICO_ID_MASCARAS + '/templates/' + id + '/excluir', { method: 'POST' })
    .then(r => r.json())
    .then(data => {
        if (data.ok) carregarMascaras();
        else alert('Erro: ' + (data.msg || 'Tente novamente.'));
    })
    .catch(() => alert('Erro de conexão.'));
}

function abrirImportarMascara() {
    document.getElementById('importarArquivo').value = '';
    document.getElementById('importarStatus').innerHTML = '';
    document.getElementById('modalImportar').style.display = 'block';
}

function fecharModalImportar() {
    document.getElementById('modalImportar').style.display = 'none';
}

function executarImportar() {
    const arquivo = document.getElementById('importarArquivo').files[0];
    if (!arquivo) { alert('Selecione um arquivo .docx'); return; }

    const btn = document.getElementById('btnExecutarImportar');
    const status = document.getElementById('importarStatus');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Analisando...';
    status.innerHTML = '<span style="color:#f59e0b;">Analisando o DOCX sem salvar máscaras...</span>';

    const formData = new FormData();
    formData.append('arquivo', arquivo);
    fetch('/api/medicos/' + MEDICO_ID_MASCARAS + '/templates/importar/analisar', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-magnifying-glass me-1"></i> Analisar e Revisar';
        if (!data.ok) {
            status.innerHTML = '<span style="color:#e05252;"><i class="fa fa-triangle-exclamation me-1"></i>' + escHtml(data.msg || 'Erro ao analisar o DOCX.') + '</span>';
            return;
        }

        _mascarasImportacao = Array.isArray(data.mascaras) ? data.mascaras : [];
        _arquivoOrigemImportacao = data.arquivo_nome || arquivo.name || 'mascaras.docx';
        if (!_mascarasImportacao.length) {
            status.innerHTML = '<span style="color:#e05252;">Nenhuma máscara foi encontrada no DOCX.</span>';
            return;
        }
        fecharModalImportar();
        abrirRevisaoImportacao(data.total_revisar || 0);
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-magnifying-glass me-1"></i> Analisar e Revisar';
        status.innerHTML = '<span style="color:#e05252;">Erro de conexão.</span>';
    });
}

function abrirRevisaoImportacao(totalRevisar) {
    document.getElementById('revisarImportacaoTitulo').innerHTML = '<i class="fa fa-list-check me-2"></i> Revisar Máscaras Importadas (' + _mascarasImportacao.length + ' detectadas)';
    document.getElementById('revisarImportacaoSubtitulo').textContent = _arquivoOrigemImportacao + (totalRevisar ? ' · ' + totalRevisar + ' requer(em) revisão' : ' · pronto para confirmar');
    renderizarRevisaoImportacao();
    document.getElementById('modalRevisarImportacao').style.display = 'block';
}

function textoPreviewImportacao(html) {
    const texto = String(html || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    return texto.length > 80 ? texto.slice(0, 80) + '…' : texto || 'Sem achados identificados';
}

function opcoesModalidadeImportacao(selecionada) {
    const modalidades = ['', 'CR', 'CT', 'MR', 'US', 'DX', 'MG', 'NM', 'PT', 'OT'];
    return modalidades.map(modalidade => {
        const texto = modalidade || 'Não definida';
        return '<option value="' + modalidade + '"' + (modalidade === selecionada ? ' selected' : '') + '>' + texto + '</option>';
    }).join('');
}

function renderizarRevisaoImportacao() {
    const lista = document.getElementById('revisarImportacaoLista');
    lista.innerHTML = _mascarasImportacao.map((mascara, indice) => {
        const requerRevisao = !!mascara.revisar;
        const corBorda = requerRevisao ? '#f59e0b' : 'var(--pacs-border,#2d3244)';
        const fundo = requerRevisao ? 'rgba(245,158,11,.08)' : 'rgba(0,0,0,.08)';
        const aviso = requerRevisao
            ? '<span class="mascara-badge" style="background:rgba(255,152,0,.15);color:#ff9800;font-size:.65rem;"><i class="fa fa-triangle-exclamation"></i> Revisar</span>'
            : '<span class="mascara-badge" style="background:rgba(34,197,94,.12);color:#22c55e;font-size:.65rem;"><i class="fa fa-circle-check"></i> Detectada</span>';
        return '<div style="border:1px solid ' + corBorda + ';background:' + fundo + ';border-radius:8px;padding:.8rem;margin-bottom:.65rem;">'
            + '<div style="display:flex;gap:.75rem;align-items:flex-start;">'
            + '<input type="checkbox" class="importacao-selecao" data-indice="' + indice + '" checked onchange="atualizarContadorImportacao()" style="margin-top:.35rem;accent-color:#1a56db;">'
            + '<div style="flex:1;min-width:0;">'
            + '<div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">'
            + '<strong style="font-size:.86rem;">' + escHtml(mascara.nome) + '</strong>' + aviso
            + '</div>'
            + '<div style="display:flex;align-items:center;gap:.5rem;margin:.45rem 0;flex-wrap:wrap;">'
            + '<label style="font-size:.74rem;color:var(--pacs-text-muted);">Modalidade</label>'
            + '<select class="medico-select" style="width:128px;height:30px;padding:.15rem .4rem;font-size:.76rem;" onchange="atualizarModalidadeImportacao(' + indice + ', this.value)">'
            + opcoesModalidadeImportacao(String(mascara.modalidade || '').toUpperCase())
            + '</select>'
            + '</div>'
            + '<div style="font-size:.76rem;color:var(--pacs-text-muted);line-height:1.4;"><strong>Achados:</strong> ' + escHtml(textoPreviewImportacao(mascara.secao_achados)) + '</div>'
            + '</div></div></div>';
    }).join('');
    atualizarContadorImportacao();
}

function atualizarModalidadeImportacao(indice, modalidade) {
    if (_mascarasImportacao[indice]) _mascarasImportacao[indice].modalidade = modalidade;
}

function atualizarContadorImportacao() {
    const total = Array.from(document.querySelectorAll('.importacao-selecao:checked')).length;
    document.getElementById('revisarImportacaoContador').textContent = total + ' de ' + _mascarasImportacao.length + ' máscara(s) selecionada(s)';
    document.getElementById('btnConfirmarImportacao').innerHTML = '<i class="fa fa-file-import me-1"></i> Importar ' + total + ' selecionada(s)';
}

function cancelarRevisaoImportacao() {
    document.getElementById('modalRevisarImportacao').style.display = 'none';
    _mascarasImportacao = [];
    _arquivoOrigemImportacao = '';
    abrirImportarMascara();
}

function confirmarImportacaoRevisada() {
    const selecionadas = Array.from(document.querySelectorAll('.importacao-selecao:checked'))
        .map(input => _mascarasImportacao[Number(input.dataset.indice)])
        .filter(Boolean);
    if (!selecionadas.length) {
        alert('Selecione ao menos uma máscara para importar.');
        return;
    }

    const btn = document.getElementById('btnConfirmarImportacao');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Salvando...';
    fetch('/api/medicos/' + MEDICO_ID_MASCARAS + '/templates/importar/confirmar', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ arquivo_nome: _arquivoOrigemImportacao, mascaras: selecionadas })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        if (!data.ok) {
            atualizarContadorImportacao();
            alert('Erro: ' + (data.msg || 'Não foi possível importar as máscaras.'));
            return;
        }
        document.getElementById('modalRevisarImportacao').style.display = 'none';
        _mascarasImportacao = [];
        _arquivoOrigemImportacao = '';
        carregarMascaras();
        alert(data.msg + (data.ignorados ? ' ' + data.ignorados + ' duplicada(s) foram ignoradas.' : ''));
    })
    .catch(() => {
        btn.disabled = false;
        atualizarContadorImportacao();
        alert('Erro de conexão.');
    });
}

function escHtml(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ─── MÓDULO DE ASSINATURA ───────────────────────────────────────────────────
const MEDICO_ID_ASSINATURA = <?= (int) $medicoId ?>;
let _assinaturaPad = null;
let _assinaturaCarregada = false;

// Carrega o estado dos blocos só quando a aba é aberta pela 1ª vez (evita
// fetch + init de canvas se o usuário nunca visitar essa aba) — mesmo
// padrão de shown.bs.tab já usado pra Máscaras.
document.getElementById('tab-assinatura')?.addEventListener('shown.bs.tab', function () {
    if (!_assinaturaCarregada) {
        _assinaturaCarregada = true;
        initCanvasAssinatura();
    }
    carregarAssinaturas();
});

function initCanvasAssinatura() {
    const canvas = document.getElementById('assinaturaCanvas');
    if (!canvas || typeof SignaturePad === 'undefined') return;
    // Fundo transparente — respeitado pelo toDataURL('image/png') mais abaixo.
    _assinaturaPad = new SignaturePad(canvas, { backgroundColor: 'rgba(0,0,0,0)' });
}

function limparCanvasAssinatura() {
    _assinaturaPad?.clear();
}

function carregarAssinaturas() {
    if (!MEDICO_ID_ASSINATURA) return;
    fetch('/medicos/' + MEDICO_ID_ASSINATURA + '/assinatura/listar')
    .then(r => r.json())
    .then(data => {
        if (!data.ok) throw new Error(data.msg || 'Erro ao carregar.');
        renderizarBlocoAssinatura('imagem', data.blocos.imagem);
        renderizarBlocoAssinatura('livre', data.blocos.livre);
    })
    .catch(err => mostrarFeedbackAssinatura('erro', err.message));
}

function renderizarBlocoAssinatura(tipo, estado) {
    const preview = document.getElementById(tipo === 'imagem' ? 'assPreviewImagem' : 'assPreviewLivre');
    const status  = document.getElementById(tipo === 'imagem' ? 'assStatusImagem' : 'assStatusLivre');
    const btnAtivar    = document.getElementById(tipo === 'imagem' ? 'btnAtivarImagem' : 'btnAtivarLivre');
    const btnDesativar = document.getElementById(tipo === 'imagem' ? 'btnDesativarImagem' : 'btnDesativarLivre');

    if (estado.existe && preview) {
        preview.innerHTML = '<img src="' + estado.preview_url + '?t=' + Date.now() + '" alt="Assinatura">';
    }

    if (status) {
        status.innerHTML = estado.ativa
            ? '<span class="ass-badge-ativa"><i class="fa fa-circle-check"></i> ' + I18N_ASSINATURA.ativa + '</span>'
            : (estado.existe ? '<span class="ass-badge-inativa"><i class="fa fa-circle-exclamation"></i> ' + I18N_ASSINATURA.inativa + '</span>' : '');
    }
    if (btnAtivar)    btnAtivar.style.display    = (estado.existe && !estado.ativa) ? '' : 'none';
    if (btnDesativar) btnDesativar.style.display = estado.ativa ? '' : 'none';
}

function uploadAssinaturaImagem() {
    const input = document.getElementById('assinaturaImagemInput');
    const arquivo = input.files[0];
    if (!arquivo) { mostrarFeedbackAssinatura('erro', I18N_ASSINATURA.selecioneArquivo); return; }

    const btn = document.getElementById('btnUploadImagem');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> ' + I18N_ASSINATURA.enviando;

    const formData = new FormData();
    formData.append('arquivo', arquivo);
    fetch('/medicos/' + MEDICO_ID_ASSINATURA + '/assinatura/imagem/upload', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-upload me-1"></i> ' + I18N_ASSINATURA.enviar;
        if (data.ok) {
            input.value = '';
            mostrarFeedbackAssinatura('sucesso', data.msg);
            carregarAssinaturas();
        } else {
            mostrarFeedbackAssinatura('erro', data.msg);
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-upload me-1"></i> ' + I18N_ASSINATURA.enviar;
        mostrarFeedbackAssinatura('erro', I18N_ASSINATURA.erroConexao);
    });
}

function salvarAssinaturaLivre() {
    if (!_assinaturaPad || _assinaturaPad.isEmpty()) {
        mostrarFeedbackAssinatura('erro', I18N_ASSINATURA.desenheAntes);
        return;
    }
    const btn = document.getElementById('btnSalvarLivre');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> ' + I18N_ASSINATURA.salvando;

    fetch('/medicos/' + MEDICO_ID_ASSINATURA + '/assinatura/livre/salvar', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ png: _assinaturaPad.toDataURL('image/png') })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-floppy-disk me-1"></i> ' + I18N_ASSINATURA.salvar;
        if (data.ok) {
            limparCanvasAssinatura();
            mostrarFeedbackAssinatura('sucesso', data.msg);
            carregarAssinaturas();
        } else {
            mostrarFeedbackAssinatura('erro', data.msg);
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-floppy-disk me-1"></i> ' + I18N_ASSINATURA.salvar;
        mostrarFeedbackAssinatura('erro', I18N_ASSINATURA.erroConexao);
    });
}

function ativarAssinatura(tipo) {
    fetch('/medicos/' + MEDICO_ID_ASSINATURA + '/assinatura/' + tipo + '/ativar', { method: 'POST' })
    .then(r => r.json())
    .then(data => {
        mostrarFeedbackAssinatura(data.ok ? 'sucesso' : 'erro', data.msg);
        if (data.ok) carregarAssinaturas();
    })
    .catch(() => mostrarFeedbackAssinatura('erro', I18N_ASSINATURA.erroConexao));
}

function desativarAssinatura(tipo) {
    fetch('/medicos/' + MEDICO_ID_ASSINATURA + '/assinatura/' + tipo + '/desativar', { method: 'POST' })
    .then(r => r.json())
    .then(data => {
        mostrarFeedbackAssinatura(data.ok ? 'sucesso' : 'erro', data.msg);
        if (data.ok) carregarAssinaturas();
    })
    .catch(() => mostrarFeedbackAssinatura('erro', I18N_ASSINATURA.erroConexao));
}

function mostrarFeedbackAssinatura(tipo, msg) {
    const fb = document.getElementById('assinaturaFeedback');
    if (!fb) return;
    const cor = tipo === 'sucesso' ? '#22c55e' : '#e05252';
    const ico = tipo === 'sucesso' ? 'fa-circle-check' : 'fa-triangle-exclamation';
    fb.style.display = 'block';
    fb.innerHTML = '<div style="padding:.6rem .9rem;border-radius:6px;background:rgba(0,0,0,.2);border:1px solid ' + cor + ';color:' + cor + ';font-size:.82rem;">'
        + '<i class="fa ' + ico + ' me-1"></i>' + escHtml(msg) + '</div>';
    setTimeout(() => { fb.style.display = 'none'; }, 4000);
}
</script>
