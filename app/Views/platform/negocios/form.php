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
        <form action="<?= isset($negocio) ? '/platform/negocios/'.$negocio->id.'/update' : '/platform/negocios' ?>" method="POST" id="formNegocio">
            <input type="hidden" name="_csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            
            <div class="tab-content" id="negocioTabsContent">
                
                <!-- ABA 1: DADOS DA EMPRESA -->
                <div class="tab-pane fade show active" id="empresa" role="tabpanel">
                    <div class="row">
                        <!-- COLUNA ESQUERDA: DADOS CADASTRAIS -->
                        <div class="col-md-8">
                            <div class="row mb-3">
                                <div class="col-md-5">
                                    <label class="form-label fw-semibold">CNPJ</label>
                                    <div class="input-group">
                                        <input type="text" name="cnpj" id="cnpj" class="form-control" value="<?= htmlspecialchars($negocio->cnpj ?? '') ?>" placeholder="00.000.000/0000-00">
                                        <button class="btn btn-outline-primary" type="button" id="btnBuscarCnpj" onclick="buscarCnpj()">
                                            <i class="fa fa-search"></i> Buscar
                                        </button>
                                    </div>
                                    <small class="text-muted" id="cnpjStatus">Busca automática em 3 bases de dados.</small>
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label fw-semibold">Razão Social</label>
                                    <input type="text" name="razao_social" id="razao_social" class="form-control" value="<?= htmlspecialchars($negocio->razao_social ?? '') ?>">
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Nome Fantasia (Nome de Exibição)</label>
                                    <input type="text" name="nome_fantasia" id="nome_fantasia" class="form-control" value="<?= htmlspecialchars($negocio->nome_fantasia ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Nome Interno (Identificação)</label>
                                    <input type="text" name="nome" id="nome" class="form-control" value="<?= htmlspecialchars($negocio->nome ?? '') ?>" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <label class="form-label fw-semibold">Slug (URL / Identificador)</label>
                                    <input type="text" name="slug" id="slug" class="form-control" value="<?= htmlspecialchars($negocio->slug ?? '') ?>" required <?= isset($negocio) ? 'readonly' : '' ?>>
                                </div>
                            </div>
                        </div>
                        
                        <!-- COLUNA DIREITA: LOGO DO CLIENTE -->
                        <div class="col-md-4">
                            <div class="card bg-light border-dashed h-100">
                                <div class="card-body text-center d-flex flex-column justify-content-center align-items-center" id="logo-dropzone" style="cursor: pointer; border: 2px dashed #ccc; border-radius: 8px;">
                                    <h6 class="fw-bold mb-3 w-100 text-start">Logo do Cliente</h6>
                                    
                                    <div id="logo-preview-container" class="<?= empty($negocio->logo_path) ? 'd-none' : '' ?> mb-3 w-100">
                                        <img src="<?= !empty($negocio->logo_path) ? '/storage/tenants/' . $negocio->id . '/logo/' . htmlspecialchars($negocio->logo_path) : '' ?>" 
                                             id="logo-preview" class="img-fluid rounded" style="max-height: 120px; object-fit: contain;">
                                        <div class="mt-3">
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removerLogo(event)"><i class="fa fa-trash"></i> Remover Logo</button>
                                        </div>
                                    </div>
                                    
                                    <div id="logo-upload-ui" class="<?= !empty($negocio->logo_path) ? 'd-none' : '' ?> py-4 w-100">
                                        <i class="fa fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                        <p class="small text-muted mb-1">Arraste e solte ou clique para selecionar</p>
                                        <p class="small text-muted" style="font-size: 0.75rem;">PNG, JPG, WEBP, SVG (Máx: 2MB)</p>
                                    </div>
                                    
                                    <input type="file" name="logo_file" id="logo_file" class="d-none" accept="image/png, image/jpeg, image/webp, image/svg+xml">
                                    <input type="hidden" name="remove_logo" id="remove_logo" value="0">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">CEP</label>
                            <input type="text" name="cep" id="cep" class="form-control" value="<?= htmlspecialchars($negocio->cep ?? '') ?>">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-semibold">Logradouro</label>
                            <input type="text" name="logradouro" id="logradouro" class="form-control" value="<?= htmlspecialchars($negocio->logradouro ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Número</label>
                            <input type="text" name="numero" id="numero" class="form-control" value="<?= htmlspecialchars($negocio->numero ?? '') ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Bairro</label>
                            <input type="text" name="bairro" id="bairro" class="form-control" value="<?= htmlspecialchars($negocio->bairro ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Cidade</label>
                            <input type="text" name="cidade" id="cidade" class="form-control" value="<?= htmlspecialchars($negocio->cidade ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Estado</label>
                            <input type="text" name="estado" id="estado" class="form-control" value="<?= htmlspecialchars($negocio->estado ?? '') ?>">
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
                                        <input type="text" name="contatos[<?= $i ?>][nome]" class="form-control form-control-sm" value="<?= htmlspecialchars($c->nome ?? '') ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small">E-mail</label>
                                        <input type="email" name="contatos[<?= $i ?>][email]" class="form-control form-control-sm" value="<?= htmlspecialchars($c->email ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small">Telefone</label>
                                        <input type="text" name="contatos[<?= $i ?>][telefone]" class="form-control form-control-sm" value="<?= htmlspecialchars($c->telefone ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small">WhatsApp</label>
                                        <input type="text" name="contatos[<?= $i ?>][whatsapp]" class="form-control form-control-sm" value="<?= htmlspecialchars($c->whatsapp ?? '') ?>">
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
                                    <option value="ativo" <?= ($negocio->status ?? '') === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                                    <option value="trial" <?= ($negocio->status ?? 'trial') === 'trial' ? 'selected' : '' ?>>Trial (Teste)</option>
                                    <option value="suspenso" <?= ($negocio->status ?? '') === 'suspenso' ? 'selected' : '' ?>>Suspenso</option>
                                    <option value="cancelado" <?= ($negocio->status ?? '') === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Cor Primária (White Label)</label>
                                <input type="color" name="cor_primaria" class="form-control form-control-color w-100" value="<?= htmlspecialchars($negocio->cor_primaria ?? '#3b82f6') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Cor Secundária (White Label)</label>
                                <input type="color" name="cor_secundaria" class="form-control form-control-color w-100" value="<?= htmlspecialchars($negocio->cor_secundaria ?? '#64748b') ?>">
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
                                    <label class="form-label fw-semibold d-block">Definição de Senha</label>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="tipo_senha" id="senha_manual" value="manual" checked onchange="toggleSenhaUi()">
                                        <label class="form-check-label" for="senha_manual">Digitar manualmente</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="tipo_senha" id="senha_token" value="token" onchange="toggleSenhaUi()">
                                        <label class="form-check-label" for="senha_token">Enviar Token de Acesso por e-mail</label>
                                    </div>
                                </div>
                                
                                <div class="mb-3" id="ui_senha_manual">
                                    <label class="form-label fw-semibold">Senha Inicial</label>
                                    <input type="password" name="admin_senha" id="admin_senha" class="form-control" placeholder="••••••••">
                                </div>
                                
                                <div class="mb-3 d-none" id="ui_senha_token">
                                    <div class="alert alert-info py-2 small">
                                        <i class="fa fa-info-circle me-1"></i> Um link seguro com validade de 24h será enviado para o e-mail informado. A senha nunca trafegará em texto puro.
                                    </div>
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
                                    <option value="<?= $p['id'] ?>" <?= ($negocio->plan_id ?? 1) == $p['id'] ? 'selected' : '' ?>>
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
                    <?php if (empty($negocio)): ?>
                        <div class="alert alert-warning">
                            <i class="fa fa-exclamation-triangle me-2"></i> 
                            Salve o cadastro do negócio primeiro para gerenciar as Unidades DICOM.
                        </div>
                    <?php else: ?>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="h6 fw-bold text-primary mb-0">Unidades e Roteamento DICOM</h5>
                            <button type="button" class="btn btn-sm btn-primary" onclick="abrirModalUnidade()"><i class="fa fa-plus"></i> Nova Unidade</button>
                        </div>
                        
                        <div class="alert alert-info py-2 small mb-3">
                            <i class="fa fa-info-circle me-1"></i> 
                            O <strong>InstitutionName (0008,0080)</strong> NÃO precisa ser único globalmente. Você pode ter "HOSPITAL A" neste tenant e outro "HOSPITAL A" em outro tenant. O vínculo será isolado.
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-sm table-hover table-bordered align-middle" id="table-unidades">
                                <thead class="table-light">
                                    <tr>
                                        <th>Nome da Unidade</th>
                                        <th>CNPJ / Cidade</th>
                                        <th>InstitutionName (0008,0080)</th>
                                        <th>AE Title</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center" width="100">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Preenchido via AJAX -->
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-3">Carregando unidades...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
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

