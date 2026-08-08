<?php
/** @var object $report */
/** @var bool $readonly */
$conteudo = [];
if (isset($report->conteudo) && is_string($report->conteudo) && trim($report->conteudo) !== '') {
    $conteudo = json_decode($report->conteudo, true) ?: [];
}
$secoesJson = is_array($conteudo['secoes'] ?? null) ? $conteudo['secoes'] : [];
$secoes = [];
foreach (['exame', 'tecnica', 'achados', 'conclusao', 'recomendacao'] as $chave) {
    $campo = 'secao_' . $chave;
    $valorColuna = property_exists($report, $campo) ? ($report->{$campo} ?? null) : null;
    $secoes[$chave] = ($valorColuna !== null && $valorColuna !== '')
        ? (string) $valorColuna
        : (string) ($secoesJson[$chave] ?? '');
}
$reportSituacao = $report->situacao ?? $report->status ?? 'rascunho';

$secaoTitulos = [
    'exame'        => 'Exame',
    'tecnica'      => 'Técnica',
    'achados'      => 'Achados',
    'conclusao'    => 'Conclusão',
    'recomendacao' => 'Recomendação',
];
?>
<div class="pacs-card reports-editor-card">
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

    <div id="editor-container" class="reports-editor-container">
        <?php foreach ($secaoTitulos as $chave => $titulo): ?>
            <h4 data-secao="<?= $chave ?>"><?= $titulo ?></h4>
            <?= $secoes[$chave] ?? '<p><br></p>' ?>
        <?php endforeach; ?>
    </div>

    <?php if (in_array($reportSituacao, ['assinado', 'liberado'], true)): ?>
        <div id="signature-block" class="reports-signature-block"></div>
    <?php endif; ?>
</div>
