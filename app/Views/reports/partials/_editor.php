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
$visual = is_array($reportVisual ?? null) ? $reportVisual : [];
$unidadeNome = trim((string) ($visual['unidade_nome'] ?? $estudo->institution_name ?? 'Clínica')) ?: 'Clínica';
$logoUnidade = ltrim(trim((string) ($visual['unidade_logo_path'] ?? '')), '/');
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

$formatarData = static function (?string $valor): string {
    if (!$valor) {
        return '—';
    }
    $timestamp = strtotime($valor);
    return $timestamp ? date('d/m/Y', $timestamp) : '—';
};
$pacienteNome = trim((string) ($estudo->patient_name_display ?? $estudo->patient_name ?? '')) ?: '—';
$solicitante = \App\Helpers\DicomPersonName::format($estudo->referring_physician_name ?? null) ?: '—';
?>
<div class="pacs-card reports-editor-card<?= $modernoLateral ? ' reports-editor-card--moderno' : '' ?>">
    <?php if ($modernoLateral): ?>
        <header class="reports-modern-document-header" aria-label="Identificação institucional do laudo">
            <div class="reports-modern-document-brand">
                <?php if ($logoUnidade !== ''): ?>
                    <img class="reports-modern-document-logo" src="/<?= htmlspecialchars($logoUnidade, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?>">
                <?php else: ?>
                    <span class="reports-modern-document-logo-fallback"><?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?></span>
                <?php endif; ?>
            </div>
            <div class="reports-modern-document-heading">
                <strong>Laudo Médico</strong>
                <span><?= htmlspecialchars($unidadeNome, ENT_QUOTES) ?></span>
                <i aria-hidden="true"></i>
            </div>
        </header>

        <section class="reports-modern-document-patient" aria-label="Identificação do paciente e exame">
            <div>
                <p><strong>Paciente:</strong> <?= htmlspecialchars($pacienteNome, ENT_QUOTES) ?></p>
                <p><strong>Data de Nascimento:</strong> <?= $formatarData($estudo->patient_birth_date ?? null) ?></p>
                <p><strong>Médico(a) Solicitante:</strong> <?= htmlspecialchars($solicitante, ENT_QUOTES) ?></p>
            </div>
            <div>
                <p><strong>ID do Paciente:</strong> <?= htmlspecialchars((string) ($estudo->patient_id ?? '—'), ENT_QUOTES) ?></p>
                <p><strong>Data do Exame:</strong> <?= $formatarData($estudo->study_date ?? null) ?></p>
                <p><strong>Prontuário:</strong> <?= htmlspecialchars((string) ($estudo->accession_number ?? '—'), ENT_QUOTES) ?></p>
            </div>
        </section>

        <h1 id="reports-modern-document-title" class="reports-modern-document-title"><?= htmlspecialchars($tituloLaudo, ENT_QUOTES) ?></h1>
    <?php endif; ?>

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

    <div id="editor-container" class="reports-editor-container" data-placeholder="Redija, cole ou aplique uma máscara de laudo...">
        <?= $corpoLaudo !== '' ? $corpoLaudo : '<p><br></p>' ?>
    </div>

    <?php if (in_array($reportSituacao, ['assinado', 'liberado'], true)): ?>
        <div id="signature-block" class="reports-signature-block"></div>
    <?php endif; ?>
</div>
