<?php
/**
 * View: platform/negocios/copilot.php
 * Aba de integração VOXEL Copilot no painel de Negócios.
 */
$negocio    = $negocio    ?? null;
$unidade    = $unidade    ?? null;
$medicos    = $medicos    ?? [];
$logEventos = $logEventos ?? [];
$tenantId   = $negocio->id ?? 0;
$integrado  = !empty($unidade) && $unidade->status === 'ativo';
?>
<!-- ══════════════════════════════════════════════════════════════════════
     Cabeçalho da página
══════════════════════════════════════════════════════════════════════ -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">
            <i class="fa fa-plug me-2 text-primary"></i>
            VOXEL Copilot — <?= htmlspecialchars($negocio->nome ?? '') ?>
        </h1>
        <p class="text-muted small mb-0 mt-1">
            Integração sistêmica entre o VOXEL PACS e o VOXEL Copilot
        </p>
    </div>
    <a href="/platform/negocios/<?= $tenantId ?>/edit" class="btn btn-outline-secondary btn-sm">
        <i class="fa fa-arrow-left me-1"></i> Voltar ao Negócio
    </a>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     Alerta de feedback
══════════════════════════════════════════════════════════════════════ -->
<div id="alertCopilot" class="alert d-none mb-3" role="alert"></div>

<!-- ══════════════════════════════════════════════════════════════════════
     SEÇÃO 1 — Código de Unidade
══════════════════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-bold">
            <i class="fa fa-qrcode me-2 text-primary"></i>
            Código de Unidade
        </span>
        <?php if ($integrado): ?>
            <span class="badge bg-success"><i class="fa fa-circle-check me-1"></i>Integração ativa</span>
        <?php else: ?>
            <span class="badge bg-secondary"><i class="fa fa-circle-xmark me-1"></i>Não configurada</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            O <strong>Código de Unidade</strong> e a <strong>Chave Secreta</strong> são gerados aqui e fornecidos
            ao médico para que ele vincule esta unidade no <strong>VOXEL Copilot → Configurações → Autorização</strong>.
        </p>

        <?php if ($integrado): ?>
        <!-- Exibe código atual -->
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Código de Unidade</label>
                <div class="input-group">
                    <input type="text" class="form-control font-monospace fw-bold"
                           value="<?= htmlspecialchars($unidade->codigo_unidade) ?>" readonly id="codigoUnidadeDisplay">
                    <button class="btn btn-outline-secondary" type="button"
                            onclick="copiarTexto('codigoUnidadeDisplay')" title="Copiar">
                        <i class="fa fa-copy"></i>
                    </button>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Status</label>
                <div>
                    <span class="badge bg-<?= $unidade->status === 'ativo' ? 'success' : 'danger' ?> fs-6">
                        <?= ucfirst($unidade->status) ?>
                    </span>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Estatísticas</label>
                <div class="small text-muted">
                    <i class="fa fa-arrow-up text-primary me-1"></i>
                    <?= number_format($unidade->total_exames_sync) ?> exames enviados
                    &nbsp;|&nbsp;
                    <i class="fa fa-arrow-down text-success me-1"></i>
                    <?= number_format($unidade->total_laudos_recv) ?> laudos recebidos
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Formulário de configuração -->
        <div class="border rounded p-3 bg-light">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">URL do VOXEL Copilot</label>
                    <input type="url" class="form-control" id="copilotUrl"
                           placeholder="https://demo.voxelpacs.com.br"
                           value="<?= htmlspecialchars($unidade->copilot_url ?? '') ?>">
                    <div class="form-text">URL base do Copilot desta unidade</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Token API do Copilot</label>
                    <div class="input-group">
                        <input type="password" class="form-control font-monospace" id="copilotApiToken"
                               placeholder="Token Bearer para autenticar chamadas"
                               value="<?= htmlspecialchars($unidade->copilot_api_token ?? '') ?>">
                        <button class="btn btn-outline-secondary" type="button"
                                onclick="toggleSenha('copilotApiToken')">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Modalidades autorizadas</label>
                    <input type="text" class="form-control" id="modalidades"
                           placeholder="CT,MR,CR — vazio = todas"
                           value="<?= htmlspecialchars($unidade->modalidades ?? '') ?>">
                </div>
            </div>
            <div class="mt-3">
                <button class="btn btn-primary" onclick="gerarCodigo(<?= $tenantId ?>)">
                    <i class="fa fa-rotate me-1"></i>
                    <?= $integrado ? 'Regenerar Código e Chave' : 'Gerar Código de Unidade' ?>
                </button>
                <?php if ($integrado): ?>
                <span class="text-muted small ms-2">
                    <i class="fa fa-triangle-exclamation text-warning"></i>
                    Regenerar invalida o código anterior — todos os médicos precisarão re-vincular.
                </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Resultado da geração -->
        <div id="resultadoGeracao" class="d-none mt-3 p-3 border border-success rounded bg-success bg-opacity-10">
            <h6 class="text-success mb-2"><i class="fa fa-circle-check me-1"></i>Código gerado com sucesso!</h6>
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Código de Unidade (forneça ao médico)</label>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace fw-bold text-primary"
                               id="novoCodigoUnidade" readonly>
                        <button class="btn btn-outline-primary btn-sm" onclick="copiarTexto('novoCodigoUnidade')">
                            <i class="fa fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Chave Secreta (guarde em local seguro)</label>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace text-danger"
                               id="novaChaveSecreta" readonly>
                        <button class="btn btn-outline-danger btn-sm" onclick="copiarTexto('novaChaveSecreta')">
                            <i class="fa fa-copy"></i>
                        </button>
                    </div>
                    <div class="form-text text-danger">
                        <i class="fa fa-lock me-1"></i>Esta chave não será exibida novamente.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     SEÇÃO 2 — Tokens por Médico
