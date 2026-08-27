<?php
/** MVP Agendamentos: formulário tenant-scoped; MWL permanece aguardando infraestrutura. */
?>
<!-- TABS -->
<div class="pacs-tabs">
    <a href="/agendamentos" class="pacs-tab active">
        <i class="fa fa-calendar-days"></i> Agendamentos
    </a>
    <a href="/estudos" class="pacs-tab">
        <i class="fa fa-list-check"></i> Estudos
    </a>
</div>

<div style="padding:2rem;">
    <?php if ($success): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-4">
            <section class="pacs-card p-4 h-100">
                <div class="d-flex align-items-center gap-2 mb-3"><i class="fa fa-calendar-plus text-pacs-primary"></i><h3 class="h5 mb-0"><?= htmlspecialchars(t('agendamentos.novo_titulo')) ?></h3></div>
                <p class="small" style="color:var(--pacs-text-muted)"><?= htmlspecialchars(t('agendamentos.novo_ajuda')) ?></p>
                <?php if (empty($unidades) || empty($modalidades)): ?>
                    <div class="alert alert-warning mb-0"><?= htmlspecialchars(t('agendamentos.sem_configuracao')) ?></div>
                <?php else: ?>
                    <form method="post" action="/agendamentos" autocomplete="off">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <div class="mb-3"><label class="form-label" for="patient_name"><?= htmlspecialchars(t('agendamentos.campo_nome')) ?></label><input id="patient_name" class="form-control" name="patient_name" maxlength="64" required></div>
                        <div class="mb-3"><label class="form-label" for="patient_birth_date"><?= htmlspecialchars(t('agendamentos.campo_nascimento')) ?></label><input id="patient_birth_date" class="form-control" type="date" name="patient_birth_date" max="<?= date('Y-m-d') ?>" required></div>
                        <div class="mb-3"><label class="form-label" for="unidade_id"><?= htmlspecialchars(t('agendamentos.campo_unidade')) ?></label><select id="unidade_id" class="form-select" name="unidade_id" required><option value=""><?= htmlspecialchars(t('agendamentos.selecione')) ?></option><?php foreach ($unidades as $unidade): ?><option value="<?= (int) $unidade['id'] ?>"><?= htmlspecialchars($unidade['nome']) ?></option><?php endforeach; ?></select></div>
                        <div class="mb-3"><label class="form-label" for="modalidade"><?= htmlspecialchars(t('agendamentos.campo_modalidade')) ?></label><select id="modalidade" class="form-select" name="modalidade" required><option value=""><?= htmlspecialchars(t('agendamentos.selecione')) ?></option><?php foreach ($modalidades as $modalidade): ?><option value="<?= htmlspecialchars($modalidade) ?>"><?= htmlspecialchars($modalidade) ?></option><?php endforeach; ?></select></div>
                        <div class="row g-2"><div class="col-sm-7"><label class="form-label" for="data_agendada"><?= htmlspecialchars(t('agendamentos.campo_data')) ?></label><input id="data_agendada" class="form-control" type="date" name="data_agendada" min="<?= date('Y-m-d') ?>" required></div><div class="col-sm-5"><label class="form-label" for="hora_agendada"><?= htmlspecialchars(t('agendamentos.campo_hora')) ?></label><input id="hora_agendada" class="form-control" type="time" name="hora_agendada"></div></div>
                        <button class="btn-pacs-primary mt-4" type="submit"><i class="fa fa-floppy-disk"></i> <?= htmlspecialchars(t('agendamentos.salvar')) ?></button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
        <div class="col-xl-8">
            <section class="pacs-card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center gap-3 mb-3"><div><h3 class="h5 mb-1"><?= htmlspecialchars(t('agendamentos.lista_titulo')) ?></h3><p class="small mb-0" style="color:var(--pacs-text-muted)"><?= htmlspecialchars(t('agendamentos.lista_ajuda')) ?></p></div><span class="badge text-bg-secondary"><?= count($agendamentos) ?></span></div>
                <?php if (empty($agendamentos)): ?><div class="text-center py-5" style="color:var(--pacs-text-muted)"><i class="fa fa-calendar-xmark fa-2x d-block mb-3"></i><?= htmlspecialchars(t('agendamentos.vazio')) ?></div><?php else: ?>
                    <div class="table-responsive"><table class="table align-middle"><thead><tr><th><?= htmlspecialchars(t('agendamentos.col_paciente')) ?></th><th><?= htmlspecialchars(t('agendamentos.col_exame')) ?></th><th><?= htmlspecialchars(t('agendamentos.col_unidade')) ?></th><th><?= htmlspecialchars(t('agendamentos.col_status')) ?></th><th class="text-end"><?= htmlspecialchars(t('agendamentos.col_acoes')) ?></th></tr></thead><tbody><?php foreach ($agendamentos as $agendamento): ?><tr><td><strong><?= htmlspecialchars($agendamento['patient_name']) ?></strong><div class="small" style="color:var(--pacs-text-muted)"><?= htmlspecialchars($agendamento['patient_birth_date']) ?></div></td><td><strong><?= htmlspecialchars($agendamento['modalidade']) ?></strong><div class="small" style="color:var(--pacs-text-muted)"><?= htmlspecialchars($agendamento['data_agendada']) ?><?= $agendamento['hora_agendada'] ? ' · ' . htmlspecialchars(substr((string) $agendamento['hora_agendada'], 0, 5)) : '' ?></div></td><td><?= htmlspecialchars($agendamento['unidade_nome']) ?></td><td><span class="badge <?= $agendamento['situacao'] === 'agendado' ? 'text-bg-primary' : ($agendamento['situacao'] === 'realizado' ? 'text-bg-success' : 'text-bg-secondary') ?>"><?= htmlspecialchars(t('agendamentos.status.' . $agendamento['situacao'])) ?></span><div class="small mt-1" style="color:var(--pacs-text-muted)"><?= htmlspecialchars(t('agendamentos.mwl_aguardando')) ?></div></td><td class="text-end"><?php if ($agendamento['situacao'] === 'agendado'): ?><form method="post" action="/agendamentos/<?= (int) $agendamento['id'] ?>/cancelar" onsubmit="return confirm('<?= htmlspecialchars(t('agendamentos.confirmar_cancelar'), ENT_QUOTES) ?>');"><input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><?= htmlspecialchars(t('agendamentos.cancelar')) ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>
