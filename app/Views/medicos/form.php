<?php
$medico = $medico ?? null;
$isEdit = !empty($medico);
$medicoId = $isEdit ? (is_array($medico) ? ($medico['id'] ?? 0) : ($medico->id ?? 0)) : 0;
$action = $isEdit ? '/medicos/' . $medicoId . '/update' : '/medicos';
$usuarios = $usuarios ?? [];
$unidades = $unidades ?? [];
$unidadesMarcadas = $unidadesMarcadas ?? [];
$usuarioIdAtual = $isEdit ? (int) (is_array($medico) ? ($medico['usuario_id'] ?? 0) : ($medico->usuario_id ?? 0)) : 0;
$estados = \App\Core\Estados::all();
$val = function (string $campo) use ($medico) {
    if (!$medico) return '';
    return is_array($medico) ? ($medico[$campo] ?? '') : ($medico->$campo ?? '');
};
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

<div class="pacs-card" style="max-width:920px;">
    <div class="pacs-card-body">
        <form method="POST" action="<?= $action ?>">

            <div style="margin-bottom:1.25rem;">
                <label class="form-label-dark">Nome Completo *</label>
                <input type="text" name="nome" class="form-control-dark"
                       value="<?= htmlspecialchars(is_array($medico) ? ($medico['nome'] ?? '') : ($medico?->nome ?? '')) ?>"
                       required placeholder="Dr. João Silva">
            </div>

            <div class="form-section-title">Dados Profissionais</div>

            <div class="form-grid" style="grid-template-columns:1fr 90px 1.6fr;">
                <div>
                    <label class="form-label-dark">CRM</label>
                    <input type="text" name="crm" class="form-control-dark"
                           value="<?= htmlspecialchars(is_array($medico) ? ($medico['crm'] ?? '') : ($medico?->crm ?? '')) ?>"
                           placeholder="123456">
                </div>
                <div>
                    <label class="form-label-dark"><?= htmlspecialchars(t('medicos.form.campo_crm_uf')) ?></label>
                    <select name="crm_uf" class="form-control-dark">
                        <option value="">—</option>
                        <?php foreach ($estados as $uf => $nomeEstado): ?>
                            <option value="<?= $uf ?>" <?= strtoupper($val('crm_uf')) === $uf ? 'selected' : '' ?>><?= $uf ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label-dark">Especialidade</label>
                    <input type="text" name="especialidade" class="form-control-dark"
                           value="<?= htmlspecialchars(is_array($medico) ? ($medico['especialidade'] ?? '') : ($medico?->especialidade ?? '')) ?>"
                           placeholder="Radiologia e Diagnóstico por Imagem">
                </div>
            </div>

            <div class="form-grid" style="grid-template-columns:1fr 1fr;">
                <div>
                    <label class="form-label-dark">E-mail</label>
                    <input type="email" name="email" class="form-control-dark"
                           value="<?= htmlspecialchars(is_array($medico) ? ($medico['email'] ?? '') : ($medico?->email ?? '')) ?>"
                           placeholder="medico@clinica.com.br">
                </div>
                <div>
                    <label class="form-label-dark">Telefone</label>
                    <input type="text" name="telefone" class="form-control-dark"
                           value="<?= htmlspecialchars(is_array($medico) ? ($medico['telefone'] ?? '') : ($medico?->telefone ?? '')) ?>"
                           placeholder="(11) 99999-9999">
                </div>
            </div>

            <div class="form-section-title">Endereço</div>

            <div class="form-grid" style="grid-template-columns:140px 2fr 110px;">
                <div>
                    <label class="form-label-dark"><?= htmlspecialchars(t('medicos.form.campo_cep')) ?></label>
                    <input type="text" id="medicoCep" name="cep" class="form-control-dark" maxlength="9"
                           value="<?= htmlspecialchars($val('cep')) ?>"
                           placeholder="00000-000" onblur="buscarCepMedico()">
                    <small id="medicoCepStatus" style="display:block;min-height:1.1em;font-size:.72rem;color:var(--pacs-text-muted);margin-top:.25rem;"></small>
                </div>
                <div>
                    <label class="form-label-dark"><?= htmlspecialchars(t('medicos.form.campo_logradouro')) ?></label>
                    <input type="text" id="medicoLogradouro" name="logradouro" class="form-control-dark" value="<?= htmlspecialchars($val('logradouro')) ?>">
                </div>
                <div>
                    <label class="form-label-dark"><?= htmlspecialchars(t('medicos.form.campo_numero')) ?></label>
                    <input type="text" name="numero" class="form-control-dark" value="<?= htmlspecialchars($val('numero')) ?>">
                </div>
            </div>

            <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr 90px;">
                <div>
                    <label class="form-label-dark"><?= htmlspecialchars(t('medicos.form.campo_complemento')) ?></label>
                    <input type="text" name="complemento" class="form-control-dark" value="<?= htmlspecialchars($val('complemento')) ?>">
                </div>
                <div>
                    <label class="form-label-dark"><?= htmlspecialchars(t('medicos.form.campo_bairro')) ?></label>
                    <input type="text" id="medicoBairro" name="bairro" class="form-control-dark" value="<?= htmlspecialchars($val('bairro')) ?>">
                </div>
                <div>
                    <label class="form-label-dark"><?= htmlspecialchars(t('medicos.form.campo_cidade')) ?></label>
                    <input type="text" id="medicoCidade" name="cidade" class="form-control-dark" value="<?= htmlspecialchars($val('cidade')) ?>">
                </div>
                <div>
                    <label class="form-label-dark"><?= htmlspecialchars(t('medicos.form.campo_estado')) ?></label>
                    <select id="medicoEstado" name="estado" class="form-control-dark">
                        <option value="">—</option>
                        <?php foreach ($estados as $uf => $nomeEstado): ?>
                            <option value="<?= $uf ?>" <?= strtoupper($val('estado')) === $uf ? 'selected' : '' ?>><?= $uf ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-section-title">Vínculo e Unidades</div>

            <div class="form-grid" style="grid-template-columns:1fr;">
                <div>
                    <label class="form-label-dark"><?= htmlspecialchars(t('medicos.form.campo_usuario')) ?></label>
                    <select name="usuario_id" class="form-control-dark" style="max-width:420px;">
                        <option value=""><?= htmlspecialchars(t('medicos.form.usuario_nao_vinculado')) ?></option>
                        <?php foreach ($usuarios as $u): ?>
                            <?php $uId = is_array($u) ? $u['id'] : $u->id; $uNome = is_array($u) ? $u['name'] : $u->name; ?>
                            <option value="<?= (int) $uId ?>" <?= $uId == $usuarioIdAtual ? 'selected' : '' ?>><?= htmlspecialchars($uNome) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:var(--pacs-text-muted);"><?= htmlspecialchars(t('medicos.form.campo_usuario_ajuda')) ?></small>
                </div>
            </div>

            <div style="margin-bottom:1.25rem;">
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

            <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:1.5rem;border-top:1px solid var(--pacs-border);padding-top:1.25rem;">
                <a href="/medicos" class="btn-pacs-outline"><i class="fa fa-arrow-left"></i> Cancelar</a>
                <button type="submit" class="btn-pacs-primary">
                    <i class="fa fa-floppy-disk"></i> <?= $isEdit ? 'Salvar Alterações' : 'Cadastrar Médico' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function buscarCepMedico() {
    const cepInput = document.getElementById('medicoCep');
    const status = document.getElementById('medicoCepStatus');
    const cep = cepInput.value.replace(/\D/g, '');

    if (cep.length !== 8) {
        if (cep.length > 0) status.innerHTML = '<span style="color:#e05252;"><?= addslashes(t('medicos.form.cep_nao_encontrado')) ?></span>';
        return;
    }

    status.innerHTML = '<span><?= addslashes(t('medicos.form.cep_buscando')) ?></span>';

    fetch('/api/medicos/cep/' + cep)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                status.innerHTML = '<span style="color:#e05252;">' + data.error + '</span>';
                return;
            }
            status.innerHTML = '';
            if (data.logradouro) document.getElementById('medicoLogradouro').value = data.logradouro;
            if (data.bairro) document.getElementById('medicoBairro').value = data.bairro;
            if (data.cidade) document.getElementById('medicoCidade').value = data.cidade;
            if (data.estado) document.getElementById('medicoEstado').value = data.estado;
        })
        .catch(() => {
            status.innerHTML = '<span style="color:#e05252;"><?= addslashes(t('medicos.form.cep_nao_encontrado')) ?></span>';
        });
}
</script>
