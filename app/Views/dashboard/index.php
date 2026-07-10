<?php
$periodos = array_column($evolucao, 'periodo_ref');
$totais   = array_column($evolucao, 'total');
$receitas = array_column($evolucao, 'receita');
$modNomes = array_column($modalidades, 'modalidade');
$modTotais = array_column($modalidades, 'total');
?>

<!-- Alertas preditivos -->
<?php foreach ($alertas as $alerta): ?>
<div class="alert alert-<?= htmlspecialchars($alerta['tipo']) ?> alert-dismissible fade show" role="alert">
    <i class="fa fa-bell me-2"></i><?= htmlspecialchars($alerta['mensagem']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-muted small mb-1">Total de Exames</p>
                        <h3 class="fw-bold mb-0"><?= number_format($kpis['total_exames'] ?? 0) ?></h3>
                    </div>
                    <div class="rounded-circle bg-primary bg-opacity-10 p-3">
                        <i class="fa fa-x-ray text-primary fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-muted small mb-1">Urgências</p>
                        <h3 class="fw-bold mb-0 text-danger"><?= number_format($kpis['total_urgencia'] ?? 0) ?></h3>
                    </div>
                    <div class="rounded-circle bg-danger bg-opacity-10 p-3">
                        <i class="fa fa-exclamation-triangle text-danger fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-muted small mb-1">SLA Médio (min)</p>
                        <h3 class="fw-bold mb-0 text-warning"><?= number_format($kpis['sla_medio'] ?? 0) ?></h3>
                    </div>
                    <div class="rounded-circle bg-warning bg-opacity-10 p-3">
                        <i class="fa fa-clock text-warning fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-muted small mb-1">Receita Total</p>
                        <h3 class="fw-bold mb-0 text-success">R$ <?= number_format($kpis['valor_total'] ?? 0, 2, ',', '.') ?></h3>
                    </div>
                    <div class="rounded-circle bg-success bg-opacity-10 p-3">
                        <i class="fa fa-dollar-sign text-success fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Gráficos -->
<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-3">
                <h6 class="fw-bold mb-0"><i class="fa fa-chart-line me-2 text-primary"></i>Evolução Mensal de Exames</h6>
            </div>
            <div class="card-body">
                <canvas id="chartEvolucao" height="100"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-3">
                <h6 class="fw-bold mb-0"><i class="fa fa-chart-pie me-2 text-primary"></i>Modalidades</h6>
            </div>
            <div class="card-body">
                <canvas id="chartModalidades" height="180"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Top Médicos + SLA -->
<div class="row g-3">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-3">
                <h6 class="fw-bold mb-0"><i class="fa fa-user-md me-2 text-primary"></i>Top Médicos</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light"><tr><th>Médico</th><th class="text-end">Exames</th></tr></thead>
                    <tbody>
                    <?php foreach ($topMedicos as $m): ?>
                    <tr>
                        <td><?= htmlspecialchars($m->medico_nome ?? '—') ?></td>
                        <td class="text-end fw-semibold"><?= number_format($m->total) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-3">
                <h6 class="fw-bold mb-0"><i class="fa fa-clock me-2 text-primary"></i>SLA por Mês</h6>
            </div>
            <div class="card-body">
                <canvas id="chartSla" height="140"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
const periodos   = <?= json_encode($periodos) ?>;
const totais     = <?= json_encode(array_map('intval', $totais)) ?>;
const receitas   = <?= json_encode(array_map('floatval', $receitas)) ?>;
const modNomes   = <?= json_encode($modNomes) ?>;
const modTotais  = <?= json_encode(array_map('intval', $modTotais)) ?>;
const slaData    = <?= json_encode($slaStatus) ?>;

// Evolução mensal
new Chart(document.getElementById('chartEvolucao'), {
    type: 'bar',
    data: {
        labels: periodos,
        datasets: [
            { label: 'Exames', data: totais, backgroundColor: 'rgba(59,130,246,.7)', borderRadius: 4 },
            { label: 'Receita (R$)', data: receitas, type: 'line', borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.1)', yAxisID: 'y1', tension: .4, fill: true }
        ]
    },
    options: { responsive: true, scales: { y: { beginAtZero: true }, y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false } } } }
});

// Modalidades
new Chart(document.getElementById('chartModalidades'), {
    type: 'doughnut',
    data: { labels: modNomes, datasets: [{ data: modTotais, backgroundColor: ['#3b82f6','#8b5cf6','#10b981','#f59e0b','#ec4899','#ef4444','#06b6d4','#84cc16'] }] },
    options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } } }
});

// SLA
new Chart(document.getElementById('chartSla'), {
    type: 'bar',
    data: {
        labels: slaData.map(r => r.periodo_ref),
        datasets: [
            { label: 'Dentro do SLA', data: slaData.map(r => r.dentro), backgroundColor: '#10b981', borderRadius: 3 },
            { label: 'Fora do SLA',   data: slaData.map(r => r.fora),   backgroundColor: '#ef4444', borderRadius: 3 }
        ]
    },
    options: { responsive: true, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } }
});
</script>
