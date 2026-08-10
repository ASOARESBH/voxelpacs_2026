<?php
$grupo               = $grupo ?? null;
$isEdit              = !empty($grupo) && !empty($grupo['id'] ?? null);
$grupoId             = $isEdit ? (int) ($grupo['id'] ?? 0) : 0;
$action              = $isEdit ? '/usuarios/grupos/' . $grupoId . '/atualizar' : '/usuarios/grupos';
$erros               = $erros ?? [];
$sugestoes           = $sugestoes ?? [];
$membros             = $membros ?? [];
$usuariosDisponiveis = $usuariosDisponiveis ?? [];
$sucesso             = $sucesso ?? '';

$val = function (string $campo) use ($grupo): string {
    if (!$grupo) return '';
    $v = is_array($grupo) ? ($grupo[$campo] ?? '') : ($grupo->$campo ?? '');
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

$sucessoMsgs = [
    'membros_adicionados' => t('usuarios.grupos.sucesso.membros_adicionados'),
    'membro_removido'     => t('usuarios.grupos.sucesso.membro_removido'),
];
?>

<style>
.grupo-form-card {
    background: var(--pacs-card-bg, #1e2330);
    border: 1px solid var(--pacs-border, #2d3244);
    border-radius: 10px;
    overflow: hidden;
}
.grupo-section { padding: 1.5rem 1.75rem; border-bottom: 1px solid var(--pacs-border, #2d3244); }
.grupo-section:last-child { border-bottom: none; }
.grupo-section-header {
    display: flex; align-items: center; gap: .5rem;
    font-size: .7rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
    color: var(--pacs-text-muted, #8892a4);
    margin-bottom: 1.25rem; padding-bottom: .6rem;
    border-bottom: 1px solid var(--pacs-border, #2d3244);
}
.grupo-section-header i { color: var(--pacs-primary, #1a56db); font-size: .8rem; }
.grupo-label { display:block; font-size:.78rem; font-weight:600; color:var(--pacs-text-muted, #8892a4); margin-bottom:.35rem; }
.grupo-label .req { color:#e05252; margin-left:.15rem; }
.grupo-input, .grupo-textarea {
    width:100%; padding:.55rem .75rem; font-size:.875rem; color:var(--pacs-text, #e2e8f0);
    background:var(--pacs-input-bg, #252b3b); border:1px solid var(--pacs-border, #3a3f4b);
    border-radius:6px; outline:none; box-sizing:border-box;
}
.grupo-input { height:38px; }
.grupo-input:focus, .grupo-textarea:focus { border-color:var(--pacs-primary, #1a56db); box-shadow:0 0 0 3px rgba(26,86,219,.18); }
.grupo-input.is-invalid { border-color:#e05252; }

.grupo-sugestoes { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; margin-top:.5rem; }
.grupo-sugestao-label { font-size:.72rem; color:var(--pacs-text-muted); }
.grupo-sugestao-btn {
    padding:.25rem .7rem; font-size:.75rem; font-weight:600; border-radius:20px;
    border:1px solid var(--pacs-border, #3a3f4b); background:transparent; color:var(--pacs-text-muted);
    cursor:pointer; transition:all .15s;
}
.grupo-sugestao-btn:hover { border-color:var(--pacs-primary, #1a56db); color:var(--pacs-primary, #1a56db); }

.grupo-membro-item {
    display:flex; align-items:center; justify-content:space-between;
    padding:.55rem .75rem; border:1px solid var(--pacs-border, #2d3244); border-radius:6px; margin-bottom:.4rem;
}
.grupo-membro-info { display:flex; flex-direction:column; }
.grupo-membro-nome { font-size:.85rem; font-weight:600; }
.grupo-membro-email { font-size:.72rem; color:var(--pacs-text-muted); }

.grupo-disponiveis-list {
    max-height: 220px; overflow-y:auto; border:1px solid var(--pacs-border, #3a3f4b); border-radius:6px; padding:.5rem;
}
.grupo-disponivel-item { display:flex; align-items:center; gap:.5rem; padding:.35rem .25rem; font-size:.82rem; }
.grupo-disponivel-item input[type=checkbox] { accent-color:var(--pacs-primary); }
</style>

<!-- Cabeçalho -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">
            <i class="fa fa-layer-group me-2 text-pacs-primary"></i>
            <?= $isEdit ? htmlspecialchars(t('usuarios.grupos.form.titulo_editar')) : htmlspecialchars(t('usuarios.grupos.form.titulo_novo')) ?>
        </h1>
    </div>
    <a href="/usuarios/grupos" class="btn-pacs-outline">
        <i class="fa fa-arrow-left me-1"></i> <?= htmlspecialchars(t('comum.acoes.voltar')) ?>
    </a>
</div>

<!-- Alertas de erro -->
<?php if (!empty($erros)): ?>
<div class="pacs-alert pacs-alert-danger mb-4" id="alertErros">
    <i class="fa fa-triangle-exclamation me-2"></i>
    <ul class="mb-0 mt-2 ps-3">
        <?php foreach ($erros as $erro): ?>
            <li><?= htmlspecialchars($erro) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Alertas de sucesso (ações de membros, via redirect) -->
<?php if ($sucesso && isset($sucessoMsgs[$sucesso])): ?>
<div class="pacs-alert pacs-alert-success mb-4">
    <i class="fa fa-check-circle me-2"></i> <?= htmlspecialchars($sucessoMsgs[$sucesso]) ?>
</div>
<?php endif; ?>

<!-- ── Formulário: Dados do Grupo ─────────────────────────────────────── -->
<form method="POST" action="<?= $action ?>" id="formGrupo" novalidate>
<div class="grupo-form-card mb-3">
    <div class="grupo-section">
        <div class="grupo-section-header">
            <i class="fa fa-id-card"></i> <?= htmlspecialchars(t('usuarios.grupos.form.secao_dados')) ?>
        </div>

        <div class="mb-3">
            <label class="grupo-label" for="grupoNome">
                <?= htmlspecialchars(t('usuarios.grupos.form.campo_nome')) ?> <span class="req">*</span>
            </label>
            <input type="text" id="grupoNome" name="nome" class="grupo-input"
                   value="<?= $val('nome') ?>" maxlength="200" required>
            <div class="grupo-sugestoes">
                <span class="grupo-sugestao-label"><?= htmlspecialchars(t('usuarios.grupos.form.sugestoes_label')) ?></span>
                <?php foreach ($sugestoes as $s): ?>
                    <button type="button" class="grupo-sugestao-btn" onclick="document.getElementById('grupoNome').value='<?= htmlspecialchars($s, ENT_QUOTES) ?>'">
                        <?= htmlspecialchars($s) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div>
            <label class="grupo-label" for="grupoDescricao"><?= htmlspecialchars(t('usuarios.grupos.form.campo_descricao')) ?></label>
            <textarea id="grupoDescricao" name="descricao" class="grupo-textarea" rows="2" maxlength="500"
                      placeholder="<?= htmlspecialchars(t('usuarios.grupos.form.descricao_placeholder')) ?>"><?= $val('descricao') ?></textarea>
        </div>
    </div>
</div>

<div style="display:flex;gap:.75rem;align-items:center;margin-bottom:1.5rem;">
    <button type="submit" class="btn-pacs-primary">
        <i class="fa fa-save me-1"></i> <?= htmlspecialchars(t('usuarios.grupos.form.botao_salvar')) ?>
    </button>
    <a href="/usuarios/grupos" class="btn-pacs-outline"><?= htmlspecialchars(t('usuarios.grupos.form.botao_cancelar')) ?></a>
</div>
</form>

<!-- ── Painel de Membros — só disponível após o grupo existir ─────────── -->
<?php if ($isEdit): ?>
<div class="grupo-form-card">
    <div class="grupo-section">
        <div class="grupo-section-header">
            <i class="fa fa-user-group"></i> <?= htmlspecialchars(t('usuarios.grupos.form.secao_membros')) ?>
        </div>

        <?php if (empty($membros)): ?>
            <p style="font-size:.82rem;color:var(--pacs-text-muted);"><?= htmlspecialchars(t('usuarios.grupos.membros.vazio')) ?></p>
        <?php else: ?>
            <?php foreach ($membros as $m): ?>
            <div class="grupo-membro-item">
                <div class="grupo-membro-info">
                    <span class="grupo-membro-nome"><?= htmlspecialchars($m['name'] ?? '') ?></span>
                    <span class="grupo-membro-email"><?= htmlspecialchars($m['email'] ?? '') ?></span>
                </div>
                <form method="POST" action="/usuarios/grupos/<?= $grupoId ?>/usuarios/<?= (int) $m['id'] ?>/remover"
                      onsubmit="return confirm('<?= addslashes(t('usuarios.grupos.membros.confirma_remover')) ?>')">
                    <button type="submit" class="btn-pacs-outline" style="padding:.2rem .5rem;font-size:.72rem;color:#e05252;">
                        <i class="fa fa-user-minus me-1"></i> <?= htmlspecialchars(t('usuarios.grupos.membros.remover')) ?>
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <hr style="border-color:var(--pacs-border, #2d3244);margin:1.25rem 0;">

        <?php if (empty($usuariosDisponiveis)): ?>
            <p style="font-size:.8rem;color:var(--pacs-text-muted);"><?= htmlspecialchars(t('usuarios.grupos.membros.nenhum_disponivel')) ?></p>
        <?php else: ?>
            <form method="POST" action="/usuarios/grupos/<?= $grupoId ?>/usuarios/adicionar">
                <label class="grupo-label"><?= htmlspecialchars(t('usuarios.grupos.membros.disponiveis_label')) ?></label>
                <div class="grupo-disponiveis-list">
                    <?php foreach ($usuariosDisponiveis as $u): ?>
                    <label class="grupo-disponivel-item">
                        <input type="checkbox" name="usuario_ids[]" value="<?= (int) $u['id'] ?>">
                        <span><?= htmlspecialchars($u['name'] ?? '') ?> <span style="color:var(--pacs-text-muted);">(<?= htmlspecialchars($u['email'] ?? '') ?>)</span></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn-pacs-primary mt-2" style="font-size:.82rem;">
                    <i class="fa fa-user-plus me-1"></i> <?= htmlspecialchars(t('usuarios.grupos.membros.adicionar_botao')) ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="pacs-alert" style="background:rgba(100,116,139,.1);color:var(--pacs-text-muted);">
    <i class="fa fa-circle-info me-2"></i> <?= htmlspecialchars(t('usuarios.grupos.form.membros_apos_criar')) ?>
</div>
<?php endif; ?>
