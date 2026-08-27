<?php
namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
use App\Core\SqlHelper;

/**
 * VOXEL PACS — Sincronização automática incremental do(s) servidor(es) Orthanc.
 *
 * Chamado pelo robô interno CLI a cada 2 minutos. A rota HTTP legada pode
 * coexistir temporariamente durante a observação. Cada servidor ativo é
 * sincronizado exatamente 1 vez por ciclo, mesmo
 * que esteja associado a vários negócios (N:N) — nunca 1 ping/import por negócio.
 *
 * Usa o endpoint incremental GET /changes?since=cursor do Orthanc: o cursor é
 * salvo por servidor (bi_pacs_servidor.changes_cursor), nunca por par negócio-servidor.
 */
class PacsSyncService
{
    private const LOCK_STALE_MINUTES = 10;
    private const PAGE_SIZE          = 100;
    private const MAX_PAGES_PER_CICLO = 200; // guarda contra loop patológico

    /** Cache por processo para não consultar o schema a cada estudo sincronizado. */
    private static ?bool $hasScheduledProcedureStepDescriptionColumn = null;

    /**
     * Mesma lista de colunas DICOM já usada em ServidorPacsController::sincronizar(),
     * fatorada aqui para ser compartilhada entre o botão manual e o robô automático.
     */
    private static function colunasEstudo(array $study): array
    {
        return [
            'orthanc_parent_patient'        => $study['orthanc_parent_patient']        ?? null,
            'is_stable'                     => $study['is_stable']                     ?? 0,
            'last_update_orthanc'           => $study['last_update_orthanc']           ?? null,
            'tags_raw'                      => $study['tags_raw']                      ?? null,
            'patient_id'                    => $study['patient_id']                    ?? null,
            'issuer_of_patient_id'          => $study['issuer_of_patient_id']          ?? null,
            'issuer_of_patient_id_normalized'=> DicomIssuerService::normalize($study['issuer_of_patient_id'] ?? null),
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
            'scheduled_procedure_step_desc' => $study['scheduled_procedure_step_desc'] ?? null,
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
            'modalities'                    => $study['modalities']                    ?? null,
            'num_series'                    => $study['num_series']                    ?? 0,
            'num_instances'                 => $study['num_instances']                 ?? 0,
            'specific_character_set'        => $study['specific_character_set']        ?? null,
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
            'reconstruction_diameter'       => $study['reconstruction_diameter']       ?? null,
            'convolution_kernel'            => $study['convolution_kernel']            ?? null,
            'gantry_detector_tilt'          => $study['gantry_detector_tilt']          ?? null,
            'table_height'                  => $study['table_height']                  ?? null,
            'rotation_direction'            => $study['rotation_direction']            ?? null,
            'spiral_pitch_factor'           => $study['spiral_pitch_factor']           ?? null,
            'ctdi_vol'                      => $study['ctdi_vol']                      ?? null,
            'data_collection_diameter'      => $study['data_collection_diameter']      ?? null,
            'number_of_slices'              => $study['number_of_slices']              ?? null,
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
            'mechanical_index'              => $study['mechanical_index']              ?? null,
            'bone_thermal_index'            => $study['bone_thermal_index']            ?? null,
            'cranial_thermal_index'         => $study['cranial_thermal_index']         ?? null,
            'soft_tissue_thermal_index'     => $study['soft_tissue_thermal_index']     ?? null,
            'radiopharmaceutical'           => $study['radiopharmaceutical']           ?? null,
            'radionuclide_total_dose'       => $study['radionuclide_total_dose']       ?? null,
            'radionuclide_half_life'        => $study['radionuclide_half_life']        ?? null,
            'radiopharmaceutical_start_time'=> $study['radiopharmaceutical_start_time']?? null,
            'entrance_dose_in_mgy'          => $study['entrance_dose_in_mgy']          ?? null,
            'dose_area_product'             => $study['dose_area_product']             ?? null,
            'placer_order_number'           => $study['placer_order_number']           ?? null,
            'filler_order_number'           => $study['filler_order_number']           ?? null,
            'reason_for_requested_procedure'=> $study['reason_for_requested_procedure']?? null,
            'current_patient_location'      => $study['current_patient_location']      ?? null,
            'patient_state'                 => $study['patient_state']                 ?? null,
            'admission_id'                  => $study['admission_id']                  ?? null,
        ];
    }

