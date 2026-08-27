<?php
namespace App\Services;

use App\Core\Logger;

class OrthancService {
    private string $baseUrl;
    private ?string $username;
    private ?string $password;
    private int $timeout;

    public function __construct(string $baseUrl, ?string $username = null, ?string $password = null, int $timeout = 30) {
        $this->baseUrl  = rtrim($baseUrl, '/');
        $this->username = $username;
        $this->password = $password;
        $this->timeout  = $timeout;
    }

    private function request(string $endpoint, string $method = 'GET', ?array $data = null): array {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $t0  = microtime(true);

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json'],
        ];

        if ($this->username !== null && $this->username !== '') {
            $options[CURLOPT_USERPWD]  = $this->username . ':' . ($this->password ?? '');
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        }

        if ($data !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        $ms       = (int) round((microtime(true) - $t0) * 1000);
        curl_close($ch);

        if ($response === false || $curlErr) {
            Logger::error("Orthanc cURL error ($method $url): $curlErr");
            return ['success' => false, 'error' => $curlErr ?: 'Sem resposta do servidor', 'code' => 0, 'ms' => $ms];
        }

        $decoded = json_decode($response, true);

        if ($httpCode === 401 || $httpCode === 403) {
            return ['success' => false, 'error' => 'Credenciais inválidas (HTTP ' . $httpCode . ')', 'code' => $httpCode, 'ms' => $ms];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'data' => $decoded, 'code' => $httpCode, 'ms' => $ms];
        }

