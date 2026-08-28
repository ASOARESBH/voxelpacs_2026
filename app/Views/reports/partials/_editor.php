<?php
/** @var object $report */
/** @var object $estudo */
/** @var bool $readonly */
/** @var string $reportLayoutCodigo */
/** @var array<string,mixed> $reportVisual */
$conteudo = [];
if (isset($report->conteudo) && is_string($report->conteudo) && trim($report->conteudo) !== '') {
    $conteudo = json_decode($report->conteudo, true) ?: [];
}
$secoesJson = is_array($conteudo['secoes'] ?? null) ? $conteudo['secoes'] : [];
$corpoLaudo = property_exists($report, 'corpo_laudo') ? (string) ($report->corpo_laudo ?? '') : '';
if (trim($corpoLaudo) === '') {
    $corpoLaudo = (string) ($secoesJson['corpo'] ?? '');
}
if (trim($corpoLaudo) === '') {
    $blocosLegados = [];
    foreach (['exame', 'tecnica', 'achados', 'conclusao', 'recomendacao'] as $chave) {
        $campo = 'secao_' . $chave;
        $valorColuna = property_exists($report, $campo) ? ($report->{$campo} ?? null) : null;
        $valor = ($valorColuna !== null && $valorColuna !== '') ? (string) $valorColuna : (string) ($secoesJson[$chave] ?? '');
        if (trim(strip_tags($valor)) !== '') {
            $blocosLegados[] = $valor;
        }
    }
    $corpoLaudo = implode('<br><br>', $blocosLegados);
}

$reportSituacao = $report->situacao ?? $report->status ?? 'rascunho';
$modernoLateral = ($reportLayoutCodigo ?? '') === 'moderno_lateral';
?>
<div class="pacs-card reports-editor-card<?= $modernoLateral ? ' reports-editor-card--moderno' : '' ?>">
    <div id="editor-toolbar" class="reports-editor-toolbar reports-editor-toolbar--two-row" role="toolbar">
        <div class="reports-toolbar-row reports-toolbar-row--format">
            <span class="ql-formats reports-toolbar-group reports-toolbar-group--font">
                <span class="reports-toolbar-caption"><?= htmlspecialchars(t('reports.editor.fonte')) ?></span>
                <select class="ql-font" title="<?= htmlspecialchars(t('reports.editor.fonte')) ?>">
                    <option selected></option>
                    <option value="arial"></option>
                    <option value="verdana"></option>
                    <option value="times-new-roman"></option>
                </select>
                <span class="reports-toolbar-caption reports-toolbar-caption--size"><?= htmlspecialchars(t('reports.editor.tamanho_fonte')) ?></span>
                <select class="ql-size" title="<?= htmlspecialchars(t('reports.editor.tamanho_fonte')) ?>">
                    <option value="small"></option>
                    <option selected></option>
                    <option value="large"></option>
                    <option value="huge"></option>
                </select>
            </span>
            <span class="ql-formats reports-toolbar-group reports-toolbar-group--text">
                <button class="ql-bold" title="Negrito"></button>
                <button class="ql-italic" title="Itálico"></button>
                <button class="ql-underline" title="Sublinhado"></button>
                <select class="ql-header" title="Cabeçalho">
                    <option value="1"></option>
                    <option value="2"></option>
                    <option value="3"></option>
                    <option selected></option>
                </select>
            </span>
            <span class="ql-formats reports-toolbar-group reports-toolbar-group--history">
                <button class="ql-undo" title="Desfazer"><i class="fa fa-rotate-left"></i></button>
                <button class="ql-redo" title="Refazer"><i class="fa fa-rotate-right"></i></button>
                <button class="ql-clean" title="Limpar formatação"></button>
            </span>
        </div>
        <div class="reports-toolbar-row reports-toolbar-row--paragraph">
            <span class="ql-formats reports-toolbar-group reports-toolbar-group--paragraph">
                <button class="ql-list" value="ordered" title="Numeração"></button>
                <button class="ql-list" value="bullet" title="Marcadores"></button>
                <select class="ql-align" title="Alinhamento">
                    <option selected></option>
                    <option value="center"></option>
                    <option value="right"></option>
                    <option value="justify"></option>
                </select>
            </span>
            <span class="ql-formats reports-toolbar-group reports-toolbar-group--insert">
                <button class="ql-link" title="Inserir link HTTPS"></button>
                <button class="ql-table" title="Inserir tabela"><i class="fa fa-table"></i></button>
            </span>
            <span class="ql-formats reports-toolbar-group reports-toolbar-group--spacing" role="group" aria-label="<?= htmlspecialchars(t('reports.editor.espacamento')) ?>">
                <span class="reports-toolbar-caption"><?= htmlspecialchars(t('reports.editor.espacamento')) ?></span>
                <button type="button" class="reports-editor-spacing-button" data-editor-spacing="compact" title="<?= htmlspecialchars(t('reports.editor.espacamento_compacto')) ?>"><?= htmlspecialchars(t('reports.editor.espacamento_compacto')) ?></button>
                <button type="button" class="reports-editor-spacing-button is-active" data-editor-spacing="normal" title="<?= htmlspecialchars(t('reports.editor.espacamento_normal')) ?>"><?= htmlspecialchars(t('reports.editor.espacamento_normal')) ?></button>
                <button type="button" class="reports-editor-spacing-button" data-editor-spacing="medium" title="<?= htmlspecialchars(t('reports.editor.espacamento_medio')) ?>"><?= htmlspecialchars(t('reports.editor.espacamento_medio')) ?></button>
                <button type="button" class="reports-editor-spacing-button" data-editor-spacing="wide" title="<?= htmlspecialchars(t('reports.editor.espacamento_amplo')) ?>"><?= htmlspecialchars(t('reports.editor.espacamento_amplo')) ?></button>
                <button type="button" class="ql-spacer" title="<?= htmlspecialchars(t('reports.editor.inserir_espaco')) ?>"><i class="fa fa-arrows-v"></i></button>
            </span>
            <span class="ql-formats reports-toolbar-group reports-toolbar-group--page">
                <label class="reports-editor-page-guide-control" for="editor-page-guide" title="<?= htmlspecialchars(t('reports.editor.guia_pagina')) ?>">
                    <input id="editor-page-guide" type="checkbox" autocomplete="off" aria-label="<?= htmlspecialchars(t('reports.editor.guia_pagina')) ?>">
                    <i class="fa fa-file-text-o" aria-hidden="true"></i>
                    <span><?= htmlspecialchars(t('reports.editor.guia_pagina')) ?></span>
                </label>
            </span>
        </div>
    </div>


    <div id="editor-container" class="reports-editor-container" data-placeholder="Redija, cole ou aplique uma máscara de laudo..." data-page-guide-label="<?= htmlspecialchars(t('reports.editor.guia_pagina_label')) ?>">
        <?= $corpoLaudo !== '' ? $corpoLaudo : '<p><br></p>' ?>
    </div>

    <?php if (in_array($reportSituacao, ['assinado', 'liberado'], true)): ?>
        <div id="signature-block" class="reports-signature-block"></div>
    <?php endif; ?>
</div>