    /**
     * Insere/atualiza um estudo já normalizado, aplicando o roteamento e o dump
     * DICOM completo. Respeita resolução manual prévia (roteamento_resolvido_por
     * já preenchido) — um ciclo automático nunca desfaz uma decisão do Platform
     * Admin, só atualiza os metadados do estudo.
     *
     * @return string 'novo'|'atualizado'
     */
    public static function upsertEstudo(\PDO $pdo, int $servidorId, array $study, array $routing, ?string $dicomTagsJson): string
    {
        $cols = self::colunasEstudo($study);
        $cols['dicom_tags_completas'] = $dicomTagsJson;

        // A descrição de procedimento agendado (0040,0007) costuma vir do
        // RIS/HIS pela Modality Worklist e pode não fazer parte das MainDicomTags.
        // A fonte completa é o shared-tags?simplify já coletado no mesmo ciclo.
        if (self::hasScheduledProcedureStepDescriptionColumn($pdo)) {
            $capturada = self::scheduledProcedureStepDescription($dicomTagsJson)
                ?? self::normalizarDescricaoAgendada($cols['scheduled_procedure_step_desc'] ?? null);
            if ($capturada !== null) {
                $cols['scheduled_procedure_step_desc'] = $capturada;
            } else {
                // Ausência temporária da tag não pode apagar um valor já obtido
                // do RIS/HIS em ciclo anterior.
                unset($cols['scheduled_procedure_step_desc']);
            }
        } else {
            // Mantém bancos que ainda não receberam a migration funcionais.
            unset($cols['scheduled_procedure_step_desc']);
        }

        // Em células Orthanc independentes o mesmo identificador interno pode
        // existir em servidores distintos. A identidade de sincronização é
        // sempre composta por servidor PACS e orthanc_id.
        $existeStmt = $pdo->prepare("
            SELECT id, tenant_id, roteamento_resolvido_por, study_description_manual
            FROM bi_pacs_estudos
            WHERE servidor_id = ? AND orthanc_id = ?
        ");
        $existeStmt->execute([$servidorId, $study['orthanc_id']]);
        $existente = $existeStmt->fetch(\PDO::FETCH_ASSOC);

        $jaResolvidoManualmente = $existente && $existente['roteamento_resolvido_por'] !== null;
        $descricaoResolvidaManualmente = $existente && !empty($existente['study_description_manual']);

        // Uma correção humana é intencional e não pode ser revertida pelo payload DICOM.
        if ($descricaoResolvidaManualmente) {
            unset($cols['study_description']);
        }

        if (!$jaResolvidoManualmente) {
            $cols['tenant_id']             = $routing['tenant_id'];
            $cols['unidade_id']            = $routing['unidade_id'] ?? null;
            $cols['roteamento_status']     = $routing['status'];
            $cols['roteamento_candidatos'] = $routing['candidatos']
                ? json_encode($routing['candidatos'], JSON_UNESCAPED_UNICODE)
                : null;
        }

        if ($existente) {
            $sets   = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($cols)));
            $vals   = array_values($cols);
            $vals[] = $existente['id'];
            $pdo->prepare("UPDATE bi_pacs_estudos SET $sets, atualizado_em=NOW() WHERE id=?")->execute($vals);
            AgendamentoService::marcarRealizadoPorEstudo(
                $pdo,
                isset($existente['tenant_id']) ? (int) $existente['tenant_id'] : null,
                $servidorId,
                $cols['accession_number'] ?? null,
                (int) $existente['id']
            );
            return 'atualizado';
        }

