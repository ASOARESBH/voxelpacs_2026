<?php
// View: Modalidades PACS — Painel do Cliente

// Prepara dados de evolução mensal para gráfico
$mesesLabels = [];
$modalidadesGrafico = [];
foreach ($evolucao as $row) {
    if (!in_array($row['mes'], $mesesLabels)) {
        $mesesLabels[] = $row['mes'];
    }
    if (!isset($modalidadesGrafico[$row['modalidade']])) {
        $modalidadesGrafico[$row['modalidade']] = [];
    }
    $modalidadesGrafico[$row['modalidade']][$row['mes']] = (int)$row['total'];
}
sort($mesesLabels);

// Paleta de cores para o gráfico
$cores = ['#0d6efd','#198754','#dc3545','#ffc107','#0dcaf0','#6f42c1','#fd7e14','#20c997','#6c757d','#d63384'];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="fa fa-satellite-dish me-2 text-primary"></i>Modalidades PACS</h1>
        <small class="text-muted">Distribuição de exames por modalidade DICOM da sua unidade</small>
    </div>
    <a href="/pacs/exames" class="btn btn-outline-secondary btn-sm">
        <i class="fa fa-list me-1"></i> Ver Todos os Exames
    </a>
</div>

<!-- CARDS DE ESTATÍSTICAS -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body py-3">
                <div class="fs-3 fw-bold text-primary"><?= number_format($stats['total_estudos'] ?? 0) ?></div>
                <small class="text-muted">Total de Estudos</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body py-3">
                <div class="fs-3 fw-bold text-success"><?= number_format($stats['total_pacientes'] ?? 0) ?></div>
                <small class="text-muted">Pacientes Únicos</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body py-3">
                <div class="fs-3 fw-bold text-info"><?= number_format($stats['total_imagens'] ?? 0) ?></div>
                <small class="text-muted">Total de Imagens</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body py-3">
                <div class="fs-3 fw-bold text-warning"><?= number_format($stats['ultimos_30_dias'] ?? 0) ?></div>
                <small class="text-muted">Últimos 30 dias</small>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">

    <!-- TABELA DE MODALIDADES -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0 fw-bold"><i class="fa fa-table me-2 text-primary"></i>Resumo por Modalidade</h6>
            </div>
            <div class="card-body p-0">
                <?php if (empty($modalidades)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fa fa-satellite-dish fa-2x mb-2 opacity-25"></i>
                        <p class="mb-0">Nenhum estudo importado ainda.</p>
                    </div>
                <?php else: ?>
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Modalidade</th>
                                <th class="text-center">Estudos</th>
                                <th class="text-center">Pacientes</th>
                                <th class="text-center">Imagens</th>
                                <th class="text-center">Unidades</th>
                                <th>Primeiro</th>
                                <th>Último</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $totalEstudos = array_sum(array_column($modalidades, 'total_estudos'));
                            foreach ($modalidades as $i => $mod):
                                $pct = $totalEstudos > 0 ? round($mod['total_estudos'] / $totalEstudos * 100, 1) : 0;
                                $cor = $cores[$i % count($cores)];
                            ?>
                                <tr>
                                    <td>
                                        <span class="badge me-1" style="background:<?= $cor ?>">
                                            <?= htmlspecialchars($mod['modalidade'] ?: '—') ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <strong><?= number_format($mod['total_estudos']) ?></strong>
                                        <div class="progress mt-1" style="height:4px;">
                                            <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $cor ?>"></div>
                                        </div>
                                        <small class="text-muted"><?= $pct ?>%</small>
                                    </td>
                                    <td class="text-center"><?= number_format($mod['total_pacientes'] ?? 0) ?></td>
                                    <td class="text-center"><?= number_format($mod['total_imagens'] ?? 0) ?></td>
                                    <td class="text-center"><?= number_format($mod['total_unidades'] ?? 0) ?></td>
                                    <td class="small text-muted">
                                        <?= $mod['primeira_data'] ? date('d/m/Y', strtotime($mod['primeira_data'])) : '—' ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?= $mod['ultima_data'] ? date('d/m/Y', strtotime($mod['ultima_data'])) : '—' ?>
                                    </td>
                                    <td>
                                        <a href="/pacs/exames?modalidade=<?= urlencode($mod['modalidade']) ?>"
                                           class="btn btn-sm btn-outline-primary py-0 px-1" title="Ver exames">
                                            <i class="fa fa-search"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- GRÁFICO DE EVOLUÇÃO -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0 fw-bold"><i class="fa fa-chart-line me-2 text-primary"></i>Evolução Mensal (12 meses)</h6>
            </div>
            <div class="card-body">
                <?php if (empty($evolucao)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fa fa-chart-line fa-2x mb-2 opacity-25"></i>
                        <p class="mb-0 small">Sem dados suficientes para o gráfico.</p>
                    </div>
                <?php else: ?>
                    <canvas id="chartModalidades" height="280"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<?php if (!empty($evolucao)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
    const labels = <?= json_encode($mesesLabels) ?>;
    const datasets = [];
    const mods = <?= json_encode(array_keys($modalidadesGrafico)) ?>;
    const data  = <?= json_encode($modalidadesGrafico) ?>;
    const cores = <?= json_encode($cores) ?>;

    mods.forEach((mod, i) => {
        const values = labels.map(mes => data[mod][mes] ?? 0);
        datasets.push({
            label: mod,
            data: values,
            borderColor: cores[i % cores.length],
            backgroundColor: cores[i % cores.length] + '33',
            tension: 0.3,
            fill: false,
            pointRadius: 3,
        });
    });

    new Chart(document.getElementById('chartModalidades'), {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } }
            },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 } },
                x: { ticks: { font: { size: 10 } } }
            }
        }
    });
})();
</script>
<?php endif; ?>
