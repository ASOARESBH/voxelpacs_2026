<?php $negocios = $negocios ?? []; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.3rem;font-weight:700;color:var(--pacs-text);margin-bottom:.25rem;">
            <i class="fa fa-building me-2 text-pacs-primary"></i>Negócios
        </h1>
        <p style="color:var(--pacs-text-muted);font-size:.82rem;">Gerencie os negócios (tenants) cadastrados na plataforma</p>
    </div>
    <a href="/platform/negocios/create" class="btn-pacs-primary"><i class="fa fa-plus"></i> Novo Negócio</a>
</div>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="pacs-alert pacs-alert-success mb-3"><i class="fa fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?></div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<div class="pacs-card">
    <div style="overflow-x:auto;">
        <table class="platform-table">
            <thead>
                <tr><th>#</th><th>Nome / Razão Social</th><th>CNPJ</th><th>Plano</th><th>Status</th><th>Cadastro</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php if (empty($negocios)): ?>
                <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--pacs-text-muted);">
                    <i class="fa fa-building fa-2x d-block mb-2"></i>
                    Nenhum negócio cadastrado. <a href="/platform/negocios/create" style="color:var(--pacs-primary);">Cadastrar primeiro</a>
                </td></tr>
            <?php else: ?>
                <?php foreach ($negocios as $n): ?>
                <?php
                $nId     = $n->id     ?? $n['id']     ?? 0;
                $nNome   = $n->nome   ?? $n['nome']   ?? '';
                $nCnpj   = $n->cnpj   ?? $n['cnpj']   ?? '';
                $nPlano  = $n->plan_nome ?? $n['plan_nome'] ?? ($n->plano ?? $n['plano'] ?? '—');
                $nStatus = $n->status ?? $n['status'] ?? 'ativo';
                $nData   = $n->created_at ?? $n['created_at'] ?? '';
                $nEmail  = $n->email_contato ?? $n['email_contato'] ?? '';
                ?>
                <tr>
                    <td style="color:var(--pacs-text-muted);"><?= $nId ?></td>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($nNome) ?></div>
                        <?php if ($nEmail): ?><small style="color:var(--pacs-text-muted);"><?= htmlspecialchars($nEmail) ?></small><?php endif; ?>
                    </td>
                    <td style="font-family:monospace;font-size:.78rem;"><?= htmlspecialchars($nCnpj ?: '—') ?></td>
                    <td><?= htmlspecialchars($nPlano) ?></td>
                    <td><span class="badge badge-<?= $nStatus === 'ativo' ? 'ativo' : ($nStatus === 'suspenso' ? 'suspenso' : 'inativo') ?>"><?= ucfirst($nStatus) ?></span></td>
                    <td style="color:var(--pacs-text-muted);font-size:.75rem;">
                        <?php try { echo (new DateTime($nData))->format('d/m/Y'); } catch (\Throwable $t) { echo '—'; } ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:.3rem;">
                            <a href="/platform/negocios/<?= $nId ?>/edit" class="pacs-btn" title="Editar"><i class="fa fa-pen"></i></a>
                            <form method="POST" action="/platform/negocios/<?= $nId ?>/impersonate" style="display:inline;">
                                <button type="submit" class="pacs-btn" title="Acessar como este negócio"><i class="fa fa-right-to-bracket"></i></button>
                            </form>
                            <form method="POST" action="/platform/negocios/<?= $nId ?>/suspend" style="display:inline;" onsubmit="return confirm('Suspender este negócio?')">
                                <button type="submit" class="pacs-btn" title="Suspender"><i class="fa fa-pause"></i></button>
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
