<?php
/** @var int $tenantId */
/** @var array<int,array<string,mixed>> $copies */
/** @var string $csrfToken */
?>
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">Revisão de imagens anonimizadas</h1>
            <p class="text-muted mb-0">Homologação do Portal. A aprovação declara que a revisão visual de dados queimados foi concluída em ambiente administrativo controlado.</p>
        </div>
        <a class="btn btn-outline-secondary" href="/platform/negocios/<?= (int) $tenantId ?>/edit">Voltar ao negócio</a>
    </div>

    <div class="alert alert-warning">
        <strong>Não ative o Viewer do Portal nesta tela.</strong> A cópia deve ser revisada no repositório anonimizado local e a aprovação só libera a elegibilidade técnica para homologação. A ativação pública continua dependente das flags e do checklist de segurança.
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Cópia</th><th>Estudo interno</th><th>Preparação</th><th>Revisão de pixels</th><th>Atualizado</th><th>Erro</th><th>Ação</th></tr></thead>
                <tbody>
                <?php foreach ($copies as $copy): ?>
                    <tr>
                        <td>#<?= (int) $copy['id'] ?></td>
                        <td>#<?= (int) $copy['source_estudo_id'] ?></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars((string) $copy['state'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string) $copy['pixel_review_status'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $copy['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($copy['error_code'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if (($copy['state'] ?? '') === 'ready'): ?>
                                <form method="post" action="/platform/negocios/<?= (int) $tenantId ?>/portal-imagens/<?= (int) $copy['id'] ?>/revisar" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <button class="btn btn-sm btn-success" name="decision" value="approved" type="submit">Aprovar</button>
                                    <button class="btn btn-sm btn-outline-danger" name="decision" value="rejected" type="submit">Rejeitar</button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted">Aguardando preparo</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$copies): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhuma cópia anonimizadas registrada para este negócio.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
