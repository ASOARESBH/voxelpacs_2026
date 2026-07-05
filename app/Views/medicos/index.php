<?php $medicos = $medicos ?? []; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.3rem;font-weight:700;color:var(--pacs-text);margin-bottom:.25rem;">
            <i class="fa fa-user-doctor me-2 text-pacs-primary"></i>Médicos / Laudadores
        </h1>
        <p style="color:var(--pacs-text-muted);font-size:.82rem;">Cadastro de médicos radiologistas e laudadores</p>
    </div>
    <a href="/medicos/create" class="btn-pacs-primary"><i class="fa fa-plus"></i> Novo Médico</a>
</div>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="pacs-alert pacs-alert-success mb-3"><i class="fa fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?></div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<div class="pacs-card">
    <div style="overflow-x:auto;">
        <table class="platform-table">
            <thead>
                <tr><th>#</th><th>Nome</th><th>CRM</th><th>Especialidade</th><th>E-mail</th><th>Status</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php if (empty($medicos)): ?>
                <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--pacs-text-muted);">
                    <i class="fa fa-user-doctor fa-2x d-block mb-2"></i>
                    Nenhum médico cadastrado.
                </td></tr>
            <?php else: ?>
                <?php foreach ($medicos as $m): ?>
                <?php
                $mId    = is_array($m) ? ($m['id'] ?? 0) : ($m->id ?? 0);
                $mNome  = is_array($m) ? ($m['nome'] ?? $m['name'] ?? '') : ($m->nome ?? $m->name ?? '');
                $mCrm   = is_array($m) ? ($m['crm'] ?? '') : ($m->crm ?? '');
                $mEsp   = is_array($m) ? ($m['especialidade'] ?? '') : ($m->especialidade ?? '');
                $mEmail = is_array($m) ? ($m['email'] ?? '') : ($m->email ?? '');
                $mSt    = is_array($m) ? ($m['status'] ?? 'ativo') : ($m->status ?? 'ativo');
                ?>
                <tr>
                    <td style="color:var(--pacs-text-muted);"><?= $mId ?></td>
                    <td style="font-weight:600;"><?= htmlspecialchars($mNome) ?></td>
                    <td style="font-family:monospace;"><?= htmlspecialchars($mCrm ?: '—') ?></td>
                    <td><?= htmlspecialchars($mEsp ?: '—') ?></td>
                    <td style="font-size:.75rem;color:var(--pacs-text-muted);"><?= htmlspecialchars($mEmail ?: '—') ?></td>
                    <td><span class="badge badge-<?= $mSt === 'ativo' ? 'ativo' : 'inativo' ?>"><?= ucfirst($mSt) ?></span></td>
                    <td>
                        <div style="display:flex;gap:.3rem;">
                            <a href="/medicos/<?= $mId ?>/edit" class="pacs-btn" title="Editar"><i class="fa fa-pen"></i></a>
                            <form method="POST" action="/medicos/<?= $mId ?>/toggle" style="display:inline;">
                                <button type="submit" class="pacs-btn" title="Ativar/Inativar">
                                    <i class="fa fa-<?= $mSt === 'ativo' ? 'pause' : 'play' ?>"></i>
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