// ============================================================
// DICOM — Grid CRUD de Unidades
// ============================================================
const TENANT_ID = <?= isset($negocio) ? (int)$negocio['id'] : 0 ?>;

function loadUnidades() {
    if (!TENANT_ID) return;
    fetch('/platform/negocios/' + TENANT_ID + '/unidades')
        .then(r => r.json())
        .then(data => {
            const tbody = document.querySelector('#table-unidades tbody');
            if (!tbody) return;
            if (!data.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3"><i class="fa fa-inbox me-2"></i>Nenhuma unidade cadastrada.</td></tr>';
                return;
            }
            tbody.innerHTML = data.map(u => `
                <tr>
                    <td><strong>${escHtml(u.nome)}</strong>${u.codigo_interno ? '<br><small class="text-muted">' + escHtml(u.codigo_interno) + '</small>' : ''}</td>
                    <td>${escHtml(u.cnpj || '')}${u.cidade ? '<br><small class="text-muted">' + escHtml(u.cidade) + (u.uf ? '/' + escHtml(u.uf) : '') + '</small>' : ''}</td>
                    <td><code class="small">${escHtml(u.institution_name)}</code></td>
                    <td>${u.ae_title ? '<code class="small">' + escHtml(u.ae_title) + '</code>' : '<span class="text-muted">-</span>'}</td>
                    <td class="text-center">
                        <span class="badge ${u.status === 'ativo' ? 'bg-success' : 'bg-secondary'}">${u.status}</span>
                    </td>
                    <td class="text-center">
                        <button class="btn btn-xs btn-outline-primary btn-sm py-0 px-1 me-1" onclick="editarUnidade(${u.id})" title="Editar"><i class="fa fa-edit"></i></button>
                        <button class="btn btn-xs btn-outline-danger btn-sm py-0 px-1" onclick="excluirUnidade(${u.id}, '${escHtml(u.nome).replace(/'/g, "\\'")}')"><i class="fa fa-trash"></i></button>
                    </td>
                </tr>
            `).join('');
        })
        .catch(() => {
            const tbody = document.querySelector('#table-unidades tbody');
            if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-3"><i class="fa fa-exclamation-circle me-2"></i>Erro ao carregar unidades.</td></tr>';
        });
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function abrirModalUnidade(id) {
    const modal = document.getElementById('modalUnidade');
    if (!modal) return;
    document.getElementById('unidadeId').value = id || '';
    document.getElementById('modalUnidadeTitulo').textContent = id ? 'Editar Unidade DICOM' : 'Nova Unidade DICOM';
    document.getElementById('formUnidade').reset();
    document.getElementById('unidadeId').value = id || '';
    if (id) {
        fetch('/platform/negocios/' + TENANT_ID + '/unidades/' + id)
            .then(r => r.json())
            .then(u => {
                ['nome','cnpj','logradouro','numero','complemento','bairro','cidade','uf','cep','institution_name','ae_title','codigo_interno','status','observacoes'].forEach(f => {
                    const el = document.getElementById('u_' + f);
                    if (el) el.value = u[f] || '';
                });
            });
    }
    new bootstrap.Modal(modal).show();
}

function editarUnidade(id) { abrirModalUnidade(id); }

function salvarUnidade() {
    const id   = document.getElementById('unidadeId').value;
    const form = document.getElementById('formUnidade');
    const data = new FormData(form);
    const url  = id
        ? '/platform/negocios/' + TENANT_ID + '/unidades/' + id + '/update'
        : '/platform/negocios/' + TENANT_ID + '/unidades';
    const btn = document.getElementById('btnSalvarUnidade');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Salvando...';
    fetch(url, { method: 'POST', body: data })
        .then(r => r.json())
        .then(res => {
            if (res.error) {
                alert('Erro: ' + res.error);
            } else {
                bootstrap.Modal.getInstance(document.getElementById('modalUnidade')).hide();
                loadUnidades();
            }
        })
        .catch(() => alert('Erro de comunicacao.'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-save me-1"></i> Salvar';
        });
}

function excluirUnidade(id, nome) {
    if (!confirm('Excluir a unidade "' + nome + '"? Esta acao nao pode ser desfeita.')) return;
    fetch('/platform/negocios/' + TENANT_ID + '/unidades/' + id + '/delete', { method: 'POST' })
        .then(r => r.json())
        .then(res => {
            if (res.error) alert('Erro: ' + res.error);
            else loadUnidades();
        })
        .catch(() => alert('Erro de comunicacao.'));
}

// Carrega unidades ao abrir a aba DICOM
document.addEventListener('DOMContentLoaded', function() {
    const dicomTab = document.getElementById('dicom-tab');
    if (dicomTab) {
        dicomTab.addEventListener('shown.bs.tab', function() { loadUnidades(); });
        // Se a aba já estiver ativa (URL com #dicom)
        if (window.location.hash === '#dicom') loadUnidades();
    }
});

// ============================================================
// LOGO — Upload isolado por Tenant
// ============================================================
function uploadLogo(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    if (file.size > 2 * 1024 * 1024) {
        alert('Arquivo muito grande. Máximo: 2MB.');
        input.value = '';
        return;
    }
    const preview = document.getElementById('logoPreview');
    const reader  = new FileReader();
    reader.onload  = e => { if (preview) preview.src = e.target.result; };
    reader.readAsDataURL(file);

    const fd = new FormData();
    fd.append('logo_file', file);
    const btn = document.getElementById('btnSalvarLogo');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>'; }
    fetch('/platform/negocios/' + TENANT_ID + '/logo', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.error) alert('Erro: ' + res.error);
            else {
                const msg = document.getElementById('logoMsg');
                if (msg) { msg.textContent = 'Logo salva com sucesso!'; msg.className = 'text-success small'; }
            }
        })
        .catch(() => alert('Erro ao fazer upload.'))
        .finally(() => { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-upload"></i>'; } });
}

function removerLogo() {
    if (!confirm('Remover a logo deste negócio?')) return;
    const fd = new FormData();
    fd.append('remove_logo', '1');
    fetch('/platform/negocios/' + TENANT_ID + '/logo', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.error) alert('Erro: ' + res.error);
            else {
                const preview = document.getElementById('logoPreview');
                if (preview) preview.src = '/public/assets/img/logo-placeholder.png';
                const msg = document.getElementById('logoMsg');
                if (msg) { msg.textContent = 'Logo removida.'; msg.className = 'text-muted small'; }
            }
        })
        .catch(() => alert('Erro ao remover logo.'));
}

