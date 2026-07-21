<?php
$config = $config ?? [];
$viewerDesktopConfig = $viewerDesktopConfig ?? ['radiant' => null, 'weasis' => null];
?>

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
                    <small style="color:var(--pacs-text-muted);font-size:.7rem;">URL base do Voxel View (o visualizador web do VOXEL PACS)</small>
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

    <!-- Visualizadores Desktop (RadiAnt / Weasis) -->
    <div class="pacs-card" style="grid-column:1 / -1;">
        <div class="pacs-card-header">
            <i class="fa fa-desktop text-pacs-primary"></i>
            <span style="font-weight:600;color:var(--pacs-text);"><?= htmlspecialchars(t('viewer_desktop.config.titulo')) ?></span>
        </div>
        <div class="pacs-card-body">
            <p style="color:var(--pacs-text-muted);font-size:.78rem;margin-bottom:1rem;">
                <?= htmlspecialchars(t('viewer_desktop.config.descricao')) ?>
            </p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <?php foreach (['radiant' => t('viewer_desktop.menu.radiant'), 'weasis' => t('viewer_desktop.menu.weasis')] as $viewerKey => $viewerLabel):
                    $vc = $viewerDesktopConfig[$viewerKey] ?? null;
                ?>
                <form method="POST" action="/configuracoes/viewer-desktop/salvar" style="border:1px solid var(--pacs-border);border-radius:8px;padding:.9rem;">
                    <input type="hidden" name="viewer" value="<?= $viewerKey ?>">
                    <div style="font-weight:600;color:var(--pacs-text);font-size:.85rem;margin-bottom:.6rem;">
                        <i class="fa fa-desktop"></i> <?= htmlspecialchars($viewerLabel) ?>
                    </div>
                    <div style="margin-bottom:.6rem;">
                        <label class="form-label-dark"><?= htmlspecialchars(t('viewer_desktop.config.campo_host')) ?></label>
                        <input type="text" name="host" class="form-control-dark"
                               value="<?= htmlspecialchars($vc['host'] ?? '') ?>"
                               placeholder="Usa o servidor PACS por padrão">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.6rem;">
                        <div>
                            <label class="form-label-dark"><?= htmlspecialchars(t('viewer_desktop.config.campo_porta')) ?></label>
                            <input type="text" name="porta" class="form-control-dark"
                                   value="<?= htmlspecialchars($vc['porta'] ?? '') ?>" placeholder="4242">
                        </div>
                        <div>
                            <label class="form-label-dark"><?= htmlspecialchars(t('viewer_desktop.config.campo_ae_title')) ?></label>
                            <input type="text" name="ae_title" class="form-control-dark"
                                   value="<?= htmlspecialchars($vc['ae_title'] ?? '') ?>" placeholder="ORTHANCPACS">
                        </div>
                    </div>
                    <div style="margin-bottom:.75rem;">
                        <label class="form-label-dark"><?= htmlspecialchars(t('viewer_desktop.config.campo_calling_ae')) ?></label>
                        <input type="text" name="calling_ae" class="form-control-dark"
                               value="<?= htmlspecialchars($vc['calling_ae'] ?? '') ?>" placeholder="VOXELVIEWER">
                    </div>
                    <label style="display:flex;align-items:center;gap:.4rem;font-size:.78rem;color:var(--pacs-text-muted);margin-bottom:.75rem;">
                        <input type="checkbox" name="ativo" class="form-check-input" <?= (($vc['ativo'] ?? 1) ? 'checked' : '') ?>>
                        <?= htmlspecialchars(t('viewer_desktop.config.campo_ativo')) ?>
                    </label>
                    <button type="submit" class="btn-pacs-primary" style="width:100%;">
                        <i class="fa fa-floppy-disk"></i> <?= htmlspecialchars(t('viewer_desktop.config.botao_salvar')) ?> <?= htmlspecialchars($viewerLabel) ?>
                    </button>
                </form>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>
