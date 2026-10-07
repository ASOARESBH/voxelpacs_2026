<?php
$draft = $draft ?? [];
$published = $published ?? null;
$unit = $unit ?? [];
$unitId = (int) ($unitId ?? 0);
$unitSource = (string) ($unitSource ?? 'institution_name');
$csrfToken = (string) ($csrfToken ?? '');
$layoutCode = (string) ($layoutCode ?? 'personalizado');
$selectedLayoutCode = (string) ($selectedLayoutCode ?? 'classico_centralizado');
$layoutCatalog = is_array($layoutCatalog ?? null) ? $layoutCatalog : [];
$layoutLabels = [
    'classico_centralizado' => t('unidades.template_editor.layout_classico'),
    'moderno_lateral' => t('unidades.template_editor.layout_moderno'),
    'corporativo_faixa' => t('unidades.template_editor.layout_corporativo'),
    'minimalista' => t('unidades.template_editor.layout_minimalista'),
    'personalizado' => t('unidades.template_editor.layout_personalizado'),
];
$layoutName = $layoutLabels[$layoutCode] ?? $layoutCode;
foreach ($layoutCatalog as $catalogItem) {
    if (($catalogItem['codigo'] ?? '') === $layoutCode) {
        $layoutName = $layoutLabels[$layoutCode] ?? (string) ($catalogItem['nome'] ?? $layoutCode);
        break;
    }
}
$baseUrl = $unitSource === 'unidade'
    ? '/unidades/' . $unitId . '/editar/template-personalizado'
    : '/unidades/' . $unitId . '/template-personalizado';
