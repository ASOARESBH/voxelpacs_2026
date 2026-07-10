<?php
/**
 * VOXEL PACS — Formulário de Plano
 */
$isEdit = !empty($plano);
$id     = $plano->id ?? $plano['id'] ?? null;
?>

<div style="margin-bottom:1.5rem;">
    <h1 style="font-size:1.3rem;font-weight:700;color:var(--pacs-text);margin-bottom:.25rem;">
        <i class="fa fa-tags me-2 text-pacs-primary"></i>
        <?= $isEdit ? 'Editar Plano' : 'Novo Plano' ?>
    </h1>
    <nav style="font-size:.78rem;color:var(--pacs-text-muted);">
        <a href="/platform/plans" style="color:var(--pacs-primary);">Planos</a>
        <span style="margin:0 .4rem;">/</span>
        <?= $isEdit ? 'Editar' : 'Novo' ?>
    </nav>
</div>

<div class="pacs-card" style="max-width:700px;">
    <div class="pacs-card-header">
        <i class="fa fa-<?= $isEdit ? 'pen' : 'plus' ?> text-pacs-primary"></i>
        <span style="font-weight:600;color:var(--pacs-text);"><?= $isEdit ? 'Editar Plano' : 'Cadastrar Novo Plano' ?></span>
    </div>
    <div class="pacs-card-body">
        <form method="POST" action="<?= $isEdit ? "/platform/plans/{$id}/update" : '/platform/plans' ?>">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label class="form-label-dark">Nome do Plano *</label>
                    <input type="text" name="nome" class="form-control form-control-dark"
                           value="<?= htmlspecialchars($plano->nome ?? $plano['nome'] ?? '') ?>"
                           placeholder="Ex: Professional" required>
                </div>
                <div>
                    <label class="form-label-dark">Slug</label>
                    <input type="text" name="slug" class="form-control form-control-dark"
                           value="<?= htmlspecialchars($plano->slug ?? $plano['slug'] ?? '') ?>"
                           placeholder="ex: professional">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1rem;">
                <div>
                    <label class="form-label-dark">Preço Mensal (R$) *</label>
                    <input type="number" name="preco_mensal" class="form-control form-control-dark"
                           value="<?= $plano->preco_mensal ?? $plano['preco_mensal'] ?? '' ?>"
                           step="0.01" min="0" placeholder="297.00" required>
                </div>
                <div>
                    <label class="form-label-dark">Máx. Usuários</label>
                    <input type="number" name="max_usuarios" class="form-control form-control-dark"
                           value="<?= $plano->max_usuarios ?? $plano['max_usuarios'] ?? 5 ?>"
                           min="1" placeholder="5">
                </div>
                <div>
                    <label class="form-label-dark">Máx. PACS</label>
                    <input type="number" name="max_pacs" class="form-control form-control-dark"
                           value="<?= $plano->max_pacs ?? $plano['max_pacs'] ?? 1 ?>"
                           min="1" placeholder="1">
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <label class="form-label-dark">Máx. Exames/Mês</label>
                <input type="number" name="max_exames_mes" class="form-control form-control-dark"
                       value="<?= $plano->max_exames_mes ?? $plano['max_exames_mes'] ?? 10000 ?>"
                       min="0" placeholder="10000">
            </div>

            <div style="margin-bottom:1.25rem;">
                <label class="form-label-dark" style="display:block;margin-bottom:.5rem;">Recursos Incluídos</label>
                <div style="display:flex;gap:1.5rem;flex-wrap:wrap;">
                    <label style="display:flex;align-items:center;gap:.4rem;color:var(--pacs-text);font-size:.82rem;cursor:pointer;">
                        <input type="checkbox" name="permite_preditivo" value="1"
                               <?= ($plano->permite_preditivo ?? $plano['permite_preditivo'] ?? 0) ? 'checked' : '' ?>>
                        Análise Preditiva
                    </label>
                    <label style="display:flex;align-items:center;gap:.4rem;color:var(--pacs-text);font-size:.82rem;cursor:pointer;">
                        <input type="checkbox" name="permite_benchmark" value="1"
                               <?= ($plano->permite_benchmark ?? $plano['permite_benchmark'] ?? 0) ? 'checked' : '' ?>>
                        Benchmark
                    </label>
                    <label style="display:flex;align-items:center;gap:.4rem;color:var(--pacs-text);font-size:.82rem;cursor:pointer;">
                        <input type="checkbox" name="permite_api" value="1"
                               <?= ($plano->permite_api ?? $plano['permite_api'] ?? 0) ? 'checked' : '' ?>>
                        Acesso API REST
                    </label>
                </div>
            </div>

            <div style="display:flex;gap:.75rem;justify-content:flex-end;">
                <a href="/platform/plans" class="btn-pacs-outline">
                    <i class="fa fa-xmark"></i> Cancelar
                </a>
                <button type="submit" class="btn-pacs-primary">
                    <i class="fa fa-<?= $isEdit ? 'floppy-disk' : 'plus' ?>"></i>
                    <?= $isEdit ? 'Salvar Alterações' : 'Criar Plano' ?>
                </button>
            </div>
        </form>
    </div>
</div>
