<?php
/** @var object $estudo */
/** @var object $report */
/** @var bool $readonly */
/** @var array|null $lockInfo */
/** @var string $csrfToken */
$pacienteNome = $estudo->patient_name_display ?? $estudo->patient_name ?? 'Paciente';
$situacao     = $report->situacao ?? $report->status ?? 'rascunho';
$reportId     = (int) $report->id;
$peerReview   = $peerReview ?? null;
$peerReviewAberto = !empty($peerReview['pendente']);

// ── SLA Médico: tempo desde que assumiu ─────────────────────────────────────
$slaTexto  = '';
$slaCor    = '#ef4444';
$assumidoEm = $estudo->assumido_em ?? null;
if ($assumidoEm) {
    try {
        $diff     = (new DateTime())->diff(new DateTime($assumidoEm));
        $totalMin = ($diff->days * 1440) + ($diff->h * 60) + $diff->i;
        if ($totalMin < 60) {
            $slaTexto = $diff->i . 'min';
            $slaCor   = '#22c55e';
        } elseif ($totalMin < 240) {
            $slaTexto = $diff->h . 'h ' . $diff->i . 'min';
            $slaCor   = '#f59e0b';
        } elseif ($totalMin < 1440) {
            $slaTexto = $diff->h . 'h ' . $diff->i . 'min';
            $slaCor   = '#f97316';
        } else {
            $slaTexto = $diff->days . 'd ' . $diff->h . 'h';
            $slaCor   = '#ef4444';
        }
    } catch (\Throwable $t) {}
}

// ── Botão principal: Assinar ou Liberar ─────────────────────────────────────
$jaAssinado = in_array($situacao, ['assinado', 'liberado'], true);
$chatPendente = !empty($chat['pendente']);
$modalidadesEstudo = [];
preg_match_all('/[A-Z0-9]{1,16}/', strtoupper((string) ($estudo->modalities ?? '')), $modalidadesEncontradas);
$modalidadesEstudo = array_values(array_unique($modalidadesEncontradas[0] ?? []));
$modalidadePrincipal = $modalidadesEstudo[0] ?? '';
$studyDescriptionDicom = \App\Services\StudyDescriptionResolver::text($estudo, '');
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
     data-report-id="<?= $reportId ?>"
     data-report-token="<?= htmlspecialchars((string) ($report->public_token ?? ''), ENT_QUOTES) ?>"
     data-template-id="<?= (int) ($report->template_id ?? 0) ?>"
     data-estudo-id="<?= (int) $estudo->id ?>"
     data-study-uid="<?= htmlspecialchars($estudo->study_instance_uid ?? '') ?>"
     data-modalidade="<?= htmlspecialchars($modalidadePrincipal, ENT_QUOTES) ?>"
     data-modalidades="<?= htmlspecialchars(implode(',', $modalidadesEstudo), ENT_QUOTES) ?>"
     data-study-description="<?= htmlspecialchars($studyDescriptionDicom, ENT_QUOTES) ?>"
     data-readonly="<?= $readonly ? '1' : '0' ?>"
     data-status="<?= htmlspecialchars($situacao) ?>"
     data-chat-pending="<?= $chatPendente ? '1' : '0' ?>"
     data-peer-review-pending="<?= $peerReviewAberto ? '1' : '0' ?>"
     data-peer-review-id="<?= (int) (($peerReview['aberta']->id ?? 0)) ?>"
     data-csrf="<?= htmlspecialchars($csrfToken) ?>">

    <!-- ═══════════════════════════════════════════════════════
         BARRA SUPERIOR
    ═══════════════════════════════════════════════════════ -->
    <header class="reports-topbar">
        <!-- Voltar -->
        <a href="/estudos" data-voxel-voltar="/estudos" data-voxel-return-worklist class="pacs-btn" id="btn-voltar" title="Voltar para a Worklist">
            <i class="fa fa-arrow-left"></i>
        </a>

        <!-- Info do paciente + SLA -->
        <div class="reports-topbar-info">
            <strong><?= htmlspecialchars($pacienteNome) ?></strong>
            <span class="text-pacs-muted"><?= htmlspecialchars($studyDescriptionDicom !== '' ? $studyDescriptionDicom : '—') ?></span>
        </div>

        <?php if ($slaTexto): ?>
        <!-- SLA Médico em destaque vermelho (seta indicada pelo usuário) -->
        <div class="reports-sla-badge" title="SLA Médico: tempo desde que assumiu o estudo">
            <i class="fa fa-clock"></i>
            <span id="sla-valor" style="color:<?= $slaCor ?>;"><?= htmlspecialchars($slaTexto) ?></span>
            <span style="font-size:.65rem;color:var(--pacs-text-muted);">SLA Médico</span>
        </div>
        <?php endif; ?>

        <div class="reports-topbar-actions">
            <span id="autosave-status" class="autosave-status"></span>

            <?php if (!$readonly): ?>
            <button type="button" class="btn-pacs-outline" id="btn-ai-generate" title="Gerar texto com IA (em breve)">
                <i class="fa fa-wand-magic-sparkles"></i> Gerar Texto
            </button>
            <button type="button" class="btn-pacs-outline" id="btn-dictate" title="Iniciar ditado por voz" aria-pressed="false">
                <i class="fa fa-microphone"></i> <span data-dictation-label>Ditar</span>
            </button>
            <span id="dictation-status" class="dictation-status" role="status" aria-live="polite"></span>
            <button type="button" class="btn-pacs-outline" id="btn-save-draft" title="Salvar rascunho (Ctrl+S)">
                <i class="fa fa-floppy-disk"></i> Salvar Rascunho
            </button>
            <?php endif; ?>

            <?php if ($situacao === 'assinado'): ?>
            <!-- Laudo assinado, mas ainda não liberado: permite finalizar. -->
            <button type="button" class="btn-pacs-success" id="btn-liberar"
                    title="<?= $chatPendente ? 'Conclua o CHAT antes de liberar' : 'Liberar laudo e fechar' ?>"
                    <?= $chatPendente ? 'disabled' : '' ?>>
                <i class="fa fa-paper-plane"></i> Liberar
            </button>
            <?php elseif (!$readonly): ?>
            <!-- Laudo não assinado: mostra Assinar -->
            <button type="button" class="btn-pacs-primary" id="btn-sign"
                    title="<?= $chatPendente ? 'Conclua o CHAT antes de assinar' : 'Assinar laudo (Ctrl+Enter)' ?>"
                    <?= $chatPendente ? 'disabled' : '' ?>>
                <i class="fa fa-signature"></i> Assinar
            </button>
            <?php endif; ?>

            <?php if (!empty($pedido) && !empty($pedido['visualizar_url'])): ?>
            <a href="<?= htmlspecialchars($pedido['visualizar_url']) ?>" class="btn-pacs-outline" id="btn-pedido" target="_blank" rel="noopener"
               title="<?= htmlspecialchars(t('pedido_medico.acao.consultar')) ?>">
                <i class="fa fa-paperclip"></i> <?= htmlspecialchars(t('pedido_medico.acao.pedido')) ?>
            </a>
            <?php endif; ?>
            <button type="button" class="btn-pacs-outline" id="btn-history" title="Histórico de versões">
                <i class="fa fa-clock-rotate-left"></i> Histórico
            </button>
            <button type="button" class="btn-pacs-outline" id="btn-view-pdf" title="Visualizar PDF">
                <i class="fa fa-file-pdf"></i> PDF
            </button>
            <button type="button" class="pacs-btn" id="btn-print" title="Imprimir">
                <i class="fa fa-print"></i>
            </button>
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
