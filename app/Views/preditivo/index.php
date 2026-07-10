<?php
$historico = $projecao['historico'] ?? [];
$proj      = $projecao['projecao'] ?? [];
$tendencia = $projecao['tendencia'] ?? 0;
$labels    = array_merge(array_column($historico, 'periodo_ref'), array_column($proj, 'periodo_ref'));
$reais     = array_merge(array_column($historico, 'total'), array_fill(0, count($proj), null));
$projetados = array_merge(array_fill(0, count($historico), null), array_column($proj, 'total'));
$minProj   = array_merge(array_fill(0, count($historico), null), array_column($proj, 'min'));
$maxProj   = array_merge(array_fill(0, count($historico), null), array_column($proj, 'max'));
?>

<!-- Alertas -->
<?php foreach ($alertas as $a): ?>
<div class="alert alert-<?= htmlspecialchars($a['tipo']) ?> alert-dismissible fade show">
    <i class="fa fa-robot me-2"></i><?= htmlspecialchars($a['mensagem']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <i class="fa fa-chart-line fa-2x text-primary mb-2"></i>
                <p class="text-muted small mb-1">Tendência Mensal</p>
                <h3 class="fw-bold <?= $tendencia >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= $tendencia >= 0 ? '+' : '' ?><?= number_format($tendencia, 1) ?> exames/mês
                </h3>
            </div>
        </div>
    </div>
    <?php foreach ($proj as $p): ?>
    <div class="col-md-<?= 8 / count($proj) ?>">
        <div class="card border-0 shadow-sm h-100 border-start border-primary border-3">
            <div class="card-body">
                <p class="text-muted small mb-1">Projeção <?= htmlspecialchars($p['periodo_ref']) ?></p>
                <h4 class="fw-bold mb-0"><?= number_format($p['total']) ?></h4>
                <small class="text-muted"><?= number_format($p['min']) ?> – <?= number_format($p['max']) ?></small>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-0 pt-3">
        <h6 class="fw-bold mb-0"><i class="fa fa-magic me-2 text-primary"></i>Projeção de Volume — Regressão Linear</h6>
    </div>
    <div class="card-body">
        <canvas id="chartPreditivo" height="80"></canvas>
    </div>
</div>

<script>
new Chart(document.getElementById('chartPreditivo'), {
    type: 'line',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [
            { label: 'Histórico', data: <?= json_encode($reais) ?>, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.1)', tension: .4, fill: true, spanGaps: false },
            { label: 'Projeção',  data: <?= json_encode($projetados) ?>, borderColor: '#10b981', borderDash: [6,3], tension: .4, spanGaps: false },
            { label: 'Mín',       data: <?= json_encode($minProj) ?>, borderColor: 'rgba(16,185,129,.3)', borderDash: [3,3], fill: false, spanGaps: false },
            { label: 'Máx',       data: <?= json_encode($maxProj) ?>, borderColor: 'rgba(16,185,129,.3)', borderDash: [3,3], fill: '-1', backgroundColor: 'rgba(16,185,129,.05)', spanGaps: false }
        ]
    },
    options: { responsive: true, scales: { y: { beginAtZero: true } } }
});
</script>