        $cols['tenant_id']             = $cols['tenant_id']             ?? $routing['tenant_id'];
        $cols['roteamento_status']     = $cols['roteamento_status']     ?? $routing['status'];
        $cols['roteamento_candidatos'] = $cols['roteamento_candidatos'] ?? ($routing['candidatos'] ? json_encode($routing['candidatos'], JSON_UNESCAPED_UNICODE) : null);

        $colNames     = '`servidor_id`, ' . implode(', ', array_map(fn($c) => "`$c`", array_keys($cols))) . ', `orthanc_id`';
        $placeholders = '?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?';
        $vals         = array_merge([$servidorId], array_values($cols), [$study['orthanc_id']]);
        $pdo->prepare("INSERT INTO bi_pacs_estudos ($colNames) VALUES ($placeholders)")->execute($vals);
        $created = $pdo->prepare('SELECT id FROM bi_pacs_estudos WHERE servidor_id = ? AND orthanc_id = ? LIMIT 1');
        $created->execute([$servidorId, $study['orthanc_id']]);
        $studyId = (int) $created->fetchColumn();
        AgendamentoService::marcarRealizadoPorEstudo(
            $pdo,
            isset($cols['tenant_id']) ? (int) $cols['tenant_id'] : (isset($routing['tenant_id']) ? (int) $routing['tenant_id'] : null),
            $servidorId,
            $cols['accession_number'] ?? null,
            $studyId
        );
        return 'novo';
    }

    private static function hasScheduledProcedureStepDescriptionColumn(\PDO $pdo): bool
    {
        if (self::$hasScheduledProcedureStepDescriptionColumn === null) {
            self::$hasScheduledProcedureStepDescriptionColumn = SqlHelper::hasColumn(
                $pdo,
                'bi_pacs_estudos',
                'scheduled_procedure_step_desc'
            );
        }

        return self::$hasScheduledProcedureStepDescriptionColumn;
    }

    /**
     * Extrai a descrição (0040,0007) do payload simplificado ou estruturado
     * retornado pelo Orthanc. A função aceita tanto o keyword DICOM quanto a
     * chave hexadecimal, pois versões/plugins distintos usam representações
     * diferentes para tags fora das MainDicomTags.
     */
    private static function normalizarDescricaoAgendada(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        return $text === '' ? null : mb_substr($text, 0, 500, 'UTF-8');
    }

    private static function scheduledProcedureStepDescription(?string $dicomTagsJson): ?string
    {
        if ($dicomTagsJson === null || trim($dicomTagsJson) === '') {
            return null;
        }

        try {
            $tags = json_decode($dicomTagsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return self::findScheduledProcedureStepDescription($tags);
    }

    private static function findScheduledProcedureStepDescription(mixed $node): ?string
    {
        if (!is_array($node)) {
            return null;
        }

        foreach ($node as $key => $value) {
            $normalizedKey = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', (string) $key));
            if (in_array($normalizedKey, ['scheduledprocedurestepdescription', '00400007'], true)) {
                $candidate = is_array($value)
                    ? ($value['Value'][0] ?? $value['value'] ?? null)
                    : $value;
                if (is_scalar($candidate)) {
                    $normalized = self::normalizarDescricaoAgendada($candidate);
                    if ($normalized !== null) {
                        return $normalized;
                    }
                }
            }

            $found = self::findScheduledProcedureStepDescription($value);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Ciclo completo: 1 chamada = todos os servidores ativos, cada um 1 vez.
     * Nunca lança para fora — falha de 1 servidor não pode abortar os demais.
     */
    public static function executarParaTodosServidores(): array
    {
        $pdo = Database::getInstance();
        $servidores = $pdo->query("SELECT * FROM bi_pacs_servidor WHERE ativo = 1")->fetchAll(\PDO::FETCH_ASSOC);

        $resumo = ['servidores' => []];

        foreach ($servidores as $servidor) {
            $resumo['servidores'][] = self::executarParaServidor($pdo, $servidor);
        }

        return $resumo;
    }

    private static function executarParaServidor(\PDO $pdo, array $servidor): array
    {
        $servidorId = (int) $servidor['id'];
        $r = ['servidor_id' => $servidorId, 'nome' => $servidor['nome']];

        // Lock de concorrência — evita 2 ciclos simultâneos no mesmo servidor
        $lockAgeMinutesSql = SqlHelper::timestampDiff('MINUTE', 'sync_lock_at', 'NOW()');
        $lockStmt = $pdo->prepare("
            UPDATE bi_pacs_servidor SET sync_lock_at = NOW()
            WHERE id = ? AND (sync_lock_at IS NULL OR {$lockAgeMinutesSql} > " . self::LOCK_STALE_MINUTES . ")
        ");
        $lockStmt->execute([$servidorId]);
        if ($lockStmt->rowCount() === 0) {
            $r['status'] = 'pulado_lock_ativo';
            return $r;
        }

        $logId = null;
        try {
            $pdo->prepare("
                INSERT INTO bi_pacs_sync_log (servidor_id, iniciado_em, status, origem) VALUES (?, NOW(), 'em_andamento', 'automatico')
            ")->execute([$servidorId]);
            $logId = $pdo->lastInsertId();
        } catch (\Exception $e) {
            Logger::error('[PacsSyncService] Erro ao criar log: ' . $e->getMessage());
        }

        $orthanc = new OrthancService(
            $servidor['url'],
            $servidor['usuario'] ?? null,
            Crypto::decrypt($servidor['senha'] ?? null),
            $servidor['timeout'] ?? 30
        );

        $ping = $orthanc->ping();
        if (!$ping['success']) {
            $pdo->prepare("UPDATE bi_pacs_servidor SET status_ping='offline', ultimo_ping=NOW(), sync_lock_at=NULL WHERE id=?")
                ->execute([$servidorId]);
            if ($logId) {
                $pdo->prepare("UPDATE bi_pacs_sync_log SET finalizado_em=NOW(), status='erro', mensagem=? WHERE id=?")
                    ->execute(['Servidor offline: ' . $ping['error'], $logId]);
            }
            $r['status'] = 'offline';
            $r['erro']   = $ping['error'];
            return $r;
        }
        $pdo->prepare("UPDATE bi_pacs_servidor SET status_ping='online', ultimo_ping=NOW() WHERE id=?")->execute([$servidorId]);

        $novos = 0; $atualizados = 0; $roteados = 0; $naoIdentificados = 0; $conflitos = 0; $erros = 0;
        $cursor = (int) $servidor['changes_cursor'];

        try {
            for ($pagina = 0; $pagina < self::MAX_PAGES_PER_CICLO; $pagina++) {
                $changesRes = $orthanc->getChanges($cursor, self::PAGE_SIZE);
                if (!$changesRes['success']) {
                    throw new \RuntimeException('Falha em GET /changes: ' . $changesRes['error']);
                }
                $data = $changesRes['data'];
                $changes = $data['Changes'] ?? [];

                $studyIds = [];
                foreach ($changes as $change) {
                    if (($change['ResourceType'] ?? '') === 'Study'
                        && in_array($change['ChangeType'] ?? '', ['NewStudy', 'StableStudy'], true)) {
                        $studyIds[$change['ID']] = true;
                    }
                }

                foreach (array_keys($studyIds) as $studyId) {
                    try {
                        $study = $orthanc->fetchAndNormalizeStudy($studyId);
                        if ($study === null) {
                            $erros++;
                            continue;
                        }

                        $sharedTagsRes = $orthanc->getSharedTags($studyId);
                        $sharedTags = ($sharedTagsRes['success'] ?? false) && is_array($sharedTagsRes['data'] ?? null)
                            ? $sharedTagsRes['data']
                            : null;
                        $dicomTagsJson = $sharedTags !== null ? json_encode($sharedTags, JSON_UNESCAPED_UNICODE) : null;

                        $issuer = $orthanc->getIssuerOfPatientId($studyId, $sharedTags);
                        if ($issuer !== null) {
                            $study['issuer_of_patient_id'] = $issuer;
                        }

                        // O RIS/HIS pode gravar (0040,0007) somente nas instâncias.
                        // Consulta a primeira instância apenas quando a descrição
                        // principal está vazia, evitando chamadas extras no ciclo normal.
                        if (trim((string) ($study['study_description'] ?? '')) === ''
                            && trim((string) ($study['scheduled_procedure_step_desc'] ?? '')) === '') {
                            $scheduled = $orthanc->getScheduledProcedureStepDescription($studyId, $sharedTags);
                            if (($scheduled['success'] ?? false) && !empty($scheduled['description'])) {
                                $study['scheduled_procedure_step_desc'] = $scheduled['description'];
                            }
                        }

                        $routing = PacsRoutingService::resolveTenant(
                            $servidorId,
                            $study['institution_name'] ?? null,
                            $study['issuer_of_patient_id'] ?? null,
                            $study['modalities'] ?? null
                        );
                        match ($routing['status']) {
                            PacsRoutingService::STATUS_ROTEADO          => $roteados++,
                            PacsRoutingService::STATUS_NAO_IDENTIFICADO => $naoIdentificados++,
                            PacsRoutingService::STATUS_CONFLITO         => $conflitos++,
                        };

                        $resultado = self::upsertEstudo($pdo, $servidorId, $study, $routing, $dicomTagsJson);
                        $resultado === 'novo' ? $novos++ : $atualizados++;
                    } catch (\Exception $e) {
                        $erros++;
                        Logger::error("[PacsSyncService] Erro ao importar estudo $studyId (servidor $servidorId): " . $e->getMessage());
                    }
                }

                $cursor = (int) ($data['Last'] ?? $cursor);
                // Persiste o cursor a cada página — idempotência: se o ciclo cair
                // no meio, a próxima chamada retoma daqui, nunca reimporta o que já passou.
                $pdo->prepare("UPDATE bi_pacs_servidor SET changes_cursor = ? WHERE id = ?")->execute([$cursor, $servidorId]);

                if (!empty($data['Done'])) {
                    break;
                }
            }

            $mensagem = "Ciclo automático: {$novos} novos, {$atualizados} atualizados, {$roteados} roteados, "
                . "{$naoIdentificados} não identificados, {$conflitos} conflitos, {$erros} erros.";

            $pdo->prepare("
                UPDATE bi_pacs_servidor SET
                    sync_lock_at = NULL, sync_ultima_execucao = NOW(),
                    sync_estudos_ultimo_ciclo = ?, sync_nao_identificados_ultimo_ciclo = ?, sync_conflitos_ultimo_ciclo = ?
                WHERE id = ?
            ")->execute([$novos + $atualizados, $naoIdentificados, $conflitos, $servidorId]);

            if ($logId) {
                $pdo->prepare("
                    UPDATE bi_pacs_sync_log SET
                        finalizado_em=NOW(), status='concluido',
                        estudos_novos=?, estudos_atualizados=?, estudos_roteados=?,
                        estudos_nao_identificados=?, estudos_conflito=?, erros=?, mensagem=?
                    WHERE id=?
                ")->execute([$novos, $atualizados, $roteados, $naoIdentificados, $conflitos, $erros, $mensagem, $logId]);
            }

            $r['status']            = 'concluido';
            $r['novos']             = $novos;
            $r['atualizados']       = $atualizados;
            $r['roteados']          = $roteados;
            $r['nao_identificados'] = $naoIdentificados;
            $r['conflitos']         = $conflitos;
            $r['erros']             = $erros;
            return $r;

        } catch (\Exception $e) {
            Logger::error("[PacsSyncService] Erro crítico no servidor $servidorId: " . $e->getMessage());
            $pdo->prepare("UPDATE bi_pacs_servidor SET sync_lock_at = NULL WHERE id = ?")->execute([$servidorId]);
            if ($logId) {
                $pdo->prepare("UPDATE bi_pacs_sync_log SET finalizado_em=NOW(), status='erro', mensagem=? WHERE id=?")
                    ->execute([$e->getMessage(), $logId]);
            }
            $r['status'] = 'erro';
            $r['erro']   = $e->getMessage();
            return $r;
        }
    }
}
