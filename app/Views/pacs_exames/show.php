<?php
// View: Detalhe de Estudo DICOM
$orthancBase = rtrim($servidor['url'] ?? '', '/');
// A tela usa uma projeção visual segura e não regrava o PatientName.
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">
            <i class="fa fa-x-ray me-2 text-primary"></i>
            Estudo DICOM — <?= htmlspecialchars(\App\Helpers\DicomPersonName::displayFromStudy($estudo) ?: 'Paciente') ?>
        </h1>
        <small class="text-muted">
            <?= $estudo['study_date'] ? date('d/m/Y', strtotime($estudo['study_date'])) : '' ?>
            <?= $estudo['study_description'] ? ' · ' . htmlspecialchars($estudo['study_description']) : '' ?>
        </small>
    </div>
    <div class="d-flex gap-2">
        <?php if ($orthancBase && $estudo['orthanc_id']): ?>
            <a href="<?= $orthancBase ?>/app/explorer.html#study?uuid=<?= urlencode($estudo['orthanc_id']) ?>"
               target="_blank" class="btn btn-outline-primary btn-sm">
                <i class="fa fa-external-link-alt me-1"></i> Abrir no <?= htmlspecialchars(\App\Config\BrandConfig::PACS_SERVER_NAME) ?>
            </a>
        <?php endif; ?>
        <a href="/pacs/exames" class="btn btn-outline-secondary btn-sm">
            <i class="fa fa-arrow-left me-1"></i> Voltar
        </a>
    </div>
</div>

