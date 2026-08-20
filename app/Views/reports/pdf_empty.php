<?php
/** @var string $reportToken */
$reportToken = trim((string) ($reportToken ?? ''));
$returnUrl = $reportToken !== '' ? '/reports/r/' . rawurlencode($reportToken) : '/estudos';
?>
<div class="container py-5">
    <div class="card shadow-sm mx-auto col-12 col-md-8">
        <div class="card-body p-4 text-center">
            <div class="fs-2 text-warning mb-3"><i class="fa fa-file-circle-xmark"></i></div>
            <h1 class="h5 mb-2">Laudo sem conteúdo clínico</h1>
            <p class="text-muted mb-4">A visualização, impressão e o download ficam disponíveis após digitar o laudo ou aplicar uma máscara com conteúdo.</p>
            <a href="<?= htmlspecialchars($returnUrl, ENT_QUOTES) ?>" class="btn btn-primary" data-voxel-voltar="<?= htmlspecialchars($returnUrl, ENT_QUOTES) ?>">
                <i class="fa fa-arrow-left me-1"></i> Voltar ao Laudário
            </a>
        </div>
    </div>
</div>
<script src="/assets/js/shared/voxel-voltar.js?v=<?= defined('ASSET_VERSION') ? ASSET_VERSION : '2.3.3' ?>"></script>
