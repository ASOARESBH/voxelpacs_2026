<?php
namespace App\Controllers\Platform;

use App\Core\Controller;
use App\Core\Database;
use App\Services\OrthancService;

/**
 * ServidorPacsController — Gerenciamento do servidor PACS global (Orthanc)
 *
 * Responsabilidades:
 *  - Configurar e testar a conexão com o Orthanc
 *  - Sincronizar estudos DICOM para o banco local
 *  - Gerenciar roteamento InstitutionName → Negócio
 *  - Exibir estudos importados com filtros
 */
class ServidorPacsController extends Controller
{
    // ----------------------------------------------------------------
    // DASHBOARD DO SERVIDOR
    // ----------------------------------------------------------------

    public function index(): void
    {
        $pdo = Database::getInstance();

        $servidor = $this->getServidor($pdo);

        $totalEstudos     = 0;
        $naoRoteados      = 0;
        $totalRoteados    = 0;
        $roteamentos      = [];
        $ultimoSync       = null;
        $institutionStats = [];

        try {
            $totalEstudos  = (int)$pdo->query("SELECT COUNT(*) FROM bi_pacs_estudos WHERE servidor_id = 1")->fetchColumn();
            $naoRoteados   = (int)$pdo->query("SELECT COUNT(*) FROM bi_pacs_estudos WHERE servidor_id = 1 AND tenant_id IS NULL")->fetchColumn();
            $totalRoteados = $totalEstudos - $naoRoteados;

            $roteamentos = $pdo->query("
                SELECT r.*, t.nome as negocio_nome, t.slug as negocio_slug,
                       COUNT(e.id) as total_estudos
                FROM bi_pacs_roteamento r
                JOIN bi_tenants t ON t.id = r.tenant_id
                LEFT JOIN bi_pacs_estudos e ON e.servidor_id = r.servidor_id
                    AND e.institution_name = r.institution_name
                WHERE r.servidor_id = 1
                GROUP BY r.id
                ORDER BY r.institution_name
            ")->fetchAll(\PDO::FETCH_ASSOC);

            $ultimoSync = $pdo->query("
                SELECT * FROM bi_pacs_sync_log WHERE servidor_id = 1 ORDER BY id DESC LIMIT 1
            ")->fetch(\PDO::FETCH_ASSOC);

            $institutionStats = $pdo->query("
                SELECT institution_name, COUNT(*) as total, tenant_id,
                       MAX(study_date) as ultimo_exame
                FROM bi_pacs_estudos
                WHERE servidor_id = 1
                GROUP BY institution_name, tenant_id
                ORDER BY total DESC
            ")->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\Exception $e) {
            error_log("[PACS] Erro ao carregar dashboard: " . $e->getMessage());
        }

        $this->view('platform/servidor_pacs/index', compact(
            'servidor', 'totalEstudos', 'naoRoteados', 'totalRoteados',
            'roteamentos', 'ultimoSync', 'institutionStats'
        ), 'platform');
    }

    // ----------------------------------------------------------------
    // CONFIGURAÇÃO DO SERVIDOR
    // ----------------------------------------------------------------

    public function configurar(): void
    {
        $pdo      = Database::getInstance();
        $servidor = $this->getServidor($pdo);
        $negocios = $pdo->query("SELECT id, nome, slug FROM bi_tenants WHERE status != 'cancelado' ORDER BY nome")
                        ->fetchAll(\PDO::FETCH_ASSOC);

        $this->view('platform/servidor_pacs/configurar', compact('servidor', 'negocios'), 'platform');
    }

    public function salvarConfig(): void
    {
        $pdo = Database::getInstance();

        $url     = rtrim(trim($_POST['url'] ?? ''), '/');
        $nome    = trim($_POST['nome'] ?? 'Orthanc Principal');
        $user    = trim($_POST['usuario'] ?? '') ?: null;
        $senha   = trim($_POST['senha'] ?? '') ?: null;
        $timeout = max(5, min(120, (int)($_POST['timeout'] ?? 30)));

        if (empty($url)) {
            $_SESSION['error'] = 'A URL do servidor é obrigatória.';
            $this->redirect('/platform/servidor-pacs/configurar');
        }

        try {
            $existe = $pdo->query("SELECT id FROM bi_pacs_servidor WHERE id = 1")->fetchColumn();

            if ($existe) {
                if ($senha !== null) {
                    $pdo->prepare("
                        UPDATE bi_pacs_servidor
                        SET nome=?, url=?, usuario=?, senha=?, timeout=?, ativo=1, updated_at=NOW()
                        WHERE id = 1
                    ")->execute([$nome, $url, $user, $senha, $timeout]);
                } else {
                    $pdo->prepare("
                        UPDATE bi_pacs_servidor
                        SET nome=?, url=?, usuario=?, timeout=?, ativo=1, updated_at=NOW()
                        WHERE id = 1
                    ")->execute([$nome, $url, $user, $timeout]);
                }
            } else {
                $pdo->prepare("
                    INSERT INTO bi_pacs_servidor (id, nome, url, usuario, senha, timeout, ativo)
                    VALUES (1, ?, ?, ?, ?, ?, 1)
                ")->execute([$nome, $url, $user, $senha, $timeout]);
            }

            error_log("[PACS] Config salva: url=$url, usuario=$user, timeout=$timeout");
            $_SESSION['success'] = 'Configurações do servidor PACS salvas com sucesso.';
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao salvar config: " . $e->getMessage());
            $_SESSION['error'] = 'Erro ao salvar configurações: ' . $e->getMessage();
        }

        $this->redirect('/platform/servidor-pacs');
    }

    // ----------------------------------------------------------------
    // TESTAR CONEXÃO (AJAX / POST)
    // ----------------------------------------------------------------

    public function testar(): void
    {
        @set_time_limit(120);
        @ini_set('display_errors', '0');
        header('Content-Type: application/json; charset=utf-8');
        $pdo      = Database::getInstance();
        $servidor = $this->getServidor($pdo);

        if (!$servidor) {
            echo json_encode(['success' => false, 'message' => 'Servidor não configurado.']);
            return;
        }

        $orthanc = new OrthancService(
            $servidor['url'],
            $servidor['usuario'] ?? null,
            $servidor['senha']   ?? null,
            $servidor['timeout'] ?? 30
        );

        $ping = $orthanc->ping();

        if ($ping['success']) {
            $data    = $ping['data'];
            $version = $data['Version']   ?? 'Desconhecida';
            $name    = $data['Name']      ?? 'Orthanc';
            $aet     = $data['DicomAet']  ?? '';
            $port    = $data['DicomPort'] ?? 4242;

            $stats     = $orthanc->getStatistics();
            $statsData = $stats['success'] ? $stats['data'] : [];

            try {
                $pdo->prepare("
                    UPDATE bi_pacs_servidor
                    SET status_ping='online', ultimo_ping=NOW(), versao=?, dicom_aet=?, dicom_port=?,
                        total_estudos=?, total_pacientes=?, total_series=?, total_instancias=?, disk_size_mb=?
                    WHERE id = 1
                ")->execute([
                    $version, $aet, $port,
                    $statsData['CountStudies']    ?? 0,
                    $statsData['CountPatients']   ?? 0,
                    $statsData['CountSeries']     ?? 0,
                    $statsData['CountInstances']  ?? 0,
                    $statsData['TotalDiskSizeMB'] ?? 0,
                ]);
            } catch (\Exception $e) {
                error_log("[PACS] Erro ao atualizar status ping: " . $e->getMessage());
            }

            echo json_encode([
                'success'  => true,
                'message'  => "Conexão bem-sucedida! {$name} v{$version} | AETitle: {$aet}:{$port}",
                'version'  => $version,
                'aet'      => $aet,
                'port'     => $port,
                'studies'  => $statsData['CountStudies']    ?? 0,
                'patients' => $statsData['CountPatients']   ?? 0,
                'disk_mb'  => $statsData['TotalDiskSizeMB'] ?? 0,
            ]);
        } else {
            try {
                $pdo->prepare("
                    UPDATE bi_pacs_servidor SET status_ping='erro', ultimo_ping=NOW(), observacoes=? WHERE id = 1
                ")->execute([$ping['error']]);
            } catch (\Exception $e) {}

            echo json_encode(['success' => false, 'message' => 'Falha na conexão: ' . $ping['error']]);
        }
    }

    // ----------------------------------------------------------------
    // SINCRONIZAÇÃO DE ESTUDOS
    // ----------------------------------------------------------------

    public function sincronizar(): void
    {
        // Aumenta o limite de execução para sincronizações longas
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');

        header('Content-Type: application/json; charset=utf-8');
        // Garante que erros PHP não quebrem o JSON
        @ini_set('display_errors', '0');

        $pdo      = Database::getInstance();
        $servidor = $this->getServidor($pdo);

        if (!$servidor) {
            echo json_encode(['success' => false, 'message' => 'Servidor não configurado.']);
            return;
        }

        $logId = null;
        try {
            $pdo->prepare("
                INSERT INTO bi_pacs_sync_log (servidor_id, iniciado_em, status) VALUES (1, NOW(), 'em_andamento')
            ")->execute();
            $logId = $pdo->lastInsertId();
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao criar log de sync: " . $e->getMessage());
        }

        $orthanc = new OrthancService(
            $servidor['url'],
            $servidor['usuario'] ?? null,
            $servidor['senha']   ?? null,
            $servidor['timeout'] ?? 30
        );

        $novos       = 0;
        $atualizados = 0;
        $roteados    = 0;
        $erros       = 0;

        try {
            $studies = $orthanc->importAllStudies(100);

            // Mapa de roteamento: institution_name (lowercase) → tenant_id
            $roteamentosMap = [];
            try {
                $rows = $pdo->query("
                    SELECT institution_name, tenant_id FROM bi_pacs_roteamento
                    WHERE servidor_id = 1 AND ativo = 1
                ")->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $roteamentosMap[strtolower(trim($r['institution_name']))] = $r['tenant_id'];
                }
            } catch (\Exception $e) {
                error_log("[PACS] Erro ao carregar roteamentos: " . $e->getMessage());
            }

            foreach ($studies as $study) {
                try {
                    $instKey  = strtolower(trim($study['institution_name'] ?? ''));
                    $tenantId = $roteamentosMap[$instKey] ?? null;
                    if ($tenantId) $roteados++;

                    // Previne duplicatas — verifica se já existe
                    $existeStmt = $pdo->prepare("SELECT id FROM bi_pacs_estudos WHERE orthanc_id = ?");
                    $existeStmt->execute([$study['orthanc_id']]);
                    $existeId = $existeStmt->fetchColumn();

                    // Monta array de colunas dinâmico — só grava TAGs que vieram preenchidas
                    $cols = [
                        'tenant_id'                     => $tenantId,
                        'orthanc_parent_patient'        => $study['orthanc_parent_patient']        ?? null,
                        'is_stable'                     => $study['is_stable']                     ?? 0,
                        'last_update_orthanc'           => $study['last_update_orthanc']           ?? null,
                        'tags_raw'                      => $study['tags_raw']                      ?? null,
                        // Patient
                        'patient_id'                    => $study['patient_id']                    ?? null,
                        'patient_name'                  => $study['patient_name']                  ?? null,
                        'patient_name_display'          => $study['patient_name_display']          ?? null,
                        'patient_birth_date'            => $study['patient_birth_date']            ?? null,
                        'patient_sex'                   => $study['patient_sex']                   ?? null,
                        'patient_age'                   => $study['patient_age']                   ?? null,
                        'patient_weight'                => $study['patient_weight']                ?? null,
                        'patient_size'                  => $study['patient_size']                  ?? null,
                        'patient_comments'              => $study['patient_comments']              ?? null,
                        'patient_identity_removed'      => $study['patient_identity_removed']      ?? null,
                        'responsible_person'            => $study['responsible_person']            ?? null,
                        'responsible_organization'      => $study['responsible_organization']      ?? null,
                        'patient_species_desc'          => $study['patient_species_desc']          ?? null,
                        'patient_breed_desc'            => $study['patient_breed_desc']            ?? null,
                        // Study
                        'study_instance_uid'            => $study['study_instance_uid']            ?? null,
                        'study_date'                    => $study['study_date']                    ?? null,
                        'study_time'                    => $study['study_time']                    ?? null,
                        'study_description'             => $study['study_description']             ?? null,
                        'accession_number'              => $study['accession_number']              ?? null,
                        'study_id'                      => $study['study_id']                      ?? null,
                        'referring_physician_name'      => $study['referring_physician_name']      ?? null,
                        'name_of_physicians_reading'    => $study['name_of_physicians_reading']    ?? null,
                        'admitting_diagnoses_desc'      => $study['admitting_diagnoses_desc']      ?? null,
                        'additional_patient_history'    => $study['additional_patient_history']    ?? null,
                        'requested_procedure_desc'      => $study['requested_procedure_desc']      ?? null,
                        'requested_procedure_id'        => $study['requested_procedure_id']        ?? null,
                        'scheduled_procedure_step_id'   => $study['scheduled_procedure_step_id']   ?? null,
                        // Equipment
                        'institution_name'              => $study['institution_name']              ?? null,
                        'institution_address'           => $study['institution_address']           ?? null,
                        'institutional_dept_name'       => $study['institutional_dept_name']       ?? null,
                        'station_name'                  => $study['station_name']                  ?? null,
                        'manufacturer'                  => $study['manufacturer']                  ?? null,
                        'manufacturer_model_name'       => $study['manufacturer_model_name']       ?? null,
                        'device_serial_number'          => $study['device_serial_number']          ?? null,
                        'software_versions'             => $study['software_versions']             ?? null,
                        'operators_name'                => $study['operators_name']                ?? null,
                        'performing_physician_name'     => $study['performing_physician_name']     ?? null,
                        // Series
                        'modalities'                    => $study['modalities']                    ?? null,
                        'num_series'                    => $study['num_series']                    ?? 0,
                        'num_instances'                 => $study['num_instances']                 ?? 0,
                        // SOP
                        'specific_character_set'        => $study['specific_character_set']        ?? null,
                        // Acquisition
                        'body_part_examined'            => $study['body_part_examined']            ?? null,
                        'protocol_name'                 => $study['protocol_name']                 ?? null,
                        'contrast_bolus_agent'          => $study['contrast_bolus_agent']          ?? null,
                        'scanning_sequence'             => $study['scanning_sequence']             ?? null,
                        'sequence_variant'              => $study['sequence_variant']              ?? null,
                        'scan_options'                  => $study['scan_options']                  ?? null,
                        'mr_acquisition_type'           => $study['mr_acquisition_type']           ?? null,
                        'slice_thickness'               => $study['slice_thickness']               ?? null,
                        'kvp'                           => $study['kvp']                           ?? null,
                        'exposure_time'                 => $study['exposure_time']                 ?? null,
                        'x_ray_tube_current'            => $study['x_ray_tube_current']            ?? null,
                        'exposure'                      => $study['exposure']                      ?? null,
                        'exposure_in_uas'               => $study['exposure_in_uas']               ?? null,
                        'distance_source_to_detector'   => $study['distance_source_to_detector']   ?? null,
                        'distance_source_to_patient'    => $study['distance_source_to_patient']    ?? null,
                        'field_of_view_dimensions'      => $study['field_of_view_dimensions']      ?? null,
                        'pixel_spacing'                 => $study['pixel_spacing']                 ?? null,
                        'rows'                          => $study['rows']                          ?? null,
                        'columns'                       => $study['columns']                       ?? null,
                        'bits_allocated'                => $study['bits_allocated']                ?? null,
                        'bits_stored'                   => $study['bits_stored']                   ?? null,
                        'photometric_interpretation'    => $study['photometric_interpretation']    ?? null,
                        'samples_per_pixel'             => $study['samples_per_pixel']             ?? null,
                        'window_center'                 => $study['window_center']                 ?? null,
                        'window_width'                  => $study['window_width']                  ?? null,
                        'rescale_intercept'             => $study['rescale_intercept']             ?? null,
                        'rescale_slope'                 => $study['rescale_slope']                 ?? null,
                        // CT
                        'reconstruction_diameter'       => $study['reconstruction_diameter']       ?? null,
                        'convolution_kernel'            => $study['convolution_kernel']            ?? null,
                        'gantry_detector_tilt'          => $study['gantry_detector_tilt']          ?? null,
                        'table_height'                  => $study['table_height']                  ?? null,
                        'rotation_direction'            => $study['rotation_direction']            ?? null,
                        'spiral_pitch_factor'           => $study['spiral_pitch_factor']           ?? null,
                        'ctdi_vol'                      => $study['ctdi_vol']                      ?? null,
                        'data_collection_diameter'      => $study['data_collection_diameter']      ?? null,
                        'number_of_slices'              => $study['number_of_slices']              ?? null,
                        // MR
                        'repetition_time'               => $study['repetition_time']               ?? null,
                        'echo_time'                     => $study['echo_time']                     ?? null,
                        'inversion_time'                => $study['inversion_time']                ?? null,
                        'echo_train_length'             => $study['echo_train_length']             ?? null,
                        'flip_angle'                    => $study['flip_angle']                    ?? null,
                        'sar'                           => $study['sar']                           ?? null,
                        'magnetic_field_strength'       => $study['magnetic_field_strength']       ?? null,
                        'imaging_frequency'             => $study['imaging_frequency']             ?? null,
                        'imaged_nucleus'                => $study['imaged_nucleus']                ?? null,
                        'number_of_averages'            => $study['number_of_averages']            ?? null,
                        'percent_sampling'              => $study['percent_sampling']              ?? null,
                        'percent_phase_field_of_view'   => $study['percent_phase_field_of_view']   ?? null,
                        'receive_coil_name'             => $study['receive_coil_name']             ?? null,
                        'transmit_coil_name'            => $study['transmit_coil_name']            ?? null,
                        'in_plane_phase_encoding_direction' => $study['in_plane_phase_encoding_direction'] ?? null,
                        'diffusion_b_value'             => $study['diffusion_b_value']             ?? null,
                        // US
                        'mechanical_index'              => $study['mechanical_index']              ?? null,
                        'bone_thermal_index'            => $study['bone_thermal_index']            ?? null,
                        'cranial_thermal_index'         => $study['cranial_thermal_index']         ?? null,
                        'soft_tissue_thermal_index'     => $study['soft_tissue_thermal_index']     ?? null,
                        // NM/PET
                        'radiopharmaceutical'           => $study['radiopharmaceutical']           ?? null,
                        'radionuclide_total_dose'       => $study['radionuclide_total_dose']       ?? null,
                        'radionuclide_half_life'        => $study['radionuclide_half_life']        ?? null,
                        'radiopharmaceutical_start_time'=> $study['radiopharmaceutical_start_time']?? null,
                        // Dose
                        'entrance_dose_in_mgy'          => $study['entrance_dose_in_mgy']          ?? null,
                        'dose_area_product'             => $study['dose_area_product']             ?? null,
                        // Workflow
                        'placer_order_number'           => $study['placer_order_number']           ?? null,
                        'filler_order_number'           => $study['filler_order_number']           ?? null,
                        'reason_for_requested_procedure'=> $study['reason_for_requested_procedure']?? null,
                        'current_patient_location'      => $study['current_patient_location']      ?? null,
                        'patient_state'                 => $study['patient_state']                 ?? null,
                        'admission_id'                  => $study['admission_id']                  ?? null,
                    ];

                    if ($existeId) {
                        // UPDATE dinâmico
                        $sets   = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($cols)));
                        $vals   = array_values($cols);
                        $vals[] = $existeId;
                        $pdo->prepare("UPDATE bi_pacs_estudos SET $sets, atualizado_em=NOW() WHERE id=?")
                            ->execute($vals);
                        $atualizados++;
                    } else {
                        // INSERT dinâmico
                        $colNames = '`servidor_id`, ' . implode(', ', array_map(fn($c) => "`$c`", array_keys($cols))) . ', `orthanc_id`';
                        $placeholders = '1, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?';
                        $vals = array_values($cols);
                        $vals[] = $study['orthanc_id'];
                        $pdo->prepare("INSERT INTO bi_pacs_estudos ($colNames) VALUES ($placeholders)")
                            ->execute($vals);
                        $novos++;
                    }
                } catch (\Exception $e) {
                    $erros++;
                    error_log("[PACS] Erro ao importar estudo {$study['orthanc_id']}: " . $e->getMessage());
                }
            }

            $mensagem = "Sincronização concluída: {$novos} novos, {$atualizados} atualizados, {$roteados} roteados, {$erros} erros.";
            error_log("[PACS] $mensagem");

            if ($logId) {
                try {
                    $pdo->prepare("
                        UPDATE bi_pacs_sync_log SET
                            finalizado_em=NOW(), status='concluido',
                            estudos_novos=?, estudos_atualizados=?, estudos_roteados=?, erros=?, mensagem=?
                        WHERE id=?
                    ")->execute([$novos, $atualizados, $roteados, $erros, $mensagem, $logId]);
                } catch (\Exception $e) {}
            }

            echo json_encode([
                'success'     => true,
                'message'     => $mensagem,
                'novos'       => $novos,
                'atualizados' => $atualizados,
                'roteados'    => $roteados,
                'erros'       => $erros,
            ]);

        } catch (\Exception $e) {
            error_log("[PACS] Erro crítico na sincronização: " . $e->getMessage());

            if ($logId) {
                try {
                    $pdo->prepare("
                        UPDATE bi_pacs_sync_log SET finalizado_em=NOW(), status='erro', mensagem=? WHERE id=?
                    ")->execute([$e->getMessage(), $logId]);
                } catch (\Exception $ex) {}
            }

            echo json_encode(['success' => false, 'message' => 'Erro na sincronização: ' . $e->getMessage()]);
        }
    }

    // ----------------------------------------------------------------
    // ROTEAMENTO InstitutionName → Negócio
    // ----------------------------------------------------------------

    public function roteamento(): void
    {
        $pdo = Database::getInstance();

        $roteamentos             = [];
        $negocios                = [];
        $institutionsNaoRoteadas = [];

        try {
            $roteamentos = $pdo->query("
                SELECT r.*, t.nome as negocio_nome,
                       COUNT(e.id) as total_estudos
                FROM bi_pacs_roteamento r
                JOIN bi_tenants t ON t.id = r.tenant_id
                LEFT JOIN bi_pacs_estudos e ON e.servidor_id = r.servidor_id
                    AND e.institution_name = r.institution_name
                WHERE r.servidor_id = 1
                GROUP BY r.id
                ORDER BY r.institution_name
            ")->fetchAll(\PDO::FETCH_ASSOC);

            $negocios = $pdo->query("
                SELECT id, nome, slug FROM bi_tenants WHERE status != 'cancelado' ORDER BY nome
            ")->fetchAll(\PDO::FETCH_ASSOC);

            $roteadasNames   = array_column($roteamentos, 'institution_name');
            $allInstitutions = $pdo->query("
                SELECT DISTINCT institution_name, COUNT(*) as total
                FROM bi_pacs_estudos WHERE servidor_id = 1 AND institution_name IS NOT NULL
                GROUP BY institution_name ORDER BY total DESC
            ")->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($allInstitutions as $inst) {
                if (!in_array($inst['institution_name'], $roteadasNames)) {
                    $institutionsNaoRoteadas[] = $inst;
                }
            }
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao carregar roteamento: " . $e->getMessage());
        }

        $this->view('platform/servidor_pacs/roteamento', compact(
            'roteamentos', 'negocios', 'institutionsNaoRoteadas'
        ), 'platform');
    }

    public function salvarRoteamento(): void
    {
        @ini_set('display_errors', '0');
        header('Content-Type: application/json; charset=utf-8');
        $pdo = Database::getInstance();

        $institutionName = trim($_POST['institution_name'] ?? '');
        $tenantId        = (int)($_POST['tenant_id'] ?? 0);
        $aetitle         = trim($_POST['aetitle'] ?? '') ?: null;
        $descricao       = trim($_POST['descricao'] ?? '') ?: null;

        if (empty($institutionName) || $tenantId <= 0) {
            echo json_encode(['success' => false, 'message' => 'InstitutionName e Negócio são obrigatórios.']);
            return;
        }

        try {
            // Previne duplicatas
            $existeStmt = $pdo->prepare("
                SELECT id FROM bi_pacs_roteamento WHERE servidor_id = 1 AND institution_name = ?
            ");
            $existeStmt->execute([$institutionName]);
            $existeId = $existeStmt->fetchColumn();

            if ($existeId) {
                $pdo->prepare("
                    UPDATE bi_pacs_roteamento
                    SET tenant_id=?, aetitle=?, descricao=?, ativo=1, updated_at=NOW()
                    WHERE id=?
                ")->execute([$tenantId, $aetitle, $descricao, $existeId]);
            } else {
                $pdo->prepare("
                    INSERT INTO bi_pacs_roteamento (servidor_id, tenant_id, institution_name, aetitle, descricao)
                    VALUES (1, ?, ?, ?, ?)
                ")->execute([$tenantId, $institutionName, $aetitle, $descricao]);
            }

            // Aplica roteamento retroativo nos estudos já importados sem negócio
            $updateStmt = $pdo->prepare("
                UPDATE bi_pacs_estudos SET tenant_id = ?
                WHERE servidor_id = 1 AND institution_name = ? AND tenant_id IS NULL
            ");
            $updateStmt->execute([$tenantId, $institutionName]);
            $afetados = $updateStmt->rowCount();

            error_log("[PACS] Roteamento salvo: institution='$institutionName' → tenant_id=$tenantId, $afetados estudos roteados retroativamente");

            echo json_encode([
                'success'  => true,
                'message'  => "Roteamento salvo! {$afetados} estudos roteados retroativamente.",
                'afetados' => $afetados,
            ]);
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao salvar roteamento: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
        }
    }

    public function removerRoteamento(int $id): void
    {
        @ini_set('display_errors', '0');
        header('Content-Type: application/json; charset=utf-8');
        $pdo = Database::getInstance();

        try {
            $rotStmt = $pdo->prepare("SELECT institution_name FROM bi_pacs_roteamento WHERE id = ? AND servidor_id = 1");
            $rotStmt->execute([$id]);
            $row = $rotStmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                $pdo->prepare("
                    UPDATE bi_pacs_estudos SET tenant_id = NULL
                    WHERE servidor_id = 1 AND institution_name = ?
                ")->execute([$row['institution_name']]);
            }

            $pdo->prepare("DELETE FROM bi_pacs_roteamento WHERE id = ? AND servidor_id = 1")->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Roteamento removido com sucesso.']);
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao remover roteamento: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ----------------------------------------------------------------
    // LISTA DE ESTUDOS
    // ----------------------------------------------------------------

    public function estudos(): void
    {
        $pdo = Database::getInstance();

        $filtroInstitution = $_GET['institution'] ?? '';
        $filtroTenant      = (int)($_GET['tenant'] ?? 0);
        $filtroStatus      = $_GET['status'] ?? '';
        $pagina            = max(1, (int)($_GET['pagina'] ?? 1));
        $porPagina         = 50;
        $offset            = ($pagina - 1) * $porPagina;

        $where  = ['servidor_id = 1'];
        $params = [];

        if ($filtroInstitution) {
            $where[]  = 'institution_name = ?';
            $params[] = $filtroInstitution;
        }
        if ($filtroTenant > 0) {
            $where[]  = 'tenant_id = ?';
            $params[] = $filtroTenant;
        }
        if ($filtroStatus === 'roteado') {
            $where[] = 'tenant_id IS NOT NULL';
        } elseif ($filtroStatus === 'nao_roteado') {
            $where[] = 'tenant_id IS NULL';
        }

        $whereStr = implode(' AND ', $where);
        $estudos  = [];
        $total    = 0;
        $institutions = [];
        $negocios     = [];

        try {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE $whereStr");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $listStmt = $pdo->prepare("
                SELECT e.*, t.nome as negocio_nome
                FROM bi_pacs_estudos e
                LEFT JOIN bi_tenants t ON t.id = e.tenant_id
                WHERE $whereStr
                ORDER BY e.study_date DESC, e.importado_em DESC
                LIMIT $porPagina OFFSET $offset
            ");
            $listStmt->execute($params);
            $estudos = $listStmt->fetchAll(\PDO::FETCH_ASSOC);

            $institutions = $pdo->query("
                SELECT DISTINCT institution_name FROM bi_pacs_estudos
                WHERE servidor_id = 1 AND institution_name IS NOT NULL
                ORDER BY institution_name
            ")->fetchAll(\PDO::FETCH_COLUMN);

            $negocios = $pdo->query("
                SELECT id, nome FROM bi_tenants WHERE status != 'cancelado' ORDER BY nome
            ")->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\Exception $e) {
            error_log("[PACS] Erro ao listar estudos: " . $e->getMessage());
        }

        $totalPaginas = $porPagina > 0 ? (int)ceil($total / $porPagina) : 1;

        $this->view('platform/servidor_pacs/estudos', compact(
            'estudos', 'total', 'pagina', 'totalPaginas', 'porPagina',
            'filtroInstitution', 'filtroTenant', 'filtroStatus',
            'institutions', 'negocios'
        ), 'platform');
    }

    // ----------------------------------------------------------------
    // HELPER PRIVADO
    // ----------------------------------------------------------------

    private function getServidor(\PDO $pdo): ?array
    {
        try {
            $stmt = $pdo->query("SELECT * FROM bi_pacs_servidor WHERE id = 1 LIMIT 1");
            $row  = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao buscar servidor: " . $e->getMessage());
            return null;
        }
    }
}