══════════════════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header">
        <span class="fw-bold">
            <i class="fa fa-user-doctor me-2 text-primary"></i>
            Tokens por Médico
        </span>
        <span class="badge bg-secondary ms-2"><?= count($medicos) ?> médico(s)</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($medicos)): ?>
            <div class="text-center text-muted py-4">
                <i class="fa fa-user-doctor fa-2x mb-2 d-block"></i>
                Nenhum médico ativo cadastrado neste negócio.
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Médico</th>
                        <th>CRM</th>
                        <th>Especialidade</th>
                        <th>Token</th>
                        <th>Status</th>
                        <th>Exames / Laudos</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($medicos as $m): ?>
                <tr id="row-medico-<?= $m->id ?>">
                    <td>
                        <strong><?= htmlspecialchars($m->nome) ?></strong>
                        <?php if ($m->medico_email ?? null): ?>
                            <div class="small text-muted"><?= htmlspecialchars($m->medico_email) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="font-monospace small">
                        <?= htmlspecialchars(($m->crm ?? '') . ($m->crm_uf ? '/' . $m->crm_uf : '')) ?>
                    </td>
                    <td class="small"><?= htmlspecialchars($m->especialidade ?? '—') ?></td>
                    <td>
                        <?php if ($m->token_id ?? null): ?>
                            <div class="input-group input-group-sm" style="max-width:280px">
                                <input type="password" class="form-control font-monospace"
                                       id="token-<?= $m->id ?>"
                                       value="<?= htmlspecialchars($m->token_integracao ?? '') ?>"
                                       readonly>
                                <button class="btn btn-outline-secondary btn-sm"
                                        onclick="toggleSenha('token-<?= $m->id ?>')">
                                    <i class="fa fa-eye"></i>
                                </button>
                                <button class="btn btn-outline-secondary btn-sm"
                                        onclick="copiarTexto('token-<?= $m->id ?>')">
                                    <i class="fa fa-copy"></i>
                                </button>
                            </div>
                        <?php else: ?>
                            <span class="text-muted small">Não gerado</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($m->token_id ?? null): ?>
                            <?php $cor = $m->token_status === 'ativo' ? 'success' : ($m->token_status === 'revogado' ? 'danger' : 'secondary'); ?>
                            <span class="badge bg-<?= $cor ?>"><?= ucfirst($m->token_status ?? '—') ?></span>
                        <?php else: ?>
                            <span class="badge bg-light text-dark">Sem token</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted">
                        <?= number_format($m->total_exames ?? 0) ?> /
                        <?= number_format($m->total_laudos ?? 0) ?>
                    </td>
                    <td>
                        <?php if (!$integrado): ?>
                            <span class="text-muted small">Gere o código primeiro</span>
                        <?php else: ?>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary"
                                    onclick="gerarTokenMedico(<?= $tenantId ?>, <?= $m->id ?>, '<?= htmlspecialchars(addslashes($m->nome)) ?>')"
                                    title="<?= ($m->token_id ?? null) ? 'Regenerar token' : 'Gerar token' ?>">
                                <i class="fa fa-rotate"></i>
                                <?= ($m->token_id ?? null) ? 'Regenerar' : 'Gerar Token' ?>
                            </button>
                            <?php if ($m->token_id ?? null): ?>
                            <button class="btn btn-outline-danger"
                                    onclick="revogarToken(<?= $tenantId ?>, <?= $m->id ?>, '<?= htmlspecialchars(addslashes($m->nome)) ?>')"
                                    title="Revogar token">
                                <i class="fa fa-ban"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     SEÇÃO 3 — Log de Sincronização
