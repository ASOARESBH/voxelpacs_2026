<?php $execucoes = $execucoes ?? []; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.3rem;font-weight:700;color:var(--pacs-text);margin-bottom:.25rem;">
            <i class="fa fa-clock-rotate-left me-2 text-pacs-primary"></i><?= htmlspecialchars(t('sla_regras.execucoes.titulo')) ?>
        </h1>
        <p style="color:var(--pacs-text-muted);font-size:.82rem;"><?= htmlspecialchars(t('sla_regras.execucoes.subtitulo')) ?></p>
    </div>
    <a href="/sla-regras" class="btn-pacs-outline"><i class="fa fa-arrow-left"></i></a>
</div>

<div class="pacs-card">
    <div style="overflow-x:auto;">
        <table class="platform-table">
            <thead>
                <tr>
                    <th><?= htmlspecialchars(t('sla_regras.execucoes.coluna_data')) ?></th>
                    <th><?= htmlspecialchars(t('sla_regras.execucoes.coluna_regra')) ?></th>
                    <th><?= htmlspecialchars(t('sla_regras.execucoes.coluna_estudo')) ?></th>
                    <th><?= htmlspecialchars(t('sla_regras.execucoes.coluna_medico_anterior')) ?></th>
                    <th><?= htmlspecialchars(t('sla_regras.execucoes.coluna_medico_novo')) ?></th>
                    <th><?= htmlspecialchars(t('sla_regras.execucoes.coluna_minutos')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($execucoes)): ?>
                <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--pacs-text-muted);">
                    <?= htmlspecialchars(t('sla_regras.execucoes.vazio_titulo')) ?>
                </td></tr>
            <?php else: ?>
                <?php foreach ($execucoes as $e): ?>
                <tr>
                    <td style="font-size:.8rem;"><?= htmlspecialchars($e->executado_em) ?></td>
                    <td><?= htmlspecialchars($e->regra_nome_atual ?? $e->regra_nome_snapshot) ?></td>
                    <td>#<?= (int) $e->estudo_id ?></td>
                    <td style="color:var(--pacs-text-muted);"><?= $e->medico_anterior_usuario_id ? '#' . (int) $e->medico_anterior_usuario_id : '—' ?></td>
                    <td>#<?= (int) $e->medico_novo_usuario_id ?></td>
                    <td><?= (int) $e->minutos_decorridos ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
