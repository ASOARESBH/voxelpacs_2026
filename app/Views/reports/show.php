<?php
/** @var object $estudo */
/** @var object $report */
/** @var bool $readonly */
?>
<div class="reports-col-left">
    <?php include __DIR__ . '/partials/_paciente_card.php'; ?>
    <?php include __DIR__ . '/partials/_exame_card.php'; ?>
    <?php include __DIR__ . '/partials/_equipamento_card.php'; ?>
</div>

<div class="reports-col-right">
    <?php include __DIR__ . '/partials/_editor.php'; ?>
</div>

<?php include __DIR__ . '/partials/_modal_templates.php'; ?>
<?php include __DIR__ . '/partials/_modal_historico.php'; ?>
<?php if (!$readonly): ?>
    <?php include __DIR__ . '/partials/_modal_assinatura.php'; ?>
<?php endif; ?>
