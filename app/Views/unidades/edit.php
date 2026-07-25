<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="fa fa-edit me-2 text-primary"></i>Editar Unidade</h1>
        <p class="text-muted small mb-0 mt-1">Edite os dados complementares da unidade. O InstitutionName DICOM é somente leitura.</p>
    </div>
    <a href="/unidades" class="btn btn-outline-secondary btn-sm">
        <i class="fa fa-arrow-left me-1"></i>Voltar
    </a>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white py-2">
        <span class="fw-semibold small">
            <i class="fa fa-lock text-muted me-1"></i>
            InstitutionName (DICOM):
            <code class="ms-1"><?= htmlspecialchars($unidade['institution_name'] ?? '') ?></code>
            <span class="badge bg-secondary ms-2">Somente leitura</span>
        </span>
    </div>
    <div class="card-body">
        <form method="POST" action="/unidades/<?= (int)($unidade['id'] ?? 0) ?>/update">

            <div class="row g-3">
                <!-- Descrição amigável -->
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Descrição Amigável</label>
                    <input type="text" name="descricao" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($unidade['descricao'] ?? '') ?>"
                           placeholder="Ex: Unidade Centro - Belo Horizonte">
                </div>

                <!-- Responsável -->
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Responsável</label>
                    <input type="text" name="responsavel" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($unidade['responsavel'] ?? '') ?>"
                           placeholder="Nome do responsável">
                </div>

                <!-- Cidade -->
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">Cidade</label>
                    <input type="text" name="cidade" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($unidade['cidade'] ?? '') ?>">
                </div>

                <!-- Estado -->
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">UF</label>
                    <input type="text" name="estado" class="form-control form-control-sm text-uppercase"
                           maxlength="2" value="<?= htmlspecialchars($unidade['estado'] ?? '') ?>">
                </div>

                <!-- CNPJ -->
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">CNPJ</label>
                    <input type="text" name="cnpj" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($unidade['cnpj'] ?? '') ?>"
                           placeholder="00.000.000/0001-00">
                </div>

                <!-- Telefone -->
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Telefone</label>
                    <input type="text" name="telefone" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($unidade['telefone'] ?? '') ?>"
                           placeholder="(00) 0000-0000">
                </div>

                <!-- E-mail -->
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">E-mail</label>
                    <input type="email" name="email" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($unidade['email'] ?? '') ?>"
                           placeholder="contato@unidade.com.br">
                </div>

                <!-- Horário -->
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Horário de Funcionamento</label>
                    <input type="text" name="horario" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($unidade['horario'] ?? '') ?>"
                           placeholder="Ex: Seg-Sex 07h-19h">
                </div>

                <!-- SLA -->
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">SLA Específico (minutos)</label>
                    <input type="number" name="sla_minutos" class="form-control form-control-sm"
                           value="<?= (int)($unidade['sla_minutos'] ?? 0) ?: '' ?>"
                           placeholder="Ex: 1440 (24h)">
                    <div class="form-text">Deixe vazio para usar o SLA padrão do negócio.</div>
                </div>

                <!-- Modalidades -->
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Modalidades Permitidas</label>
                    <input type="text" name="modalidades_permitidas" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($unidade['modalidades_permitidas'] ?? '') ?>"
                           placeholder="Ex: CT,MR,US,CR">
                    <div class="form-text">Separadas por vírgula.</div>
                </div>

                <!-- Status -->
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Status</label>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="ativo" id="ativo"
                               <?= ($unidade['ativo'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ativo">Unidade Ativa</label>
                    </div>
                </div>

                <!-- Observações -->
                <div class="col-12">
                    <label class="form-label small fw-semibold">Observações</label>
                    <textarea name="observacoes" class="form-control form-control-sm" rows="3"
                              placeholder="Observações internas sobre esta unidade..."><?= htmlspecialchars($unidade['observacoes'] ?? '') ?></textarea>
                </div>
            </div>

            <hr class="my-4">

            <div class="d-flex justify-content-between align-items-center">
                <a href="/unidades" class="btn btn-outline-secondary btn-sm">
                    <i class="fa fa-arrow-left me-1"></i>Cancelar
                </a>
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa fa-save me-1"></i>Salvar Alterações
                </button>
            </div>

        </form>
    </div>
</div>
