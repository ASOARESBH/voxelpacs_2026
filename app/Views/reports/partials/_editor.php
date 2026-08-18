<?php
/** @var object $report */
/** @var object $estudo */
/** @var bool $readonly */
/** @var string $reportLayoutCodigo */
/** @var array<string,mixed> $reportVisual */
/** @var string $mascaraTitulo */

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
$tituloLaudo = trim((string) ($mascaraTitulo ?? ''));
if ($tituloLaudo === '') {
    foreach (['study_description', 'requested_procedure_desc', 'body_part_examined', 'modalities'] as $campo) {
        $tituloLaudo = trim((string) ($estudo->{$campo} ?? ''));
        if ($tituloLaudo !== '') {
            break;
        }
    }
}
$tituloLaudo = $tituloLaudo !== '' ? $tituloLaudo : 'Laudo Médico';
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
            <button class="ql-table" title="Inserir tabela"><i class="fa fa-table"></i></button>
        </span>
        <span class="ql-formats">
            <button class="ql-undo" title="Desfazer"><i class="fa fa-rotate-left"></i></button>
            <button class="ql-redo" title="Refazer"><i class="fa fa-rotate-right"></i></button>
        </span>
        <span class="ql-formats">
            <button class="ql-clean" title="Limpar formatação"></button>
        </span>
    </div>

    <h1 id="reports-editor-document-title" class="reports-editor-document-title"><?= htmlspecialchars($tituloLaudo, ENT_QUOTES) ?></h1>

    <div id="editor-container" class="reports-editor-container" data-placeholder="Redija, cole ou aplique uma máscara de laudo...">
        <?= $corpoLaudo !== '' ? $corpoLaudo : '<p><br></p>' ?>
    </div>

    <?php if (in_array($reportSituacao, ['assinado', 'liberado'], true)): ?>
        <div id="signature-block" class="reports-signature-block"></div>
    <?php endif; ?>
</div>
