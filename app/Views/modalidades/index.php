<?php
$coresMod = ['#3b82f6','#8b5cf6','#10b981','#f59e0b','#ec4899','#ef4444','#06b6d4','#84cc16','#f97316','#6366f1'];
$totalEstudos = (int)($resumo['total_estudos'] ?? 0);
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold"><i class="fa fa-satellite-dish me-2 text-primary"></i>Modalidades</h1>
        <small class="text-muted">Modalidades DICOM recebidas pela unidade via PACS</small>
    </div>
    <a href="/pacs/exames" class="btn btn-outline-primary btn-sm"><i class="fa fa-x-ray me-1"></i> Ver Exames</a>
</div>
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body text-center py-4"><div class="fs-1 fw-bold text-primary"><?= number_format($resumo['total_modalidades'] ?? 0) ?></div><small class="text-muted">Modalidades Ativas</small></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body text-center py-4"><div class="fs-1 fw-bold text-success"><?= number_format($resumo['total_estudos'] ?? 0) ?></div><small class="text-muted">Total de Estudos</small></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body text-center py-4"><div class="fs-1 fw-bold text-info"><?= number_format($resumo['total_pacientes'] ?? 0) ?></div><small class="text-muted">Pacientes Unicos</small></div></div></div>
</div>
<?php if (empty($modalidades)): ?>
<div class="card border-0 shadow-sm"><div class="card-body text-center py-5 text-muted"><i class="fa fa-satellite-dish fa-3x mb-3 opacity-25 d-block"></i><h5>Nenhuma modalidade encontrada</h5><p>Sincronize os estudos PACS para visualizar as modalidades.</p></div></div>
<?php else: ?>
<div class="row g-3 mb-4">
<?php foreach ($modalidades as $i => $mod):
    $cor = $coresMod[$i % count($coresMod)];
    $pct = $totalEstudos > 0 ? round($mod['total_estudos']/$totalEstudos*100,1) : 0;
?>
<div class="col-md-6 col-xl-4">
    <div class="card border-0 shadow-sm h-100" style="border-left:4px solid <?= $cor ?> !important;">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div><span class="badge fs-6 px-3 py-2" style="background:<?= $cor ?>"><?= htmlspecialchars($mod['sigla']) ?></span>
                <div class="mt-1"><small class="text-muted"><?php if (!empty($mod['primeira_data']) && $mod['primeira_data'] !== '0000-00-00'): ?>Desde <?= date('m/Y', strtotime($mod['primeira_data'])) ?><?php endif; ?></small></div></div>
                <div class="text-end"><div class="fs-3 fw-bold" style="color:<?= $cor ?>"><?= number_format($mod['total_estudos']) ?></div><small class="text-muted">estudos</small></div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6"><div class="bg-light rounded p-2 text-center"><div class="fw-bold small"><?= number_format($mod['total_pacientes']) ?></div><div style="font-size:.65rem" class="text-muted">Pacientes</div></div></div>
                <div class="col-6"><div class="bg-light rounded p-2 text-center"><div class="fw-bold small"><?= number_format($mod['total_imagens'] ?? 0) ?></div><div style="font-size:.65rem" class="text-muted">Imagens</div></div></div>
                <div class="col-6"><div class="bg-light rounded p-2 text-center"><div class="fw-bold small"><?= $mod['media_imagens'] ?? '---' ?></div><div style="font-size:.65rem" class="text-muted">Img/Estudo</div></div></div>
                <div class="col-6"><div class="bg-light rounded p-2 text-center"><div class="fw-bold small"><?= $pct ?>%</div><div style="font-size:.65rem" class="text-muted">do Total</div></div></div>
            </div>
            <div class="mb-2">
                <div class="d-flex justify-content-between mb-1"><small class="text-muted">Participacao</small><small class="fw-bold"><?= $pct ?>%</small></div>
                <div class="progress" style="height:6px;"><div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $cor ?>;border-radius:3px;"></div></div>
            </div>
            <?php if (!empty($mod['ultima_data']) && $mod['ultima_data'] !== '0000-00-00'): ?><small class="text-muted"><i class="fa fa-calendar-check me-1"></i>Ultimo: <?= date('d/m/Y', strtotime($mod['ultima_data'])) ?></small><?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-0 pt-3 pb-0"><h6 class="fw-bold mb-0"><i class="fa fa-table me-2 text-primary"></i>Resumo Comparativo</h6></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light"><tr><th class="ps-3">Modalidade</th><th class="text-center">Estudos</th><th class="text-center">Pacientes</th><th class="text-center">Imagens</th><th class="text-center">Img/Est.</th><th class="text-center">Primeiro</th><th class="text-center">Ultimo</th><th class="pe-3">Participacao</th></tr></thead>
                <tbody>
                <?php foreach ($modalidades as $i => $mod):
                    $cor = $coresMod[$i % count($coresMod)];
                    $pct = $totalEstudos > 0 ? round($mod['total_estudos']/$totalEstudos*100,1) : 0;
                ?>
                <tr>
                    <td class="ps-3"><span class="badge px-2 py-1" style="background:<?= $cor ?>"><?= htmlspecialchars($mod['sigla']) ?></span></td>
                    <td class="text-center fw-bold"><?= number_format($mod['total_estudos']) ?></td>
                    <td class="text-center"><?= number_format($mod['total_pacientes']) ?></td>
                    <td class="text-center"><?= number_format($mod['total_imagens'] ?? 0) ?></td>
                    <td class="text-center text-muted"><?= $mod['media_imagens'] ?? '---' ?></td>
                    <td class="text-center text-muted small"><?= (!empty($mod['primeira_data']) && $mod['primeira_data'] !== '0000-00-00') ? date('d/m/Y',strtotime($mod['primeira_data'])) : '---' ?></td>
                    <td class="text-center text-muted small"><?= (!empty($mod['ultima_data']) && $mod['ultima_data'] !== '0000-00-00') ? date('d/m/Y',strtotime($mod['ultima_data'])) : '---' ?></td>
                    <td class="pe-3"><div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" style="height:8px;"><div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $cor ?>;border-radius:4px;"></div></div><small class="text-muted" style="min-width:35px;"><?= $pct ?>%</small></div></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
