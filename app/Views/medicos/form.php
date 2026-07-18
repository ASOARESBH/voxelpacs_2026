<?php
$medico = $medico ?? null;
$isEdit = !empty($medico);
$medicoId = $isEdit ? (is_array($medico) ? ($medico['id'] ?? 0) : ($medico->id ?? 0)) : 0;
$action = $isEdit ? '/medicos/' . $medicoId . '/update' : '/medicos';
$usuarios = $usuarios ?? [];
$unidades = $unidades ?? [];
$unidadesMarcadas = $unidadesMarcadas ?? [];
$usuarioIdAtual = $isEdit ? (int) (is_array($medico) ? ($medico['usuario_id'] ?? 0) : ($medico->usuario_id ?? 0)) : 0;
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.3rem;font-weight:700;color:var(--pacs-text);margin-bottom:.25rem;">
            <i class="fa fa-user-doctor me-2 text-pacs-primary"></i>
            <?= $isEdit ? 'Editar Médico' : 'Novo Médico' ?>
        </h1>
        <p style="color:var(--pacs-text-muted);font-size:.82rem;">
            <?= $isEdit ? 'Atualize os dados do médico' : 'Preencha os dados para cadastrar um novo médico' ?>
        </p>
    </div>
    <?php if (!$isEdit): ?>
    <button type="button" class="btn-pacs-outline" disabled
            style="opacity:.6;cursor:not-allowed;"
            title="Em breve: importa o cadastro do médico a partir de outro produto usando um token de integração. Funcionalidade ainda não implementada.">
        <i class="fa fa-key"></i> Token Import
    </button>
    <?php endif; ?>
</div>

<div class="pacs-card" style="max-width:700px;">
    <div class="pacs-card-body">
        <form method="POST" action="<?= $action ?>">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label class="form-label-dark">Nome Completo *</label>
                    <input type="text" name="nome" class="form-control-dark"
                           value="<?= htmlspecialchars(is_array($medico) ? ($medico['nome'] ?? '') : ($medico?->nome ?? '')) ?>"
                           required placeholder="Dr. João Silva">
                </div>
                <div>
                    <label class="form-label-dark">CRM</label>
                    <input type="text" name="crm" class="form-control-dark"
                           value="<?= htmlspecialchars(is_array($medico) ? ($medico['crm'] ?? '') : ($medico?->crm ?? '')) ?>"
                           placeholder="CRM/SP 123456">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label class="form-label-dark">Especialidade</label>
                    <input type="text" name="especialidade" class="form-control-dark"
                           value="<?= htmlspecialchars(is_array($medico) ? ($medico['especialidade'] ?? '') : ($medico?->especialidade ?? '')) ?>"
                           placeholder="Radiologia e Diagnóstico por Imagem">
                </div>
                <div>
                    <label class="form-label-dark">E-mail</label>
                    <input type="email" name="email" class="form-control-dark"
                           value="<?= htmlspecialchars(is_array($medico) ? ($medico['email'] ?? '') : ($medico?->email ?? '')) ?>"
                           placeholder="medico@clinica.com.br">
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <label class="form-label-dark">Telefone</label>
                <input type="text" name="telefone" class="form-control-dark"
                       value="<?= htmlspecialchars(is_array($medico) ? ($medico['telefone'] ?? '') : ($medico?->telefone ?? '')) ?>"
                       placeholder="(11) 99999-9999">
            </div>

            <div style="margin-bottom:1rem;">
                <label class="form-label-dark"><?= htmlspecialchars(t('medicos.form.campo_usuario')) ?></label>
                <select name="usuario_id" class="form-control-dark">
                    <option value=""><?= htmlspecialchars(t('medicos.form.usuario_nao_vinculado')) ?></option>
                    <?php foreach ($usuarios as $u): ?>
                        <?php $uId = is_array($u) ? $u['id'] : $u->id; $uNome = is_array($u) ? $u['name'] : $u->name; ?>
                        <option value="<?= (int) $uId ?>" <?= $uId == $usuarioIdAtual ? 'selected' : '' ?>><?= htmlspecialchars($uNome) ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color:var(--pacs-text-muted);"><?= htmlspecialchars(t('medicos.form.campo_usuario_ajuda')) ?></small>
            </div>

            <div style="margin-bottom:1rem;">
                <label class="form-label-dark"><?= htmlspecialchars(t('medicos.form.campo_unidades')) ?></label>
                <div style="display:flex;flex-wrap:wrap;gap:.6rem;padding:.6rem;border:1px solid var(--pacs-border,#3a3f4b);border-radius:6px;">
                    <?php if (empty($unidades)): ?>
                        <span style="color:var(--pacs-text-muted);font-size:.8rem;">—</span>
                    <?php endif; ?>
                    <?php foreach ($unidades as $unidade): ?>
                        <label style="display:flex;align-items:center;gap:.35rem;font-size:.82rem;font-weight:400;">
                            <input type="checkbox" name="unidades[]" value="<?= htmlspecialchars($unidade) ?>"
                                   <?= in_array($unidade, $unidadesMarcadas, true) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($unidade) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:1.5rem;">
                <a href="/medicos" class="btn-pacs-outline"><i class="fa fa-arrow-left"></i> Cancelar</a>
                <button type="submit" class="btn-pacs-primary">
                    <i class="fa fa-floppy-disk"></i> <?= $isEdit ? 'Salvar Alterações' : 'Cadastrar Médico' ?>
                </button>
            </div>
        </form>
    </div>
</div>
