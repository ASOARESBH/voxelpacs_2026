<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Servidores Orthanc</h1>
    <a href="/servidor/create" class="btn btn-primary">
        <i class="fa fa-plus me-1"></i> Novo Servidor
    </a>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h6 class="m-0 font-weight-bold text-primary">Servidores PACS Conectados</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Nome</th>
                        <th>URL</th>
                        <th>Status</th>
                        <th>Versão</th>
                        <th>Último Ping</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($servidores)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">Nenhum servidor Orthanc configurado.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach($servidores as $s): ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($s['nome']) ?></td>
                            <td><code><?= htmlspecialchars($s['url']) ?></code></td>
                            <td>
                                <?php if($s['status_ping'] === 'online'): ?>
                                    <span class="badge bg-success"><i class="fa fa-check-circle me-1"></i>Online</span>
                                <?php elseif($s['status_ping'] === 'erro'): ?>
                                    <span class="badge bg-danger" title="<?= htmlspecialchars($s['observacoes'] ?? '') ?>"><i class="fa fa-times-circle me-1"></i>Erro</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><i class="fa fa-question-circle me-1"></i>Desconhecido</span>
                                <?php endif; ?>
                                
                                <?php if(!$s['ativo']): ?>
                                    <span class="badge bg-warning text-dark ms-1">Inativo</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($s['versao'] ?? '-') ?></td>
                            <td class="small text-muted">
                                <?= $s['ultimo_ping'] ? date('d/m/Y H:i', strtotime($s['ultimo_ping'])) : 'Nunca testado' ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-info text-white btn-testar" data-id="<?= $s['id'] ?>">
                                    <i class="fa fa-plug"></i> Testar
                                </button>
                                <a href="/servidor/<?= $s['id'] ?>/edit" class="btn btn-sm btn-outline-primary ms-1">
                                    <i class="fa fa-edit"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal de Teste -->
<div class="modal fade" id="testModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Testando Conexão...</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div id="testLoading">
                    <div class="spinner-border text-primary mb-3" role="status"></div>
                    <p class="mb-0">Conectando ao servidor Orthanc via API REST...</p>
                </div>
                <div id="testResult" class="d-none">
                    <i id="testIcon" class="fa fa-check-circle text-success fs-1 mb-3"></i>
                    <p id="testMessage" class="mb-0 fw-bold"></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const testModal = new bootstrap.Modal(document.getElementById('testModal'));
    
    document.querySelectorAll('.btn-testar').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            
            document.getElementById('testLoading').classList.remove('d-none');
            document.getElementById('testResult').classList.add('d-none');
            testModal.show();
            
            fetch(`/servidor/${id}/testar`)
                .then(res => res.json())
                .then(data => {
                    document.getElementById('testLoading').classList.add('d-none');
                    document.getElementById('testResult').classList.remove('d-none');
                    
                    const icon = document.getElementById('testIcon');
                    if(data.success) {
                        icon.className = 'fa fa-check-circle text-success fs-1 mb-3';
                    } else {
                        icon.className = 'fa fa-times-circle text-danger fs-1 mb-3';
                    }
                    
                    document.getElementById('testMessage').textContent = data.message;
                    
                    // Recarrega a página após fechar o modal para atualizar o status na tabela
                    document.getElementById('testModal').addEventListener('hidden.bs.modal', function () {
                        window.location.reload();
                    }, {once: true});
                })
                .catch(err => {
                    document.getElementById('testLoading').classList.add('d-none');
                    document.getElementById('testResult').classList.remove('d-none');
                    document.getElementById('testIcon').className = 'fa fa-times-circle text-danger fs-1 mb-3';
                    document.getElementById('testMessage').textContent = 'Erro de rede ao tentar conectar.';
                });
        });
    });
});
</script>
