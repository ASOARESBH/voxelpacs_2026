<?php
$medicos      = $medicos ?? [];
$busca        = $busca ?? '';
$pagina       = $pagina ?? 1;
$totalPaginas = $totalPaginas ?? 1;
$total        = $total ?? 0;
?>

<!-- Cabeçalho -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">
            <i class="fa fa-user-doctor me-2 text-pacs-primary"></i>Médicos / Laudadores
        </h1>
        <p class="text-muted small mb-0 mt-1">
            Cadastro de médicos radiologistas e laudadores
            <?php if ($total > 0): ?>
                — <strong><?= $total ?></strong> registro<?= $total !== 1 ? 's' : '' ?> encontrado<?= $total !== 1 ? 's' : '' ?>
            <?php endif; ?>
        </p>
    </div>
    <a href="/medicos/create" class="btn-pacs-primary">
        <i class="fa fa-plus me-1"></i> Novo Médico
    </a>
</div>

<!-- Mensagens de sessão -->
<?php if (!empty($_SESSION['success'])): ?>
    <div class="pacs-alert pacs-alert-success mb-3">
        <i class="fa fa-check-circle me-2"></i> <?= htmlspecialchars($_SESSION['success']) ?>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
    <div class="pacs-alert pacs-alert-danger mb-3">
        <i class="fa fa-triangle-exclamation me-2"></i> <?= htmlspecialchars($_SESSION['error']) ?>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- Barra de pesquisa -->
