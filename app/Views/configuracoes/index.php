<?php $config = $config ?? []; ?>

<div style="margin-bottom:1.5rem;">
    <h1 style="font-size:1.3rem;font-weight:700;color:var(--pacs-text);margin-bottom:.25rem;">
        <i class="fa fa-gear me-2 text-pacs-primary"></i>Configurações do Sistema
    </h1>
    <p style="color:var(--pacs-text-muted);font-size:.82rem;">Configurações gerais do VOXEL PACS</p>
</div>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="pacs-alert pacs-alert-success mb-3"><i class="fa fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?></div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;max-width:900px;">

    <!-- Configurações PACS -->
    <div class="pacs-card">
        <div class="pacs-card-header">
            <i class="fa fa-x-ray text-pacs-primary"></i>
            <span style="font-weight:600;color:var(--pacs-text);">Servidor PACS (Orthanc)</span>
        </div>
        <div class="pacs-card-body">
            <form method="POST" action="/configuracoes/salvar">
                <input type="hidden" name="grupo" value="pacs">

                <div style="margin-bottom:.75rem;">
                    <label class="form-label-dark">URL do Orthanc</label>
                    <input type="url" name="orthanc_url" class="form-control-dark"
                           value="<?= htmlspecialchars($config['orthanc_url'] ?? 'http://localhost:8042') ?>"
                           placeholder="http://localhost:8042">
                </div>
                <div style="margin-bottom:.75rem;">
                    <label class="form-label-dark">Usuário Orthanc</label>
                    <input type="text" name="orthanc_user" class="form-control-dark"
                           value="<?= htmlspecialchars($config['orthanc_user'] ?? '') ?>"
                           placeholder="admin">
                </div>
                <div style="margin-bottom:.75rem;">
                    <label class="form-label-dark">Senha Orthanc</label>
                    <input type="password" name="orthanc_pass" class="form-control-dark"
                           value="" placeholder="••••••••">
                </div>
                <div style="margin-bottom:1rem;">
                    <label class="form-label-dark">URL do Viewer DICOM</label>
                    <input type="url" name="viewer_url" class="form-control-dark"
                           value="<?= htmlspecialchars($config['viewer_url'] ?? '') ?>"
                           placeholder="http://localhost:3000">
                    <small style="color:var(--pacs-text-muted);font-size:.7rem;">URL base do OHIF Viewer ou Weasis</small>
                </div>
                <button type="submit" class="btn-pacs-primary" style="width:100%;">
                    <i class="fa fa-floppy-disk"></i> Salvar Configurações PACS
                </button>
            </form>
        </div>
    </div>

    <!-- Configurações Gerais -->
    <div class="pacs-card">
        <div class="pacs-card-header">
            <i class="fa fa-building text-pacs-primary"></i>
            <span style="font-weight:600;color:var(--pacs-text);">Dados da Empresa</span>
        </div>
        <div class="pacs-card-body">
            <form method="POST" action="/configuracoes/salvar">
                <input type="hidden" name="grupo" value="empresa">

                <div style="margin-bottom:.75rem;">
                    <label class="form-label-dark">Nome da Empresa</label>
                    <input type="text" name="empresa_nome" class="form-control-dark"
                           value="<?= htmlspecialchars($config['empresa_nome'] ?? '') ?>"
                           placeholder="Clínica de Radiologia">
                </div>
                <div style="margin-bottom:.75rem;">
                    <label class="form-label-dark">CNPJ</label>
                    <input type="text" name="empresa_cnpj" class="form-control-dark"
                           value="<?= htmlspecialchars($config['empresa_cnpj'] ?? '') ?>"
                           placeholder="00.000.000/0001-00">
                </div>
                <div style="margin-bottom:.75rem;">
                    <label class="form-label-dark">E-mail de Contato</label>
                    <input type="email" name="empresa_email" class="form-control-dark"
                           value="<?= htmlspecialchars($config['empresa_email'] ?? '') ?>"
                           placeholder="contato@clinica.com.br">
                </div>
                <div style="margin-bottom:1rem;">
                    <label class="form-label-dark">Telefone</label>
                    <input type="text" name="empresa_telefone" class="form-control-dark"
                           value="<?= htmlspecialchars($config['empresa_telefone'] ?? '') ?>"
                           placeholder="(11) 3000-0000">
                </div>
                <button type="submit" class="btn-pacs-primary" style="width:100%;">
                    <i class="fa fa-floppy-disk"></i> Salvar Dados da Empresa
                </button>
            </form>
        </div>
    </div>

</div>
