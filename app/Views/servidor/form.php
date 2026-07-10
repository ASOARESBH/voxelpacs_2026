<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800"><?= htmlspecialchars($title) ?></h1>
    <a href="/servidor" class="btn btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form action="<?= $servidor ? '/servidor/'.$servidor['id'].'/update' : '/servidor' ?>" method="POST">
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Nome do Servidor *</label>
                    <input type="text" name="nome" class="form-control" required 
                           value="<?= htmlspecialchars($servidor['nome'] ?? 'Orthanc Principal') ?>"
                           placeholder="Ex: Orthanc Matriz">
                </div>
                <div class="col-md-6">
                    <label class="form-label">URL da API REST *</label>
                    <input type="url" name="url" class="form-control" required 
                           value="<?= htmlspecialchars($servidor['url'] ?? 'http://46.225.51.122:8042') ?>"
                           placeholder="Ex: http://46.225.51.122:8042">
                    <div class="form-text">Inclua o protocolo (http/https) e a porta.</div>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Usuário (HTTP Basic Auth)</label>
                    <input type="text" name="usuario" class="form-control" 
                           value="<?= htmlspecialchars($servidor['usuario'] ?? '') ?>"
                           placeholder="Opcional">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Senha</label>
                    <input type="password" name="senha" class="form-control" 
                           placeholder="<?= $servidor ? 'Deixe em branco para manter a atual' : 'Opcional' ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Timeout (segundos)</label>
                    <input type="number" name="timeout" class="form-control" min="5" max="120" required
                           value="<?= htmlspecialchars($servidor['timeout'] ?? 30) ?>">
                </div>
            </div>

            <div class="mb-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="ativo" id="ativo" value="1" 
                           <?= (!isset($servidor) || $servidor['ativo']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="ativo">Servidor Ativo</label>
                </div>
            </div>

            <hr>
            
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save me-1"></i> Salvar Servidor
                </button>
            </div>
        </form>
    </div>
</div>