// ============================================================
// TOKEN DE ACESSO — Envio por e-mail
// ============================================================
function enviarTokenAcesso() {
    const email = document.getElementById('token_email')?.value?.trim();
    const nome  = document.getElementById('token_nome')?.value?.trim();
    if (!email) { alert('Informe o e-mail do administrador.'); return; }
    const btn = document.getElementById('btnEnviarToken');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Gerando...';
    const fd = new FormData();
    fd.append('admin_email', email);
    fd.append('admin_nome',  nome || 'Administrador');
    fetch('/platform/negocios/' + TENANT_ID + '/enviar-token', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.error) {
                alert('Erro: ' + res.error);
            } else {
                const box = document.getElementById('tokenResultBox');
                if (box) {
                    box.innerHTML = `
                        <div class="alert alert-success py-2 small">
                            <i class="fa fa-check-circle me-1"></i> Token gerado com sucesso!<br>
                            <strong>Link de acesso:</strong><br>
                            <a href="${res.link}" target="_blank" class="text-break">${res.link}</a><br>
                            <small class="text-muted">Expira em: ${res.expires}</small>
                        </div>
                    `;
                    box.style.display = 'block';
                }
            }
        })
        .catch(() => alert('Erro de comunicacao.'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-paper-plane me-1"></i> Gerar Link de Acesso';
        });
}
</script>

