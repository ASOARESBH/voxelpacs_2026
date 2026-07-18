<?php $regras = $regras ?? []; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem;">
    <div>
        <h1 style="font-size:1.3rem;font-weight:700;color:var(--pacs-text);margin-bottom:.25rem;">
            <i class="fa fa-gauge-high me-2 text-pacs-primary"></i><?= htmlspecialchars(t('sla_regras.index.titulo')) ?>
        </h1>
        <p style="color:var(--pacs-text-muted);font-size:.82rem;"><?= htmlspecialchars(t('sla_regras.index.subtitulo')) ?></p>
    </div>
    <div style="display:flex;gap:.5rem;">
        <a href="/sla-regras/execucoes" class="btn-pacs-outline"><i class="fa fa-clock-rotate-left"></i> <?= htmlspecialchars(t('sla_regras.index.botao_historico')) ?></a>
        <a href="/sla-regras/robo" class="btn-pacs-outline"><i class="fa fa-robot"></i> <?= htmlspecialchars(t('sla_regras.index.botao_robo')) ?></a>
        <a href="/sla-regras/create" class="btn-pacs-primary"><i class="fa fa-plus"></i> <?= htmlspecialchars(t('sla_regras.index.botao_novo')) ?></a>
    </div>
</div>

<div class="pacs-card">
    <div style="overflow-x:auto;">
        <table class="platform-table">
            <thead>
                <tr>
                    <th><?= htmlspecialchars(t('sla_regras.index.coluna_nome')) ?></th>
                    <th><?= htmlspecialchars(t('sla_regras.index.coluna_metrica')) ?></th>
                    <th><?= htmlspecialchars(t('sla_regras.index.coluna_condicao')) ?></th>
                    <th><?= htmlspecialchars(t('sla_regras.index.coluna_filtros')) ?></th>
                    <th><?= htmlspecialchars(t('sla_regras.index.coluna_acao')) ?></th>
                    <th><?= htmlspecialchars(t('sla_regras.index.coluna_prioridade')) ?></th>
                    <th><?= htmlspecialchars(t('sla_regras.index.coluna_status')) ?></th>
                    <th><?= htmlspecialchars(t('sla_regras.index.coluna_acoes')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($regras)): ?>
                <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--pacs-text-muted);">
                    <i class="fa fa-gauge-high fa-2x d-block mb-2"></i>
                    <?= htmlspecialchars(t('sla_regras.index.vazio_titulo')) ?>
                    <br><a href="/sla-regras/create"><?= htmlspecialchars(t('sla_regras.index.vazio_link')) ?></a>
                </td></tr>
            <?php else: ?>
                <?php foreach ($regras as $r): ?>
                <?php
                    $rId       = is_array($r) ? $r['id'] : $r->id;
                    $rNome     = is_array($r) ? $r['nome'] : $r->nome;
                    $rMetrica  = is_array($r) ? $r['metrica'] : $r->metrica;
                    $rOperador = is_array($r) ? $r['operador'] : $r->operador;
                    $rLimite   = (int) (is_array($r) ? $r['limite_minutos'] : $r->limite_minutos);
                    $rUnidade  = is_array($r) ? ($r['filtro_institution_name'] ?? null) : ($r->filtro_institution_name ?? null);
                    $rModal    = is_array($r) ? ($r['filtro_modalidade'] ?? null) : ($r->filtro_modalidade ?? null);
                    $rAcao     = is_array($r) ? $r['tipo_acao'] : $r->tipo_acao;
                    $rMedNome  = is_array($r) ? ($r['medico_especifico_nome'] ?? null) : ($r->medico_especifico_nome ?? null);
                    $rPrio     = is_array($r) ? $r['prioridade'] : $r->prioridade;
                    $rAtivo    = (int) (is_array($r) ? $r['ativo'] : $r->ativo);
                    $horas     = intdiv($rLimite, 60);
                    $mins      = $rLimite % 60;
                ?>
                <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($rNome) ?></td>
                    <td><?= htmlspecialchars(t('sla_regras.metrica.' . $rMetrica)) ?></td>
                    <td><?= htmlspecialchars(t('sla_regras.operador.' . $rOperador)) ?> <?= $horas ?>h <?= $mins ?>min</td>
                    <td style="font-size:.78rem;color:var(--pacs-text-muted);">
                        <?= $rUnidade ? htmlspecialchars($rUnidade) : htmlspecialchars(t('sla_regras.form.campo_unidade_todas')) ?>
                        <?= $rModal ? ' · ' . htmlspecialchars($rModal) : '' ?>
                    </td>
                    <td>
                        <?= htmlspecialchars(t('sla_regras.acao.' . $rAcao)) ?>
                        <?= $rAcao === 'especifico' && $rMedNome ? ' — ' . htmlspecialchars($rMedNome) : '' ?>
                    </td>
                    <td style="text-align:center;"><?= (int) $rPrio ?></td>
                    <td><span class="badge badge-<?= $rAtivo ? 'ativo' : 'inativo' ?>"><?= $rAtivo ? htmlspecialchars(t('comum.status.ativo')) : htmlspecialchars(t('comum.status.inativo')) ?></span></td>
                    <td>
                        <div style="display:flex;gap:.3rem;">
                            <a href="/sla-regras/<?= (int) $rId ?>/edit" class="pacs-btn" title="<?= htmlspecialchars(t('sla_regras.index.acao_editar')) ?>"><i class="fa fa-pen"></i></a>
                            <form method="POST" action="/sla-regras/<?= (int) $rId ?>/toggle" style="display:inline;">
                                <button type="submit" class="pacs-btn" title="<?= $rAtivo ? htmlspecialchars(t('sla_regras.index.acao_desativar')) : htmlspecialchars(t('sla_regras.index.acao_ativar')) ?>">
                                    <i class="fa fa-<?= $rAtivo ? 'pause' : 'play' ?>"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
