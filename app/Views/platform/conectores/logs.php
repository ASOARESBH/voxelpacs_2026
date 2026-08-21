<?php
$logs = $logs ?? [];
$tipo = $tipo ?? '';
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div><h1 class="h3 mb-1"><i class="fa fa-list text-primary me-2"></i>Logs de Conectores</h1><p class="text-muted mb-0">Últimos 100 disparos e testes, sem exposição de tokens ou API Keys.</p></div>
        <a class="btn btn-outline-secondary" href="/platform/conectores"><i class="fa fa-arrow-left me-1"></i>Conectores</a>
    </div>
    <form method="get" class="card border-0 shadow-sm mb-3"><div class="card-body d-flex flex-wrap gap-2 align-items-end"><div><label class="form-label small" for="tipo">Conector</label><select class="form-select" id="tipo" name="tipo"><option value="">Todos</option><option value="whatsapp" <?= $tipo === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option><option value="telegram" <?= $tipo === 'telegram' ? 'selected' : '' ?>>Telegram</option></select></div><button class="btn btn-primary" type="submit"><i class="fa fa-filter me-1"></i>Filtrar</button></div></form>
    <div class="card border-0 shadow-sm overflow-auto"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Data</th><th>Conector</th><th>Evento</th><th>Destino</th><th>Status</th><th>HTTP</th><th>Resposta</th></tr></thead><tbody>
    <?php if ($logs === []): ?><tr><td colspan="7" class="text-center text-muted py-5">Nenhum registro encontrado.</td></tr><?php endif; ?>
    <?php foreach ($logs as $log):
        $ok = ($log['status'] ?? '') === 'enviado';
        $response = mb_substr((string) ($log['resposta'] ?? ''), 0, 350);
    ?>
    <tr><td class="small text-nowrap"><?= !empty($log['created_at']) ? htmlspecialchars(date('d/m/Y H:i:s', strtotime((string) $log['created_at']))) : '—' ?></td><td><span class="badge text-bg-light border"><?= htmlspecialchars(strtoupper((string) $log['conector_tipo'])) ?></span></td><td class="small"><?= htmlspecialchars((string) $log['evento']) ?></td><td class="small"><?= htmlspecialchars((string) ($log['destino'] ?? '—')) ?></td><td><span class="badge <?= $ok ? 'text-bg-success' : 'text-bg-danger' ?>"><?= $ok ? 'ENVIADO' : 'ERRO' ?></span></td><td><?= $log['http_code'] !== null ? (int) $log['http_code'] : '—' ?></td><td class="small text-break" style="max-width:360px;"><?= htmlspecialchars($response !== '' ? $response : '—') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
