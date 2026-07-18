<?php
$regra   = $regra ?? null;
$isEdit  = !empty($regra);
$regraId = $isEdit ? (is_array($regra) ? ($regra['id'] ?? 0) : ($regra->id ?? 0)) : 0;
$action  = $isEdit ? '/sla-regras/' . $regraId . '/update' : '/sla-regras';
$unidades = $unidades ?? [];
$medicos  = $medicos ?? [];

$val = function (string $campo, $default = '') use ($regra) {
    if (!$regra) return $default;
    return is_array($regra) ? ($regra[$campo] ?? $default) : ($regra->$campo ?? $default);
};
$limiteMinutosTotal = (int) $val('limite_minutos', 0);
$horasAtuais = intdiv($limiteMinutosTotal, 60);
$minutosAtuais = $limiteMinutosTotal % 60;
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.3rem;font-weight:700;color:var(--pacs-text);margin-bottom:.25rem;">
            <i class="fa fa-gauge-high me-2 text-pacs-primary"></i>
            <?= $isEdit ? htmlspecialchars(t('sla_regras.form.titulo_editar')) : htmlspecialchars(t('sla_regras.form.titulo_novo')) ?>
        </h1>
    </div>
</div>

<div class="pacs-card" style="max-width:820px;">
    <div class="pacs-card-body">
        <form method="POST" action="<?= $action ?>">

            <div style="margin-bottom:1rem;">
                <label class="form-label-dark"><?= htmlspecialchars(t('sla_regras.form.campo_nome')) ?> *</label>
                <input type="text" name="nome" class="form-control-dark" required
                       value="<?= htmlspecialchars($val('nome')) ?>" placeholder="Ex: Estouro SLA Médico 2h20">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label class="form-label-dark"><?= htmlspecialchars(t('sla_regras.form.campo_metrica')) ?></label>
                    <select name="metrica" class="form-control-dark">
                        <option value="sla_medico" <?= $val('metrica', 'sla_medico') === 'sla_medico' ? 'selected' : '' ?>><?= htmlspecialchars(t('sla_regras.metrica.sla_medico')) ?></option>
                        <option value="sla_estudo" <?= $val('metrica') === 'sla_estudo' ? 'selected' : '' ?>><?= htmlspecialchars(t('sla_regras.metrica.sla_estudo')) ?></option>
                    </select>
                </div>
                <div>
                    <label class="form-label-dark"><?= htmlspecialchars(t('sla_regras.form.campo_operador')) ?></label>
                    <select name="operador" class="form-control-dark">
                        <option value="maior" <?= $val('operador', 'maior') === 'maior' ? 'selected' : '' ?>><?= htmlspecialchars(t('sla_regras.operador.maior')) ?></option>
                        <option value="menor" <?= $val('operador') === 'menor' ? 'selected' : '' ?>><?= htmlspecialchars(t('sla_regras.operador.menor')) ?></option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <label class="form-label-dark"><?= htmlspecialchars(t('sla_regras.form.campo_limite')) ?> *</label>
                <div style="display:flex;gap:.75rem;align-items:center;">
                    <input type="number" min="0" name="limite_horas" class="form-control-dark" style="max-width:120px;"
                           value="<?= $horasAtuais ?>"> <span style="color:var(--pacs-text-muted);">h</span>
                    <input type="number" min="0" max="59" name="limite_minutos_extra" class="form-control-dark" style="max-width:120px;"
                           value="<?= $minutosAtuais ?>"> <span style="color:var(--pacs-text-muted);">min</span>
                </div>
                <small style="color:var(--pacs-text-muted);"><?= htmlspecialchars(t('sla_regras.form.ajuda_limite')) ?></small>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label class="form-label-dark"><?= htmlspecialchars(t('sla_regras.form.campo_unidade')) ?></label>
                    <select name="filtro_institution_name" class="form-control-dark">
                        <option value=""><?= htmlspecialchars(t('sla_regras.form.campo_unidade_todas')) ?></option>
                        <?php foreach ($unidades as $u): ?>
                            <option value="<?= htmlspecialchars($u) ?>" <?= $val('filtro_institution_name') === $u ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label-dark"><?= htmlspecialchars(t('sla_regras.form.campo_modalidade')) ?></label>
                    <input type="text" name="filtro_modalidade" class="form-control-dark"
                           value="<?= htmlspecialchars($val('filtro_modalidade')) ?>" placeholder="Ex: CT, MR, US...">
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <label class="form-label-dark"><?= htmlspecialchars(t('sla_regras.form.campo_tipo_acao')) ?></label>
                <select name="tipo_acao" id="tipoAcaoSelect" class="form-control-dark" onchange="document.getElementById('blocoMedicoEspecifico').style.display = this.value === 'especifico' ? 'block' : 'none';">
                    <option value="menor_carga" <?= $val('tipo_acao', 'menor_carga') === 'menor_carga' ? 'selected' : '' ?>><?= htmlspecialchars(t('sla_regras.acao.menor_carga')) ?></option>
                    <option value="aleatorio" <?= $val('tipo_acao') === 'aleatorio' ? 'selected' : '' ?>><?= htmlspecialchars(t('sla_regras.acao.aleatorio')) ?></option>
                    <option value="especifico" <?= $val('tipo_acao') === 'especifico' ? 'selected' : '' ?>><?= htmlspecialchars(t('sla_regras.acao.especifico')) ?></option>
                </select>
            </div>

            <div id="blocoMedicoEspecifico" style="margin-bottom:1rem;display:<?= $val('tipo_acao') === 'especifico' ? 'block' : 'none' ?>;">
                <label class="form-label-dark"><?= htmlspecialchars(t('sla_regras.form.campo_medico_especifico')) ?></label>
                <select name="medico_especifico_id" class="form-control-dark">
                    <option value="">—</option>
                    <?php foreach ($medicos as $m): ?>
                        <option value="<?= (int) $m['id'] ?>" <?= (int) $val('medico_especifico_id') === (int) $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;align-items:end;">
                <div>
                    <label class="form-label-dark"><?= htmlspecialchars(t('sla_regras.form.campo_prioridade')) ?></label>
                    <input type="number" min="0" name="prioridade" class="form-control-dark" value="<?= (int) $val('prioridade', 0) ?>">
                    <small style="color:var(--pacs-text-muted);"><?= htmlspecialchars(t('sla_regras.form.ajuda_prioridade')) ?></small>
                </div>
                <div>
                    <label style="display:flex;align-items:center;gap:.5rem;font-weight:400;">
                        <input type="checkbox" name="ativo" value="1" <?= $val('ativo', 1) ? 'checked' : '' ?>>
                        <?= htmlspecialchars(t('comum.status.ativo')) ?>
                    </label>
                </div>
            </div>

            <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:1.5rem;">
                <a href="/sla-regras" class="btn-pacs-outline"><i class="fa fa-arrow-left"></i> <?= htmlspecialchars(t('sla_regras.form.botao_cancelar')) ?></a>
                <button type="submit" class="btn-pacs-primary"><i class="fa fa-floppy-disk"></i> <?= htmlspecialchars(t('sla_regras.form.botao_salvar')) ?></button>
            </div>
        </form>
    </div>
</div>
