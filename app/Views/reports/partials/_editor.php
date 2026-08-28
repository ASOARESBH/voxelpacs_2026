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
    <div id="editor-toolbar" class="reports-editor-toolbar">
        <span class="ql-formats">
            <button class="ql-bold" title="Negrito"></button>
            <button class="ql-italic" title="Itálico"></button>
            <button class="ql-underline" title="Sublinhado"></button>
        </span>
        <span class="ql-formats">
            <select class="ql-header" title="Cabeçalho">
                <option value="1"></option>
                <option value="2"></option>
                <option value="3"></option>
                <option selected></option>
            </select>
        </span>
        <span class="ql-formats">
            <button class="ql-list" value="ordered" title="Numeração"></button>
            <button class="ql-list" value="bullet" title="Marcadores"></button>
        </span>
        <span class="ql-formats">
            <select class="ql-align" title="Alinhamento">
                <option selected></option>
                <option value="center"></option>
                <option value="right"></option>
                <option value="justify"></option>
            </select>
            <button class="ql-link" title="Inserir link HTTPS"></button>
        </span>
        <span class="ql-formats">
            <button class="ql-table" title="Inserir tabela"><i class="fa fa-table"></i></button>
        </span>
        <span class="ql-formats reports-editor-spacing-controls">
            <label class="reports-editor-spacing-label" for="editor-spacing-select"><?= htmlspecialchars(t('reports.editor.espacamento')) ?></label>
            <select id="editor-spacing-select" class="reports-editor-spacing-select" title="<?= htmlspecialchars(t('reports.editor.espacamento')) ?>">
                <option value="compact"><?= htmlspecialchars(t('reports.editor.espacamento_compacto')) ?></option>
                <option value="normal" selected><?= htmlspecialchars(t('reports.editor.espacamento_normal')) ?></option>
                <option value="medium"><?= htmlspecialchars(t('reports.editor.espacamento_medio')) ?></option>
                <option value="wide"><?= htmlspecialchars(t('reports.editor.espacamento_amplo')) ?></option>
            </select>
            <button type="button" class="ql-spacer" title="<?= htmlspecialchars(t('reports.editor.inserir_espaco')) ?>"><i class="fa fa-arrows-v"></i></button>
        </span>
        <span class="ql-formats reports-editor-page-guide-control">
            <label for="editor-page-guide" title="<?= htmlspecialchars(t('reports.editor.guia_pagina')) ?>">
                <input id="editor-page-guide" type="checkbox" autocomplete="off">
                <span><?= htmlspecialchars(t('reports.editor.guia_pagina')) ?></span>
            </label>
        </span>
        <span class="ql-formats">
            <button class="ql-undo" title="Desfazer"><i class="fa fa-rotate-left"></i></button>
            <button class="ql-redo" title="Refazer"><i class="fa fa-rotate-right"></i></button>
        </span>
        <span class="ql-formats">
            <button class="ql-clean" title="Limpar formatação"></button>
        </span>
    </div>


    <div id="editor-container" class="reports-editor-container" data-placeholder="Redija, cole ou aplique uma máscara de laudo..." data-page-guide-label="<?= htmlspecialchars(t('reports.editor.guia_pagina_label')) ?>">
        <?= $corpoLaudo !== '' ? $corpoLaudo : '<p><br></p>' ?>
    </div>

    <?php if (in_array($reportSituacao, ['assinado', 'liberado'], true)): ?>
        <div id="signature-block" class="reports-signature-block"></div>
    <?php endif; ?>
</div>