══════════════════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header">
        <span class="fw-bold">
            <i class="fa fa-list-check me-2 text-primary"></i>
            Log de Sincronização (últimos 20 eventos)
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($logEventos)): ?>
            <div class="text-center text-muted py-4">
                <i class="fa fa-inbox fa-2x mb-2 d-block"></i>
                Nenhum evento registrado ainda.
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Data/Hora</th>
                        <th>Evento</th>
                        <th>Direção</th>
                        <th>Médico</th>
                        <th>HTTP</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($logEventos as $ev): ?>
                <tr>
                    <td class="small font-monospace">
                        <?= date('d/m/Y H:i:s', strtotime($ev->created_at)) ?>
                    </td>
                    <td><code class="small"><?= htmlspecialchars($ev->evento) ?></code></td>
                    <td class="small">
                        <?php if ($ev->direcao === 'pacs_para_copilot'): ?>
                            <span class="text-primary"><i class="fa fa-arrow-right me-1"></i>PACS → Copilot</span>
                        <?php else: ?>
                            <span class="text-success"><i class="fa fa-arrow-left me-1"></i>Copilot → PACS</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= htmlspecialchars($ev->medico_nome ?? '—') ?></td>
                    <td class="small font-monospace"><?= $ev->http_status ?: '—' ?></td>
                    <td>
                        <?php $cor = $ev->status === 'sucesso' ? 'success' : ($ev->status === 'erro' ? 'danger' : 'warning'); ?>
                        <span class="badge bg-<?= $cor ?>"><?= ucfirst($ev->status) ?></span>
                        <?php if ($ev->erro_msg): ?>
                            <small class="text-danger d-block"><?= htmlspecialchars(substr($ev->erro_msg, 0, 80)) ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     SEÇÃO 4 — Instruções de uso
