<?php
// View: Servidor PACS — Roteamento InstitutionName → Negócio
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="fa fa-route me-2 text-primary"></i>Roteamento PACS</h1>
        <small class="text-muted">Mapeia InstitutionName (DICOM 0008,0080) para cada Negócio</small>
    </div>
    <a href="/platform/servidor-pacs" class="btn btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="alert alert-info">
    <i class="fa fa-info-circle me-2"></i>
    <strong>Como funciona o roteamento?</strong><br>
    Cada equipamento de imagem (TC, RM, RX) envia estudos DICOM com um campo <strong>InstitutionName (0008,0080)</strong>.
    O VOXEL B.I usa esse campo para identificar a qual <strong>Negócio</strong> o estudo pertence.
    Configure aqui o mapeamento: <code>InstitutionName</code> → <strong>Negócio</strong>.
</div>

<!-- FORMULÁRIO DE NOVO ROTEAMENTO -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white">
        <h6 class="mb-0 fw-bold"><i class="fa fa-plus me-2 text-success"></i>Adicionar Roteamento</h6>
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-semibold">InstitutionName (DICOM)</label>
                <input type="text" id="novoInstitution" class="form-control"
                       placeholder="Ex: CEAE PATOS DE MINAS"
                       list="institutionSuggestions">
                <datalist id="institutionSuggestions">
                    <?php foreach ($institutionsNaoRoteadas as $inst): ?>
                        <option value="<?= htmlspecialchars($inst['institution_name']) ?>">
                            <?= htmlspecialchars($inst['institution_name']) ?> (<?= $inst['total'] ?> estudos)
                        </option>
                    <?php endforeach; ?>
                </datalist>
                <small class="text-muted">Valor exato do campo DICOM</small>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Negócio <span class="text-danger">*</span></label>
                <select id="novoTenant" class="form-select">
                    <option value="">Selecione o Negócio...</option>
                    <?php foreach ($negocios as $n): ?>
                        <option value="<?= $n['id'] ?>"><?= htmlspecialchars($n['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold">AETitle (opcional)</label>
                <input type="text" id="novoAetitle" class="form-control" placeholder="Ex: MODALIDADE01" maxlength="64">
                <small class="text-muted">AETitle da modalidade</small>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Descrição (opcional)</label>
                <input type="text" id="novaDescricao" class="form-control" placeholder="Ex: TC do Centro">
            </div>
            <div class="col-md-1">
                <button class="btn btn-success w-100" onclick="salvarRoteamento()">
                    <i class="fa fa-save"></i>
                </button>
            </div>
        </div>
        <div id="roteamentoResult" class="mt-3 d-none"></div>
    </div>
</div>

<!-- INSTITUTIONS NÃO ROTEADAS -->
<?php if (!empty($institutionsNaoRoteadas)): ?>
<div class="card border-0 shadow-sm mb-4 border-warning">
    <div class="card-header bg-warning bg-opacity-10">
        <h6 class="mb-0 fw-bold text-warning"><i class="fa fa-exclamation-triangle me-2"></i>InstitutionNames sem Roteamento (<?= count($institutionsNaoRoteadas) ?>)</h6>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>InstitutionName</th>
                    <th class="text-center">Estudos no PACS</th>
                    <th>Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($institutionsNaoRoteadas as $inst): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($inst['institution_name']) ?></code></td>
                        <td class="text-center"><span class="badge bg-warning text-dark"><?= $inst['total'] ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-outline-warning"
                                    onclick="preencherForm('<?= htmlspecialchars(addslashes($inst['institution_name'])) ?>')">
                                <i class="fa fa-route me-1"></i> Rotear
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ROTEAMENTOS CONFIGURADOS -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <h6 class="mb-0 fw-bold"><i class="fa fa-check-circle me-2 text-success"></i>Roteamentos Configurados (<?= count($roteamentos) ?>)</h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($roteamentos)): ?>
            <div class="p-4 text-center text-muted">
                <i class="fa fa-route fa-2x mb-2"></i>
                <p class="mb-0">Nenhum roteamento configurado ainda.</p>
            </div>
        <?php else: ?>
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>InstitutionName (DICOM)</th>
                        <th>AETitle</th>
                        <th>Negócio</th>
                        <th class="text-center">Estudos Roteados</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roteamentos as $r): ?>
                        <tr id="rot-<?= $r['id'] ?>">
                            <td>
                                <code class="fs-6"><?= htmlspecialchars($r['institution_name']) ?></code>
                                <?php if ($r['descricao']): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($r['descricao']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><code><?= htmlspecialchars($r['aetitle'] ?? '—') ?></code></td>
                            <td>
                                <i class="fa fa-building me-1 text-primary"></i>
                                <strong><?= htmlspecialchars($r['negocio_nome']) ?></strong>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary"><?= number_format($r['total_estudos']) ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= $r['ativo'] ? 'success' : 'secondary' ?>">
                                    <?= $r['ativo'] ? 'Ativo' : 'Inativo' ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-danger"
                                        onclick="removerRoteamento(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['institution_name'])) ?>')"
                                        title="Remover roteamento">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
function preencherForm(institution) {
    document.getElementById('novoInstitution').value = institution;
    document.getElementById('novoInstitution').focus();
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function salvarRoteamento() {
    const institution = document.getElementById('novoInstitution').value.trim();
    const tenantId    = document.getElementById('novoTenant').value;
    const aetitle     = document.getElementById('novoAetitle').value.trim();
    const descricao   = document.getElementById('novaDescricao').value.trim();
    const result      = document.getElementById('roteamentoResult');

    if (!institution || !tenantId) {
        result.className = 'mt-3 alert alert-warning';
        result.innerHTML = '<i class="fa fa-exclamation-triangle me-2"></i>InstitutionName e Negócio são obrigatórios.';
        result.classList.remove('d-none');
        return;
    }

    result.className = 'mt-3 alert alert-info';
    result.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>Salvando roteamento...';
    result.classList.remove('d-none');

    const formData = new FormData();
    formData.append('institution_name', institution);
    formData.append('tenant_id', tenantId);
    formData.append('aetitle', aetitle);
    formData.append('descricao', descricao);

    fetch('/platform/servidor-pacs/roteamento/salvar', {method: 'POST', body: formData})
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                result.className = 'mt-3 alert alert-success';
                result.innerHTML = `<i class="fa fa-check-circle me-2"></i>${data.message}`;
                setTimeout(() => location.reload(), 1500);
            } else {
                result.className = 'mt-3 alert alert-danger';
                result.innerHTML = `<i class="fa fa-times-circle me-2"></i>${data.message}`;
            }
        })
        .catch(() => {
            result.className = 'mt-3 alert alert-danger';
            result.innerHTML = '<i class="fa fa-times-circle me-2"></i>Erro de comunicação.';
        });
}

function removerRoteamento(id, institution) {
    if (!confirm(`Remover roteamento de "${institution}"?\nOs estudos associados ficarão sem negócio vinculado.`)) return;

    fetch(`/platform/servidor-pacs/roteamento/${id}/remover`, {method: 'POST'})
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById(`rot-${id}`)?.remove();
                alert(data.message);
            } else {
                alert('Erro: ' + data.message);
            }
        });
}
</script>
