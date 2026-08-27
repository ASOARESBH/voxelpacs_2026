<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800"><i class="fa fa-building me-2"></i><?= $title ?? (isset($negocio) ? 'Editar Negócio' : 'Novo Negócio') ?></h1>
    <a href="/platform/negocios" class="btn btn-outline-secondary shadow-sm">
        <i class="fa fa-arrow-left me-1"></i> Voltar
    </a>
</div>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="alert alert-success"><i class="fa fa-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success']) ?></div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
    <div class="alert alert-danger"><i class="fa fa-triangle-exclamation me-2"></i><?= htmlspecialchars($_SESSION['error']) ?></div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

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
                <button class="nav-link fw-bold" id="dicom-tab" data-bs-toggle="tab" data-bs-target="#dicom" type="button" role="tab"><i class="fa fa-x-ray me-1"></i><?= htmlspecialchars(t('negocios.dicom.aba_titulo')) ?></button>
            </li>
            <?php if (isset($negocio) && !empty($negocio['id'])): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-bold" id="servidores-tab" data-bs-toggle="tab" data-bs-target="#servidores" type="button" role="tab"><i class="fa fa-server me-1"></i> Servidores Vinculados</button>
            </li>
            <?php endif; ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-bold" id="webhook-tab" data-bs-toggle="tab" data-bs-target="#webhook" type="button" role="tab"><i class="fa fa-plug me-1"></i> Webhooks HUB</button>
            </li>
            <?php if (isset($negocio) && !empty($negocio['id'])): ?>
                <li class="nav-item" role="presentation">
                    <a class="nav-link fw-bold" href="/platform/negocios/<?= (int) $negocio['id'] ?>/report-delivery"><i class="fa fa-paper-plane me-1"></i> Devolutiva de Laudos</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link fw-bold" href="/platform/negocios/<?= (int) $negocio['id'] ?>/imagiflow"><i class="fa fa-link me-1"></i> Conector Imagiflow</a>
                </li>
            <?php endif; ?>
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

                    <?php $ufsRegistro = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO']; ?>
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold" for="registro_crm_uf">Registro no CRM da Empresa</label>
                            <select name="registro_crm_uf" id="registro_crm_uf" class="form-select">
                                <option value="">UF (opcional)</option>
                                <?php foreach ($ufsRegistro as $ufRegistro): ?>
                                    <option value="<?= $ufRegistro ?>" <?= ($negocio['registro_crm_uf'] ?? '') === $ufRegistro ? 'selected' : '' ?>><?= $ufRegistro ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold" for="registro_crm_numero">Número do Registro CRM</label>
                            <input type="text" name="registro_crm_numero" id="registro_crm_numero" class="form-control" maxlength="30" value="<?= htmlspecialchars($negocio['registro_crm_numero'] ?? '') ?>" placeholder="Ex.: 1.045.798">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-text mb-1">Opcional. Se preenchido, será exibido na assinatura institucional do laudo.</div>
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
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?= htmlspecialchars(t('negocios.form.campo_idioma')) ?></label>
                            <?php $idiomaAtual = $negocio['idioma_padrao'] ?? 'pt_BR'; ?>
                            <select name="idioma_padrao" class="form-select form-select-lg mb-3">
                                <option value="pt_BR" <?= $idiomaAtual === 'pt_BR' ? 'selected' : '' ?>><?= htmlspecialchars(t('comum.idioma.pt_br')) ?></option>
                                <option value="en" <?= $idiomaAtual === 'en' ? 'selected' : '' ?>><?= htmlspecialchars(t('comum.idioma.en')) ?></option>
                                <option value="es" <?= $idiomaAtual === 'es' ? 'selected' : '' ?>><?= htmlspecialchars(t('comum.idioma.es')) ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ABA 5: DICOM — InstitutionName e Issuer por modalidade são controles independentes -->
                <div class="tab-pane fade" id="dicom" role="tabpanel">
                    <?php
                    $institutionValues = array_values(array_filter(array_map('trim', explode(',', (string) ($institutionNames ?? '')))));
                    $issuerModalidadeRules = $issuerModalidadeRules ?? [];
                    ?>
                    <div class="alert alert-info">
                        <i class="fa fa-shield-alt me-2"></i>
                        <strong><?= htmlspecialchars(t('negocios.dicom.controle_independente_titulo')) ?></strong>
                        <?= htmlspecialchars(t('negocios.dicom.controle_independente_ajuda')) ?>
                    </div>

                    <section class="border rounded p-3 mb-4">
                        <div class="d-flex align-items-center justify-content-between gap-3 mb-2">
                            <div>
                                <h6 class="mb-1 fw-bold"><i class="fa fa-building me-1"></i><?= htmlspecialchars(t('negocios.dicom.institution_section')) ?></h6>
                                <p class="small text-muted mb-0"><?= htmlspecialchars(t('negocios.dicom.institution_section_ajuda')) ?></p>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="dicom_add_institution"><i class="fa fa-plus me-1"></i><?= htmlspecialchars(t('negocios.dicom.institution_adicionar')) ?></button>
                        </div>
                        <input type="hidden" name="institution_names" id="institution_names_legacy" value="">
                        <div id="dicom_institutions_body" class="row g-2">
                            <?php foreach ($institutionValues as $institution): ?>
                                <div class="col-md-6 dicom-institution-row"><div class="input-group"><input class="form-control form-control-sm dicom-institution" value="<?= htmlspecialchars($institution) ?>" maxlength="128"><button type="button" class="btn btn-sm btn-outline-danger dicom-institution-remove" title="<?= htmlspecialchars(t('comum.acoes.excluir')) ?>"><i class="fa fa-trash"></i></button></div></div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="border rounded p-3">
                        <div class="d-flex align-items-center justify-content-between gap-3 mb-2">
                            <div>
                                <h6 class="mb-1 fw-bold"><i class="fa fa-id-card me-1"></i><?= htmlspecialchars(t('negocios.dicom.issuer_section')) ?></h6>
                                <p class="small text-muted mb-0"><?= htmlspecialchars(t('negocios.dicom.issuer_section_ajuda')) ?></p>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="issuer_add_rule"><i class="fa fa-plus me-1"></i><?= htmlspecialchars(t('negocios.dicom.issuer_adicionar')) ?></button>
                        </div>
                        <div class="table-responsive border rounded">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light"><tr><th><?= htmlspecialchars(t('negocios.dicom.issuer')) ?></th><th><?= htmlspecialchars(t('negocios.dicom.modalidades')) ?></th><th class="text-end"><?= htmlspecialchars(t('comum.acoes.titulo')) ?></th></tr></thead>
                                <tbody id="issuer_rules_body">
                                    <?php foreach ($issuerModalidadeRules as $index => $rule): ?>
                                        <tr class="issuer-rule-row"><td><input class="form-control form-control-sm issuer-value" name="issuer_modalidades[<?= (int) $index ?>][issuer_of_patient_id]" value="<?= htmlspecialchars($rule['issuer_of_patient_id'] ?? '') ?>" maxlength="64" required></td><td><input class="form-control form-control-sm issuer-modalities mb-1" name="issuer_modalidades[<?= (int) $index ?>][modalidades]" value="<?= htmlspecialchars(implode(', ', $rule['modalidades'] ?? [])) ?>" placeholder="CT, CR, US" required><div class="form-check"><input class="form-check-input issuer-all-modalities" type="checkbox" id="issuer_all_<?= (int) $index ?>" <?= in_array('*', $rule['modalidades'] ?? [], true) ? 'checked' : '' ?>><label class="form-check-label small" for="issuer_all_<?= (int) $index ?>">Todas as modalidades</label></div></td><td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger issuer-remove" title="<?= htmlspecialchars(t('comum.acoes.excluir')) ?>"><i class="fa fa-trash"></i></button></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="small text-muted mt-3 mb-0"><?= htmlspecialchars(t('negocios.dicom.issuer_regra_seguranca')) ?></p>
                    </section>

                    <template id="dicom_institution_template"><div class="col-md-6 dicom-institution-row"><div class="input-group"><input class="form-control form-control-sm dicom-institution" maxlength="128"><button type="button" class="btn btn-sm btn-outline-danger dicom-institution-remove" title="<?= htmlspecialchars(t('comum.acoes.excluir')) ?>"><i class="fa fa-trash"></i></button></div></div></template>
                    <template id="issuer_rule_template"><tr class="issuer-rule-row"><td><input class="form-control form-control-sm issuer-value" maxlength="64" required></td><td><input class="form-control form-control-sm issuer-modalities mb-1" placeholder="CT, CR, US" required><div class="form-check"><input class="form-check-input issuer-all-modalities" type="checkbox"><label class="form-check-label small">Todas as modalidades</label></div></td><td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger issuer-remove" title="<?= htmlspecialchars(t('comum.acoes.excluir')) ?>"><i class="fa fa-trash"></i></button></td></tr></template>
                    <script>
                    (() => {
                        const institutionBody = document.getElementById('dicom_institutions_body');
                        const institutionTemplate = document.getElementById('dicom_institution_template');
                        const institutionLegacy = document.getElementById('institution_names_legacy');
                        const issuerBody = document.getElementById('issuer_rules_body');
                        const issuerTemplate = document.getElementById('issuer_rule_template');
                        const syncInstitutions = () => { institutionLegacy.value = [...institutionBody.querySelectorAll('.dicom-institution')].map(input => input.value.trim()).filter(Boolean).filter((value, index, all) => all.indexOf(value) === index).join(', '); };
                        const renumberIssuers = () => issuerBody.querySelectorAll('.issuer-rule-row').forEach((row, index) => { row.querySelector('.issuer-value').name = `issuer_modalidades[${index}][issuer_of_patient_id]`; row.querySelector('.issuer-modalities').name = `issuer_modalidades[${index}][modalidades]`; });
                        const bindInstitution = row => row.querySelector('.dicom-institution-remove').addEventListener('click', () => { row.remove(); syncInstitutions(); });
                        const bindIssuer = row => {
                            row.querySelector('.issuer-remove').addEventListener('click', () => { row.remove(); renumberIssuers(); });
                            const all = row.querySelector('.issuer-all-modalities');
                            const modalities = row.querySelector('.issuer-modalities');
                            const applyAll = () => { if (all.checked) { modalities.value = '*'; modalities.readOnly = true; } else { if (modalities.value === '*') modalities.value = ''; modalities.readOnly = false; } };
                            all.addEventListener('change', applyAll); applyAll();
                        };
                        institutionBody.querySelectorAll('.dicom-institution-row').forEach(bindInstitution);
                        issuerBody.querySelectorAll('.issuer-rule-row').forEach(bindIssuer);
                        institutionBody.addEventListener('input', syncInstitutions);
                        document.getElementById('dicom_add_institution').addEventListener('click', () => { const row = institutionTemplate.content.firstElementChild.cloneNode(true); bindInstitution(row); institutionBody.appendChild(row); row.querySelector('.dicom-institution').focus(); });
                        document.getElementById('issuer_add_rule').addEventListener('click', () => { const row = issuerTemplate.content.firstElementChild.cloneNode(true); bindIssuer(row); issuerBody.appendChild(row); renumberIssuers(); row.querySelector('.issuer-value').focus(); });
                        document.querySelector('#formNegocio')?.addEventListener('submit', () => { syncInstitutions(); renumberIssuers(); });
                        syncInstitutions(); renumberIssuers();
                    })();
                    </script>
                </div>

                <?php if (isset($negocio) && !empty($negocio['id'])): ?>
                <div class="tab-pane fade" id="servidores" role="tabpanel">
                    <div class="alert alert-info"><i class="fa fa-shield-alt me-2"></i><strong>Herança de regras DICOM.</strong> Ao vincular um servidor, este negócio preserva as regras acima: <code>InstitutionName (0008,0080)</code> e <code>IssuerOfPatientID (0010,0021)</code>, com modalidades autorizadas. Em célula exclusiva, o tenant da célula permanece a fronteira principal.</div>
                    <div class="table-responsive border rounded"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Vincular</th><th>Servidor PACS</th><th>Tipo / estado</th><th>Tags aplicadas neste negócio</th></tr></thead><tbody>
                    <?php if (empty($pacsServers ?? [])): ?><tr><td colspan="4" class="text-center text-muted py-4">Nenhum servidor PACS disponível.</td></tr><?php endif; ?>
                    <?php foreach (($pacsServers ?? []) as $server): ?>
                      <?php $belongsElsewhere = !empty($server['cell_tenant_id']) && (int)$server['cell_tenant_id'] !== (int)$negocio['id']; $exclusiveHere = !empty($server['cell_tenant_id']) && (int)$server['cell_tenant_id'] === (int)$negocio['id']; $checked = !empty($server['vinculado']) || $exclusiveHere; ?>
                      <tr class="<?= $belongsElsewhere ? 'table-light text-muted' : '' ?>">
                        <td><input class="form-check-input" type="checkbox" name="servidor_pacs_ids[]" value="<?= (int)$server['id'] ?>" <?= $checked ? 'checked' : '' ?> <?= ($belongsElsewhere || $exclusiveHere) ? 'disabled' : '' ?>><?php if ($exclusiveHere): ?><input type="hidden" name="servidor_pacs_ids[]" value="<?= (int)$server['id'] ?>"><?php endif; ?></td>
                        <td><strong><?= htmlspecialchars($server['nome']) ?></strong><br><small class="text-muted">AE: <code><?= htmlspecialchars($server['dicom_aet'] ?: '—') ?></code></small></td>
                        <td><?php if (!empty($server['cell_profile'])): ?><span class="badge bg-primary">Célula <?= htmlspecialchars($server['cell_profile']) ?></span><br><small><?= htmlspecialchars($server['cell_status']) ?></small><?php else: ?><span class="badge bg-secondary">Orthanc compartilhado</span><?php endif; ?></td>
                        <td><small><code>(0008,0080)</code> <?= htmlspecialchars($institutionNames ?: 'sem regra') ?><br><code>(0010,0021)</code> <?= htmlspecialchars(empty($issuerModalidadeRules) ? 'sem regra' : 'Issuer + modalidades definidos na aba DICOM') ?></small><?php if ($belongsElsewhere): ?><br><span class="text-danger small">Indisponível: célula exclusiva de outro negócio.</span><?php endif; ?></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <p class="small text-muted mt-3 mb-0">Selecione um ou mais servidores compartilhados e salve o negócio. Deixar um servidor desmarcado remove apenas o vínculo futuro; não reatribui estudos já roteados. Células exclusivas não podem ser vinculadas a outro tenant.</p>
                </div>
                <?php endif; ?>

                <!-- ABA 6: WEBHOOKS HUB -->
                <div class="tab-pane fade" id="webhook" role="tabpanel">
