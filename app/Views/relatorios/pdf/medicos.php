<?php
/** @var array<int,array<string,mixed>> $linhas */
/** @var array<int,array<string,mixed>> $porMedico */
/** @var array<string,int|null> $totalizadores */
/** @var array{modalidades:array<string,int>,prioridades:array<string,int>} $resumoLiberados */
/** @var array<string,string> $resumo */
/** @var string $tenantNome */
/** @var string $usuarioNome */
/** @var string $geradoEm */

$fmtMin = static function (?int $min): string {
    if ($min === null) return '—';
    $h = intdiv($min, 60);
    $m = $min % 60;
    return $h > 0 ? "{$h}h {$m}min" : "{$m}min";
};
$fmtDate = static function (?string $value): string {
    $ts = $value ? strtotime($value) : false;
    return $ts ? date('d/m/Y H:i', $ts) : '—';
};
$fmtPrioridade = static function (array $linha): string {
    $efetiva = \App\Repositories\RelatorioProdutividadeMedicosRepository::prioridadeLabel((string) ($linha['prioridade'] ?? 'ROUTINE'));
    if (empty($linha['prioridade_manual'])) return $efetiva;
    $origem = \App\Repositories\RelatorioProdutividadeMedicosRepository::prioridadeLabel((string) ($linha['prioridade_origem'] ?? 'ROUTINE'));
    return $efetiva . ' · Alterada manualmente · DICOM original: ' . $origem;
};
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><style>
@page{margin:18mm 12mm 15mm 12mm}.page{font-family:DejaVu Sans,sans-serif;color:#1e293b;font-size:8px}.header{border-bottom:2px solid #1a56db;padding-bottom:8px;margin-bottom:10px}.tenant{font-size:16px;font-weight:bold;color:#0f3d94}.title{font-size:12px;font-weight:bold;margin-top:3px}.meta{color:#64748b;font-size:8px;margin-top:3px}.filters{margin:8px 0;padding:7px;background:#f1f5f9;border:1px solid #dbe3ee}.filters span{margin-right:14px}.cards{width:100%;border-collapse:collapse;margin:8px 0}.cards td{width:16.66%;border:1px solid #dbe3ee;padding:7px}.card-label{display:block;font-size:7px;color:#64748b;text-transform:uppercase}.card-value{font-size:13px;font-weight:bold;color:#1a56db;margin-top:3px}.breakdowns{width:100%;border-collapse:separate;border-spacing:6px 0;margin:7px -6px}.breakdowns td{width:50%;border:1px solid #dbe3ee;padding:6px;vertical-align:top}.breakdown-title{font-weight:bold;color:#0f3d94;text-transform:uppercase;font-size:7px;margin-bottom:4px}.breakdown-item{display:inline-block;border:1px solid #dbe3ee;background:#f8fafc;margin:1px;padding:2px 4px}.breakdown-count{color:#1a56db;font-weight:bold;margin-left:4px}h2{font-size:10px;margin:12px 0 5px;color:#0f3d94}table{width:100%;border-collapse:collapse;font-size:7px}th{background:#1a56db;color:#fff;padding:5px;text-align:left}td{border:1px solid #dbe3ee;padding:4px;vertical-align:top}tr:nth-child(even) td{background:#f8fafc}.muted{color:#64748b}.footer{position:fixed;bottom:-8mm;left:0;right:0;text-align:center;color:#64748b;font-size:7px}
</style></head><body><div class="page">
<div class="header"><div class="tenant"><?= htmlspecialchars($tenantNome) ?></div><div class="title">Relatório de Produtividade Médica</div><div class="meta">Gerado em <?= htmlspecialchars($geradoEm) ?> por <?= htmlspecialchars($usuarioNome) ?></div></div>
<div class="filters"><?php foreach ($resumo as $label => $valor): ?><span><strong><?= htmlspecialchars($label) ?>:</strong> <?= htmlspecialchars($valor) ?></span><?php endforeach; ?></div>
<table class="cards"><tr><td><span class="card-label">Exames laudados</span><span class="card-value"><?= (int)($totalizadores['laudos'] ?? 0) ?></span></td><td><span class="card-label">Assinados</span><span class="card-value"><?= (int)($totalizadores['assinados'] ?? 0) ?></span></td><td><span class="card-label">Liberados</span><span class="card-value"><?= (int)($totalizadores['liberados'] ?? 0) ?></span></td><td><span class="card-label">Peer Review</span><span class="card-value"><?= (int)($totalizadores['peer_reviews'] ?? 0) ?></span></td><td><span class="card-label">SLA médio</span><span class="card-value"><?= htmlspecialchars($fmtMin($totalizadores['sla_medio_min'] ?? null)) ?></span></td><td><span class="card-label">SLA total</span><span class="card-value"><?= htmlspecialchars($fmtMin($totalizadores['sla_total_min'] ?? null)) ?></span></td></tr></table>
<table class="breakdowns"><tr><td><div class="breakdown-title"><?= htmlspecialchars(t('relatorios.medicos.liberados_por_modalidade')) ?></div><?php if (empty($resumoLiberados['modalidades'])): ?><span class="muted"><?= htmlspecialchars(t('relatorios.medicos.nenhum_liberado')) ?></span><?php else: foreach ($resumoLiberados['modalidades'] as $modalidade => $quantidade): ?><span class="breakdown-item"><?= htmlspecialchars($modalidade) ?><span class="breakdown-count"><?= (int)$quantidade ?></span></span><?php endforeach; endif; ?></td><td><div class="breakdown-title"><?= htmlspecialchars(t('relatorios.medicos.liberados_por_prioridade')) ?></div><?php if (empty($resumoLiberados['prioridades'])): ?><span class="muted"><?= htmlspecialchars(t('relatorios.medicos.nenhum_liberado')) ?></span><?php else: foreach ($resumoLiberados['prioridades'] as $prioridade => $quantidade): ?><span class="breakdown-item"><?= htmlspecialchars(\App\Repositories\RelatorioProdutividadeMedicosRepository::prioridadeLabel($prioridade)) ?><span class="breakdown-count"><?= (int)$quantidade ?></span></span><?php endforeach; endif; ?></td></tr></table>
<h2>Produtividade por médico</h2><table><thead><tr><th>Médico</th><th>Laudados</th><th>Assinados</th><th>Liberados</th><th>Peer Review</th><th>SLA médio</th><th>SLA total</th></tr></thead><tbody><?php if (!$porMedico): ?><tr><td colspan="7" class="muted">Nenhum resultado.</td></tr><?php else: foreach ($porMedico as $item): ?><tr><td><?= htmlspecialchars($item['medico']) ?></td><td><?= (int)$item['laudos'] ?></td><td><?= (int)$item['assinados'] ?></td><td><?= (int)$item['liberados'] ?></td><td><?= (int)$item['peer_reviews'] ?></td><td><?= htmlspecialchars($fmtMin($item['sla_medio_min'])) ?></td><td><?= htmlspecialchars($fmtMin($item['sla_total_min'])) ?></td></tr><?php endforeach; endif; ?></tbody></table>
<h2>Detalhamento dos exames laudados</h2><table><thead><tr><th>Estudo</th><th>Paciente</th><th>Descrição</th><th>Unidade</th><th>Modalidade</th><th>Prioridade</th><th>Médico</th><th>Assumido</th><th>Assinado</th><th>Liberado</th><th>Tempo médico</th><th>Conclusão</th><th>PR</th></tr></thead><tbody><?php if (!$linhas): ?><tr><td colspan="13" class="muted">Nenhum resultado.</td></tr><?php else: foreach ($linhas as $linha): ?><tr><td><?= htmlspecialchars(trim(($linha['study_date'] ?? '') . ' ' . ($linha['study_time'] ?? ''))) ?></td><td><?= htmlspecialchars($linha['paciente'] ?? '') ?><br><span class="muted">ID <?= htmlspecialchars($linha['patient_id'] ?? '') ?></span></td><td><?= htmlspecialchars($linha['descricao_estudo'] ?? '') ?></td><td><?= htmlspecialchars($linha['unidade'] ?? '') ?></td><td><?= htmlspecialchars($linha['modalities'] ?? '') ?></td><td><?= htmlspecialchars($fmtPrioridade($linha)) ?></td><td><?= htmlspecialchars($linha['medico_nome'] ?? '') ?></td><td><?= htmlspecialchars($fmtDate($linha['assumido_em'] ?? null)) ?></td><td><?= htmlspecialchars($fmtDate($linha['assinado_em'] ?? null)) ?></td><td><?= htmlspecialchars($fmtDate($linha['liberado_em'] ?? null)) ?></td><td><?= htmlspecialchars($fmtMin($linha['tempo_assinatura_min'] ?? null)) ?></td><td><?= htmlspecialchars($fmtMin($linha['tempo_conclusao_min'] ?? null)) ?></td><td><?= (int)($linha['peer_reviews'] ?? 0) ?></td></tr><?php endforeach; endif; ?></tbody></table>
<div class="footer"><?= htmlspecialchars($tenantNome) ?> — Relatório de Produtividade Médica</div>
</div></body></html>
