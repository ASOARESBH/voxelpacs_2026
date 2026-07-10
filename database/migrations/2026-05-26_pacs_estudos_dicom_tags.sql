-- ============================================================
-- VOXEL B.I — Migração: Expansão de TAGs DICOM em bi_pacs_estudos
-- Data: 2026-05-26
-- Descrição: Adiciona todas as TAGs DICOM relevantes à tabela
--            bi_pacs_estudos para cruzamentos analíticos completos.
--            Execute APÓS 2026-05-26_servidor_pacs.sql
-- ============================================================

-- ============================================================
-- RECRIA a tabela bi_pacs_estudos com TODAS as TAGs DICOM
-- (DROP seguro — só executa se existir)
-- ============================================================

DROP TABLE IF EXISTS `bi_pacs_estudos`;

CREATE TABLE `bi_pacs_estudos` (
    -- --------------------------------------------------------
    -- CHAVES E CONTROLE INTERNO
    -- --------------------------------------------------------
    `id`                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `servidor_id`               INT UNSIGNED NOT NULL                   COMMENT 'FK → bi_pacs_servidor.id',
    `tenant_id`                 INT UNSIGNED NULL                       COMMENT 'FK → bi_tenants.id (NULL = não roteado)',
    `orthanc_id`                VARCHAR(64)  NOT NULL                   COMMENT 'UUID interno do Orthanc',
    `importado_em`              TIMESTAMP    DEFAULT CURRENT_TIMESTAMP  COMMENT 'Data da primeira importação',
    `atualizado_em`             TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- --------------------------------------------------------
    -- GRUPO: PATIENT (0010,xxxx)
    -- --------------------------------------------------------
    `patient_id`                VARCHAR(64)  NULL  COMMENT '(0010,0020) PatientID',
    `patient_name`              VARCHAR(255) NULL  COMMENT '(0010,0010) PatientName — formato DICOM nome^sobrenome',
    `patient_name_display`      VARCHAR(255) NULL  COMMENT 'Nome formatado para exibição (Sobrenome Nome)',
    `patient_birth_date`        DATE         NULL  COMMENT '(0010,0030) PatientBirthDate',
    `patient_sex`               CHAR(1)      NULL  COMMENT '(0010,0040) PatientSex: M/F/O',
    `patient_age`               VARCHAR(10)  NULL  COMMENT '(0010,1010) PatientAge — ex: 045Y',
    `patient_weight`            DECIMAL(6,2) NULL  COMMENT '(0010,1030) PatientWeight (kg)',
    `patient_size`              DECIMAL(5,3) NULL  COMMENT '(0010,1020) PatientSize (m)',
    `patient_comments`          TEXT         NULL  COMMENT '(0010,4000) PatientComments',
    `patient_identity_removed`  CHAR(3)      NULL  COMMENT '(0012,0062) PatientIdentityRemoved: YES/NO',
    `responsible_person`        VARCHAR(255) NULL  COMMENT '(0010,2297) ResponsiblePerson (veterinária)',
    `responsible_organization`  VARCHAR(255) NULL  COMMENT '(0010,2299) ResponsibleOrganization (veterinária)',
    `patient_species_desc`      VARCHAR(255) NULL  COMMENT '(0010,2201) PatientSpeciesDescription',
    `patient_breed_desc`        VARCHAR(255) NULL  COMMENT '(0010,2292) PatientBreedDescription',

    -- --------------------------------------------------------
    -- GRUPO: STUDY (0008,xxxx / 0020,xxxx)
    -- --------------------------------------------------------
    `study_instance_uid`        VARCHAR(255) NULL  COMMENT '(0020,000D) StudyInstanceUID',
    `study_date`                DATE         NULL  COMMENT '(0008,0020) StudyDate',
    `study_time`                TIME         NULL  COMMENT '(0008,0030) StudyTime',
    `study_description`         VARCHAR(500) NULL  COMMENT '(0008,1030) StudyDescription',
    `accession_number`          VARCHAR(100) NULL  COMMENT '(0008,0050) AccessionNumber',
    `study_id`                  VARCHAR(64)  NULL  COMMENT '(0020,0010) StudyID',
    `referring_physician_name`  VARCHAR(255) NULL  COMMENT '(0008,0090) ReferringPhysicianName',
    `name_of_physicians_reading`VARCHAR(255) NULL  COMMENT '(0008,1060) NameOfPhysiciansReadingStudy',
    `admitting_diagnoses_desc`  TEXT         NULL  COMMENT '(0008,1080) AdmittingDiagnosesDescription',
    `additional_patient_history`TEXT         NULL  COMMENT '(0010,21B0) AdditionalPatientHistory',
    `requested_procedure_desc`  VARCHAR(500) NULL  COMMENT '(0032,1070) RequestedProcedureDescription',
    `requested_procedure_id`    VARCHAR(64)  NULL  COMMENT '(0040,1001) RequestedProcedureID',
    `scheduled_procedure_step_id` VARCHAR(64) NULL COMMENT '(0040,0009) ScheduledProcedureStepID',

    -- --------------------------------------------------------
    -- GRUPO: EQUIPMENT / INSTITUTION (0008,xxxx)
    -- --------------------------------------------------------
    `institution_name`          VARCHAR(255) NULL  COMMENT '(0008,0080) InstitutionName',
    `institution_address`       VARCHAR(500) NULL  COMMENT '(0008,0081) InstitutionAddress',
    `institutional_dept_name`   VARCHAR(255) NULL  COMMENT '(0008,1040) InstitutionalDepartmentName',
    `station_name`              VARCHAR(64)  NULL  COMMENT '(0008,1010) StationName',
    `manufacturer`              VARCHAR(255) NULL  COMMENT '(0008,0070) Manufacturer',
    `manufacturer_model_name`   VARCHAR(255) NULL  COMMENT '(0008,1090) ManufacturerModelName',
    `device_serial_number`      VARCHAR(100) NULL  COMMENT '(0018,1000) DeviceSerialNumber',
    `software_versions`         VARCHAR(255) NULL  COMMENT '(0018,1020) SoftwareVersions',
    `operators_name`            VARCHAR(255) NULL  COMMENT '(0008,1070) OperatorsName',
    `performing_physician_name` VARCHAR(255) NULL  COMMENT '(0008,1050) PerformingPhysicianName',

    -- --------------------------------------------------------
    -- GRUPO: SERIES (0008,xxxx / 0018,xxxx / 0020,xxxx)
    -- --------------------------------------------------------
    `modalities`                VARCHAR(255) NULL  COMMENT '(0008,0061) ModalitiesInStudy — lista separada por \\',
    `num_series`                INT          NOT NULL DEFAULT 0 COMMENT 'Número de séries',
    `num_instances`             INT          NOT NULL DEFAULT 0 COMMENT 'Número total de instâncias (imagens)',

    -- --------------------------------------------------------
    -- GRUPO: SOP / TRANSFER (0002,xxxx / 0008,xxxx)
    -- --------------------------------------------------------
    `sop_classes_in_study`      TEXT         NULL  COMMENT '(0008,0062) SOPClassesInStudy — lista JSON',
    `specific_character_set`    VARCHAR(64)  NULL  COMMENT '(0008,0005) SpecificCharacterSet',

    -- --------------------------------------------------------
    -- GRUPO: ACQUISITION / IMAGEM (0018,xxxx / 0028,xxxx)
    -- --------------------------------------------------------
    `body_part_examined`        VARCHAR(100) NULL  COMMENT '(0018,0015) BodyPartExamined',
    `protocol_name`             VARCHAR(255) NULL  COMMENT '(0018,1030) ProtocolName',
    `contrast_bolus_agent`      VARCHAR(255) NULL  COMMENT '(0018,0010) ContrastBolusAgent',
    `scanning_sequence`         VARCHAR(100) NULL  COMMENT '(0018,0020) ScanningSequence (MR)',
    `sequence_variant`          VARCHAR(100) NULL  COMMENT '(0018,0021) SequenceVariant (MR)',
    `scan_options`              VARCHAR(255) NULL  COMMENT '(0018,0022) ScanOptions',
    `mr_acquisition_type`       VARCHAR(10)  NULL  COMMENT '(0018,0023) MRAcquisitionType: 2D/3D',
    `slice_thickness`           DECIMAL(8,4) NULL  COMMENT '(0050,0018) SliceThickness (mm)',
    `kvp`                       DECIMAL(8,2) NULL  COMMENT '(0018,0060) KVP — kV do tubo (CR/CT/DX)',
    `exposure_time`             INT          NULL  COMMENT '(0018,1150) ExposureTime (ms)',
    `x_ray_tube_current`        INT          NULL  COMMENT '(0018,1151) XRayTubeCurrent (mA)',
    `exposure`                  INT          NULL  COMMENT '(0018,1152) Exposure (mAs)',
    `exposure_in_uas`           INT          NULL  COMMENT '(0018,1153) ExposureInuAs',
    `image_and_fluoroscopy_area_dose_product` DECIMAL(12,4) NULL COMMENT '(0018,115E) ImageAndFluoroscopyAreaDoseProduct',
    `distance_source_to_detector` DECIMAL(8,2) NULL COMMENT '(0018,1110) DistanceSourceToDetector (mm)',
    `distance_source_to_patient`  DECIMAL(8,2) NULL COMMENT '(0018,1111) DistanceSourceToPatient (mm)',
    `field_of_view_dimensions`  VARCHAR(64)  NULL  COMMENT '(0018,1149) FieldOfViewDimensions',
    `collimator_shape`          VARCHAR(64)  NULL  COMMENT '(0018,1700) CollimatorShape',
    `pixel_spacing`             VARCHAR(64)  NULL  COMMENT '(0028,0030) PixelSpacing',
    `rows`                      INT          NULL  COMMENT '(0028,0010) Rows',
    `columns`                   INT          NULL  COMMENT '(0028,0011) Columns',
    `bits_allocated`            SMALLINT     NULL  COMMENT '(0028,0100) BitsAllocated',
    `bits_stored`               SMALLINT     NULL  COMMENT '(0028,0101) BitsStored',
    `photometric_interpretation`VARCHAR(32)  NULL  COMMENT '(0028,0004) PhotometricInterpretation',
    `samples_per_pixel`         SMALLINT     NULL  COMMENT '(0028,0002) SamplesPerPixel',
    `window_center`             VARCHAR(64)  NULL  COMMENT '(0028,1050) WindowCenter',
    `window_width`              VARCHAR(64)  NULL  COMMENT '(0028,1051) WindowWidth',
    `rescale_intercept`         DECIMAL(12,4) NULL COMMENT '(0028,1052) RescaleIntercept',
    `rescale_slope`             DECIMAL(12,6) NULL COMMENT '(0028,1053) RescaleSlope',

    -- --------------------------------------------------------
    -- GRUPO: CT ESPECÍFICO (0018,xxxx)
    -- --------------------------------------------------------
    `ct_acquisition_type`       VARCHAR(64)  NULL  COMMENT '(0018,9302) CTAcquisitionType',
    `reconstruction_diameter`   DECIMAL(8,2) NULL  COMMENT '(0018,1100) ReconstructionDiameter (mm)',
    `convolution_kernel`        VARCHAR(64)  NULL  COMMENT '(0018,1210) ConvolutionKernel',
    `gantry_detector_tilt`      DECIMAL(8,4) NULL  COMMENT '(0018,1120) GantryDetectorTilt (graus)',
    `table_height`              DECIMAL(8,2) NULL  COMMENT '(0018,1130) TableHeight (mm)',
    `rotation_direction`        CHAR(3)      NULL  COMMENT '(0018,1140) RotationDirection: CW/CC',
    `spiral_pitch_factor`       DECIMAL(8,4) NULL  COMMENT '(0018,9311) SpiralPitchFactor',
    `ctdi_vol`                  DECIMAL(10,4) NULL COMMENT '(0018,9345) CTDIvol (mGy)',
    `data_collection_diameter`  DECIMAL(8,2) NULL  COMMENT '(0018,0090) DataCollectionDiameter (mm)',
    `number_of_slices`          INT          NULL  COMMENT '(0054,0081) NumberOfSlices',

    -- --------------------------------------------------------
    -- GRUPO: MR ESPECÍFICO (0018,xxxx)
    -- --------------------------------------------------------
    `repetition_time`           DECIMAL(12,4) NULL COMMENT '(0018,0080) RepetitionTime (ms)',
    `echo_time`                 DECIMAL(12,4) NULL COMMENT '(0018,0081) EchoTime (ms)',
    `inversion_time`            DECIMAL(12,4) NULL COMMENT '(0018,0082) InversionTime (ms)',
    `echo_train_length`         INT          NULL  COMMENT '(0018,0091) EchoTrainLength',
    `flip_angle`                DECIMAL(8,4) NULL  COMMENT '(0018,1314) FlipAngle (graus)',
    `sar`                       DECIMAL(10,4) NULL COMMENT '(0018,1316) SAR (W/kg)',
    `magnetic_field_strength`   DECIMAL(6,2) NULL  COMMENT '(0018,0087) MagneticFieldStrength (T)',
    `imaging_frequency`         DECIMAL(12,6) NULL COMMENT '(0018,0084) ImagingFrequency (MHz)',
    `imaged_nucleus`            VARCHAR(16)  NULL  COMMENT '(0018,0085) ImagedNucleus',
    `number_of_averages`        DECIMAL(8,4) NULL  COMMENT '(0018,0083) NumberOfAverages',
    `percent_sampling`          DECIMAL(8,4) NULL  COMMENT '(0018,0093) PercentSampling',
    `percent_phase_field_of_view` DECIMAL(8,4) NULL COMMENT '(0018,0094) PercentPhaseFieldOfView',
    `receive_coil_name`         VARCHAR(100) NULL  COMMENT '(0018,1250) ReceiveCoilName',
    `transmit_coil_name`        VARCHAR(100) NULL  COMMENT '(0018,1251) TransmitCoilName',
    `in_plane_phase_encoding_direction` VARCHAR(16) NULL COMMENT '(0018,1312) InPlanePhaseEncodingDirection',
    `diffusion_b_value`         DECIMAL(10,4) NULL COMMENT '(0018,9087) DiffusionBValue',

    -- --------------------------------------------------------
    -- GRUPO: US ESPECÍFICO (0018,xxxx)
    -- --------------------------------------------------------
    `ultrasound_color_data_present` CHAR(3) NULL COMMENT '(0028,0014) UltrasoundColorDataPresent: YES/NO',
    `mechanical_index`          DECIMAL(8,4) NULL  COMMENT '(0018,5022) MechanicalIndex',
    `bone_thermal_index`        DECIMAL(8,4) NULL  COMMENT '(0018,5024) BoneThermalIndex',
    `cranial_thermal_index`     DECIMAL(8,4) NULL  COMMENT '(0018,5026) CranialThermalIndex',
    `soft_tissue_thermal_index` DECIMAL(8,4) NULL  COMMENT '(0018,5027) SoftTissueThermalIndex',

    -- --------------------------------------------------------
    -- GRUPO: NM / PET ESPECÍFICO (0054,xxxx)
    -- --------------------------------------------------------
    `radionuclide_code_value`   VARCHAR(64)  NULL  COMMENT '(0054,0300) RadionuclideCodeSequence > CodeValue',
    `radiopharmaceutical`       VARCHAR(255) NULL  COMMENT '(0018,0031) Radiopharmaceutical',
    `radionuclide_total_dose`   DECIMAL(14,6) NULL COMMENT '(0018,1074) RadionuclideTotalDose (Bq)',
    `radionuclide_half_life`    DECIMAL(14,4) NULL COMMENT '(0018,1075) RadionuclideHalfLife (s)',
    `radiopharmaceutical_start_time` TIME NULL     COMMENT '(0018,1072) RadiopharmaceuticalStartTime',

    -- --------------------------------------------------------
    -- GRUPO: DOSE / RADIATION (0018,xxxx / 0040,xxxx)
    -- --------------------------------------------------------
    `dose_reference_uid`        VARCHAR(255) NULL  COMMENT '(300A,0013) DoseReferenceUID',
    `organ_dose`                DECIMAL(12,6) NULL COMMENT '(0040,0316) OrganDose (Gy)',
    `entrance_dose_in_mgy`      DECIMAL(12,6) NULL COMMENT '(0040,8302) EntranceDoseInmGy',
    `dose_area_product`         DECIMAL(14,6) NULL COMMENT '(0018,115E) DoseAreaProduct (Gy·cm²)',

    -- --------------------------------------------------------
    -- GRUPO: WORKFLOW / WORKLIST (0040,xxxx / 0032,xxxx)
    -- --------------------------------------------------------
    `placer_order_number`       VARCHAR(100) NULL  COMMENT '(0040,2016) PlacerOrderNumberImagingServiceRequest',
    `filler_order_number`       VARCHAR(100) NULL  COMMENT '(0040,2017) FillerOrderNumberImagingServiceRequest',
    `order_entered_by`          VARCHAR(255) NULL  COMMENT '(0040,2008) OrderEnteredBy',
    `order_enterer_location`    VARCHAR(255) NULL  COMMENT '(0040,2009) OrderEntererLocation',
    `reason_for_requested_procedure` TEXT NULL     COMMENT '(0040,1002) ReasonForRequestedProcedure',
    `current_patient_location`  VARCHAR(255) NULL  COMMENT '(0038,0300) CurrentPatientLocation',
    `patient_state`             VARCHAR(255) NULL  COMMENT '(0038,0500) PatientState',
    `admission_id`              VARCHAR(64)  NULL  COMMENT '(0038,0010) AdmissionID',
    `issuer_of_admission_id`    VARCHAR(64)  NULL  COMMENT '(0038,0011) IssuerOfAdmissionID',

    -- --------------------------------------------------------
    -- GRUPO: ORTHANC METADATA (controle interno)
    -- --------------------------------------------------------
    `is_stable`                 TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Estudo estável no Orthanc',
    `last_update_orthanc`       DATETIME     NULL               COMMENT 'Última atualização no Orthanc',
    `orthanc_parent_patient`    VARCHAR(64)  NULL               COMMENT 'ID do paciente no Orthanc',
    `tags_raw`                  JSON         NULL               COMMENT 'JSON completo das MainDicomTags para consultas futuras',

    -- --------------------------------------------------------
    -- ÍNDICES
    -- --------------------------------------------------------
    UNIQUE KEY `uq_orthanc_id`          (`orthanc_id`),
    INDEX `idx_servidor`                (`servidor_id`),
    INDEX `idx_tenant`                  (`tenant_id`),
    INDEX `idx_institution`             (`institution_name`),
    INDEX `idx_study_date`              (`study_date`),
    INDEX `idx_patient_id`              (`patient_id`),
    INDEX `idx_patient_name`            (`patient_name`),
    INDEX `idx_accession`               (`accession_number`),
    INDEX `idx_study_uid`               (`study_instance_uid`(64)),
    INDEX `idx_modalities`              (`modalities`),
    INDEX `idx_body_part`               (`body_part_examined`),
    INDEX `idx_referring_physician`     (`referring_physician_name`),
    INDEX `idx_performing_physician`    (`performing_physician_name`),
    INDEX `idx_station_name`            (`station_name`),
    INDEX `idx_manufacturer`            (`manufacturer`),
    INDEX `idx_tenant_date`             (`tenant_id`, `study_date`),
    INDEX `idx_tenant_modality`         (`tenant_id`, `modalities`),
    INDEX `idx_tenant_institution`      (`tenant_id`, `institution_name`),

    CONSTRAINT `fk_est_servidor` FOREIGN KEY (`servidor_id`) REFERENCES `bi_pacs_servidor`(`id`) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cache completo de estudos DICOM importados do Orthanc com todas as TAGs relevantes';
