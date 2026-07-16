<?php $negocios = $negocios ?? []; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.3rem;font-weight:700;color:var(--pacs-text);margin-bottom:.25rem;">
            <i class="fa fa-building me-2 text-pacs-primary"></i><?= htmlspecialchars(t('negocios.index.titulo')) ?>
        </h1>
        <p style="color:var(--pacs-text-muted);font-size:.82rem;"><?= htmlspecialchars(t('negocios.index.subtitulo')) ?></p>
    </div>
    <a href="/platform/negocios/create" class="btn-pacs-primary"><i class="fa fa-plus"></i> <?= htmlspecialchars(t('negocios.index.botao_novo')) ?></a>
</div>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="pacs-alert pacs-alert-success mb-3"><i class="fa fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?></div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<div class="pacs-card">
    <div style="overflow-x:auto;">
        <table class="platform-table">
            <thead>
                <tr>
                    <th><?= htmlspecialchars(t('negocios.index.coluna_id')) ?></th>
                    <th><?= htmlspecialchars(t('negocios.index.coluna_nome')) ?></th>
                    <th><?= htmlspecialchars(t('negocios.index.coluna_cnpj')) ?></th>
                    <th><?= htmlspecialchars(t('negocios.index.coluna_plano')) ?></th>
                    <th><?= htmlspecialchars(t('negocios.index.coluna_status')) ?></th>
                    <th><?= htmlspecialchars(t('negocios.index.coluna_cadastro')) ?></th>
                    <th><?= htmlspecialchars(t('negocios.index.coluna_acoes')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($negocios)): ?>
                <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--pacs-text-muted);">
                    <i class="fa fa-building fa-2x d-block mb-2"></i>
                    <?= htmlspecialchars(t('negocios.index.vazio_titulo')) ?> <a href="/platform/negocios/create" style="color:var(--pacs-primary);"><?= htmlspecialchars(t('negocios.index.vazio_link')) ?></a>
                </td></tr>
            <?php else: ?>
                <?php
                $statusLabels = [
                    'ativo'     => t('negocios.status.ativo'),
                    'suspenso'  => t('negocios.status.suspenso'),
                    'cancelado' => t('negocios.status.cancelado'),
                    'trial'     => t('negocios.status.trial'),
                ];
                ?>
                <?php foreach ($negocios as $n): ?>
                <?php
                $nId     = $n->id ?? 0;
                $nNome   = $n->nome ?? '';
                $nCnpj   = $n->cnpj ?? '';
                $nPlano  = $n->plano_nome ?? '—';
                $nStatus = $n->status ?? 'ativo';
                $nData   = $n->created_at ?? '';
                $nEmail  = $n->email_contato ?? '';
                $nStatusLabel = $statusLabels[$nStatus] ?? ucfirst($nStatus);
                ?>
                <tr>
                    <td style="color:var(--pacs-text-muted);"><?= $nId ?></td>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($nNome) ?></div>
                        <?php if ($nEmail): ?><small style="color:var(--pacs-text-muted);"><?= htmlspecialchars($nEmail) ?></small><?php endif; ?>
                    </td>
                    <td style="font-family:monospace;font-size:.78rem;"><?= htmlspecialchars($nCnpj ?: '—') ?></td>
                    <td><?= htmlspecialchars($nPlano) ?></td>
                    <td><span class="badge badge-<?= $nStatus === 'ativo' ? 'ativo' : ($nStatus === 'suspenso' ? 'suspenso' : 'inativo') ?>"><?= htmlspecialchars($nStatusLabel) ?></span></td>
                    <td style="color:var(--pacs-text-muted);font-size:.75rem;">
                        <?php try { echo (new DateTime($nData))->format('d/m/Y'); } catch (\Throwable $ex) { echo '—'; } ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:.3rem;">
                            <a href="/platform/negocios/<?= $nId ?>/edit" class="pacs-btn" title="<?= htmlspecialchars(t('negocios.index.acao_editar')) ?>"><i class="fa fa-pen"></i></a>
                            <form method="POST" action="/platform/negocios/<?= $nId ?>/impersonate" style="display:inline;">
                                <button type="submit" class="pacs-btn" title="<?= htmlspecialchars(t('negocios.index.acao_acessar')) ?>"><i class="fa fa-right-to-bracket"></i></button>
                            </form>
                            <form method="POST" action="/platform/negocios/<?= $nId ?>/suspend" style="display:inline;" onsubmit="return confirm('<?= addslashes(t('negocios.index.confirma_suspender')) ?>')">
                                <button type="submit" class="pacs-btn" title="<?= htmlspecialchars(t('negocios.index.acao_suspender')) ?>"><i class="fa fa-pause"></i></button>
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
