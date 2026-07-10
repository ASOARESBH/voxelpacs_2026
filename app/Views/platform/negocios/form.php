<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800"><i class="fa fa-building me-2"></i><?= $title ?? (isset($negocio) ? 'Editar Negócio' : 'Novo Negócio') ?></h1>
    <a href="/platform/negocios" class="btn btn-outline-secondary shadow-sm">
        <i class="fa fa-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="card shadow mb-4">
    <div class="card-header bg-white py-3">
        <ul class="nav nav-tabs card-header-tabs" id="negocioTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-bold" id="empresa-tab" data-bs-toggle="tab" data-bs-target="#empresa" type="button" role="tab"><i class="fa fa-building me-1"></i> Dados da Empresa</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-bold" id="contatos-tab" data-bs-toggle="tab" data-bs-target="#contatos" type="button" role="tab"><i class="fa fa-users me-1"></i> Contatos</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-bold" id="perfil-tab" data-bs-toggle="tab" data-bs-target="#perfil" type="button" role="tab"><i class="fa fa-user-shield me-1"></i> Perfil / Acesso</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-bold" id="plano-tab" data-bs-toggle="tab" data-bs-target="#plano" type="button" role="tab"><i class="fa fa-star me-1"></i> Plano</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-bold" id="dicom-tab" data-bs-toggle="tab" data-bs-target="#dicom" type="button" role="tab"><i class="fa fa-x-ray me-1"></i> DICOM (InstitutionName)</button>
            </li>
        </ul>
    </div>
    
    <div class="card-body">
        <form action="<?= isset($negocio) ? '/platform/negocios/'.$negocio['id'].'/update' : '/platform/negocios' ?>" method="POST" id="formNegocio">
            <input type="hidden" name="_csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            
            <div class="tab-content" id="negocioTabsContent">
                
                <!-- ABA 1: DADOS DA EMPRESA -->
                <div class="tab-pane fade show active" id="empresa" role="tabpanel">
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">CNPJ</label>
                            <div class="input-group">
                                <input type="text" name="cnpj" id="cnpj" class="form-control" value="<?= htmlspecialchars($negocio['cnpj'] ?? '') ?>" placeholder="00.000.000/0000-00">
                                <button class="btn btn-outline-primary" type="button" id="btnBuscarCnpj" onclick="buscarCnpj()">
                                    <i class="fa fa-search"></i> Buscar
                                </button>
                            </div>
                            <small class="text-muted" id="cnpjStatus">Busca automática em 3 bases de dados.</small>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Razão Social</label>
                            <input type="text" name="razao_social" id="razao_social" class="form-control" value="<?= htmlspecialchars($negocio['razao_social'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Nome Fantasia (Nome de Exibição)</label>
                            <input type="text" name="nome" id="nome_fantasia" class="form-control" value="<?= htmlspecialchars($negocio['nome'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Slug (URL / Identificador)</label>
                            <input type="text" name="slug" id="slug" class="form-control" value="<?= htmlspecialchars($negocio['slug'] ?? '') ?>" required <?= isset($negocio) ? 'readonly' : '' ?>>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">CEP</label>
                            <input type="text" name="cep" id="cep" class="form-control" value="<?= htmlspecialchars($negocio['cep'] ?? '') ?>">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-semibold">Logradouro</label>
                            <input type="text" name="logradouro" id="logradouro" class="form-control" value="<?= htmlspecialchars($negocio['logradouro'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Número</label>
                            <input type="text" name="numero" id="numero" class="form-control" value="<?= htmlspecialchars($negocio['numero'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Bairro</label>
                            <input type="text" name="bairro" id="bairro" class="form-control" value="<?= htmlspecialchars($negocio['bairro'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Cidade</label>
                            <input type="text" name="cidade" id="cidade" class="form-control" value="<?= htmlspecialchars($negocio['cidade'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Estado</label>
                            <input type="text" name="estado" id="estado" class="form-control" value="<?= htmlspecialchars($negocio['estado'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- ABA 2: CONTATOS -->
                <div class="tab-pane fade" id="contatos" role="tabpanel">
                    <div class="alert alert-info py-2"><i class="fa fa-info-circle me-2"></i>O primeiro contato será considerado o Contato Principal.</div>
                    
                    <div id="contatosContainer">
                        <?php if (!empty($contatos)): ?>
                            <?php foreach ($contatos as $i => $c): ?>
                                <div class="row mb-3 contato-row border-bottom pb-3">
                                    <div class="col-md-3">
                                        <label class="form-label small">Nome</label>
                                        <input type="text" name="contatos[<?= $i ?>][nome]" class="form-control form-control-sm" value="<?= htmlspecialchars($c['nome'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small">E-mail</label>
                                        <input type="email" name="contatos[<?= $i ?>][email]" class="form-control form-control-sm" value="<?= htmlspecialchars($c['email'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small">Telefone</label>
                                        <input type="text" name="contatos[<?= $i ?>][telefone]" class="form-control form-control-sm" value="<?= htmlspecialchars($c['telefone'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small">WhatsApp</label>
                                        <input type="text" name="contatos[<?= $i ?>][whatsapp]" class="form-control form-control-sm" value="<?= htmlspecialchars($c['whatsapp'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.contato-row').remove()"><i class="fa fa-trash"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="row mb-3 contato-row border-bottom pb-3">
                                <div class="col-md-3">
                                    <label class="form-label small">Nome (Principal)</label>
                                    <input type="text" name="contatos[0][nome]" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">E-mail</label>
                                    <input type="email" name="contatos[0][email]" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Telefone</label>
                                    <input type="text" name="contatos[0][telefone]" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">WhatsApp</label>
                                    <input type="text" name="contatos[0][whatsapp]" class="form-control form-control-sm">
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="addContato()"><i class="fa fa-plus"></i> Adicionar Contato</button>
                </div>

                <!-- ABA 3: PERFIL / ACESSO -->
                <div class="tab-pane fade" id="perfil" role="tabpanel">
                    <div class="row">
                        <div class="col-md-6 border-end">
                            <h5 class="h6 fw-bold mb-3 text-primary">Status de Acesso do Negócio</h5>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Status</label>
                                <select name="status" class="form-select">
                                    <option value="ativo" <?= ($negocio['status'] ?? '') === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                                    <option value="trial" <?= ($negocio['status'] ?? 'trial') === 'trial' ? 'selected' : '' ?>>Trial (Teste)</option>
                                    <option value="suspenso" <?= ($negocio['status'] ?? '') === 'suspenso' ? 'selected' : '' ?>>Suspenso</option>
                                    <option value="cancelado" <?= ($negocio['status'] ?? '') === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Cor Primária (White Label)</label>
                                <input type="color" name="cor_primaria" class="form-control form-control-color w-100" value="<?= htmlspecialchars($negocio['cor_primaria'] ?? '#3b82f6') ?>">
                            </div>
                        </div>
                        
                        <div class="col-md-6 ps-4">
                            <h5 class="h6 fw-bold mb-3 text-primary">Administrador Principal</h5>
                            <?php if (isset($negocio) && !empty($admin)): ?>
                                <div class="alert alert-success">
                                    <strong>Admin atual:</strong> <?= htmlspecialchars($admin['name'] ?? '') ?><br>
                                    <strong>E-mail:</strong> <?= htmlspecialchars($admin['email'] ?? '') ?>
                                </div>
                                <p class="small text-muted">Para alterar a senha ou gerenciar outros usuários, acesse o painel do negócio.</p>
                            <?php else: ?>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Nome do Admin</label>
                                    <input type="text" name="admin_nome" class="form-control" placeholder="Ex: João Silva">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">E-mail de Acesso</label>
                                    <input type="email" name="admin_email" class="form-control" placeholder="admin@empresa.com.br">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Senha Inicial</label>
                                    <input type="password" name="admin_senha" class="form-control" placeholder="••••••••">
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ABA 4: PLANO -->
                <div class="tab-pane fade" id="plano" role="tabpanel">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Plano Contratado</label>
                            <select name="plan_id" class="form-select form-select-lg mb-3">
                                <?php foreach ($planos as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= ($negocio['plan_id'] ?? 1) == $p['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['nome']) ?> — R$ <?= number_format($p['preco_mensal'], 2, ',', '.') ?>/mês
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <div class="alert alert-warning">
                                <i class="fa fa-exclamation-triangle me-2"></i> Alterar o plano afetará imediatamente os limites de usuários, PACS e exames deste negócio.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ABA 5: DICOM (InstitutionName) -->
                <div class="tab-pane fade" id="dicom" role="tabpanel">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="alert alert-info">
                                <i class="fa fa-info-circle me-2"></i> 
                                <strong>InstitutionName (0008,0080)</strong> é o campo do cabeçalho DICOM usado para identificar a origem do exame. 
                                Cadastre aqui todos os nomes que este negócio utiliza em seus equipamentos para que o VOXEL B.I possa rotear os exames corretamente.
                            </div>
                            
                            <label class="form-label fw-semibold">Nomes DICOM (separados por vírgula)</label>
                            <textarea name="institution_names" class="form-control" rows="4" placeholder="Ex: CLINICA_CENTRO, HOSPITAL_SAO_JOAO, MATRIZ_RM"><?= htmlspecialchars($institution_names_str ?? '') ?></textarea>
                            <small class="text-muted mt-1 d-block">Estes nomes serão usados na importação automática via PACS ou HL7.</small>
                        </div>
                    </div>
                </div>

            </div>

            <hr class="my-4">
            <div class="d-flex justify-content-end">
                <a href="/platform/negocios" class="btn btn-light me-2">Cancelar</a>
                <button type="submit" class="btn btn-primary px-4 fw-bold"><i class="fa fa-save me-2"></i> Salvar Negócio</button>
            </div>
        </form>
    </div>
</div>

<script>
let contatoIndex = <?= !empty($contatos) ? count($contatos) : 1 ?>;

function addContato() {
    const html = `
        <div class="row mb-3 contato-row border-bottom pb-3">
            <div class="col-md-3">
                <label class="form-label small">Nome</label>
                <input type="text" name="contatos[${contatoIndex}][nome]" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
                <label class="form-label small">E-mail</label>
                <input type="email" name="contatos[${contatoIndex}][email]" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Telefone</label>
                <input type="text" name="contatos[${contatoIndex}][telefone]" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label small">WhatsApp</label>
                <input type="text" name="contatos[${contatoIndex}][whatsapp]" class="form-control form-control-sm">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.contato-row').remove()"><i class="fa fa-trash"></i></button>
            </div>
        </div>
    `;
    document.getElementById('contatosContainer').insertAdjacentHTML('beforeend', html);
    contatoIndex++;
}

function buscarCnpj() {
    const cnpj = document.getElementById('cnpj').value.replace(/\D/g, '');
    const btn = document.getElementById('btnBuscarCnpj');
    const status = document.getElementById('cnpjStatus');
    
    if (cnpj.length !== 14) {
        alert('Digite um CNPJ válido com 14 números.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Buscando...';
    status.innerHTML = '<span class="text-primary">Consultando bases de dados...</span>';

    fetch('/platform/api/cnpj/' + cnpj)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                status.innerHTML = '<span class="text-danger"><i class="fa fa-times-circle"></i> ' + data.error + '</span>';
            } else {
                status.innerHTML = '<span class="text-success"><i class="fa fa-check-circle"></i> Encontrado via ' + data.source + '</span>';
                
                // Preenche os campos
                if(data.razao_social) document.getElementById('razao_social').value = data.razao_social;
                if(data.nome_fantasia) {
                    document.getElementById('nome_fantasia').value = data.nome_fantasia;
                    // Gera slug automático
                    if(!document.getElementById('slug').value) {
                        document.getElementById('slug').value = data.nome_fantasia.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)+/g, '');
                    }
                }
                if(data.cep) document.getElementById('cep').value = data.cep;
                if(data.logradouro) document.getElementById('logradouro').value = data.logradouro;
                if(data.numero) document.getElementById('numero').value = data.numero;
                if(data.bairro) document.getElementById('bairro').value = data.bairro;
                if(data.cidade) document.getElementById('cidade').value = data.cidade;
                if(data.estado) document.getElementById('estado').value = data.estado;
                
                // Preenche o primeiro contato se vazio
                const telInput = document.querySelector('input[name="contatos[0][telefone]"]');
                if(telInput && !telInput.value && data.telefone) telInput.value = data.telefone;
                
                const emailInput = document.querySelector('input[name="contatos[0][email]"]');
                if(emailInput && !emailInput.value && data.email) emailInput.value = data.email;
            }
        })
        .catch(err => {
            status.innerHTML = '<span class="text-danger"><i class="fa fa-times-circle"></i> Erro na comunicação com a API.</span>';
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-search"></i> Buscar';
        });
}
</script>