<?php
$webhookConfig = [];
try {
    $whModel = new \App\Models\WebhookHubConfig();
    $webhookConfig = $whModel->getByTenant($negocio['id'] ?? 0) ?: [];
} catch (\Throwable $e) { /* tabela pode não existir ainda */ }
$whEventsEnabled = json_decode($webhookConfig['events_enabled'] ?? '["study.received"]', true) ?: ['study.received'];
$whBackoff = json_decode($webhookConfig['retry_backoff_seconds'] ?? '[5,15,60,300]', true) ?: [5,15,60,300];
$negocioId = (int)($negocio['id'] ?? 0);
?>
                    <div class="row g-4">
                        <div class="col-lg-8">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-dark text-white d-flex align-items-center gap-2">
                                    <i class="fa fa-plug"></i><strong>Configuração do VOXEL HUB</strong>
                                    <?php if (!empty($webhookConfig['status'])): ?>
                                    <span class="badge ms-auto <?= $webhookConfig['status']==='enabled'?'bg-success':($webhookConfig['status']==='testing'?'bg-warning text-dark':'bg-secondary') ?>">
                                        <?= $webhookConfig['status']==='enabled'?'Habilitado':($webhookConfig['status']==='testing'?'Testando':'Desabilitado') ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <h6 class="text-muted fw-semibold mb-3"><i class="fa fa-link me-1"></i> Conexão</h6>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">URL do VOXEL HUB <span class="text-danger">*</span></label>
                                        <input type="url" id="wh_hub_url" class="form-control" value="<?= htmlspecialchars($webhookConfig['hub_url'] ?? '') ?>" placeholder="https://hub.voxelpacs.com.br">
                                        <small class="text-muted">URL base do servidor VOXEL HUB (sem barra final).</small>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">JWT Secret <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="password" id="wh_jwt_secret" class="form-control" value="<?= htmlspecialchars($webhookConfig['jwt_secret'] ?? '') ?>" placeholder="Mínimo 16 caracteres">
                                            <button class="btn btn-outline-secondary" type="button" onclick="whToggleSecret()" title="Mostrar/ocultar"><i class="fa fa-eye" id="wh_eye_icon"></i></button>
                                            <button class="btn btn-outline-primary" type="button" onclick="whGenerateSecret()" title="Gerar chave aleatória"><i class="fa fa-key"></i></button>
                                        </div>
                                        <small class="text-muted">Chave compartilhada para assinatura JWT HMAC-SHA256.</small>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label fw-semibold">Algoritmo JWT</label>
                                            <select id="wh_jwt_algorithm" class="form-select"><option value="HS256" selected>HS256</option></select>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label fw-semibold">Issuer</label>
                                            <input type="text" id="wh_jwt_issuer" class="form-control" value="<?= htmlspecialchars($webhookConfig['jwt_issuer'] ?? 'voxel-pacs') ?>">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label fw-semibold">Audience</label>
                                            <input type="text" id="wh_jwt_audience" class="form-control" value="<?= htmlspecialchars($webhookConfig['jwt_audience'] ?? 'voxel-hub') ?>">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Expiração do Token (segundos)</label>
                                        <input type="number" id="wh_jwt_expiry" class="form-control" min="60" max="86400" value="<?= (int)($webhookConfig['jwt_expiry_seconds'] ?? 3600) ?>">
                                    </div>
                                    <hr>
                                    <h6 class="text-muted fw-semibold mb-3"><i class="fa fa-bell me-1"></i> Eventos Habilitados</h6>
                                    <div class="row">
                                        <?php
                                        $allEvents = [
                                            'study.received'  => ['label'=>'Estudo Recebido',   'icon'=>'fa-file-medical','desc'=>'Novo estudo DICOM chegou ao PACS'],
                                            'study.assumed'   => ['label'=>'Estudo Assumido',   'icon'=>'fa-user-check',  'desc'=>'Médico assumiu o estudo'],
                                            'study.signed'    => ['label'=>'Laudo Assinado',    'icon'=>'fa-signature',   'desc'=>'Laudo assinado/liberado'],
                                            'study.updated'   => ['label'=>'Estudo Atualizado', 'icon'=>'fa-edit',        'desc'=>'Dados do estudo foram alterados'],
                                            'patient.created' => ['label'=>'Paciente Criado',   'icon'=>'fa-user-plus',   'desc'=>'Novo paciente cadastrado'],
                                        ];
                                        foreach ($allEvents as $evKey => $evInfo):
                                            $checked = in_array($evKey, $whEventsEnabled) ? 'checked' : '';
                                        ?>
                                        <div class="col-md-6 mb-2">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input wh-event-check" type="checkbox" id="ev_<?= $evKey ?>" value="<?= $evKey ?>" <?= $checked ?>>
                                                <label class="form-check-label" for="ev_<?= $evKey ?>">
                                                    <i class="fa <?= $evInfo['icon'] ?> me-1 text-primary"></i>
                                                    <strong><?= $evInfo['label'] ?></strong><br>
                                                    <small class="text-muted"><?= $evInfo['desc'] ?></small>
                                                </label>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <hr>
                                    <h6 class="text-muted fw-semibold mb-3"><i class="fa fa-redo me-1"></i> Política de Retry</h6>
                                    <div class="row">
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label fw-semibold">Retry Ativo</label>
                                            <div class="form-check form-switch mt-2">
                                                <input class="form-check-input" type="checkbox" id="wh_retry_enabled" <?= ($webhookConfig['retry_enabled'] ?? 1) ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="wh_retry_enabled">Habilitado</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label fw-semibold">Máx. Tentativas</label>
                                            <input type="number" id="wh_retry_max" class="form-control" min="1" max="10" value="<?= (int)($webhookConfig['retry_max_attempts'] ?? 5) ?>">
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label fw-semibold">Timeout (s)</label>
                                            <input type="number" id="wh_timeout" class="form-control" min="5" max="120" value="<?= (int)($webhookConfig['request_timeout_seconds'] ?? 30) ?>">
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label fw-semibold">Rate Limit/min</label>
                                            <input type="number" id="wh_rate_limit" class="form-control" min="1" max="10000" value="<?= (int)($webhookConfig['rate_limit_per_minute'] ?? 1000) ?>">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Backoff (segundos, separados por vírgula)</label>
                                        <input type="text" id="wh_backoff" class="form-control" value="<?= htmlspecialchars(implode(',', $whBackoff)) ?>" placeholder="5,15,60,300">
                                        <small class="text-muted">Delay entre tentativas. Ex: 5,15,60,300</small>
                                    </div>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="wh_dlq_enabled" <?= ($webhookConfig['retry_dlq_enabled'] ?? 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="wh_dlq_enabled"><strong>DLQ (Dead Letter Queue)</strong> — Mover para fila de falhas após esgotar tentativas</label>
                                    </div>
                                    <hr>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Status da Integração</label>
                                        <select id="wh_status" class="form-select">
                                            <option value="disabled" <?= ($webhookConfig['status'] ?? 'disabled')==='disabled'?'selected':'' ?>>Desabilitado</option>
                                            <option value="enabled"  <?= ($webhookConfig['status'] ?? '')==='enabled' ?'selected':'' ?>>Habilitado</option>
                                        </select>
                                    </div>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <button type="button" class="btn btn-primary" onclick="whSaveConfig(<?= $negocioId ?>)"><i class="fa fa-save me-1"></i> Salvar Configuração</button>
                                        <button type="button" class="btn btn-outline-info" onclick="whHealthCheck(<?= $negocioId ?>)"><i class="fa fa-heartbeat me-1"></i> Health Check</button>
                                        <button type="button" class="btn btn-outline-success" onclick="whTestConnection(<?= $negocioId ?>)"><i class="fa fa-paper-plane me-1"></i> Enviar Evento de Teste</button>
                                        <button type="button" class="btn btn-outline-secondary" onclick="whLoadLogs(<?= $negocioId ?>)"><i class="fa fa-list me-1"></i> Ver Logs</button>
                                    </div>
                                    <div id="wh_feedback" class="mt-3"></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card border-0 shadow-sm mb-3">
                                <div class="card-header bg-dark text-white"><i class="fa fa-heartbeat me-1"></i> Último Health Check</div>
                                <div class="card-body">
                                    <?php if (!empty($webhookConfig['last_health_check'])): ?>
                                    <?php
                                    $hcStatus = $webhookConfig['last_health_status'] ?? 'unknown';
                                    $hcBadge  = ['ok'=>'success','error'=>'danger','timeout'=>'warning','unknown'=>'secondary'][$hcStatus] ?? 'secondary';
                                    $hcIcon   = ['ok'=>'fa-check-circle','error'=>'fa-times-circle','timeout'=>'fa-clock','unknown'=>'fa-question-circle'][$hcStatus] ?? 'fa-question-circle';
                                    ?>
                                    <span class="badge bg-<?= $hcBadge ?> fs-6"><i class="fa <?= $hcIcon ?> me-1"></i><?= strtoupper($hcStatus) ?></span>
                                    <p class="text-muted small mt-2 mb-1"><?= htmlspecialchars($webhookConfig['last_health_message'] ?? '') ?></p>
                                    <small class="text-muted">Verificado em: <?= date('d/m/Y H:i', strtotime($webhookConfig['last_health_check'])) ?></small>
                                    <?php else: ?>
                                    <p class="text-muted small mb-0"><i class="fa fa-info-circle me-1"></i> Nenhum health check realizado ainda.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card border-0 shadow-sm mb-3">
                                <div class="card-header bg-dark text-white"><i class="fa fa-chart-bar me-1"></i> Estatísticas de Eventos</div>
                                <div class="card-body" id="wh_stats_panel">
                                    <?php
                                    $whStats = ['total'=>0,'sent'=>0,'pending'=>0,'failed'=>0,'dlq'=>0];
                                    try { $whEventModel = new \App\Models\WebhookHubEvent(); $whStats = $whEventModel->statsByTenant($negocioId); } catch (\Throwable $e) {}
                                    ?>
                                    <div class="row text-center g-2">
                                        <div class="col-6"><div class="bg-light rounded p-2"><div class="fs-4 fw-bold text-dark"><?= (int)$whStats['total'] ?></div><small class="text-muted">Total</small></div></div>
                                        <div class="col-6"><div class="bg-light rounded p-2"><div class="fs-4 fw-bold text-success"><?= (int)$whStats['sent'] ?></div><small class="text-muted">Enviados</small></div></div>
                                        <div class="col-6"><div class="bg-light rounded p-2"><div class="fs-4 fw-bold text-warning"><?= (int)$whStats['pending'] ?></div><small class="text-muted">Pendentes</small></div></div>
                                        <div class="col-6"><div class="bg-light rounded p-2"><div class="fs-4 fw-bold text-danger"><?= (int)$whStats['failed'] + (int)$whStats['dlq'] ?></div><small class="text-muted">Falhas/DLQ</small></div></div>
                                    </div>
                                </div>
                            </div>
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-dark text-white"><i class="fa fa-book me-1"></i> Documentação</div>
                                <div class="card-body">
                                    <ul class="list-unstyled small text-muted mb-0">
                                        <li class="mb-2"><i class="fa fa-check text-success me-1"></i> JWT HMAC-SHA256 (HS256)</li>
                                        <li class="mb-2"><i class="fa fa-check text-success me-1"></i> Retry com backoff configurável</li>
                                        <li class="mb-2"><i class="fa fa-check text-success me-1"></i> DLQ para eventos com falha</li>
                                        <li class="mb-2"><i class="fa fa-check text-success me-1"></i> Idempotência por event_id</li>
                                        <li class="mb-2"><i class="fa fa-check text-success me-1"></i> Log completo de auditoria</li>
                                        <li><i class="fa fa-info-circle text-primary me-1"></i> Endpoint HUB: <code>/api/webhook/receive</code></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal de Logs -->
                    <div class="modal fade" id="whLogsModal" tabindex="-1">
                        <div class="modal-dialog modal-xl">
                            <div class="modal-content">
                                <div class="modal-header bg-dark text-white">
                                    <h5 class="modal-title"><i class="fa fa-list me-2"></i> Logs de Eventos Webhook HUB</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row g-2 mb-3">
                                        <div class="col-md-3"><select id="wh_log_status" class="form-select form-select-sm"><option value="">Todos os status</option><option value="sent">Enviados</option><option value="pending">Pendentes</option><option value="failed">Falhos</option><option value="dlq">DLQ</option></select></div>
                                        <div class="col-md-3"><select id="wh_log_event_type" class="form-select form-select-sm"><option value="">Todos os eventos</option><option value="study.received">study.received</option><option value="study.assumed">study.assumed</option><option value="study.signed">study.signed</option><option value="system.test">system.test</option></select></div>
                                        <div class="col-md-2"><input type="date" id="wh_log_date_from" class="form-control form-control-sm"></div>
                                        <div class="col-md-2"><input type="date" id="wh_log_date_to" class="form-control form-control-sm"></div>
                                        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100" onclick="whLoadLogs(<?= $negocioId ?>)"><i class="fa fa-search me-1"></i> Filtrar</button></div>
                                    </div>
                                    <div id="wh_logs_table"><div class="text-center text-muted py-4"><i class="fa fa-spinner fa-spin me-2"></i> Carregando...</div></div>
                                </div>
                            </div>
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

// ============================================================
// WEBHOOK HUB — JavaScript
// ============================================================
function whToggleSecret() {
    const inp = document.getElementById('wh_jwt_secret');
    const ico = document.getElementById('wh_eye_icon');
    if (inp.type === 'password') { inp.type = 'text'; ico.className = 'fa fa-eye-slash'; }
    else { inp.type = 'password'; ico.className = 'fa fa-eye'; }
}

function whGenerateSecret() {
    const arr = new Uint8Array(32);
    crypto.getRandomValues(arr);
    const secret = btoa(String.fromCharCode(...arr)).replace(/[+/=]/g,'').substring(0,48);
    document.getElementById('wh_jwt_secret').value = secret;
    document.getElementById('wh_jwt_secret').type = 'text';
}

function whCollectConfig() {
    const events = [...document.querySelectorAll('.wh-event-check:checked')].map(c => c.value);
    const backoffRaw = document.getElementById('wh_backoff').value;
    const backoff = backoffRaw.split(',').map(v => parseInt(v.trim())).filter(v => !isNaN(v));
    return {
        hub_url:                document.getElementById('wh_hub_url').value.trim(),
        jwt_secret:             document.getElementById('wh_jwt_secret').value.trim(),
        jwt_algorithm:          document.getElementById('wh_jwt_algorithm').value,
        jwt_issuer:             document.getElementById('wh_jwt_issuer').value.trim(),
        jwt_audience:           document.getElementById('wh_jwt_audience').value.trim(),
        jwt_expiry_seconds:     parseInt(document.getElementById('wh_jwt_expiry').value) || 3600,
        events_enabled:         events,
        retry_enabled:          document.getElementById('wh_retry_enabled').checked ? 1 : 0,
        retry_max_attempts:     parseInt(document.getElementById('wh_retry_max').value) || 5,
        retry_backoff_seconds:  backoff,
        retry_dlq_enabled:      document.getElementById('wh_dlq_enabled').checked ? 1 : 0,
        request_timeout_seconds:parseInt(document.getElementById('wh_timeout').value) || 30,
        rate_limit_per_minute:  parseInt(document.getElementById('wh_rate_limit').value) || 1000,
        status:                 document.getElementById('wh_status').value,
    };
}

function whFeedback(html, type) {
    const fb = document.getElementById('wh_feedback');
    if (!fb) return;
    fb.innerHTML = `<div class="alert alert-${type} py-2">${html}</div>`;
    setTimeout(() => { fb.innerHTML = ''; }, 8000);
}

function whSaveConfig(negocioId) {
    const cfg = whCollectConfig();
    if (!cfg.hub_url) { whFeedback('<i class="fa fa-exclamation-triangle me-1"></i> Informe a URL do VOXEL HUB.', 'warning'); return; }
    if (!cfg.jwt_secret || cfg.jwt_secret.length < 16) { whFeedback('<i class="fa fa-exclamation-triangle me-1"></i> JWT Secret deve ter ao menos 16 caracteres.', 'warning'); return; }
    whFeedback('<i class="fa fa-spinner fa-spin me-1"></i> Salvando...', 'info');
    fetch(`/platform/api/negocios/${negocioId}/webhook-hub/save`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(cfg)
    }).then(r => r.json()).then(d => {
        if (d.success) whFeedback('<i class="fa fa-check-circle me-1"></i> Configuração salva com sucesso!', 'success');
        else whFeedback('<i class="fa fa-times-circle me-1"></i> Erro: ' + (d.error || 'desconhecido'), 'danger');
    }).catch(() => whFeedback('<i class="fa fa-times-circle me-1"></i> Erro de comunicação.', 'danger'));
}