<!-- Modal Unidade DICOM -->
<div class="modal fade" id="modalUnidade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h6 class="modal-title fw-bold" id="modalUnidadeTitulo">Nova Unidade DICOM</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formUnidade">
                    <input type="hidden" id="unidadeId">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Nome da Unidade <span class="text-danger">*</span></label>
                            <input type="text" id="u_nome" name="nome" class="form-control form-control-sm" required placeholder="Ex: Clínica Central">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">InstitutionName (0008,0080) <span class="text-danger">*</span></label>
                            <input type="text" id="u_institution_name" name="institution_name" class="form-control form-control-sm" required placeholder="Ex: CLINICA CENTRAL">
                            <small class="text-muted">Deve corresponder exatamente ao valor enviado pelo equipamento DICOM.</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">CNPJ</label>
                            <input type="text" id="u_cnpj" name="cnpj" class="form-control form-control-sm" placeholder="00.000.000/0000-00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">AE Title</label>
                            <input type="text" id="u_ae_title" name="ae_title" class="form-control form-control-sm" placeholder="Ex: VOXEL01">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Código Interno</label>
                            <input type="text" id="u_codigo_interno" name="codigo_interno" class="form-control form-control-sm" placeholder="Código do sistema">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Cidade</label>
                            <input type="text" id="u_cidade" name="cidade" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small">UF</label>
                            <input type="text" id="u_uf" name="uf" class="form-control form-control-sm" maxlength="2" placeholder="MG">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Status</label>
                            <select id="u_status" name="status" class="form-select form-select-sm">
                                <option value="ativo">Ativo</option>
                                <option value="inativo">Inativo</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small">Observações</label>
                            <textarea id="u_observacoes" name="observacoes" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-sm btn-primary" id="btnSalvarUnidade" onclick="salvarUnidade()">
                    <i class="fa fa-save me-1"></i> Salvar
                </button>
            </div>
        </div>
    </div>
</div>
