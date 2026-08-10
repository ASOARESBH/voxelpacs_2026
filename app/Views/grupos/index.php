<?php
$grupos  = $grupos  ?? [];
$sucesso = $sucesso ?? '';
$error   = $error   ?? '';
?>

<style>
.usuarios-tabs-bar { display:flex; gap:.25rem; border-bottom:1px solid var(--pacs-border); margin-bottom:1.25rem; }
.usuarios-tab-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.65rem 1rem; font-size:.85rem; font-weight:600; color:var(--pacs-text-muted); text-decoration:none; border-bottom:2px solid transparent; }
.usuarios-tab-btn:hover { color:var(--pacs-text); }
.usuarios-tab-btn.active { color:var(--pacs-primary); border-bottom-color:var(--pacs-primary); }

.grupo-status-badge { display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:20px;font-size:.7rem;font-weight:700;letter-spacing:.03em; }
.grupo-status-ativo   { background:rgba(52,211,153,.15); color:#34d399; }
.grupo-status-inativo { background:rgba(100,116,139,.15); color:#94a3b8; }
.grupo-membros-count { display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem;color:var(--pacs-text-muted); }
.grupo-row-inactive { opacity:.55; }
</style>

<!-- Cabeçalho -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3 mb-0">
            <i class="fa fa-users me-2 text-pacs-primary"></i><?= htmlspecialchars(t('usuarios.grupos.titulo')) ?>
        </h1>
        <p class="text-muted small mb-0 mt-1">
            <?= htmlspecialchars(t('usuarios.grupos.subtitulo')) ?>
        </p>
    </div>
    <a href="/usuarios/grupos/novo" class="btn-pacs-primary">
        <i class="fa fa-plus me-1"></i> <?= htmlspecialchars(t('usuarios.grupos.botao_novo')) ?>
    </a>
</div>

<!-- Navegação Usuários / Grupos -->
<div class="usuarios-tabs-bar">
    <a href="/usuarios" class="usuarios-tab-btn">
        <i class="fa fa-users"></i> <?= htmlspecialchars(t('usuarios.tabs.usuarios')) ?>
    </a>
    <a href="/usuarios/grupos" class="usuarios-tab-btn active">
        <i class="fa fa-layer-group"></i> <?= htmlspecialchars(t('usuarios.tabs.grupos')) ?>
    </a>
</div>

<!-- Alertas -->
<?php
$sucessoMsgs = [
    'grupo_criado'          => t('usuarios.grupos.sucesso.grupo_criado'),
    'grupo_atualizado'      => t('usuarios.grupos.sucesso.grupo_atualizado'),
    'grupo_status_alterado' => t('usuarios.grupos.sucesso.grupo_status_alterado'),
];
?>
<?php if ($sucesso && isset($sucessoMsgs[$sucesso])): ?>
<div class="pacs-alert pacs-alert-success mb-3">
    <i class="fa fa-check-circle me-2"></i> <?= htmlspecialchars($sucessoMsgs[$sucesso]) ?>
</div>
<?php endif; ?>

<!-- Tabela de grupos -->
<div class="pacs-card">
    <div class="pacs-card-body" style="padding:0;">
        <?php if (empty($grupos)): ?>
        <div style="text-align:center;padding:3rem 1rem;">
            <div style="font-size:2.5rem;opacity:.3;margin-bottom:.75rem;"><i class="fa fa-layer-group"></i></div>
            <div style="font-weight:600;color:var(--pacs-text-secondary);"><?= htmlspecialchars(t('usuarios.grupos.vazio_titulo')) ?></div>
            <div style="font-size:.85rem;color:var(--pacs-text-muted);margin-top:.4rem;">
                <?= htmlspecialchars(t('usuarios.grupos.vazio_texto')) ?>
            </div>
        </div>
        <?php else: ?>
        <table class="pacs-table" style="margin:0;">
            <thead>
                <tr>
                    <th><?= htmlspecialchars(t('usuarios.grupos.coluna_nome')) ?></th>
                    <th><?= htmlspecialchars(t('usuarios.grupos.coluna_descricao')) ?></th>
                    <th style="width:120px;text-align:center;"><?= htmlspecialchars(t('usuarios.grupos.coluna_membros')) ?></th>
                    <th style="width:100px;text-align:center;"><?= htmlspecialchars(t('usuarios.grupos.coluna_status')) ?></th>
                    <th style="width:110px;text-align:right;padding-right:1rem;"><?= htmlspecialchars(t('usuarios.grupos.coluna_acoes')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($grupos as $g):
                $ativo = (int) ($g['ativo'] ?? 1) === 1;
            ?>
            <tr class="<?= !$ativo ? 'grupo-row-inactive' : '' ?>">
                <td style="font-weight:600;font-size:.88rem;"><?= htmlspecialchars($g['nome'] ?? '') ?></td>
                <td style="font-size:.8rem;color:var(--pacs-text-muted);">
                    <?= $g['descricao'] ? htmlspecialchars($g['descricao']) : '—' ?>
                </td>
                <td style="text-align:center;">
                    <span class="grupo-membros-count">
                        <i class="fa fa-user-group"></i> <?= (int) ($g['total_membros'] ?? 0) ?>
                    </span>
                </td>
                <td style="text-align:center;">
                    <?php if ($ativo): ?>
                        <span class="grupo-status-badge grupo-status-ativo"><?= htmlspecialchars(t('usuarios.grupos.status_ativo')) ?></span>
                    <?php else: ?>
                        <span class="grupo-status-badge grupo-status-inativo"><?= htmlspecialchars(t('usuarios.grupos.status_inativo')) ?></span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;padding-right:1rem;">
                    <div style="display:flex;gap:.3rem;justify-content:flex-end;align-items:center;">
                        <a href="/usuarios/grupos/<?= (int) $g['id'] ?>/editar"
                           class="btn-pacs-outline" style="padding:.2rem .5rem;font-size:.72rem;"
                           title="<?= htmlspecialchars(t('usuarios.grupos.acao_editar')) ?>">
                            <i class="fa fa-edit"></i>
                        </a>
                        <form method="POST" action="/usuarios/grupos/<?= (int) $g['id'] ?>/excluir"
                              style="display:inline;"
                              onsubmit="return confirm('<?= addslashes($ativo ? t('usuarios.grupos.confirma_excluir') : t('usuarios.grupos.confirma_reativar')) ?>')">
                            <button type="submit"
                                    class="btn-pacs-outline"
                                    style="padding:.2rem .5rem;font-size:.72rem;cursor:pointer;<?= $ativo ? 'color:#f59e0b;' : 'color:#34d399;' ?>"
                                    title="<?= htmlspecialchars($ativo ? t('usuarios.grupos.acao_excluir') : t('usuarios.grupos.acao_reativar')) ?>">
                                <i class="fa fa-<?= $ativo ? 'ban' : 'check' ?>"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