<div class="pacs-card mb-3">
    <div class="pacs-card-body" style="padding:.75rem 1rem;">
        <form method="GET" action="/medicos" class="d-flex gap-2 align-items-center flex-wrap">
            <div style="flex:1;min-width:220px;">
                <input type="text"
                       name="busca"
                       class="form-control-dark"
                       value="<?= htmlspecialchars($busca) ?>"
                       placeholder="Pesquisar por nome, CRM, especialidade ou e-mail..."
                       style="width:100%;">
            </div>
            <button type="submit" class="btn-pacs-primary" style="white-space:nowrap;">
                <i class="fa fa-search me-1"></i> Pesquisar
            </button>
            <?php if ($busca): ?>
                <a href="/medicos" class="btn-pacs-outline" style="white-space:nowrap;">
                    <i class="fa fa-times me-1"></i> Limpar
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Tabela de médicos -->
<div class="pacs-card">
    <div style="overflow-x:auto;">
        <table class="platform-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nome</th>
                    <th>CRM</th>
                    <th>Especialidade</th>
                    <th>E-mail</th>
                    <th>Telefone</th>
                    <th>Conta Vinculada</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($medicos)): ?>
                <tr>
                    <td colspan="9" style="text-align:center;padding:2.5rem;color:var(--pacs-text-muted);">
                        <i class="fa fa-user-doctor fa-2x d-block mb-2 opacity-50"></i>
                        <?php if ($busca): ?>
                            Nenhum médico encontrado para "<strong><?= htmlspecialchars($busca) ?></strong>".
                        <?php else: ?>
                            Nenhum médico cadastrado ainda.
                            <a href="/medicos/create" class="d-block mt-2" style="color:var(--pacs-primary);">
                                <i class="fa fa-plus me-1"></i> Cadastrar primeiro médico
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($medicos as $m): ?>
                <?php
                $mId          = is_array($m) ? ($m['id'] ?? 0) : ($m->id ?? 0);
                $mNome        = is_array($m) ? ($m['nome'] ?? '') : ($m->nome ?? '');
                $mCrm         = is_array($m) ? ($m['crm'] ?? '') : ($m->crm ?? '');
                $mCrmUf       = is_array($m) ? ($m['crm_uf'] ?? '') : ($m->crm_uf ?? '');
                $mEsp         = is_array($m) ? ($m['especialidade'] ?? '') : ($m->especialidade ?? '');
                $mEmail       = is_array($m) ? ($m['email'] ?? '') : ($m->email ?? '');
                $mTelefone    = is_array($m) ? ($m['telefone'] ?? '') : ($m->telefone ?? '');
                $mStatus      = is_array($m) ? ($m['status'] ?? 'ativo') : ($m->status ?? 'ativo');
                $mAtivo       = is_array($m) ? ($m['ativo'] ?? 1) : ($m->ativo ?? 1);
                $mUsuarioNome = is_array($m) ? ($m['usuario_nome'] ?? null) : ($m->usuario_nome ?? null);
                $crmFormatado = $mCrm ? ($mCrm . ($mCrmUf ? '/' . $mCrmUf : '')) : '—';
                ?>
                <tr>
                    <td style="color:var(--pacs-text-muted);font-size:.8rem;"><?= (int) $mId ?></td>
                    <td style="font-weight:600;"><?= htmlspecialchars($mNome) ?></td>
                    <td style="font-family:monospace;font-size:.82rem;"><?= htmlspecialchars($crmFormatado) ?></td>
                    <td style="font-size:.82rem;"><?= htmlspecialchars($mEsp ?: '—') ?></td>
                    <td style="font-size:.78rem;color:var(--pacs-text-muted);">
                        <?php if ($mEmail): ?>
                            <a href="mailto:<?= htmlspecialchars($mEmail) ?>" style="color:inherit;">
                                <?= htmlspecialchars($mEmail) ?>
                            </a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="font-size:.82rem;font-family:monospace;"><?= htmlspecialchars($mTelefone ?: '—') ?></td>
                    <td>
                        <?php if ($mUsuarioNome): ?>
                            <span style="font-size:.78rem;">
                                <i class="fa fa-user me-1 opacity-50"></i><?= htmlspecialchars($mUsuarioNome) ?>
                            </span>
                        <?php else: ?>
                            <span class="badge badge-inativo" title="Sem conta de usuário vinculada">Sem vínculo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= $mAtivo ? 'ativo' : 'inativo' ?>">
                            <?= $mAtivo ? 'Ativo' : 'Inativo' ?>
                        </span>
                    </td>
                    <td>
                        <div style="display:flex;gap:.3rem;">
                            <a href="/medicos/<?= (int) $mId ?>/edit"
                               class="pacs-btn"
                               title="Editar médico">
                                <i class="fa fa-pen"></i>
                            </a>
                            <form method="POST"
                                  action="/medicos/<?= (int) $mId ?>/toggle"
                                  style="display:inline;"
                                  onsubmit="return confirm('<?= $mAtivo ? 'Inativar' : 'Ativar' ?> o médico <?= addslashes(htmlspecialchars($mNome)) ?>?');">
                                <button type="submit"
                                        class="pacs-btn"
                                        title="<?= $mAtivo ? 'Inativar' : 'Ativar' ?> médico">
                                    <i class="fa fa-<?= $mAtivo ? 'pause' : 'play' ?>"></i>
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

    <!-- Paginação -->
    <?php if ($totalPaginas > 1): ?>
    <div class="d-flex justify-content-center align-items-center gap-2 p-3"
         style="border-top:1px solid var(--pacs-border);">
        <?php if ($pagina > 1): ?>
            <a href="?busca=<?= urlencode($busca) ?>&pagina=<?= $pagina - 1 ?>"
               class="btn-pacs-outline" style="padding:.3rem .7rem;">
                <i class="fa fa-chevron-left"></i>
            </a>
        <?php endif; ?>

        <?php
        $inicio = max(1, $pagina - 2);
        $fim    = min($totalPaginas, $pagina + 2);
        for ($p = $inicio; $p <= $fim; $p++):
        ?>
            <a href="?busca=<?= urlencode($busca) ?>&pagina=<?= $p ?>"
               class="<?= $p === $pagina ? 'btn-pacs-primary' : 'btn-pacs-outline' ?>"
               style="padding:.3rem .7rem;min-width:36px;text-align:center;">
                <?= $p ?>
            </a>
        <?php endfor; ?>

        <?php if ($pagina < $totalPaginas): ?>
            <a href="?busca=<?= urlencode($busca) ?>&pagina=<?= $pagina + 1 ?>"
               class="btn-pacs-outline" style="padding:.3rem .7rem;">
                <i class="fa fa-chevron-right"></i>
            </a>
        <?php endif; ?>

        <span style="font-size:.8rem;color:var(--pacs-text-muted);margin-left:.5rem;">
            Página <?= $pagina ?> de <?= $totalPaginas ?>
        </span>
    </div>
    <?php endif; ?>
</div>
