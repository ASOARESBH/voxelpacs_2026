<?php
// View: Detalhe de Estudo DICOM
$orthancBase = rtrim($servidor['url'] ?? '', '/');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-0">
            <i class="fa fa-x-ray me-2 text-primary"></i>
            Estudo DICOM — <?= htmlspecialchars($estudo['patient_name_display'] ?: $estudo['patient_name'] ?: 'Paciente') ?>
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
                <i class="fa fa-external-link-alt me-1"></i> Abrir no Orthanc
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
                    'Nome'           => $estudo['patient_name_display'] ?: $estudo['patient_name'],
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