$backUrl = $unitSource === 'unidade' ? '/unidades/' . $unitId . '/editar' : '/unidades/' . $unitId . '/edit';
$placeholders = [
    t('unidades.template_editor.grupo_unidade') => ['{{unidade.nome}}', '{{unidade.cnpj}}', '{{unidade.endereco}}', '{{unidade.telefone}}', '{{unidade.email}}', '{{unidade.logo}}'],
    t('unidades.template_editor.grupo_canais') => ['{{unidade.qrcode}}', '{{unidade.site}}', '{{unidade.instagram}}', '{{unidade.facebook}}'],
    t('unidades.template_editor.grupo_paciente') => ['{{paciente.nome}}', '{{paciente.data_nascimento}}', '{{paciente.id}}'],
    t('unidades.template_editor.grupo_exame') => ['{{exame.modalidade}}', '{{exame.data}}', '{{exame.descricao}}', '{{exame.prontuario}}', '{{exame.acesso}}'],
    t('unidades.template_editor.grupo_medico') => ['{{medico.nome}}', '{{medico.crm}}', '{{medico_solicitante.nome}}'],
    t('unidades.template_editor.grupo_laudo') => ['{{laudo.titulo}}', '{{laudo.corpo}}', '{{laudo.tecnica}}', '{{laudo.achados}}', '{{laudo.impressao}}', '{{laudo.data_emissao}}', '{{laudo.token_validacao}}'],
    t('unidades.template_editor.grupo_assinatura') => ['{{assinatura.imagem}}', '{{assinatura.data}}', '{{qrcode}}'],
];
$sectionLabels = [
    'header' => t('unidades.template_editor.secao_cabecalho'),
    'body' => t('unidades.template_editor.secao_corpo'),
    'footer' => t('unidades.template_editor.secao_rodape'),
];
?>
<div class="template-custom-page" data-preview-url="<?= htmlspecialchars($baseUrl . '/preview') ?>">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1"><i class="fa fa-palette text-primary me-2"></i><?= htmlspecialchars(t('unidades.template_editor.titulo')) ?>: <?= htmlspecialchars($layoutName) ?></h1>
            <p class="text-muted mb-0 small">
                <?= htmlspecialchars(t('unidades.template_editor.unidade')) ?>: <strong><?= htmlspecialchars($unit['nome'] ?? $unit['institution_name'] ?? t('unidades.template_editor.unidade_fallback')) ?></strong>. <?= htmlspecialchars(t('unidades.template_editor.dados_exemplo_preview')) ?>
            </p>
        </div>
        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-outline-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i><?= htmlspecialchars(t('unidades.template_editor.voltar_unidade')) ?></a>
    </div>

    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success py-2 small"><?= htmlspecialchars($_SESSION['success']) ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger py-2 small"><?= htmlspecialchars($_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="alert alert-info small py-2">
        <i class="fa fa-shield-halved me-1"></i><strong><?= htmlspecialchars(t('unidades.template_editor.seguranca_titulo')) ?>:</strong>
        <?= htmlspecialchars(t('unidades.template_editor.seguranca_texto')) ?>
        <?php if ($published): ?>
            <?= htmlspecialchars(t('unidades.template_editor.versao_publicada')) ?>: <strong><?= (int) ($published['version'] ?? 0) ?></strong> <?= htmlspecialchars(t('unidades.template_editor.em')) ?> <?= htmlspecialchars((string) ($published['published_at'] ?? '')) ?>.
        <?php else: ?>
            <?= htmlspecialchars(t('unidades.template_editor.nenhuma_versao')) ?>: <?= htmlspecialchars(t('unidades.template_editor.pdf_nativo_ate_publicar')) ?>
        <?php endif; ?>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong><i class="fa fa-layer-group text-primary me-2"></i><?= htmlspecialchars(t('unidades.template_editor.layout_base')) ?></strong>
            <span class="badge <?= $layoutCode === $selectedLayoutCode ? 'bg-primary' : 'bg-secondary' ?>">
                <?= htmlspecialchars(t($layoutCode === $selectedLayoutCode ? 'unidades.template_editor.selecionado' : 'unidades.template_editor.nao_selecionado')) ?>
            </span>
        </div>
        <div class="card-body py-2">
            <div class="template-layout-switcher" role="tablist" aria-label="<?= htmlspecialchars(t('unidades.template_editor.layouts_editaveis')) ?>">
                <?php foreach ($layoutCatalog as $catalogItem):
                    $code = (string) ($catalogItem['codigo'] ?? '');
                    if ($code === '') continue;
                    $active = $code === $layoutCode;
                    $href = $baseUrl . '?layout=' . rawurlencode($code);
                ?>
                    <a class="template-layout-switch <?= $active ? 'active' : '' ?>" href="<?= htmlspecialchars($href) ?>" role="tab" aria-selected="<?= $active ? 'true' : 'false' ?>">
                        <?= htmlspecialchars((string) ($catalogItem['nome'] ?? $code)) ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <p class="small text-muted mb-0 mt-2"><?= htmlspecialchars(t('unidades.template_editor.escolha_publicacao_ajuda')) ?></p>
        </div>
    </div>

    <form id="templateCustomForm" method="POST" action="<?= htmlspecialchars($baseUrl . '/salvar') ?>" data-publish-action="<?= htmlspecialchars($baseUrl . '/publicar') ?>">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="layout_code" value="<?= htmlspecialchars($layoutCode) ?>">
        <div class="template-custom-layout">
            <section class="template-custom-editors">
                <?php foreach ($sectionLabels as $section => $label):
                    $mode = $draft[$section . '_mode'] ?? 'texto';
                    $content = (string) ($draft[$section . '_content'] ?? '');
                ?>
                <article class="card shadow-sm mb-3 template-custom-section" data-section="<?= htmlspecialchars($section) ?>">
                    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <strong><i class="fa <?= $section === 'header' ? 'fa-heading' : ($section === 'body' ? 'fa-file-lines' : 'fa-shoe-prints') ?> text-primary me-2"></i><?= htmlspecialchars($label) ?></strong>
                        <div class="btn-group btn-group-sm" role="group" aria-label="<?= htmlspecialchars(t('unidades.template_editor.modo_aria')) ?> <?= htmlspecialchars($label) ?>">
                            <button type="button" class="btn mode-toggle <?= $mode === 'texto' ? 'btn-primary active' : 'btn-outline-primary' ?>" data-mode="texto"><?= htmlspecialchars(t('unidades.template_editor.texto')) ?></button>
                            <button type="button" class="btn mode-toggle <?= $mode === 'html' ? 'btn-primary active' : 'btn-outline-primary' ?>" data-mode="html"><?= htmlspecialchars(t('unidades.template_editor.html')) ?></button>
                        </div>
                    </div>
                    <div class="card-body">
                        <input type="hidden" name="<?= htmlspecialchars($section) ?>_mode" value="<?= htmlspecialchars($mode) ?>">
                        <div class="template-dynamic-fields mb-2">
                            <span class="small text-muted me-2"><?= htmlspecialchars(t('unidades.template_editor.inserir_campo')) ?>:</span>
                            <?php foreach ($placeholders as $group => $fields): ?>
                                <div class="btn-group btn-group-sm me-1 mb-1">
                                    <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><?= htmlspecialchars($group) ?></button>
                                    <ul class="dropdown-menu">
                                        <?php foreach ($fields as $field): ?>
                                            <li><button type="button" class="dropdown-item insert-placeholder" data-placeholder="<?= htmlspecialchars($field) ?>"><code><?= htmlspecialchars($field) ?></code></button></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div id="templateToolbar-<?= htmlspecialchars($section) ?>" class="template-custom-toolbar <?= $mode === 'texto' ? '' : 'd-none' ?>">
                            <span class="ql-formats"><button type="button" class="ql-bold" aria-label="<?= htmlspecialchars(t('unidades.template_editor.negrito')) ?>"></button><button type="button" class="ql-italic" aria-label="<?= htmlspecialchars(t('unidades.template_editor.italico')) ?>"></button><button type="button" class="ql-underline" aria-label="<?= htmlspecialchars(t('unidades.template_editor.sublinhado')) ?>"></button></span>
                            <span class="ql-formats"><select class="ql-header"><option selected></option><option value="1"></option><option value="2"></option><option value="3"></option></select></span>
                            <span class="ql-formats"><button type="button" class="ql-list" value="ordered"></button><button type="button" class="ql-list" value="bullet"></button></span>
                            <span class="ql-formats"><button type="button" class="ql-table" aria-label="<?= htmlspecialchars(t('unidades.template_editor.inserir_tabela')) ?>">▦</button></span>
                            <span class="ql-formats"><button type="button" class="ql-undo" aria-label="<?= htmlspecialchars(t('unidades.template_editor.desfazer')) ?>">↶</button><button type="button" class="ql-redo" aria-label="<?= htmlspecialchars(t('unidades.template_editor.refazer')) ?>">↷</button><button type="button" class="ql-clean" aria-label="<?= htmlspecialchars(t('unidades.template_editor.limpar_formatacao')) ?>"></button></span>
                        </div>
                        <div class="template-text-editor <?= $mode === 'texto' ? '' : 'd-none' ?>" data-editor="<?= htmlspecialchars($section) ?>"></div>
                        <textarea class="template-html-editor <?= $mode === 'html' ? '' : 'd-none' ?>" data-html="<?= htmlspecialchars($section) ?>" rows="9" spellcheck="false" placeholder="<?= htmlspecialchars(t('unidades.template_editor.html_placeholder')) ?>"><?= htmlspecialchars($content) ?></textarea>
                        <textarea class="d-none" name="<?= htmlspecialchars($section) ?>_content" data-content="<?= htmlspecialchars($section) ?>"><?= htmlspecialchars($content) ?></textarea>
                    </div>
                </article>
                <?php endforeach; ?>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-outline-primary"><i class="fa fa-floppy-disk me-1"></i><?= htmlspecialchars(t('unidades.template_editor.salvar_rascunho')) ?></button>
                    <button type="button" id="publishTemplate" class="btn btn-primary"><i class="fa fa-cloud-arrow-up me-1"></i><?= htmlspecialchars(t('unidades.template_editor.publicar_versao')) ?></button>
                    <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-outline-secondary"><?= htmlspecialchars(t('unidades.template_editor.cancelar')) ?></a>
                </div>
            </section>

            <aside class="template-custom-preview-wrap">
                <div class="card shadow-sm template-custom-preview-card">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <strong><i class="fa fa-eye text-primary me-2"></i><?= htmlspecialchars(t('unidades.template_editor.preview_a4')) ?></strong>
                        <span id="templatePreviewStatus" class="small text-muted"><?= htmlspecialchars(t('unidades.template_editor.atualizando')) ?></span>
                    </div>
                    <div class="card-body p-2">
                        <iframe id="templatePreviewFrame" title="<?= htmlspecialchars(t('unidades.template_editor.preview_template')) ?>" sandbox="allow-same-origin"></iframe>
                    </div>
                </div>
            </aside>
        </div>
    </form>
</div>

<style>
.template-custom-layout{display:grid;grid-template-columns:minmax(0,1.08fr) minmax(360px,.92fr);gap:1rem;align-items:start}.template-custom-preview-card{position:sticky;top:1rem}.template-custom-preview-card iframe{display:block;width:100%;height:720px;border:1px solid #cbd5e1;background:#e2e8f0}.template-custom-toolbar{border:1px solid #cbd5e1;border-bottom:0;border-radius:.375rem .375rem 0 0;background:#f8fafc}.template-text-editor{min-height:190px;background:#fff}.template-custom-toolbar+.template-text-editor .ql-container{border-top-left-radius:0;border-top-right-radius:0}.template-text-editor .ql-editor{min-height:150px}.template-html-editor{display:block;width:100%;font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;color:#172033;background:#0f172a;color:#e2e8f0;border:1px solid #334155;border-radius:.375rem;padding:.75rem;resize:vertical}.template-dynamic-fields code{font-size:.72rem}.template-layout-switcher{display:flex;flex-wrap:wrap;gap:.4rem}.template-layout-switch{border:1px solid #cbd5e1;border-radius:.35rem;padding:.35rem .6rem;font-size:.78rem;text-decoration:none;color:#334155;background:#fff}.template-layout-switch:hover,.template-layout-switch.active{border-color:#0d6efd;background:#eff6ff;color:#0d6efd;font-weight:600}@media(max-width:1199px){.template-custom-layout{grid-template-columns:1fr}.template-custom-preview-card{position:static}.template-custom-preview-card iframe{height:620px}}@media(max-width:576px){.template-custom-preview-card iframe{height:500px}.template-custom-page .card-header{align-items:flex-start}.template-dynamic-fields .btn{font-size:.72rem}}
</style>