<div class="row g-3">

    <!-- COLUNA ESQUERDA: Dados do Paciente + Estudo -->
    <div class="col-lg-4">

        <!-- PACIENTE -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-primary text-white py-2">
                <h6 class="mb-0"><i class="fa fa-user me-2"></i>Paciente</h6>
            </div>
            <div class="card-body p-3">
                <?php
                $fields = [
                    'Nome'           => \App\Helpers\DicomPersonName::displayFromStudy($estudo),
                    'ID Paciente'    => $estudo['patient_id'],
                    'Data Nasc.'     => $estudo['patient_birth_date'] ? date('d/m/Y', strtotime($estudo['patient_birth_date'])) : null,
                    'Sexo'           => match(strtoupper($estudo['patient_sex'] ?? '')) {
                        'M' => 'Masculino', 'F' => 'Feminino', 'O' => 'Outro', default => null
                    },
                    'Idade'          => $estudo['patient_age'] ? preg_replace('/^0+(\d+)([YMD])$/', '$1$2', $estudo['patient_age']) : null,
                    'Peso'           => $estudo['patient_weight'] ? $estudo['patient_weight'] . ' kg' : null,
                    'Altura'         => $estudo['patient_size']   ? $estudo['patient_size']   . ' m'  : null,
                    'Espécie'        => $estudo['patient_species_desc'],
                    'Raça'           => $estudo['patient_breed_desc'],
                    'Resp. (Vet.)'   => $estudo['responsible_person'],
                ];
                foreach ($fields as $label => $val):
                    if ($val === null || $val === '') continue;
                ?>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <small class="text-muted"><?= $label ?></small>
                        <small class="fw-semibold text-end" style="max-width:60%;"><?= htmlspecialchars($val) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ESTUDO -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-info text-white py-2">
                <h6 class="mb-0"><i class="fa fa-folder-open me-2"></i>Estudo</h6>
            </div>
            <div class="card-body p-3">
                <?php
                $fields2 = [
                    'Data'           => $estudo['study_date'] ? date('d/m/Y', strtotime($estudo['study_date'])) : null,
                    'Hora'           => $estudo['study_time'] ? substr($estudo['study_time'], 0, 5) : null,
                    'Descrição'      => $estudo['study_description'],
                    'Nº de Acesso'   => $estudo['accession_number'],
                    'Study ID'       => $estudo['study_id'],
                    'Séries'         => $estudo['num_series'],
                    'Imagens'        => number_format($estudo['num_instances']),
                    'Parte do Corpo' => $estudo['body_part_examined'],
                    'Protocolo'      => $estudo['protocol_name'],
                    'Contraste'      => $estudo['contrast_bolus_agent'],
                    'Médico Solic.'  => \App\Helpers\DicomPersonName::format($estudo['referring_physician_name'] ?? null),
                    'Médico Exec.'   => \App\Helpers\DicomPersonName::format($estudo['performing_physician_name'] ?? null),
                    'Médico Laudo'   => \App\Helpers\DicomPersonName::format($estudo['name_of_physicians_reading'] ?? null),
                    'Diagnóstico'    => $estudo['admitting_diagnoses_desc'],
                    'Hist. Paciente' => $estudo['additional_patient_history'],
                    'Procedimento'   => $estudo['requested_procedure_desc'],
                    'ID Procedimento'=> $estudo['requested_procedure_id'],
                ];
                foreach ($fields2 as $label => $val):
                    if ($val === null || $val === '') continue;
                ?>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <small class="text-muted"><?= $label ?></small>
                        <small class="fw-semibold text-end" style="max-width:65%;"><?= htmlspecialchars($val) ?></small>
                    </div>
                <?php endforeach;
                if (!empty($estudo['modalities'])): ?>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <small class="text-muted">Modalidades</small>
                        <small class="text-end" style="max-width:65%;">
                            <?php foreach (array_filter(array_map('trim', explode('\\', $estudo['modalities']))) as $mod):
                                $modCod  = \App\Services\DicomModalityService::code($mod);
                                $modDesc = \App\Services\DicomModalityService::description($mod);
                            ?>
                                <span class="dicom-modality" data-bs-toggle="tooltip" data-bs-placement="top"
                                      title="<?= htmlspecialchars($modDesc) ?>"><?= htmlspecialchars($modCod) ?></span>
                            <?php endforeach; ?>
                        </small>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- COLUNA DIREITA: Equipamento + Aquisição + Séries -->
    <div class="col-lg-8">

        <!-- EQUIPAMENTO / INSTITUIÇÃO -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-secondary text-white py-2">
                <h6 class="mb-0"><i class="fa fa-hospital me-2"></i>Equipamento e Instituição</h6>
            </div>
            <div class="card-body p-3">
                <div class="row g-2">
                    <?php
                    $equip = [
                        'Instituição'        => $estudo['institution_name'],
                        'Endereço'           => $estudo['institution_address'],
                        'Departamento'       => $estudo['institutional_dept_name'],
                        'Estação'            => $estudo['station_name'],
                        'Fabricante'         => $estudo['manufacturer'],
                        'Modelo'             => $estudo['manufacturer_model_name'],
                        'Nº de Série'        => $estudo['device_serial_number'],
                        'Software'           => $estudo['software_versions'],
                        'Operador'           => \App\Helpers\DicomPersonName::format($estudo['operators_name'] ?? null),
                    ];
                    foreach ($equip as $label => $val):
                        if ($val === null || $val === '') continue;
                    ?>
                        <div class="col-md-6">
                            <div class="d-flex justify-content-between border-bottom py-1">
                                <small class="text-muted"><?= $label ?></small>
                                <small class="fw-semibold text-end" style="max-width:65%;"><?= htmlspecialchars($val) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- PARÂMETROS DE AQUISIÇÃO -->
        <?php
        $acqFields = array_filter([
            'kVp'                    => $estudo['kvp']                    ? $estudo['kvp'] . ' kV'    : null,
            'Corrente (mA)'          => $estudo['x_ray_tube_current']     ? $estudo['x_ray_tube_current'] . ' mA' : null,
            'Tempo Exposição'        => $estudo['exposure_time']          ? $estudo['exposure_time'] . ' ms' : null,
            'Exposição (mAs)'        => $estudo['exposure'],
            'CTDIvol'                => $estudo['ctdi_vol']               ? $estudo['ctdi_vol'] . ' mGy' : null,
            'Espessura de Corte'     => $estudo['slice_thickness']        ? $estudo['slice_thickness'] . ' mm' : null,
            'Nº de Cortes'           => $estudo['number_of_slices'],
            'Kernel Convolução'      => $estudo['convolution_kernel'],
            'Pitch Espiral'          => $estudo['spiral_pitch_factor'],
            'Diâm. Reconstrução'     => $estudo['reconstruction_diameter'] ? $estudo['reconstruction_diameter'] . ' mm' : null,
            'TR (ms)'                => $estudo['repetition_time'],
            'TE (ms)'                => $estudo['echo_time'],
            'TI (ms)'                => $estudo['inversion_time'],
            'Flip Angle'             => $estudo['flip_angle']             ? $estudo['flip_angle'] . '°' : null,
            'Campo Magnético'        => $estudo['magnetic_field_strength'] ? $estudo['magnetic_field_strength'] . ' T' : null,
            'SAR'                    => $estudo['sar']                    ? $estudo['sar'] . ' W/kg' : null,
            'Bobina Recepção'        => $estudo['receive_coil_name'],
            'Bobina Transmissão'     => $estudo['transmit_coil_name'],
            'Valor-b Difusão'        => $estudo['diffusion_b_value'],
            'Pixel Spacing'          => $estudo['pixel_spacing'],
            'Linhas × Colunas'       => ($estudo['rows'] && $estudo['columns']) ? $estudo['rows'] . ' × ' . $estudo['columns'] : null,
            'Bits Alocados'          => $estudo['bits_allocated'],
            'Fotométrico'            => $estudo['photometric_interpretation'],
        ], fn($v) => $v !== null && $v !== '');
        ?>
        <?php if (!empty($acqFields)): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-warning text-dark py-2">
                <h6 class="mb-0"><i class="fa fa-sliders-h me-2"></i>Parâmetros de Aquisição</h6>
            </div>
            <div class="card-body p-3">
                <div class="row g-1">
                    <?php foreach ($acqFields as $label => $val): ?>
                        <div class="col-md-4">
                            <div class="d-flex justify-content-between border-bottom py-1">
                                <small class="text-muted"><?= $label ?></small>
                                <small class="fw-semibold"><?= htmlspecialchars($val) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- SÉRIES -->
        <?php if (!empty($series)): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-dark text-white py-2">
                <h6 class="mb-0"><i class="fa fa-layer-group me-2"></i>Séries (<?= count($series) ?>)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Descrição</th>
                                <th>Modalidade</th>
                                <th class="text-center">Imagens</th>
                                <th>Estação</th>
                                <th>Data/Hora</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($series as $i => $serie): ?>
                                <?php
                                $sTags = $serie['MainDicomTags'] ?? [];
                                $sDate = $sTags['SeriesDate'] ?? '';
                                $sTime = $sTags['SeriesTime'] ?? '';
                                if (strlen($sDate) === 8) {
                                    $sDate = substr($sDate,6,2).'/'.substr($sDate,4,2).'/'.substr($sDate,0,4);
                                }
                                if (strlen($sTime) >= 6) {
                                    $sTime = substr($sTime,0,2).':'.substr($sTime,2,2);
                                }
                                ?>
                                <tr>
                                    <td class="text-muted small"><?= $i + 1 ?></td>
                                    <td class="small"><?= htmlspecialchars($sTags['SeriesDescription'] ?? '—') ?></td>
                                    <td><span class="badge bg-primary"><?= htmlspecialchars($sTags['Modality'] ?? '—') ?></span></td>
                                    <td class="text-center"><span class="badge bg-secondary"><?= count($serie['Instances'] ?? []) ?></span></td>
                                    <td class="small text-muted"><?= htmlspecialchars($sTags['StationName'] ?? '—') ?></td>
                                    <td class="small text-muted"><?= $sDate ?> <?= $sTime ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- UIDs TÉCNICOS -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-light py-2">
                <h6 class="mb-0 text-muted"><i class="fa fa-fingerprint me-2"></i>Identificadores Técnicos</h6>
            </div>
            <div class="card-body p-3">
                <?php
                $uids = [
                    'StudyInstanceUID' => $estudo['study_instance_uid'],
                    'Orthanc ID'       => $estudo['orthanc_id'],
                    'CharacterSet'     => $estudo['specific_character_set'],
                    'Importado em'     => $estudo['importado_em'],
                    'Atualizado em'    => $estudo['atualizado_em'],
                    'Último Update PACS' => $estudo['last_update_orthanc'],
                    'Estável no PACS'  => $estudo['is_stable'] ? 'Sim' : 'Não',
                ];
                foreach ($uids as $label => $val):
                    if ($val === null || $val === '') continue;
                ?>
                    <div class="d-flex justify-content-between border-bottom py-1">
                        <small class="text-muted"><?= $label ?></small>
                        <small class="fw-semibold text-break text-end" style="max-width:75%;font-family:monospace;font-size:.75rem;"><?= htmlspecialchars($val) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</div>

