<?php
$usuarios = $usuarios ?? [];
$sucesso  = $sucesso  ?? '';
$error    = $error    ?? '';

$perfilLabel = [
    'admin'      => ['Administrador',  'pacs-badge-admin'],
    'medico'     => ['Médico',         'pacs-badge-medico'],
    'secretaria' => ['Secretaria',     'pacs-badge-secretaria'],
    'analista'   => ['Analista',       'pacs-badge-analista'],
    'viewer'     => ['Visualizador',   'pacs-badge-viewer'],
];
?>

<style>
.pacs-badge-admin      { background:rgba(239,68,68,.15);  color:#ef4444; }
.pacs-badge-medico     { background:rgba(79,195,247,.15); color:#4fc3f7; }
.pacs-badge-secretaria { background:rgba(167,139,250,.15);color:#a78bfa; }
.pacs-badge-analista   { background:rgba(52,211,153,.15); color:#34d399; }
.pacs-badge-viewer     { background:rgba(100,116,139,.15);color:#94a3b8; }
.perfil-badge { display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:20px;font-size:.7rem;font-weight:700;letter-spacing:.03em; }
.medico-tag { display:inline-flex;align-items:center;gap:.25rem;background:rgba(79,195,247,.1);color:#4fc3f7;border-radius:4px;padding:.1rem .4rem;font-size:.68rem; }
.user-avatar { width:32px;height:32px;border-radius:50%;background:var(--pacs-primary);display:inline-flex;align-items:center;justify-content:center;color:#0a1628;font-weight:700;font-size:.8rem;flex-shrink:0; }
.user-row-inactive { opacity:.55; }
</style>

<!-- Cabeçalho -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">
            <i class="fa fa-users me-2 text-pacs-primary"></i>Usuários
        </h1>
        <p class="text-muted small mb-0 mt-1">
            Gerencie os usuários e permissões de acesso do seu negócio
        </p>
    </div>
    <a href="/usuarios/create" class="btn-pacs-primary">
        <i class="fa fa-plus me-1"></i> Novo Usuário
    </a>
</div>

<!-- Alertas -->
<?php if ($sucesso === 'usuario_criado'): ?>
<div class="pacs-alert pacs-alert-success mb-3">
    <i class="fa fa-check-circle me-2"></i>
    Usuário criado com sucesso! Um link para criação de senha foi enviado por e-mail.
</div>
<?php elseif ($sucesso === 'usuario_atualizado'): ?>
<div class="pacs-alert pacs-alert-success mb-3">
    <i class="fa fa-check-circle me-2"></i> Usuário atualizado com sucesso.
</div>
<?php elseif ($sucesso === 'link_reenviado'): ?>
<div class="pacs-alert pacs-alert-success mb-3">
    <i class="fa fa-envelope me-2"></i> Link de acesso reenviado com sucesso.
</div>
<?php endif; ?>

<?php if ($error === 'nao_pode_desativar_proprio'): ?>
<div class="pacs-alert pacs-alert-danger mb-3">
    <i class="fa fa-triangle-exclamation me-2"></i> Você não pode desativar sua própria conta.
</div>
<?php elseif ($error): ?>
<div class="pacs-alert pacs-alert-danger mb-3">
    <i class="fa fa-triangle-exclamation me-2"></i> Ocorreu um erro. Tente novamente.
</div>
<?php endif; ?>

<!-- Tabela de usuários -->
<div class="pacs-card">
    <div class="pacs-card-body" style="padding:0;">
        <?php if (empty($usuarios)): ?>
        <div style="text-align:center;padding:3rem 1rem;">
            <div style="font-size:2.5rem;opacity:.3;margin-bottom:.75rem;"><i class="fa fa-users"></i></div>
            <div style="font-weight:600;color:var(--pacs-text-secondary);">Nenhum usuário cadastrado</div>
            <div style="font-size:.85rem;color:var(--pacs-text-muted);margin-top:.4rem;">
                Clique em <strong>Novo Usuário</strong> para adicionar o primeiro.
            </div>
        </div>
        <?php else: ?>
        <table class="pacs-table" style="margin:0;">
            <thead>
                <tr>
                    <th style="width:36px;"></th>
                    <th>Nome / E-mail</th>
                    <th style="width:140px;">Perfil</th>
                    <th style="width:160px;">Médico vinculado</th>
                    <th style="width:90px;text-align:center;">Status</th>
                    <th style="width:110px;text-align:center;">Último acesso</th>
                    <th style="width:120px;text-align:right;padding-right:1rem;">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($usuarios as $u):
                $ativo    = ($u['tenant_ativo'] ?? 1) == 1;
                $perfil   = $u['perfil'] ?? 'viewer';
                [$pLabel, $pClass] = $perfilLabel[$perfil] ?? ['Viewer','pacs-badge-viewer'];
                $inicial  = strtoupper(mb_substr($u['name'] ?? '?', 0, 1));
            ?>
            <tr class="<?= !$ativo ? 'user-row-inactive' : '' ?>">
                <td style="padding:.5rem .75rem;">
                    <div class="user-avatar"><?= $inicial ?></div>
                </td>
                <td>
                    <div style="font-weight:600;font-size:.88rem;"><?= htmlspecialchars($u['name'] ?? '') ?></div>
                    <div style="font-size:.75rem;color:var(--pacs-text-muted);"><?= htmlspecialchars($u['email'] ?? '') ?></div>
                </td>
                <td>
                    <span class="perfil-badge <?= $pClass ?>">
                        <?php if ($perfil === 'admin'):      ?><i class="fa fa-shield-halved"></i>
                        <?php elseif ($perfil === 'medico'): ?><i class="fa fa-stethoscope"></i>
                        <?php elseif ($perfil === 'secretaria'): ?><i class="fa fa-clipboard-user"></i>
                        <?php else: ?><i class="fa fa-eye"></i>
                        <?php endif; ?>
                        <?= $pLabel ?>
                    </span>
                </td>
                <td>
                    <?php if (!empty($u['medico_nome'])): ?>
                    <span class="medico-tag">
                        <i class="fa fa-user-doctor"></i>
                        <?= htmlspecialchars($u['medico_nome']) ?>
                        <?php if (!empty($u['medico_crm'])): ?>
                            <span style="opacity:.6;">CRM <?= htmlspecialchars($u['medico_crm']) ?></span>
                        <?php endif; ?>
                    </span>
                    <?php else: ?>
                    <span style="color:var(--pacs-text-muted);font-size:.75rem;">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;">
                    <?php if ($ativo): ?>
                        <span class="situacao-badge sit-assinado" style="font-size:.65rem;">Ativo</span>
                    <?php else: ?>
                        <span class="situacao-badge" style="background:rgba(100,116,139,.15);color:#94a3b8;font-size:.65rem;">Inativo</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;font-size:.75rem;color:var(--pacs-text-muted);">
                    <?php if (!empty($u['ultimo_login'])): ?>
                        <?= date('d/m/Y H:i', strtotime($u['ultimo_login'])) ?>
                    <?php else: ?>
                        <span style="color:var(--pacs-warning, #f59e0b);font-size:.7rem;">
                            <i class="fa fa-clock me-1"></i>Nunca
                        </span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;padding-right:1rem;">
                    <div style="display:flex;gap:.3rem;justify-content:flex-end;align-items:center;">
                        <a href="/usuarios/<?= $u['id'] ?>/edit"
                           class="btn-pacs-outline" style="padding:.2rem .5rem;font-size:.72rem;"
                           title="Editar usuário">
                            <i class="fa fa-edit"></i>
                        </a>
                        <form method="POST" action="/usuarios/<?= $u['id'] ?>/reenviar-link"
                              style="display:inline;"
                              title="Reenviar link de acesso">
                            <button type="submit" class="btn-pacs-outline"
                                    style="padding:.2rem .5rem;font-size:.72rem;cursor:pointer;"
                                    title="Reenviar link de criação de senha">
                                <i class="fa fa-envelope"></i>
                            </button>
                        </form>
                        <form method="POST" action="/usuarios/<?= $u['id'] ?>/toggle"
                              style="display:inline;"
                              onsubmit="return confirm('Confirmar alteração de status?')">
                            <button type="submit"
                                    class="btn-pacs-outline"
                                    style="padding:.2rem .5rem;font-size:.72rem;cursor:pointer;<?= $ativo ? 'color:#f59e0b;' : 'color:#34d399;' ?>"
                                    title="<?= $ativo ? 'Desativar' : 'Ativar' ?> usuário">
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

<!-- Legenda de perfis -->
<div class="pacs-card mt-3" style="background:rgba(14,26,46,.5);">
    <div class="pacs-card-body" style="padding:.75rem 1rem;">
        <div style="font-size:.72rem;color:var(--pacs-text-muted);font-weight:600;margin-bottom:.5rem;">
            <i class="fa fa-circle-info me-1"></i> Perfis de acesso
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
            <span class="perfil-badge pacs-badge-admin"><i class="fa fa-shield-halved"></i> Administrador — acesso total ao negócio</span>
            <span class="perfil-badge pacs-badge-medico"><i class="fa fa-stethoscope"></i> Médico — worklist e laudos próprios</span>
            <span class="perfil-badge pacs-badge-secretaria"><i class="fa fa-clipboard-user"></i> Secretaria — worklist e agendamentos</span>
            <span class="perfil-badge pacs-badge-analista"><i class="fa fa-eye"></i> Analista — leitura de todos os módulos</span>
            <span class="perfil-badge pacs-badge-viewer"><i class="fa fa-eye"></i> Visualizador — leitura básica</span>
        </div>
    </div>
</div>
