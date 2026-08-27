<?php
/** @var array<string,mixed> $tenant */
/** @var array<int,array<string,mixed>> $destinations */
/** @var array<int,array<string,mixed>> $jobs */
/** @var array<int,array<string,mixed>> $deliveries */
/** @var array{patient:string,modality:string,issuer:string} $deliveryFilters */
/** @var array<string,int> $stats */
/** @var string $csrfToken */
/** @var array<int,string> $transports */
/** @var array<int,string> $institutionNames */
/** @var array<int,array{issuer:string,normalized:string}> $issuers */

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$transportLabels = [
    'dicom_pdf' => 'DICOM Encapsulated PDF',
    'dicom_sr' => 'DICOM Structured Report',
    'hl7_oru' => 'HL7 ORU^R01',
    'https_webhook' => 'HTTPS Webhook/API',
    'sftp' => 'SFTP/FTPS',
];
?>

<div class="container-fluid py-4" id="report-delivery-page">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted small mb-1">Integrações clínicas</p>
            <h1 class="h3 mb-1">VOXEL Report Delivery Hub</h1>
            <p class="text-muted mb-0">Devolutiva de laudos do negócio <strong><?= $escape($tenant['nome'] ?? $tenant['razao_social'] ?? ('#' . ($tenant['id'] ?? ''))) ?></strong>.</p>
        </div>
        <a class="btn btn-outline-secondary" href="/platform/negocios/<?= (int) $tenant['id'] ?>/edit">Voltar ao negócio</a>
    </div>

    <div class="alert alert-warning border-warning-subtle" role="alert">
        <strong>Modo seguro:</strong> destinos novos iniciam desativados e em homologação. A configuração não envia laudos por si só; a ativação depende do worker e de homologação técnica por cliente.
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Total de jobs</div><div class="h3 mb-0"><?= (int) ($stats['total'] ?? 0) ?></div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Na fila</div><div class="h3 mb-0 text-primary"><?= (int) ($stats['queued'] ?? 0) ?></div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Entregues</div><div class="h3 mb-0 text-success"><?= (int) ($stats['delivered'] ?? 0) ?></div></div></div></div>
        <div class="col-6 col-lg-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Falhas/DLQ</div><div class="h3 mb-0 text-danger"><?= (int) ($stats['failed'] ?? 0) ?></div></div></div></div>
    </div>

    <div class="card border-warning shadow-sm mb-4">
        <div class="card-header bg-warning-subtle"><h2 class="h5 mb-0"><i class="fa fa-vial-circle-check me-1"></i> Envio único de homologação</h2></div>
        <div class="card-body">
            <p class="small mb-3">Use somente após confirmar o destino e o PACS de origem. O laudo deve estar <strong>liberado</strong>; o Hub prioriza o Issuer e usa InstitutionName somente quando o estudo não tiver Issuer, criando job apenas para destinos habilitados em homologação.</p>
            <form id="manual-delivery-form" method="post" action="/platform/negocios/<?= (int) $tenant['id'] ?>/report-delivery/reports/enqueue" class="row g-3">
                <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
                <div class="col-lg-8">
                    <label class="form-label" for="manual-report-token">Link ou token público do laudo liberado</label>
                    <input class="form-control font-monospace" id="manual-report-token" name="report_public_token" maxlength="512" required placeholder="Cole o link /reports/r/{token} ou o token de 48 caracteres">
                    <div class="form-text">O identificador não expõe o ID sequencial do laudo. O servidor valida o tenant, o status liberado e o PACS de origem.</div>
                </div>
                <div class="col-lg-4 d-flex align-items-end"><button type="submit" class="btn btn-warning w-100"><i class="fa fa-paper-plane me-1"></i> Criar entrega de homologação</button></div>
                <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="manual-delivery-confirm" name="confirm_single_delivery" value="1" required><label class="form-check-label" for="manual-delivery-confirm">Confirmo a criação de <strong>uma única</strong> entrega para o laudo informado.</label></div></div>
            </form>
            <div id="manual-delivery-feedback" class="d-none alert mt-3 mb-0" role="alert"></div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-5">
            <div class="card shadow-sm">
                <div class="card-header bg-white"><h2 class="h5 mb-0" id="destination-form-title">Novo destino</h2></div>
                <div class="card-body">
                    <div id="delivery-feedback" class="d-none alert" role="alert"></div>
                    <form id="destination-form" method="post" action="/platform/negocios/<?= (int) $tenant['id'] ?>/report-delivery/destinations">
                        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
                        <div class="mb-3">
                            <label class="form-label" for="destination-name">Nome do destino</label>
                            <input class="form-control" id="destination-name" name="nome" maxlength="120" required placeholder="Ex.: PACS Cliente — Homologação">
                        </div>
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label" for="destination-transport">Canal</label>
                                <select class="form-select" id="destination-transport" name="transport" required>
                                    <?php foreach ($transports as $transport): ?>
                                        <option value="<?= $escape($transport) ?>"><?= $escape($transportLabels[$transport] ?? $transport) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="destination-environment">Ambiente</label>
                                <select class="form-select" id="destination-environment" name="ambiente">
                                    <option value="homologacao">Homologação</option>
                                    <option value="producao">Produção</option>
                                </select>
                            </div>
                        </div>
                        <div class="border rounded-3 bg-light-subtle p-3 mt-3" id="institution-routing">
                            <h3 class="h6 mb-1"><i class="fa fa-fingerprint me-1"></i> Issuers dos servidores PACS</h3>
                            <p class="small text-muted mb-3">Selecione os <strong>Issuers</strong> observados ou configurados nos servidores PACS deste negócio. Com Issuer presente no estudo, somente este vínculo é usado para devolução.</p>
                            <?php if (!$issuers): ?>
                                <div class="alert alert-warning mb-3 small">Nenhum Issuer foi encontrado nos servidores PACS deste negócio. Use InstitutionName como fallback até que o PACS envie Issuer.</div>
                            <?php else: ?>
                                <div class="row g-2 mb-3">
                                    <?php foreach ($issuers as $issuer): ?>
                                        <?php $issuerInputId = 'destination-issuer-' . substr(sha1((string) $issuer['normalized']), 0, 12); ?>
                                        <div class="col-12 col-md-6">
                                            <div class="form-check border rounded bg-white px-3 py-2 h-100">
                                                <input class="form-check-input issuer-selector" type="checkbox" name="issuer_of_patient_ids[]" value="<?= $escape($issuer['issuer']) ?>" id="<?= $issuerInputId ?>">
                                                <label class="form-check-label w-100" for="<?= $issuerInputId ?>"><?= $escape($issuer['issuer']) ?></label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <h3 class="h6 mb-1 mt-3"><i class="fa fa-building-circle-check me-1"></i> InstitutionName de fallback</h3>
                            <p class="small text-muted mb-3">Marque também os <strong>InstitutionNames</strong> aceitos quando o estudo não tiver Issuer. Eles não substituem Issuer presente no estudo.</p>
                            <?php if (!$institutionNames): ?>
                                <div class="alert alert-warning mb-0 small">Nenhum InstitutionName ativo foi encontrado neste negócio. Cadastre primeiro as Unidades/PACS de origem antes de criar um destino.</div>
                            <?php else: ?>
                                <div class="row g-2">
                                    <?php foreach ($institutionNames as $institutionName): ?>
                                        <?php $institutionInputId = 'destination-institution-' . substr(sha1((string) $institutionName), 0, 12); ?>
                                        <div class="col-12 col-md-6">
                                            <div class="form-check border rounded bg-white px-3 py-2 h-100">
                                                <input class="form-check-input institution-selector" type="checkbox" name="institution_names[]" value="<?= $escape($institutionName) ?>" id="<?= $institutionInputId ?>">
                                                <label class="form-check-label w-100" for="<?= $institutionInputId ?>"><?= $escape($institutionName) ?></label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" id="destination-config" name="configuration_json" value="{}">
                        <input type="hidden" id="destination-secret" name="configuration_secret" value="">

                        <div class="alert alert-light border mt-3 mb-0" id="destination-guide" role="status">
                            <i class="fa fa-circle-info me-1"></i> Selecione o canal para preencher somente os dados necessários. As configurações técnicas são geradas automaticamente.
                        </div>

                        <div class="destination-fields mt-3" data-transport-group="dicom_pdf,dicom_sr">
                            <h3 class="h6 border-bottom pb-2"><i class="fa fa-x-ray me-1"></i> Conexão com o PACS do cliente</h3>
                            <div class="row g-3">
                                <div class="col-md-8"><label class="form-label" for="dicom-host">Endereço do PACS</label><input class="form-control" id="dicom-host" data-field="host" data-required placeholder="Ex.: 10.0.0.20 ou pacs.cliente.com.br"><div class="form-text">Informe o IP privado/VPN ou o domínio fornecido pelo cliente.</div></div>
                                <div class="col-md-4"><label class="form-label" for="dicom-port">Porta DICOM</label><input class="form-control" id="dicom-port" data-field="port" type="number" min="1" max="65535" value="104" data-required></div>
                                <div class="col-md-6"><label class="form-label" for="dicom-called-ae">AE Title do PACS cliente</label><input class="form-control text-uppercase" id="dicom-called-ae" data-field="called_ae" maxlength="16" data-required placeholder="Ex.: CLIENTE_PACS"></div>
                                <div class="col-md-6"><label class="form-label" for="dicom-calling-ae">AE Title do VOXEL PACS</label><input class="form-control text-uppercase" id="dicom-calling-ae" data-field="calling_ae" maxlength="16" value="VOXEL_PACS" data-required></div>
                                <div class="col-md-12"><label class="form-label" for="dicom-patient-id-normalization">Compatibilidade de identificação do paciente</label><select class="form-select" id="dicom-patient-id-normalization" data-field="patient_id_normalization"><option value="">Preservar o Patient ID original (padrão)</option><option value="vue_prefix_before_triple_dollar">Vue PACS: usar somente a parte antes de $$$</option></select><div class="form-text">Ative apenas quando o administrador do PACS confirmar que o archive remove o sufixo $$$ do Patient ID para localizar o estudo existente.</div></div>
                            </div>
                            <div class="form-check mt-3"><input class="form-check-input" id="dicom-tls" data-field="use_tls" type="checkbox"><label class="form-check-label" for="dicom-tls">Usar conexão DICOM TLS, quando disponibilizada pelo cliente</label></div>
                        </div>

                        <div class="destination-fields mt-3 d-none" data-transport-group="hl7_oru">
                            <h3 class="h6 border-bottom pb-2"><i class="fa fa-hospital me-1"></i> Conexão HL7 do HIS/RIS</h3>
                            <div class="row g-3">
                                <div class="col-md-8"><label class="form-label" for="hl7-host">Servidor HL7</label><input class="form-control" id="hl7-host" data-field="host" data-required placeholder="Ex.: hl7.cliente.com.br"></div>
                                <div class="col-md-4"><label class="form-label" for="hl7-port">Porta MLLP</label><input class="form-control" id="hl7-port" data-field="port" type="number" min="1" max="65535" value="2575" data-required></div>
                                <div class="col-md-6"><label class="form-label" for="hl7-sending-app">Aplicação remetente</label><input class="form-control" id="hl7-sending-app" data-field="sending_application" data-required value="VOXEL_PACS"></div>
                                <div class="col-md-6"><label class="form-label" for="hl7-sending-facility">Instituição remetente</label><input class="form-control" id="hl7-sending-facility" data-field="sending_facility" data-required placeholder="Ex.: VOXEL"></div>
                                <div class="col-md-6"><label class="form-label" for="hl7-receiving-app">Aplicação destinatária</label><input class="form-control" id="hl7-receiving-app" data-field="receiving_application" data-required placeholder="Ex.: RIS_CLIENTE"></div>
                                <div class="col-md-6"><label class="form-label" for="hl7-receiving-facility">Instituição destinatária</label><input class="form-control" id="hl7-receiving-facility" data-field="receiving_facility" data-required placeholder="Ex.: HOSPITAL_CLIENTE"></div>
                            </div>
                        </div>

                        <div class="destination-fields mt-3 d-none" data-transport-group="https_webhook">
                            <h3 class="h6 border-bottom pb-2"><i class="fa fa-link me-1"></i> Endpoint HTTPS do cliente</h3>
                            <div class="row g-3">
                                <div class="col-12"><label class="form-label" for="https-url">URL de recebimento</label><input class="form-control" id="https-url" data-field="url" type="url" data-required placeholder="https://integracao.cliente.com.br/laudos"><div class="form-text">Somente endereços HTTPS são aceitos.</div></div>
                                <div class="col-md-6"><label class="form-label" for="https-auth">Autenticação</label><select class="form-select" id="https-auth" data-field="auth_type"><option value="none">Sem autenticação</option><option value="bearer">Token Bearer</option></select></div>
                                <div class="col-md-6"><label class="form-label" for="https-token">Token Bearer</label><div class="input-group"><input class="form-control" id="https-token" data-secret-field="bearer_token" type="password" autocomplete="new-password" placeholder="Informe apenas se fornecido pelo cliente"><button class="btn btn-outline-secondary toggle-secret" type="button" data-target="https-token" aria-label="Mostrar ou ocultar token"><i class="fa fa-eye"></i></button></div><div class="form-text">O token é cifrado e não é exibido novamente após salvar.</div></div>
                            </div>
                        </div>

                        <div class="destination-fields mt-3 d-none" data-transport-group="sftp">
                            <h3 class="h6 border-bottom pb-2"><i class="fa fa-folder-open me-1"></i> Pasta segura do cliente</h3>
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label" for="sftp-protocol">Protocolo</label><select class="form-select" id="sftp-protocol" data-field="protocol"><option value="sftp">SFTP (recomendado)</option><option value="ftps">FTPS</option></select></div>
                                <div class="col-md-5"><label class="form-label" for="sftp-host">Servidor</label><input class="form-control" id="sftp-host" data-field="host" data-required placeholder="sftp.cliente.com.br"></div>
                                <div class="col-md-3"><label class="form-label" for="sftp-port">Porta</label><input class="form-control" id="sftp-port" data-field="port" type="number" min="1" max="65535" value="22" data-required></div>
                                <div class="col-md-7"><label class="form-label" for="sftp-directory">Pasta de entrega</label><input class="form-control" id="sftp-directory" data-field="remote_directory" data-required placeholder="Ex.: /entrada/laudos"></div>
                                <div class="col-md-5"><label class="form-label" for="sftp-username">Usuário</label><input class="form-control" id="sftp-username" data-field="username" data-required autocomplete="off"></div>
                                <div class="col-12"><label class="form-label" for="sftp-password">Senha ou chave privada</label><div class="input-group"><input class="form-control" id="sftp-password" data-secret-field="password" type="password" autocomplete="new-password" placeholder="Informe somente a credencial fornecida pelo cliente"><button class="btn btn-outline-secondary toggle-secret" type="button" data-target="sftp-password" aria-label="Mostrar ou ocultar senha"><i class="fa fa-eye"></i></button></div><div class="form-text">A credencial é cifrada. O conteúdo nunca é mostrado novamente.</div></div>
                            </div>
                        </div>
                        <div class="row g-3 mt-0">
                            <div class="col-md-6"><label class="form-label" for="destination-timeout">Timeout (segundos)</label><input class="form-control" id="destination-timeout" type="number" name="timeout_seconds" min="5" max="120" value="30"></div>
                            <div class="col-md-6"><label class="form-label" for="destination-attempts">Tentativas máximas</label><input class="form-control" id="destination-attempts" type="number" name="max_attempts" min="1" max="10" value="5"></div>
                        </div>
                        <div class="form-check mt-3"><input class="form-check-input" id="destination-release" type="checkbox" name="disparar_na_liberacao" value="1" checked><label class="form-check-label" for="destination-release">Criar job quando o laudo for liberado</label></div>
                        <div class="form-check mt-2"><input class="form-check-input" id="destination-enabled" type="checkbox" name="enabled" value="1"><label class="form-check-label" for="destination-enabled"><?= $escape(t('delivery_hub.destination.habilitar')) ?></label></div>
                        <div class="alert alert-warning small mt-2 mb-0 d-none" id="production-activation-confirmation">
                            <div class="form-check"><input class="form-check-input" id="destination-confirm-production-activation" type="checkbox" name="confirm_production_activation" value="1"><label class="form-check-label fw-semibold" for="destination-confirm-production-activation"><?= $escape(t('delivery_hub.destination.confirmar_producao')) ?></label></div>
                            <div class="mt-1"><?= $escape(t('delivery_hub.destination.confirmar_producao_ajuda')) ?></div>
                        </div>
                        <div class="d-flex gap-2 mt-4"><button type="submit" class="btn btn-primary">Salvar destino</button><button type="button" class="btn btn-outline-secondary d-none" id="destination-cancel">Cancelar edição</button></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center"><h2 class="h5 mb-0">Destinos configurados</h2><span class="badge text-bg-secondary"><?= count($destinations) ?></span></div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Nome</th><th>Origem e prioridade</th><th>Canal</th><th>Ambiente</th><th>Status</th><th class="text-end">Ação</th></tr></thead>
                        <tbody>
                            <?php if (!$destinations): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Nenhum destino cadastrado para este negócio.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($destinations as $destination): ?>
                                <?php $json = htmlspecialchars(json_encode($destination, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>
                                <tr>
                                    <td><strong><?= $escape($destination['nome']) ?></strong><div class="small text-muted">Timeout: <?= (int) $destination['timeout_seconds'] ?>s · <?= (int) $destination['max_attempts'] ?> tentativas</div></td>
                                    <?php $destinationInstitutions = str_replace('||', ', ', (string) ($destination['institution_names'] ?? '')); ?>
                                    <?php $destinationIssuers = str_replace('||', ', ', (string) ($destination['issuers'] ?? '')); ?>
                                    <td class="small">
                                        <?php if ($destinationIssuers !== ''): ?><div><strong>Issuer:</strong> <?= $escape($destinationIssuers) ?></div><?php endif; ?>
                                        <?php if ($destinationInstitutions !== ''): ?><div><strong>Fallback:</strong> <?= $escape($destinationInstitutions) ?></div><?php endif; ?>
                                        <?php if ($destinationIssuers === '' && $destinationInstitutions === ''): ?><span class="text-warning">Sem origem vinculada</span><?php endif; ?>
                                    </td>
                                    <td><?= $escape($transportLabels[$destination['transport']] ?? $destination['transport']) ?></td>
                                    <td><span class="badge <?= $destination['ambiente'] === 'producao' ? 'text-bg-dark' : 'text-bg-info' ?>"><?= $escape($destination['ambiente']) ?></span></td>
                                    <td><?= !empty($destination['enabled']) ? '<span class="badge text-bg-success">Habilitado</span>' : '<span class="badge text-bg-secondary">Desativado</span>' ?></td>
                                    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary edit-destination" data-destination="<?= $json ?>">Editar</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div><h2 class="h5 mb-0"><?= $escape(t('delivery_hub.released.titulo')) ?></h2><div class="small text-muted"><?= $escape(t('delivery_hub.released.ajuda_status')) ?></div></div>
                    <span class="badge text-bg-secondary"><?= $escape(sprintf(t('delivery_hub.released.exibidos'), count($deliveries))) ?></span>
                </div>
                <div class="card-body border-bottom bg-light-subtle">
                    <form method="get" action="/platform/negocios/<?= (int) $tenant['id'] ?>/report-delivery" class="row g-2 align-items-end">
                        <div class="col-md-4"><label class="form-label small mb-1" for="delivery-filter-patient"><?= $escape(t('delivery_hub.released.filtro_paciente')) ?></label><input class="form-control form-control-sm" id="delivery-filter-patient" name="patient" value="<?= $escape($deliveryFilters['patient'] ?? '') ?>" maxlength="120" placeholder="<?= $escape(t('delivery_hub.released.placeholder_paciente')) ?>"></div>
                        <div class="col-md-3"><label class="form-label small mb-1" for="delivery-filter-modality"><?= $escape(t('delivery_hub.released.filtro_modalidade')) ?></label><input class="form-control form-control-sm" id="delivery-filter-modality" name="modality" value="<?= $escape($deliveryFilters['modality'] ?? '') ?>" maxlength="64" placeholder="<?= $escape(t('delivery_hub.released.placeholder_modalidade')) ?>"></div>
                        <div class="col-md-3"><label class="form-label small mb-1" for="delivery-filter-issuer"><?= $escape(t('delivery_hub.released.filtro_issuer')) ?></label><input class="form-control form-control-sm" id="delivery-filter-issuer" name="issuer" value="<?= $escape($deliveryFilters['issuer'] ?? '') ?>" maxlength="120" placeholder="<?= $escape(t('delivery_hub.released.placeholder_issuer')) ?>"></div>
                        <div class="col-md-2 d-flex gap-2"><button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="fa fa-filter me-1"></i><?= $escape(t('delivery_hub.released.filtrar')) ?></button><a class="btn btn-sm btn-outline-secondary" href="/platform/negocios/<?= (int) $tenant['id'] ?>/report-delivery" title="<?= $escape(t('delivery_hub.released.limpar')) ?>"><?= $escape(t('delivery_hub.released.limpar')) ?></a></div>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th><?= $escape(t('delivery_hub.released.coluna_laudo')) ?></th><th><?= $escape(t('delivery_hub.released.coluna_paciente')) ?></th><th><?= $escape(t('delivery_hub.released.coluna_modalidade')) ?></th><th><?= $escape(t('delivery_hub.released.coluna_issuer')) ?></th><th><?= $escape(t('delivery_hub.released.coluna_destino_canal')) ?></th><th><?= $escape(t('delivery_hub.released.coluna_status')) ?></th><th class="text-end"><?= $escape(t('delivery_hub.released.coluna_acoes')) ?></th></tr></thead>
                        <tbody>
                            <?php if (!$deliveries): ?><tr><td colspan="7" class="text-center text-muted py-4"><?= $escape(t('delivery_hub.released.vazio')) ?></td></tr><?php endif; ?>
                            <?php foreach ($deliveries as $delivery): ?>
                                <?php
                                    $total = (int) ($delivery['jobs_total'] ?? 0);
                                    $delivered = (int) ($delivery['jobs_delivered'] ?? 0);
                                    $queued = (int) ($delivery['jobs_queued'] ?? 0);
                                    $failed = (int) ($delivery['jobs_failed'] ?? 0);
                                    $routingState = (string) ($delivery['routing_state'] ?? 'unmapped');
                                    if ($total > 0 && $delivered === $total) { $statusKey = 'delivery_hub.released.status_entregue'; $statusClass = 'success'; }
                                    elseif ($queued > 0) { $statusKey = 'delivery_hub.released.status_fila'; $statusClass = 'primary'; }
                                    elseif ($failed > 0) { $statusKey = 'delivery_hub.released.status_falha'; $statusClass = 'danger'; }
                                    elseif ($routingState === 'configured_inactive') { $statusKey = 'delivery_hub.released.status_destino_desativado'; $statusClass = 'warning'; }
                                    elseif ($routingState === 'manual_eligible') { $statusKey = 'delivery_hub.released.status_pronto_reenviar'; $statusClass = 'info'; }
                                    elseif ($routingState === 'automatic_only') { $statusKey = 'delivery_hub.released.status_automatico_liberacao'; $statusClass = 'info'; }
                                    else { $statusKey = 'delivery_hub.released.status_sem_destino'; $statusClass = 'secondary'; }
                                    $canResend = $failed > 0 && in_array($routingState, ['manual_eligible', 'automatic_only'], true);
                                    $routingDestinations = trim((string) ($delivery['routing_destinations'] ?? ''));
                                    $destinationName = (string) ($delivery['destination_name'] ?? '');
                                    $displayDestination = $destinationName !== '' ? $destinationName : $routingDestinations;
                                ?>
                                <tr>
                                    <td>#<?= (int) $delivery['report_id'] ?><div class="small text-muted"><?= $escape((string) ($delivery['liberado_em'] ?? '')) ?></div></td>
                                    <td><?= $escape($delivery['patient_name']) ?></td>
                                    <td><?= $escape($delivery['modalities'] ?: '—') ?></td>
                                    <td class="small"><?= $escape($delivery['issuer_of_patient_id'] ?: '—') ?></td>
                                    <td><?= $escape($displayDestination !== '' ? $displayDestination : '—') ?><div class="small text-muted"><?= $escape($transportLabels[$delivery['transport'] ?? ''] ?? ($delivery['transport'] ?? '')) ?></div></td>
                                    <td><span class="badge text-bg-<?= $statusClass ?>"><?= $escape(t($statusKey)) ?></span><div class="small text-muted mt-1"><?= $escape(sprintf(t('delivery_hub.released.contagem_entregue'), $delivered, $total)) ?></div></td>
                                    <td class="text-end">
                                        <?php if ($canResend): ?>
                                            <form method="post" action="/platform/negocios/<?= (int) $tenant['id'] ?>/report-delivery/reports/<?= (int) $delivery['report_id'] ?>/resend" class="d-inline delivery-resend-form">
                                                <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="confirm_resend" value="1">
                                                <button type="submit" class="btn btn-sm btn-outline-primary"><?= $escape(t('delivery_hub.released.reenviar_falha')) ?></button>
                                            </form>
                                        <?php else: ?>
                                            <?php if ($routingState === 'configured_inactive'): ?>
                                                <span class="text-muted small" title="<?= $escape(t('delivery_hub.released.acao_destino_desativado_ajuda')) ?>"><?= $escape(t('delivery_hub.released.acao_destino_desativado')) ?></span>
                                            <?php elseif ($routingState === 'automatic_only'): ?>
                                                <span class="text-muted small" title="<?= $escape(t('delivery_hub.released.acao_automatica_ajuda')) ?>"><?= $escape(t('delivery_hub.released.acao_automatica')) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="delivery-resend-feedback" class="d-none alert mt-4 mb-0" role="alert" aria-live="polite"></div>

<script>
(() => {
    const form = document.getElementById('destination-form');
    const manualForm = document.getElementById('manual-delivery-form');
    const manualFeedback = document.getElementById('manual-delivery-feedback');
    const resendFeedback = document.getElementById('delivery-resend-feedback');
    const title = document.getElementById('destination-form-title');
    const cancel = document.getElementById('destination-cancel');
    const feedback = document.getElementById('delivery-feedback');
    const transport = document.getElementById('destination-transport');
    const environment = document.getElementById('destination-environment');
    const enabled = document.getElementById('destination-enabled');
    const productionConfirmation = document.getElementById('destination-confirm-production-activation');
    const productionConfirmationBox = document.getElementById('production-activation-confirmation');
    const guide = document.getElementById('destination-guide');
    const configInput = document.getElementById('destination-config');
    const secretInput = document.getElementById('destination-secret');
    const institutionSelectors = Array.from(form.querySelectorAll('.institution-selector'));
    const issuerSelectors = Array.from(form.querySelectorAll('.issuer-selector'));
    const baseAction = form.action;
    const knownKeys = ['host', 'port', 'called_ae', 'calling_ae', 'patient_id_normalization', 'use_tls', 'sending_application', 'sending_facility', 'receiving_application', 'receiving_facility', 'url', 'auth_type', 'protocol', 'remote_directory', 'username'];
    const guideText = {
        dicom_pdf: 'Informe os dados de rede e os AE Titles fornecidos pelo administrador do PACS do cliente.',
        dicom_sr: 'Informe os dados de rede e os AE Titles fornecidos pelo administrador do PACS do cliente.',
        hl7_oru: 'Informe os dados da interface HL7/MLLP fornecidos pelo HIS ou RIS do cliente.',
        https_webhook: 'Informe a URL HTTPS fornecida pela equipe de integração do cliente.',
        sftp: 'Informe a pasta segura e as credenciais fornecidas pelo cliente. FTP simples não é aceito.',
    };
    let currentConfig = {};

    function parseConfig(raw) {
        try {
            const parsed = JSON.parse(raw || '{}');
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (_) {
            return {};
        }
    }

    function isActiveGroup(group) {
        return group.dataset.transportGroup.split(',').includes(transport.value);
    }

    function renderTransportFields() {
        document.querySelectorAll('.destination-fields').forEach((group) => {
            const active = isActiveGroup(group);
            group.classList.toggle('d-none', !active);
            group.querySelectorAll('[data-required]').forEach((input) => { input.required = active; });
        });
        guide.innerHTML = '<i class="fa fa-circle-info me-1"></i>' + (guideText[transport.value] || 'Preencha os dados fornecidos pelo cliente.');
        populateActiveFields();
    }

    function populateActiveFields() {
        document.querySelectorAll('.destination-fields').forEach((group) => {
            if (!isActiveGroup(group)) return;
            group.querySelectorAll('[data-field]').forEach((input) => {
                const value = currentConfig[input.dataset.field];
                if (value === undefined || value === null) return;
                if (input.type === 'checkbox') input.checked = Boolean(value);
                else input.value = value;
            });
        });
    }

    function setSelectedInstitutions(rawNames) {
        const selected = new Set(String(rawNames || '').split('||').filter(Boolean));
        institutionSelectors.forEach((input) => { input.checked = selected.has(input.value); });
    }

    function setSelectedIssuers(rawIssuers) {
        const selected = new Set(String(rawIssuers || '').split('||').filter(Boolean));
        issuerSelectors.forEach((input) => { input.checked = selected.has(input.value); });
    }

    function syncEnvironment() {
        const production = environment.value === 'producao';
        const confirmationRequired = production && enabled.checked;
        productionConfirmationBox.classList.toggle('d-none', !confirmationRequired);
        productionConfirmation.required = confirmationRequired;
        if (!production) productionConfirmation.checked = false;
    }

    function serializeConfiguration() {
        const config = { ...currentConfig };
        knownKeys.forEach((key) => delete config[key]);
        const secret = {};
        document.querySelectorAll('.destination-fields').forEach((group) => {
            if (!isActiveGroup(group)) return;
            group.querySelectorAll('[data-field]').forEach((input) => {
                const key = input.dataset.field;
                if (input.type === 'checkbox') config[key] = input.checked;
                else if (input.type === 'number') config[key] = Number(input.value);
                else if (input.value.trim() !== '') config[key] = input.value.trim();
            });
            group.querySelectorAll('[data-secret-field]').forEach((input) => {
                if (input.value.trim() !== '') secret[input.dataset.secretField] = input.value;
            });
        });
        configInput.value = JSON.stringify(config);
        secretInput.value = Object.keys(secret).length ? JSON.stringify(secret) : '';
    }

    function resetForm() {
        form.reset();
        currentConfig = {};
        form.action = baseAction;
        configInput.value = '{}';
        secretInput.value = '';
        setSelectedInstitutions('');
        setSelectedIssuers('');
        title.textContent = 'Novo destino';
        cancel.classList.add('d-none');
        renderTransportFields();
        syncEnvironment();
    }

    manualForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const raw = document.getElementById('manual-report-token').value.trim();
        const match = raw.match(/\/reports\/r\/([a-f0-9]{48})$/i) || raw.match(/^([a-f0-9]{48})$/i);
        if (!match) {
            manualFeedback.className = 'alert alert-danger mt-3 mb-0';
            manualFeedback.textContent = 'Informe um link /reports/r/{token} ou um token válido de 48 caracteres.';
            manualFeedback.classList.remove('d-none');
            return;
        }
        document.getElementById('manual-report-token').value = match[1].toLowerCase();
        const response = await fetch(manualForm.action, { method: 'POST', body: new FormData(manualForm), credentials: 'same-origin' });
        const result = await response.json().catch(() => ({ success: false, message: 'Resposta inválida do servidor.' }));
        manualFeedback.className = 'alert ' + (result.success ? 'alert-success' : 'alert-danger') + ' mt-3 mb-0';
        manualFeedback.textContent = result.message || 'Operação concluída.';
        manualFeedback.classList.remove('d-none');
        if (result.success) window.setTimeout(() => window.location.reload(), 1000);
    });

    document.querySelectorAll('.delivery-resend-form').forEach((resendForm) => {
        resendForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!window.confirm('<?= addslashes(t('delivery_hub.released.confirmar_reenvio_falha')) ?>')) return;
            const submitButton = resendForm.querySelector('button[type="submit"]');
            if (submitButton) submitButton.disabled = true;
            try {
                const response = await fetch(resendForm.action, { method: 'POST', body: new FormData(resendForm), credentials: 'same-origin' });
                const result = await response.json().catch(() => ({ success: false, message: '<?= addslashes(t('delivery_hub.released.resposta_invalida')) ?>' }));
                resendFeedback.className = 'alert mt-4 mb-0 ' + (result.success ? 'alert-success' : 'alert-danger');
                resendFeedback.textContent = result.message || '<?= addslashes(t('delivery_hub.released.erro_reenvio')) ?>';
                resendFeedback.classList.remove('d-none');
                if (result.success) window.setTimeout(() => window.location.reload(), 1200);
            } catch (_) {
                resendFeedback.className = 'alert mt-4 mb-0 alert-danger';
                resendFeedback.textContent = '<?= addslashes(t('delivery_hub.released.erro_reenvio')) ?>';
                resendFeedback.classList.remove('d-none');
            } finally {
                if (submitButton) submitButton.disabled = false;
            }
        });
    });

    transport.addEventListener('change', () => { currentConfig = {}; renderTransportFields(); });
    environment.addEventListener('change', syncEnvironment);
    enabled.addEventListener('change', syncEnvironment);
    document.querySelectorAll('.toggle-secret').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.target);
            input.type = input.type === 'password' ? 'text' : 'password';
            button.querySelector('i').className = input.type === 'password' ? 'fa fa-eye' : 'fa fa-eye-slash';
        });
    });

    document.querySelectorAll('.edit-destination').forEach((button) => {
        button.addEventListener('click', () => {
            const item = JSON.parse(button.dataset.destination);
            currentConfig = parseConfig(item.configuration_json);
            form.action = baseAction + '/' + encodeURIComponent(item.id);
            title.textContent = 'Editar destino: ' + item.nome;
            document.getElementById('destination-name').value = item.nome;
            transport.value = item.transport;
            environment.value = item.ambiente;
            secretInput.value = '';
            document.querySelectorAll('[data-secret-field]').forEach((input) => { input.value = ''; });
            document.getElementById('destination-timeout').value = item.timeout_seconds;
            document.getElementById('destination-attempts').value = item.max_attempts;
            document.getElementById('destination-release').checked = Number(item.disparar_na_liberacao) === 1;
            enabled.checked = Number(item.enabled) === 1;
            setSelectedInstitutions(item.institution_names);
            setSelectedIssuers(item.issuers);
            cancel.classList.remove('d-none');
            renderTransportFields();
            syncEnvironment();
            window.scrollTo({ top: form.getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth' });
        });
    });
    cancel.addEventListener('click', resetForm);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!institutionSelectors.some((input) => input.checked) && !issuerSelectors.some((input) => input.checked)) {
            feedback.className = 'alert alert-danger';
            feedback.textContent = 'Selecione ao menos um Issuer ou InstitutionName de fallback para este destino.';
            feedback.classList.remove('d-none');
            return;
        }
        if (environment.value === 'producao' && enabled.checked && !productionConfirmation.checked) {
            feedback.className = 'alert alert-danger';
            feedback.textContent = '<?= addslashes(t('delivery_hub.destination.confirmacao_producao_obrigatoria')) ?>';
            feedback.classList.remove('d-none');
            return;
        }
        serializeConfiguration();
        const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
        const result = await response.json().catch(() => ({ success: false, message: 'Resposta inválida do servidor.' }));
        feedback.className = 'alert ' + (result.success ? 'alert-success' : 'alert-danger');
        feedback.textContent = result.message || 'Operação concluída.';
        feedback.classList.remove('d-none');
        if (result.success) window.setTimeout(() => window.location.reload(), 700);
    });

    renderTransportFields();
    syncEnvironment();
})();
</script>
