<?php
/** @var object $report */
/** @var array|null $peerReview */
$situacaoPeerReview = $report->situacao ?? $report->status ?? 'rascunho';
$abertaPeerReview = is_array($peerReview) ? ($peerReview['aberta'] ?? null) : null;
$elegivelPeerReview = in_array($situacaoPeerReview, ['assinado', 'liberado'], true);
$mostrarPeerReview = $elegivelPeerReview || $situacaoPeerReview === 'peer_review' || $abertaPeerReview;
if (!$mostrarPeerReview) return;
?>
<div class="pacs-card reports-card peer-review-card" id="card-peer-review"
     data-peer-review-report-id="<?= (int) $report->id ?>"
     data-peer-review-open="<?= $abertaPeerReview ? '1' : '0' ?>">
    <div class="pacs-card-header peer-review-header">
        <span><i class="fa fa-user-check"></i> <?= htmlspecialchars(t('peer_review.titulo')) ?></span>
        <?php if ($abertaPeerReview): ?>
            <span class="peer-review-status peer-review-status-open">
                <?= htmlspecialchars(t('peer_review.status_aberta')) ?>
            </span>
        <?php else: ?>
            <span class="peer-review-status peer-review-status-available">
                <?= htmlspecialchars(t('peer_review.status_disponivel')) ?>
            </span>
        <?php endif; ?>
    </div>
    <div class="pacs-card-body reports-card-body peer-review-body">
        <?php if ($abertaPeerReview): ?>
            <div class="peer-review-alert">
                <i class="fa fa-rotate"></i>
                <span><?= htmlspecialchars(t('peer_review.aviso_aberta')) ?></span>
            </div>
            <div class="peer-review-meta">
                <div><span><?= htmlspecialchars(t('peer_review.ciclo')) ?></span> <strong><?= (int) ($abertaPeerReview->ciclo ?? 0) ?></strong></div>
                <div><span><?= htmlspecialchars(t('peer_review.motivo')) ?></span> <strong><?= htmlspecialchars($abertaPeerReview->motivo ?? '') ?></strong></div>
            </div>
            <small class="peer-review-hint">
                <?= htmlspecialchars(t('peer_review.original_preservado')) ?>
            </small>
        <?php else: ?>
            <p class="peer-review-description"><?= htmlspecialchars(t('peer_review.descricao')) ?></p>
            <div class="peer-review-field">
                <label for="peer-review-motivo"><?= htmlspecialchars(t('peer_review.motivo')) ?></label>
                <textarea id="peer-review-motivo" class="form-control" rows="4" minlength="20" maxlength="2000"
                          placeholder="<?= htmlspecialchars(t('peer_review.motivo_placeholder')) ?>"></textarea>
                <div class="peer-review-counter"><span id="peer-review-motivo-count">0</span>/2000</div>
            </div>
            <button type="button" class="pacs-btn btn-pacs-warning peer-review-open-btn" id="btn-open-peer-review">
                <i class="fa fa-rotate"></i> <?= htmlspecialchars(t('peer_review.liberar_botao')) ?>
            </button>
            <div id="peer-review-error" class="reports-alert-erro d-none" role="alert"></div>
        <?php endif; ?>
    </div>
</div>