function whHealthCheck(negocioId) {
    whFeedback('<i class="fa fa-spinner fa-spin me-1"></i> Verificando health check...', 'info');
    fetch(`/platform/api/negocios/${negocioId}/webhook-hub/health`)
        .then(r => r.json()).then(d => {
            const icon = d.status === 'ok' ? 'fa-check-circle' : 'fa-times-circle';
            const type = d.status === 'ok' ? 'success' : 'danger';
            whFeedback(`<i class="fa ${icon} me-1"></i> ${d.message || d.status}`, type);
        }).catch(() => whFeedback('<i class="fa fa-times-circle me-1"></i> Erro de comunicação.', 'danger'));
}

function whTestConnection(negocioId) {
    whFeedback('<i class="fa fa-spinner fa-spin me-1"></i> Enviando evento de teste...', 'info');
    fetch(`/platform/api/negocios/${negocioId}/webhook-hub/test`, { method: 'POST' })
        .then(r => r.json()).then(d => {
            if (d.success) whFeedback('<i class="fa fa-check-circle me-1"></i> Evento de teste enviado! HTTP ' + (d.http_code || ''), 'success');
            else whFeedback('<i class="fa fa-times-circle me-1"></i> Falha: ' + (d.error || 'desconhecido'), 'danger');
        }).catch(() => whFeedback('<i class="fa fa-times-circle me-1"></i> Erro de comunicação.', 'danger'));
}