        $errMsg = $decoded['Message'] ?? ('Erro HTTP ' . $httpCode);
        Logger::error("Orthanc API HTTP $httpCode ($method $url): $errMsg");
        return ['success' => false, 'error' => $errMsg, 'code' => $httpCode, 'ms' => $ms];
    }

    public function ping(): array { return $this->request('/system'); }

    /**
     * Endpoint incremental do Orthanc — só traz o que mudou desde o cursor salvo.
     * GET /changes?since={since}&limit={limit} → {Changes:[{Seq,ChangeType,ResourceType,ID,...}], Last, Done}
     * `Last` é o cursor a salvar para a próxima chamada; `Done=false` significa
     * que ainda há mais páginas a buscar com since=Last antes de considerar o
     * ciclo completo.
     */
    public function getChanges(int $since, int $limit = 100): array {
        return $this->request('/changes?since=' . $since . '&limit=' . $limit);
    }

    /**
     * Tags compartilhadas por todas as instâncias do estudo (dump DICOM mais
     * completo que MainDicomTags de GET /studies/{id} — inclui tags que não
     * fazem parte do "resumo" que o Orthanc indexa nas colunas principais).
     * GET /studies/{id}/shared-tags?simplify
     */
    public function getSharedTags(string $studyId): array {
        return $this->request("/studies/$studyId/shared-tags?simplify");
    }

    /**
     * Lê a tag (0040,0007) mesmo quando ela não é comum a todas as instâncias
     * e por isso não aparece em shared-tags. É uma propriedade que costuma vir
     * da Modality Worklist/RIS, portanto a primeira instância é a fonte de
     * fallback adequada após a tentativa nas tags compartilhadas.
     *
     * @param array<string,mixed>|null $sharedTags dados já obtidos por getSharedTags()
     * @return array{success:bool,description:?string,error?:string}
     */
    public function getScheduledProcedureStepDescription(string $studyId, ?array $sharedTags = null): array
    {
        $description = $this->findScheduledProcedureStepDescription($sharedTags ?? []);
        if ($description !== null) {
            return ['success' => true, 'description' => $description];
        }

        $study = $this->getStudy($studyId);
        if (!($study['success'] ?? false) || !is_array($study['data'] ?? null)) {
            return ['success' => false, 'description' => null, 'error' => $study['error'] ?? 'Falha ao buscar estudo'];
        }

        $seriesId = $study['data']['Series'][0] ?? null;
        if (!is_string($seriesId) || $seriesId === '') {
            return ['success' => true, 'description' => null];
        }

        $series = $this->request('/series/' . rawurlencode($seriesId));
        if (!($series['success'] ?? false) || !is_array($series['data'] ?? null)) {
            return ['success' => false, 'description' => null, 'error' => $series['error'] ?? 'Falha ao buscar série'];
        }

        $instanceId = $series['data']['Instances'][0] ?? null;
        if (!is_string($instanceId) || $instanceId === '') {
            return ['success' => true, 'description' => null];
        }

        $tags = $this->request('/instances/' . rawurlencode($instanceId) . '/tags?simplify');
        if (!($tags['success'] ?? false) || !is_array($tags['data'] ?? null)) {
            return ['success' => false, 'description' => null, 'error' => $tags['error'] ?? 'Falha ao buscar tags da instância'];
        }

        return [
            'success' => true,
            'description' => $this->findScheduledProcedureStepDescription($tags['data']),
        ];
    }

    /**
     * Obtém Issuer of Patient ID (0010,0021). A tag normalmente já está nas
     * shared-tags; quando não está, consulta somente a primeira instância do
     * estudo, seguindo o mesmo fallback controlado da Worklist.
     */
    public function getIssuerOfPatientId(string $studyId, ?array $sharedTags = null): ?string
    {
        $issuer = DicomIssuerService::extractIssuerOfPatientId($sharedTags ?? []);
        if ($issuer !== null) {
            return $issuer;
        }

        $study = $this->getStudy($studyId);
        if (!($study['success'] ?? false) || !is_array($study['data'] ?? null)) {
            return null;
        }

        $seriesId = $study['data']['Series'][0] ?? null;
        if (!is_string($seriesId) || $seriesId === '') {
            return null;
        }

        $series = $this->request('/series/' . rawurlencode($seriesId));
        $instanceId = ($series['success'] ?? false) && is_array($series['data'] ?? null)
            ? ($series['data']['Instances'][0] ?? null)
            : null;
        if (!is_string($instanceId) || $instanceId === '') {
            return null;
        }

        $tags = $this->request('/instances/' . rawurlencode($instanceId) . '/tags?simplify');
        return ($tags['success'] ?? false) && is_array($tags['data'] ?? null)
            ? DicomIssuerService::extractIssuerOfPatientId($tags['data'])
            : null;
    }

    /** @param array<string,mixed> $node */
    private function findScheduledProcedureStepDescription(array $node): ?string
    {
        foreach ($node as $key => $value) {
            $normalizedKey = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', (string) $key));
            if (in_array($normalizedKey, ['scheduledprocedurestepdescription', '00400007'], true)) {
                $candidate = is_array($value) ? ($value['Value'][0] ?? $value['value'] ?? null) : $value;
                if (is_scalar($candidate)) {
                    $text = trim((string) $candidate);
                    if ($text !== '') {
                        return mb_substr($text, 0, 500, 'UTF-8');
                    }
                }
            }
            if (is_array($value)) {
                $found = $this->findScheduledProcedureStepDescription($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    public function getStudies(): array { return $this->request('/studies?expand'); }

    public function countStudies(): int {
        $res = $this->request('/studies');
        return ($res['success'] && is_array($res['data'])) ? count($res['data']) : 0;
    }

    public function getStudy(string $studyId): array { return $this->request("/studies/$studyId"); }

    public function getSeries(string $seriesId): array { return $this->request('/series/' . rawurlencode($seriesId)); }

    public function getInstance(string $instanceId): array { return $this->request('/instances/' . rawurlencode($instanceId)); }

    /** Retorna bytes DICOM ao proxy interno; nunca revela credenciais ao cliente Desktop. */
    public function downloadInstance(string $instanceId): array {
        $ch = curl_init($this->baseUrl . '/instances/' . rawurlencode($instanceId) . '/file');
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/dicom'],
        ];
        if ($this->username !== null && $this->username !== '') {
            $options[CURLOPT_USERPWD] = $this->username . ':' . ($this->password ?? '');
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return ['success' => $body !== false && $error === '' && $code >= 200 && $code < 300, 'body' => $body ?: ''];
    }

    public function getPatients(): array { return $this->request('/patients?expand'); }

    public function countPatients(): int {
        $res = $this->request('/patients');
        return ($res['success'] && is_array($res['data'])) ? count($res['data']) : 0;
    }

    public function getStatistics(): array { return $this->request('/statistics'); }

    public function getPlugins(): array { return $this->request('/plugins'); }

    public function echoModality(string $name): array { return $this->request("/modalities/$name/echo", 'POST'); }

    // ================================================================
    // IMPORTAÇÃO DE ESTUDOS
    // ================================================================

    /**
     * Importa todos os estudos do Orthanc.
     * GET /studies  → lista de IDs → GET /studies/{id} → normaliza cada um.
     *
     * @throws \RuntimeException em caso de falha de conexão
     */
    public function importAllStudies(int $batchSize = 100): array
    {
        $idsRes = $this->request('/studies');

        if (!$idsRes['success']) {
            throw new \RuntimeException(
                'Falha ao obter lista de estudos do Orthanc: ' . $idsRes['error']
            );
        }

        $ids = $idsRes['data'];
        if (!is_array($ids) || empty($ids)) {
            return [];
        }

        $normalized = [];

        foreach ($ids as $studyId) {
            if (empty($studyId)) continue;

            $studyRes = $this->request("/studies/$studyId");

            if (!$studyRes['success']) {
                Logger::error("Orthanc: falha ao buscar estudo $studyId: " . $studyRes['error']);
                continue;
            }

            $study = $studyRes['data'];
            if (!is_array($study)) continue;

            $modalities = $this->fetchModalitiesInStudy($studyId);

            $normalized[] = $this->normalizeStudy($study, $modalities);
        }

        return $normalized;
    }

    /**
     * Busca e normaliza um único estudo (GET /studies/{id} + /series para
     * modalidades) — mesma lógica por-estudo de importAllStudies(), extraída
     * para ser reaproveitada pela sincronização incremental via /changes
     * (PacsSyncService), que processa estudo a estudo em vez de em lote.
     *
     * @return array|null null se o estudo não pôde ser obtido do Orthanc
     */
    public function fetchAndNormalizeStudy(string $studyId): ?array
    {
        $studyRes = $this->request("/studies/$studyId");
        if (!$studyRes['success'] || !is_array($studyRes['data'])) {
            Logger::error("Orthanc: falha ao buscar estudo $studyId: " . ($studyRes['error'] ?? 'resposta inválida'));
            return null;
        }

        $modalities = $this->fetchModalitiesInStudy($studyId);
        return $this->normalizeStudy($studyRes['data'], $modalities);
    }

    /**
     * Modality (0008,0060) é atributo de Series, não de Study — o Orthanc nunca
     * inclui isso em MainDicomTags de GET /studies/{id} (ModalitiesInStudy é um
     * tag computado, só disponível via ?requestedTags desde o Orthanc 1.11.0,
     * o que não podemos assumir em toda instalação). Por isso agregamos aqui a
     * partir de GET /studies/{id}/series, que já retorna cada Series expandida
     * com seu MainDicomTags.Modality — 1 requisição extra por estudo durante o
     * sync, não afeta a listagem (que só lê a coluna já persistida).
     *
     * Mesmo critério do tag DICOM ModalitiesInStudy: modalidades distintas, na
     * ordem em que aparecem, separadas por '\' — mesmo separador já usado pela
     * coluna bi_pacs_estudos.modalities e pela view do worklist.
     */
    private function fetchModalitiesInStudy(string $studyId): ?string
    {
        $res = $this->request("/studies/$studyId/series");
        if (!$res['success'] || !is_array($res['data'])) {
            Logger::error("Orthanc: falha ao buscar series do estudo $studyId: " . ($res['error'] ?? 'resposta inválida'));
            return null;
        }

        $modalidades = [];
        foreach ($res['data'] as $serie) {
            $modality = trim($serie['MainDicomTags']['Modality'] ?? '');
            if ($modality !== '' && !in_array($modality, $modalidades, true)) {
                $modalidades[] = $modality;
            }
        }

        return $modalidades ? implode('\\', $modalidades) : null;
    }

    /**
     * Normaliza um estudo Orthanc para o formato interno do VOXEL B.I
     */
    private function normalizeStudy(array $study, ?string $modalities = null): array
    {
        $main    = $study['MainDicomTags']        ?? [];
        $patient = $study['PatientMainDicomTags'] ?? [];
        $series  = $study['Series']               ?? [];

        // Helper inline para trim seguro
        $t = fn($v) => ($v !== null && $v !== '') ? trim((string)$v) ?: null : null;
        $n = fn($v) => is_numeric($v) ? (float)$v : null;
        $i = fn($v) => is_numeric($v) ? (int)$v   : null;

        $rawName    = $patient['PatientName'] ?? '';
        $nameClean  = $this->cleanDicomName($rawName);
        $nameDisplay= $this->buildDisplayName($rawName);

        return [
            // --- Controle interno ---
            'orthanc_id'                    => $study['ID']                          ?? '',
            'orthanc_parent_patient'        => $study['ParentPatient']               ?? null,
            'is_stable'                     => (bool)($study['IsStable']             ?? true),
            'last_update_orthanc'           => $this->parseDicomDatetime($study['LastUpdate'] ?? ''),
            'tags_raw'                      => json_encode($main + $patient),

            // --- Patient ---
            'patient_id'                    => $t($patient['PatientID']              ?? null),
            'issuer_of_patient_id'          => DicomIssuerService::sanitizeIssuer($patient['IssuerOfPatientID'] ?? null),
            'patient_name'                  => $nameClean,
            'patient_name_display'          => $nameDisplay,
            'patient_birth_date'            => $this->parseDicomDate($patient['PatientBirthDate'] ?? ''),
            'patient_sex'                   => strtoupper(trim($patient['PatientSex'] ?? '')) ?: null,
            'patient_age'                   => $t($main['PatientAge']                ?? null),
            'patient_weight'                => $n($main['PatientWeight']             ?? null),
            'patient_size'                  => $n($main['PatientSize']               ?? null),
            'patient_comments'              => $t($patient['PatientComments']        ?? null),
            'patient_identity_removed'      => $t($main['PatientIdentityRemoved']    ?? null),
            'responsible_person'            => $t($patient['ResponsiblePerson']      ?? null),
            'responsible_organization'      => $t($patient['ResponsibleOrganization']?? null),
            'patient_species_desc'          => $t($patient['PatientSpeciesDescription'] ?? null),
            'patient_breed_desc'            => $t($patient['PatientBreedDescription']?? null),

            // --- Study ---
            'study_instance_uid'            => $t($main['StudyInstanceUID']          ?? null),
            'study_date'                    => $this->parseDicomDate($main['StudyDate'] ?? ''),
            'study_time'                    => $this->parseDicomTime($main['StudyTime'] ?? ''),
            'study_description'             => $t($main['StudyDescription']          ?? null),
            'accession_number'              => $t($main['AccessionNumber']           ?? null),
            'study_id'                      => $t($main['StudyID']                   ?? null),
            'referring_physician_name'      => $t($main['ReferringPhysicianName']    ?? null),
            'name_of_physicians_reading'    => $t($main['NameOfPhysiciansReadingStudy'] ?? null),
            'admitting_diagnoses_desc'      => $t($main['AdmittingDiagnosesDescription'] ?? null),
            'additional_patient_history'    => $t($main['AdditionalPatientHistory']  ?? null),
            'requested_procedure_desc'      => $t($main['RequestedProcedureDescription'] ?? null),
            'requested_procedure_id'        => $t($main['RequestedProcedureID']      ?? null),
            'scheduled_procedure_step_id'   => $t($main['ScheduledProcedureStepID'] ?? null),
            'scheduled_procedure_step_desc' => $t($main['ScheduledProcedureStepDescription'] ?? null),

            // --- Equipment / Institution ---
            'institution_name'              => $t($main['InstitutionName']           ?? null),
            'institution_address'           => $t($main['InstitutionAddress']        ?? null),
            'institutional_dept_name'       => $t($main['InstitutionalDepartmentName'] ?? null),
            'station_name'                  => $t($main['StationName']               ?? null),
            'manufacturer'                  => $t($main['Manufacturer']              ?? null),
            'manufacturer_model_name'       => $t($main['ManufacturerModelName']     ?? null),
            'device_serial_number'          => $t($main['DeviceSerialNumber']        ?? null),
            'software_versions'             => $t($main['SoftwareVersions']          ?? null),
            'operators_name'                => $t($main['OperatorsName']             ?? null),
            'performing_physician_name'     => $t($main['PerformingPhysicianName']   ?? null),

            // --- Series summary ---
            'modalities'                    => $modalities,
            'num_series'                    => count($series),
            'num_instances'                 => (int)($study['Statistics']['CountInstances'] ?? 0),

            // --- SOP ---
            'specific_character_set'        => $t($main['SpecificCharacterSet']      ?? null),

            // --- Acquisition ---
            'body_part_examined'            => $t($main['BodyPartExamined']          ?? null),
            'protocol_name'                 => $t($main['ProtocolName']              ?? null),
            'contrast_bolus_agent'          => $t($main['ContrastBolusAgent']        ?? null),
            'scanning_sequence'             => $t($main['ScanningSequence']          ?? null),
            'sequence_variant'              => $t($main['SequenceVariant']           ?? null),
            'scan_options'                  => $t($main['ScanOptions']               ?? null),
            'mr_acquisition_type'           => $t($main['MRAcquisitionType']         ?? null),
            'slice_thickness'               => $n($main['SliceThickness']            ?? null),
            'kvp'                           => $n($main['KVP']                       ?? null),
            'exposure_time'                 => $i($main['ExposureTime']              ?? null),
            'x_ray_tube_current'            => $i($main['XRayTubeCurrent']           ?? null),
            'exposure'                      => $i($main['Exposure']                  ?? null),
            'exposure_in_uas'               => $i($main['ExposureInuAs']             ?? null),
            'distance_source_to_detector'   => $n($main['DistanceSourceToDetector'] ?? null),
            'distance_source_to_patient'    => $n($main['DistanceSourceToPatient']   ?? null),
            'field_of_view_dimensions'      => $t($main['FieldOfViewDimensions']     ?? null),
            'pixel_spacing'                 => $t($main['PixelSpacing']              ?? null),
            'rows'                          => $i($main['Rows']                      ?? null),
            'columns'                       => $i($main['Columns']                   ?? null),
            'bits_allocated'                => $i($main['BitsAllocated']             ?? null),
            'bits_stored'                   => $i($main['BitsStored']                ?? null),
            'photometric_interpretation'    => $t($main['PhotometricInterpretation'] ?? null),
            'samples_per_pixel'             => $i($main['SamplesPerPixel']           ?? null),
            'window_center'                 => $t($main['WindowCenter']              ?? null),
            'window_width'                  => $t($main['WindowWidth']               ?? null),
            'rescale_intercept'             => $n($main['RescaleIntercept']          ?? null),
            'rescale_slope'                 => $n($main['RescaleSlope']              ?? null),

            // --- CT ---
            'reconstruction_diameter'       => $n($main['ReconstructionDiameter']   ?? null),
            'convolution_kernel'            => $t($main['ConvolutionKernel']         ?? null),
            'gantry_detector_tilt'          => $n($main['GantryDetectorTilt']        ?? null),
            'table_height'                  => $n($main['TableHeight']               ?? null),
            'rotation_direction'            => $t($main['RotationDirection']         ?? null),
            'spiral_pitch_factor'           => $n($main['SpiralPitchFactor']         ?? null),
            'ctdi_vol'                      => $n($main['CTDIvol']                   ?? null),
            'data_collection_diameter'      => $n($main['DataCollectionDiameter']    ?? null),
            'number_of_slices'              => $i($main['NumberOfSlices']            ?? null),

            // --- MR ---
            'repetition_time'               => $n($main['RepetitionTime']            ?? null),
            'echo_time'                     => $n($main['EchoTime']                  ?? null),
            'inversion_time'                => $n($main['InversionTime']             ?? null),
            'echo_train_length'             => $i($main['EchoTrainLength']           ?? null),
            'flip_angle'                    => $n($main['FlipAngle']                 ?? null),
            'sar'                           => $n($main['SAR']                       ?? null),
            'magnetic_field_strength'       => $n($main['MagneticFieldStrength']     ?? null),
            'imaging_frequency'             => $n($main['ImagingFrequency']          ?? null),
            'imaged_nucleus'                => $t($main['ImagedNucleus']             ?? null),
            'number_of_averages'            => $n($main['NumberOfAverages']          ?? null),
            'percent_sampling'              => $n($main['PercentSampling']           ?? null),
            'percent_phase_field_of_view'   => $n($main['PercentPhaseFieldOfView']   ?? null),
            'receive_coil_name'             => $t($main['ReceiveCoilName']           ?? null),
            'transmit_coil_name'            => $t($main['TransmitCoilName']          ?? null),
            'in_plane_phase_encoding_direction' => $t($main['InPlanePhaseEncodingDirection'] ?? null),
            'diffusion_b_value'             => $n($main['DiffusionBValue']           ?? null),

            // --- US ---
            'mechanical_index'              => $n($main['MechanicalIndex']           ?? null),
            'bone_thermal_index'            => $n($main['BoneThermalIndex']          ?? null),
            'cranial_thermal_index'         => $n($main['CranialThermalIndex']       ?? null),
            'soft_tissue_thermal_index'     => $n($main['SoftTissueThermalIndex']    ?? null),

            // --- NM/PET ---
            'radiopharmaceutical'           => $t($main['Radiopharmaceutical']       ?? null),
            'radionuclide_total_dose'       => $n($main['RadionuclideTotalDose']     ?? null),
            'radionuclide_half_life'        => $n($main['RadionuclideHalfLife']      ?? null),
            'radiopharmaceutical_start_time'=> $this->parseDicomTime($main['RadiopharmaceuticalStartTime'] ?? ''),

            // --- Dose ---
            'entrance_dose_in_mgy'          => $n($main['EntranceDoseInmGy']        ?? null),
            'dose_area_product'             => $n($main['DoseAreaProduct']           ?? null),

            // --- Workflow ---
            'placer_order_number'           => $t($main['PlacerOrderNumberImagingServiceRequest'] ?? null),
            'filler_order_number'           => $t($main['FillerOrderNumberImagingServiceRequest'] ?? null),
            'reason_for_requested_procedure'=> $t($main['ReasonForRequestedProcedure'] ?? null),
            'current_patient_location'      => $t($main['CurrentPatientLocation']   ?? null),
            'patient_state'                 => $t($main['PatientState']              ?? null),
            'admission_id'                  => $t($main['AdmissionID']              ?? null),
        ];
    }

    /**
     * Formata nome DICOM (Sobrenome^Nome) para exibição (Nome Sobrenome)
     */
    private function buildDisplayName(string $raw): ?string
    {
        if (empty($raw)) return null;
        $parts = array_filter(array_map('trim', explode('^', $raw)));
        if (empty($parts)) return null;
        $arr = array_values($parts);
        if (count($arr) >= 2) {
            return trim($arr[1] . ' ' . $arr[0]) ?: null;
        }
        return $arr[0] ?: null;
    }

    private function parseDicomDate(string $raw): ?string
    {
        $d = preg_replace('/[^0-9]/', '', $raw);
        if (strlen($d) === 8) {
            $dt = \DateTime::createFromFormat('Ymd', $d);
            return $dt ? $dt->format('Y-m-d') : null;
        }
        return null;
    }

    private function parseDicomTime(string $raw): ?string
    {
        $t = preg_replace('/[^0-9]/', '', explode('.', $raw)[0]);
        if (strlen($t) >= 6) {
            return substr($t, 0, 2) . ':' . substr($t, 2, 2) . ':' . substr($t, 4, 2);
        }
        return null;
    }

    private function parseDicomDatetime(string $raw): ?string
    {
        if (empty($raw)) return null;
        $clean = preg_replace('/[^0-9T]/', '', $raw);
        $dt    = \DateTime::createFromFormat('Ymd\THis', $clean);
        if ($dt) return $dt->format('Y-m-d H:i:s');
        $dt2 = \DateTime::createFromFormat('YmdHis', str_replace('T', '', $clean));
        return $dt2 ? $dt2->format('Y-m-d H:i:s') : null;
    }

    private function cleanDicomName(string $raw): ?string
    {
        if (empty($raw)) return null;
        $parts = array_filter(array_map('trim', explode('^', $raw)));
        if (empty($parts)) return null;
        $arr = array_values($parts);
        if (count($arr) >= 2) {
            return trim($arr[1] . ' ' . $arr[0]) ?: null;
        }
        return $arr[0];
    }

    /**
     * Diagnóstico completo passo-a-passo:
     * /system → auth → version → /statistics → /plugins → /studies
     * Retorna steps[] com label, ok, detail, ms + dados brutos para o modal.
     */
    public function fullDiagnostic(): array {
        $steps = [];

        // ── PASSO 1: Conectividade + /system ───────────────────────────
        $sys = $this->request('/system');
        if (!$sys['success']) {
            $steps[] = [
                'id'     => 'system',
                'label'  => 'Servidor Orthanc acessível',
                'ok'     => false,
                'detail' => $sys['error'],
                'ms'     => $sys['ms'],
            ];
            return ['success' => false, 'steps' => $steps, 'error' => $sys['error']];
        }
        $sysData = $sys['data'];
        $steps[] = [
            'id'     => 'system',
            'label'  => 'Servidor Orthanc acessível',
            'ok'     => true,
            'detail' => 'HTTP 200 em ' . $sys['ms'] . 'ms · ' . $this->baseUrl,
            'ms'     => $sys['ms'],
        ];

        // ── PASSO 2: Autenticação ───────────────────────────────────────
        $steps[] = [
            'id'     => 'auth',
            'label'  => 'Autenticação HTTP Basic',
            'ok'     => true,
            'detail' => $this->username
                ? 'Credenciais aceitas — usuário: ' . $this->username
                : 'Acesso público (sem credenciais)',
            'ms'     => null,
        ];

        // ── PASSO 3: Versão e configuração ─────────────────────────────
        $steps[] = [
            'id'     => 'version',
            'label'  => 'Versão e configuração DICOM',
            'ok'     => true,
            'detail' => 'Orthanc v' . ($sysData['Version'] ?? '?')
                . ' · API v' . ($sysData['ApiVersion'] ?? '?')
                . ' · DB v' . ($sysData['DatabaseVersion'] ?? '?')
                . ' · AET: ' . ($sysData['DicomAet'] ?? '?')
                . ' · Porta DICOM: ' . ($sysData['DicomPort'] ?? '?'),
            'ms'     => null,
        ];

        // ── PASSO 4: Estatísticas /statistics ──────────────────────────
        $stat = $this->request('/statistics');
        if ($stat['success']) {
            $d      = $stat['data'];
            $detail = ($d['CountPatients']  ?? '?') . ' pacientes'
                . ' · ' . ($d['CountStudies']   ?? '?') . ' estudos'
                . ' · ' . ($d['CountSeries']    ?? '?') . ' séries'
                . ' · ' . ($d['CountInstances'] ?? '?') . ' instâncias';
            $disco  = $d['TotalDiskSize'] ?? $this->fmtBytes((int)($d['TotalDiskSizeInBytes'] ?? 0));
            if ($disco) $detail .= ' · Disco: ' . $disco;
            $steps[] = ['id' => 'statistics', 'label' => 'Estatísticas do PACS /statistics', 'ok' => true, 'detail' => $detail, 'ms' => $stat['ms']];
        } else {
            $steps[] = ['id' => 'statistics', 'label' => 'Estatísticas do PACS /statistics', 'ok' => false, 'detail' => $stat['error'], 'ms' => $stat['ms']];
        }

        // ── PASSO 5: Plugins /plugins ───────────────────────────────────
        $plug = $this->request('/plugins');
        $plugList = ($plug['success'] && is_array($plug['data'])) ? $plug['data'] : [];
        if ($plug['success']) {
            $detail = count($plugList) > 0
                ? count($plugList) . ' plugin(s): ' . implode(', ', array_slice($plugList, 0, 6))
                : 'Nenhum plugin ativo';
            $steps[] = ['id' => 'plugins', 'label' => 'Plugins /plugins', 'ok' => true, 'detail' => $detail, 'ms' => $plug['ms']];
        } else {
            $steps[] = ['id' => 'plugins', 'label' => 'Plugins /plugins', 'ok' => false, 'detail' => $plug['error'], 'ms' => $plug['ms']];
        }

        // ── PASSO 6: Índice de estudos /studies ────────────────────────
        $studies = $this->request('/studies');
        if ($studies['success'] && is_array($studies['data'])) {
            $n = count($studies['data']);
            $steps[] = ['id' => 'studies', 'label' => 'Índice de estudos /studies', 'ok' => true, 'detail' => number_format($n) . ' estudo(s) indexado(s)', 'ms' => $studies['ms']];
        } else {
            $steps[] = ['id' => 'studies', 'label' => 'Índice de estudos /studies', 'ok' => false, 'detail' => $studies['error'] ?? 'Não disponível', 'ms' => $studies['ms'] ?? null];
        }

        // ── PASSO 7: Pacientes /patients ────────────────────────────────
        $patients = $this->request('/patients');
        if ($patients['success'] && is_array($patients['data'])) {
            $n = count($patients['data']);
            $steps[] = ['id' => 'patients', 'label' => 'Índice de pacientes /patients', 'ok' => true, 'detail' => number_format($n) . ' paciente(s) indexado(s)', 'ms' => $patients['ms']];
        } else {
            $steps[] = ['id' => 'patients', 'label' => 'Índice de pacientes /patients', 'ok' => false, 'detail' => $patients['error'] ?? 'Não disponível', 'ms' => $patients['ms'] ?? null];
        }

        return [
            'success'    => true,
            'steps'      => $steps,
            'system'     => $sysData,
            'statistics' => $stat['success'] ? $stat['data'] : null,
            'plugins'    => $plugList,
        ];
    }

    private function fmtBytes(int $bytes): string {
        if ($bytes <= 0) return '0 B';
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 1) . ' ' . $u[$i];
    }

    // ── Download em Lote ────────────────────────────────────────────────────

    /**
     * Cria um job de archive assíncrono no Orthanc.
     * POST /tools/create-archive
     * Body: {"Resources": ["orthancId1", ...], "Synchronous": false}
     *
     * @param  string[] $orthancIds  Lista de Orthanc Study UUIDs
     * @return array{success: bool, data: array, error?: string}
     */
    public function createArchive(array $orthancIds): array {
        return $this->request('/tools/create-archive', 'POST', [
            'Resources'   => $orthancIds,
            'Synchronous' => false,
        ]);
    }

    /**
     * Consulta o status de um job no Orthanc.
     * GET /jobs/{jobId}
     * Retorna State (Pending|Running|Success|Failure|Paused|Retry) e Progress (0-100).
     *
     * @param  string $jobId  UUID do job retornado por createArchive()
     * @return array{success: bool, data: array, error?: string}
     */
    public function getJob(string $jobId): array {
        return $this->request('/jobs/' . rawurlencode($jobId));
    }

    /**
     * Faz streaming do ZIP gerado pelo job para o cliente, em chunks de 256 KB.
     * GET /jobs/{jobId}/archive
     * O backend atua como proxy autenticado — a URL do Orthanc nunca é exposta ao browser.
     *
     * @param  string   $jobId     UUID do job concluído
     * @param  callable $callback  Recebe cada chunk (string) para enviar ao cliente
     * @return void
     */
    public function streamJobArchive(string $jobId, callable $callback): void {
        $url = $this->baseUrl . '/jobs/' . rawurlencode($jobId) . '/archive';
        $ch  = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_BUFFERSIZE     => 262144,
            CURLOPT_WRITEFUNCTION  => function ($ch, string $data) use ($callback): int {
                $callback($data);
                return strlen($data);
            },
        ];
        if ($this->username !== null && $this->username !== '') {
            $options[CURLOPT_USERPWD]  = $this->username . ':' . ($this->password ?? '');
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        }
        curl_setopt_array($ch, $options);
        curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($curlErr) {
            Logger::error('[OrthancService::streamJobArchive] cURL error: ' . $curlErr, ['job_id' => $jobId]);
        }
    }
}