══════════════════════════════════════════════════════════════════════ -->
<div class="card border-info mb-4">
    <div class="card-header bg-info bg-opacity-10">
        <span class="fw-bold text-info">
            <i class="fa fa-circle-info me-2"></i>Como funciona a integração
        </span>
    </div>
    <div class="card-body">
        <ol class="mb-0 small">
            <li class="mb-2">
                <strong>Gere o Código de Unidade</strong> acima e informe ao médico.
            </li>
            <li class="mb-2">
                <strong>Gere o Token individual</strong> de cada médico na tabela acima.
            </li>
            <li class="mb-2">
                O médico acessa o <strong>VOXEL Copilot → Configurações → Autorização</strong>
                e informa o Código de Unidade + seu Token.
            </li>
            <li class="mb-2">
                Quando o médico <strong>assumir um exame</strong> na worklist do PACS,
                o sistema notifica automaticamente o Copilot, que cria o workspace de laudo.
            </li>
            <li class="mb-2">
                Quando o médico <strong>finalizar e assinar o laudo</strong> no Copilot,
                o PACS é notificado e o estudo muda para <code>assinado</code>.
            </li>
        </ol>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     JavaScript
══════════════════════════════════════════════════════════════════════ -->
<script>
function mostrarAlerta(msg, tipo = 'success') {
    const el = document.getElementById('alertCopilot');
    el.className = `alert alert-${tipo}`;
    el.innerHTML = `<i class="fa fa-${tipo === 'success' ? 'circle-check' : 'triangle-exclamation'} me-2"></i>${msg}`;
    el.classList.remove('d-none');
    setTimeout(() => el.classList.add('d-none'), 6000);
}

function copiarTexto(inputId) {
    const el = document.getElementById(inputId);
    if (!el) return;
    el.type = 'text';
    el.select();
    document.execCommand('copy');
    mostrarAlerta('Copiado para a área de transferência!', 'success');
}

function toggleSenha(inputId) {
    const el = document.getElementById(inputId);
    if (!el) return;
    el.type = el.type === 'password' ? 'text' : 'password';
}

function gerarCodigo(tenantId) {
    const url       = document.getElementById('copilotUrl')?.value       || '';
    const apiToken  = document.getElementById('copilotApiToken')?.value  || '';
    const modalidades = document.getElementById('modalidades')?.value    || '';

    if (!confirm('Gerar (ou regenerar) o código de unidade? Médicos com código anterior precisarão re-vincular.')) return;

    fetch(`/platform/negocios/${tenantId}/copilot/gerar-codigo`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ copilot_url: url, copilot_api_token: apiToken, modalidades: modalidades }),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) { mostrarAlerta(data.msg || 'Erro ao gerar código.', 'danger'); return; }
        document.getElementById('novoCodigoUnidade').value = data.codigo_unidade;
        document.getElementById('novaChaveSecreta').value  = data.chave_secreta;
        document.getElementById('resultadoGeracao').classList.remove('d-none');
        mostrarAlerta('Código de unidade gerado com sucesso!', 'success');
        setTimeout(() => location.reload(), 4000);
    })
    .catch(() => mostrarAlerta('Erro de comunicação.', 'danger'));
}

function gerarTokenMedico(tenantId, medicoId, medicoNome) {
    if (!confirm(`Gerar token para ${medicoNome}? Token anterior será invalidado.`)) return;

    fetch(`/platform/negocios/${tenantId}/copilot/medico/${medicoId}/gerar-token`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({}),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) { mostrarAlerta(data.msg || 'Erro ao gerar token.', 'danger'); return; }
        mostrarAlerta(`Token gerado para ${data.medico_nome}. Recarregando...`, 'success');
        setTimeout(() => location.reload(), 2000);
    })
    .catch(() => mostrarAlerta('Erro de comunicação.', 'danger'));
}

function revogarToken(tenantId, medicoId, medicoNome) {
    if (!confirm(`Revogar token de ${medicoNome}? O médico perderá acesso ao Copilot por esta unidade.`)) return;

    fetch(`/platform/negocios/${tenantId}/copilot/medico/${medicoId}/revogar`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({}),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) { mostrarAlerta(data.msg || 'Erro ao revogar.', 'danger'); return; }
        mostrarAlerta(`Token de ${medicoNome} revogado.`, 'warning');
        setTimeout(() => location.reload(), 2000);
    })
    .catch(() => mostrarAlerta('Erro de comunicação.', 'danger'));
}
</script>
