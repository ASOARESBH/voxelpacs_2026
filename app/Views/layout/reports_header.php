<?php
/** @var object $estudo */
/** @var object $report */
/** @var bool $readonly */
/** @var array|null $lockInfo */
/** @var string $csrfToken */
$pacienteNome = $estudo->patient_name_display ?? $estudo->patient_name ?? 'Paciente';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laudo — <?= htmlspecialchars($pacienteNome) ?> — VOXEL PACS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css">
    <link rel="stylesheet" href="/assets/css/pacs.css?v=<?= defined('ASSET_VERSION') ? ASSET_VERSION : '2.1.0' ?>">
    <link rel="stylesheet" href="/assets/css/reports.css?v=<?= defined('ASSET_VERSION') ? ASSET_VERSION : '2.1.0' ?>">
</head>
<body class="reports-body">

<div id="reports-app"
     data-report-id="<?= (int) $report->id ?>"
     data-estudo-id="<?= (int) $estudo->id ?>"
     data-study-uid="<?= htmlspecialchars($estudo->study_instance_uid ?? '') ?>"
     data-modalidade="<?= htmlspecialchars(explode('/', (string) ($estudo->modalities ?? ''))[0] ?? '') ?>"
     data-readonly="<?= $readonly ? '1' : '0' ?>"
     data-status="<?= htmlspecialchars($report->status ?? '') ?>"
     data-csrf="<?= htmlspecialchars($csrfToken) ?>">

    <!-- ═══════════════════════════════════════════════════════
         BARRA SUPERIOR
    ═══════════════════════════════════════════════════════ -->
    <header class="reports-topbar">
        <a href="/estudos" class="pacs-btn" title="Voltar para a Worklist"><i class="fa fa-arrow-left"></i></a>

        <div class="reports-topbar-info">
            <strong><?= htmlspecialchars($pacienteNome) ?></strong>
            <span class="text-pacs-muted"><?= htmlspecialchars($estudo->study_description ?? '—') ?></span>
        </div>

        <div class="reports-topbar-actions">
            <span id="autosave-status" class="autosave-status"></span>

            <?php if (!$readonly): ?>
            <button type="button" class="pacs-btn" id="btn-template" title="Templates"><i class="fa fa-file-lines"></i> Template</button>
            <button type="button" class="pacs-btn" id="btn-ai-generate" title="Gerar texto com IA (em breve)"><i class="fa fa-wand-magic-sparkles"></i> Gerar Texto</button>
            <button type="button" class="pacs-btn" id="btn-save-draft" title="Salvar rascunho"><i class="fa fa-floppy-disk"></i> Salvar Rascunho</button>
            <button type="button" class="pacs-btn btn-pacs-primary" id="btn-sign" title="Assinar (Ctrl+Enter)"><i class="fa fa-signature"></i> Assinar</button>
            <?php endif; ?>

            <button type="button" class="pacs-btn" id="btn-history" title="Histórico de versões"><i class="fa fa-clock-rotate-left"></i> Histórico</button>
            <button type="button" class="pacs-btn" id="btn-view-pdf" title="Visualizar PDF"><i class="fa fa-file-pdf"></i> PDF</button>
            <button type="button" class="pacs-btn" id="btn-print" title="Imprimir"><i class="fa fa-print"></i></button>
        </div>
    </header>

    <?php if ($readonly): ?>
    <div class="reports-lock-banner">
        <i class="fa fa-lock"></i>
        <?php if ($lockInfo): ?>
            Este exame está em edição por <strong><?= htmlspecialchars($lockInfo['nome'] ?? 'outro médico') ?></strong>
            desde <?= htmlspecialchars($lockInfo['desde'] ?? '—') ?>. Modo somente leitura.
        <?php else: ?>
            Este laudo já foi assinado e está em modo somente leitura.
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="reports-body-grid">