<?php
// ─── PAINEL VOXEL COPILOT ─────────────────────────────────────────────────
// Exibe o status do laudo no Copilot com acompanhamento em tempo real.
// Requer as colunas copilot_status, copilot_enviado_em, copilot_laudo_em
// adicionadas pela migration 2026-08-01_pacs_estudos_copilot_status.sql
$copilotStatus    = $estudo['copilot_status']      ?? 'nenhum';
$copilotEnviadoEm = $estudo['copilot_enviado_em']  ?? null;
$copilotLaudoEm   = $estudo['copilot_laudo_em']    ?? null;
$copilotMedico    = $estudo['copilot_medico_nome'] ?? null;

$statusLabel = [
    'nenhum'          => ['label' => 'Não enviado ao Copilot', 'color' => 'secondary', 'icon' => 'fa-circle'],
    'enviado_copilot' => ['label' => 'Aguardando laudo',       'color' => 'warning',   'icon' => 'fa-clock'],
    'em_laudo'        => ['label' => 'Em laudo (viewer aberto)','color' => 'info',      'icon' => 'fa-pen'],
    'rascunho'        => ['label' => 'Rascunho salvo',         'color' => 'primary',   'icon' => 'fa-file-alt'],
    'assinado'        => ['label' => 'Laudo assinado',         'color' => 'success',   'icon' => 'fa-check-circle'],
    'erro'            => ['label' => 'Erro na integração',     'color' => 'danger',    'icon' => 'fa-exclamation-circle'],
];
$info = $statusLabel[$copilotStatus] ?? $statusLabel['nenhum'];
?>