function whLoadLogs(negocioId) {
    const modal = new bootstrap.Modal(document.getElementById('whLogsModal'));
    modal.show();
    const status    = document.getElementById('wh_log_status')?.value || '';
    const evType    = document.getElementById('wh_log_event_type')?.value || '';
    const dateFrom  = document.getElementById('wh_log_date_from')?.value || '';
    const dateTo    = document.getElementById('wh_log_date_to')?.value || '';
    const params    = new URLSearchParams({ status, event_type: evType, date_from: dateFrom, date_to: dateTo });
    document.getElementById('wh_logs_table').innerHTML = '<div class="text-center text-muted py-4"><i class="fa fa-spinner fa-spin me-2"></i> Carregando...</div>';
    fetch(`/platform/api/negocios/${negocioId}/webhook-hub/logs?${params}`)
        .then(r => r.json()).then(d => {
            if (!d.events || d.events.length === 0) {
                document.getElementById('wh_logs_table').innerHTML = '<p class="text-center text-muted py-4">Nenhum evento encontrado.</p>';
                return;
            }
            let html = `<table class="table table-sm table-hover">
                <thead class="table-dark"><tr>
                    <th>Data</th><th>Evento</th><th>Status</th><th>HTTP</th><th>Tentativas</th><th>Ações</th>
                </tr></thead><tbody>`;
            d.events.forEach(ev => {
                const badgeMap = {sent:'success',pending:'warning',failed:'danger',dlq:'dark'};
                const badge = badgeMap[ev.status] || 'secondary';
                html += `<tr>
                    <td><small>${ev.created_at || ''}</small></td>
                    <td><code>${ev.event_type || ''}</code></td>
                    <td><span class="badge bg-${badge}">${ev.status}</span></td>
                    <td>${ev.last_http_code || '—'}</td>
                    <td>${ev.attempt_count || 0}/${ev.max_attempts || 5}</td>
                    <td>${ev.status === 'failed' || ev.status === 'dlq' ? `<button class="btn btn-xs btn-outline-warning" onclick="whRetryEvent(${negocioId},${ev.id})"><i class="fa fa-redo"></i></button>` : ''}</td>
                </tr>`;
            });
            html += '</tbody></table>';
            document.getElementById('wh_logs_table').innerHTML = html;
        }).catch(() => { document.getElementById('wh_logs_table').innerHTML = '<p class="text-danger">Erro ao carregar logs.</p>'; });
}

function whRetryEvent(negocioId, eventId) {
    fetch(`/platform/api/negocios/${negocioId}/webhook-hub/retry/${eventId}`, { method: 'POST' })
        .then(r => r.json()).then(d => {
            if (d.success) { alert('Evento reagendado para reenvio!'); whLoadLogs(negocioId); }
            else alert('Erro: ' + (d.error || 'desconhecido'));
        });
}

// ============================================================
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