<!-- PAINEL VOXEL COPILOT -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm" id="copilot-painel">
            <div class="card-header py-2 d-flex align-items-center justify-content-between"
                 style="background: linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff;">
                <h6 class="mb-0">
                    <i class="fa fa-robot me-2"></i>VOXEL Copilot — Acompanhamento do Laudo
                </h6>
                <div class="d-flex align-items-center gap-2">
                    <span id="copilot-last-update" class="small opacity-75">
                        Atualizado: <?= date('H:i:s') ?>
                    </span>
                    <button class="btn btn-sm btn-light btn-outline-light" onclick="copilotRefresh()" title="Atualizar status">
                        <i class="fa fa-sync-alt" id="copilot-refresh-icon"></i> Atualizar
                    </button>
                </div>
            </div>
            <div class="card-body p-3" id="copilot-status-body">

                <!-- TIMELINE DE STATUS -->
                <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
                    <?php
                    $steps = [
                        'nenhum'          => ['Não enviado',   'fa-circle',          'secondary'],
                        'enviado_copilot' => ['Assumido',      'fa-user-check',      'warning'],
                        'em_laudo'        => ['Em laudo',      'fa-eye',             'info'],
                        'rascunho'        => ['Rascunho',      'fa-file-medical-alt','primary'],
                        'assinado'        => ['Assinado',      'fa-check-circle',    'success'],
                    ];
                    $stepOrder = array_keys($steps);
                    $currentIdx = array_search($copilotStatus, $stepOrder);
                    if ($currentIdx === false) $currentIdx = 0;
                    foreach ($steps as $stepKey => $stepInfo):
                        $idx = array_search($stepKey, $stepOrder);
                        $done    = $idx <= $currentIdx && $copilotStatus !== 'nenhum';
                        $current = ($stepKey === $copilotStatus);
                        $color   = $done ? $stepInfo[2] : 'light';
                        $textC   = $done ? 'white' : 'muted';
                    ?>
                    <div class="d-flex align-items-center <?= $idx < count($steps)-1 ? 'flex-grow-1' : '' ?>">
                        <div class="d-flex flex-column align-items-center">
                            <div class="rounded-circle d-flex align-items-center justify-content-center
                                        bg-<?= $color ?> text-<?= $textC ?> <?= $current ? 'shadow' : '' ?>"
                                 style="width:38px;height:38px;border:2px solid <?= $done ? 'transparent' : '#dee2e6' ?>;
                                        <?= $current ? 'box-shadow:0 0 0 3px rgba(99,102,241,.3)!important;' : '' ?>">
                                <i class="fa <?= $stepInfo[1] ?>"></i>
                            </div>
                            <small class="mt-1 text-<?= $done ? $stepInfo[2] : 'muted' ?> fw-<?= $current ? 'bold' : 'normal' ?>"
                                   style="font-size:.7rem;white-space:nowrap;"><?= $stepInfo[0] ?></small>
                        </div>
                        <?php if ($idx < count($steps)-1): ?>
                        <div class="flex-grow-1 mx-1" style="height:2px;background:<?= $idx < $currentIdx ? '#6366f1' : '#dee2e6' ?>;"></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- STATUS ATUAL + DETALHES -->
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-light text-center">
                            <div class="mb-1">
                                <span class="badge bg-<?= $info['color'] ?> fs-6 px-3 py-2">
                                    <i class="fa <?= $info['icon'] ?> me-1"></i>
                                    <?= $info['label'] ?>
                                </span>
                            </div>
                            <small class="text-muted">Status atual no Copilot</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-light">
                            <div class="d-flex justify-content-between border-bottom pb-1 mb-1">
                                <small class="text-muted">Enviado ao Copilot</small>
                                <small class="fw-semibold"><?= $copilotEnviadoEm ? date('d/m H:i', strtotime($copilotEnviadoEm)) : '—' ?></small>
                            </div>
                            <div class="d-flex justify-content-between border-bottom pb-1 mb-1">
                                <small class="text-muted">Laudo finalizado</small>
                                <small class="fw-semibold"><?= $copilotLaudoEm ? date('d/m H:i', strtotime($copilotLaudoEm)) : '—' ?></small>
                            </div>
                            <div class="d-flex justify-content-between">
                                <small class="text-muted">Médico Copilot</small>
                                <small class="fw-semibold"><?= $copilotMedico ? htmlspecialchars($copilotMedico) : '—' ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-light">
                            <?php if ($copilotStatus === 'nenhum'): ?>
                                <p class="small text-muted mb-0">
                                    <i class="fa fa-info-circle me-1 text-info"></i>
                                    Este estudo ainda não foi assumido por um médico vinculado ao Copilot.
                                </p>
                            <?php elseif ($copilotStatus === 'assinado'): ?>
                                <p class="small text-success mb-0">
                                    <i class="fa fa-check-circle me-1"></i>
                                    Laudo finalizado e recebido do Copilot com sucesso.
                                </p>
                            <?php elseif ($copilotStatus === 'erro'): ?>
                                <p class="small text-danger mb-0">
                                    <i class="fa fa-exclamation-triangle me-1"></i>
                                    Falha na comunicação com o Copilot. Verifique os logs em
                                    <code>storage/logs/copilot-<?= date('Y-m-d') ?>.log</code>
                                </p>
                            <?php else: ?>
                                <p class="small text-muted mb-0">
                                    <i class="fa fa-sync-alt fa-spin me-1 text-primary"></i>
                                    Laudo em andamento no Copilot. Esta página atualiza automaticamente.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- LOG DE SINCRONIZAÇÃO (últimas 5 entradas) -->
                <?php
                try {
                    $db = \App\Core\Database::getInstance();
                    $logStmt = $db->prepare("
                        SELECT evento, direcao, status, http_status, created_at, erro_msg
                        FROM bi_copilot_sync_log
                        WHERE estudo_id = :eid
                        ORDER BY created_at DESC
                        LIMIT 5
                    ");
                    $logStmt->execute(['eid' => $estudo['id']]);
                    $syncLogs = $logStmt->fetchAll(\PDO::FETCH_OBJ);
                } catch (\Throwable $e) {
                    $syncLogs = [];
                }
                if (!empty($syncLogs)): ?>
                <div class="mt-3">
                    <h6 class="text-muted small mb-2"><i class="fa fa-history me-1"></i>Histórico de Sincronização</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="small">Evento</th>
                                    <th class="small">Direção</th>
                                    <th class="small">Status</th>
                                    <th class="small">HTTP</th>
                                    <th class="small">Data/Hora</th>
                                    <th class="small">Erro</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($syncLogs as $log): ?>
                                <tr>
                                    <td><code class="small"><?= htmlspecialchars($log->evento) ?></code></td>
                                    <td><small class="text-muted"><?= $log->direcao === 'pacs_para_copilot' ? '→ Copilot' : '← PACS' ?></small></td>
                                    <td>
                                        <span class="badge bg-<?= $log->status === 'sucesso' ? 'success' : 'danger' ?> small">
                                            <?= $log->status ?>
                                        </span>
                                    </td>
                                    <td><small><?= $log->http_status ?: '—' ?></small></td>
                                    <td><small><?= date('d/m H:i:s', strtotime($log->created_at)) ?></small></td>
                                    <td><small class="text-danger"><?= $log->erro_msg ? htmlspecialchars(substr($log->erro_msg, 0, 40)) . '...' : '—' ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- /.card-body -->
        </div><!-- /.card -->
    </div>
</div>

<script>
// Auto-refresh do painel Copilot a cada 60 segundos
let copilotTimer = null;
const copilotEstudoId = <?= (int)($estudo['id'] ?? 0) ?>;

function copilotRefresh() {
    const icon = document.getElementById('copilot-refresh-icon');
    if (icon) { icon.classList.add('fa-spin'); }

    fetch('/api/pacs/estudo-copilot-status?estudo_id=' + copilotEstudoId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok && data.html) {
            document.getElementById('copilot-status-body').innerHTML = data.html;
        }
        const ts = document.getElementById('copilot-last-update');
        if (ts) {
            const now = new Date();
            ts.textContent = 'Atualizado: ' + now.toLocaleTimeString('pt-BR');
        }
    })
    .catch(() => {})
    .finally(() => {
        if (icon) { icon.classList.remove('fa-spin'); }
    });
}

// Inicia o auto-refresh
copilotTimer = setInterval(copilotRefresh, 60000);

// Limpa ao sair da página
window.addEventListener('beforeunload', () => clearInterval(copilotTimer));
</script>
