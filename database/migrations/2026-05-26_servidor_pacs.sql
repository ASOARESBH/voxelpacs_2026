-- ============================================================
-- VOXEL B.I — Módulo Servidor PACS (Orthanc)
-- Migração: 2026-05-26
-- Banco: MariaDB / MySQL 5.7+
-- ============================================================

-- Tabela principal do servidor PACS (único servidor global, gerenciado pelo superadmin)
CREATE TABLE IF NOT EXISTS `bi_pacs_servidor` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nome`              VARCHAR(255) NOT NULL DEFAULT 'Orthanc Principal',
    `url`               VARCHAR(500) NOT NULL COMMENT 'Ex: http://46.225.51.122:8042',
    `usuario`           VARCHAR(255) NULL COMMENT 'Usuário HTTP Basic Auth (opcional)',
    `senha`             VARCHAR(500) NULL COMMENT 'Senha HTTP Basic Auth (opcional)',
    `timeout`           INT NOT NULL DEFAULT 30 COMMENT 'Timeout em segundos',
    `ativo`             TINYINT(1) NOT NULL DEFAULT 1,
    `versao`            VARCHAR(50) NULL COMMENT 'Versão do Orthanc detectada no último ping',
    `dicom_aet`         VARCHAR(64) NULL COMMENT 'AETitle do servidor Orthanc',
    `dicom_port`        INT NULL DEFAULT 4242,
    `status_ping`       ENUM('online','offline','erro','nunca_testado') NOT NULL DEFAULT 'nunca_testado',
    `ultimo_ping`       DATETIME NULL,
    `total_estudos`     INT NULL DEFAULT 0,
    `total_pacientes`   INT NULL DEFAULT 0,
    `total_series`      INT NULL DEFAULT 0,
    `total_instancias`  INT NULL DEFAULT 0,
    `disk_size_mb`      BIGINT NULL DEFAULT 0,
    `observacoes`       TEXT NULL,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Servidor Orthanc PACS global da plataforma';

-- Tabela de roteamento: mapeia InstitutionName/AETitle → Negócio (tenant)
CREATE TABLE IF NOT EXISTS `bi_pacs_roteamento` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `servidor_id`       INT UNSIGNED NOT NULL,
    `tenant_id`         INT UNSIGNED NOT NULL,
    `institution_name`  VARCHAR(255) NOT NULL COMMENT 'Valor do campo DICOM InstitutionName (0008,0080)',
    `aetitle`           VARCHAR(64) NULL COMMENT 'AETitle da modalidade (opcional)',
    `descricao`         VARCHAR(255) NULL,
    `ativo`             TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_servidor_institution` (`servidor_id`, `institution_name`),
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_institution` (`institution_name`),
    CONSTRAINT `fk_rot_servidor` FOREIGN KEY (`servidor_id`) REFERENCES `bi_pacs_servidor`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rot_tenant`   FOREIGN KEY (`tenant_id`)   REFERENCES `bi_tenants`(`id`)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Roteamento DICOM: InstitutionName → Negócio';

-- Cache de estudos importados do Orthanc
CREATE TABLE IF NOT EXISTS `bi_pacs_estudos` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `servidor_id`           INT UNSIGNED NOT NULL,
    `tenant_id`             INT UNSIGNED NULL COMMENT 'NULL = não roteado ainda',
    `orthanc_id`            VARCHAR(64) NOT NULL COMMENT 'ID interno do Orthanc (UUID)',
    `study_instance_uid`    VARCHAR(255) NULL,
    `accession_number`      VARCHAR(100) NULL,
    `institution_name`      VARCHAR(255) NULL,
    `patient_id`            VARCHAR(100) NULL,
    `patient_name`          VARCHAR(255) NULL,
    `patient_birth_date`    DATE NULL,
    `patient_sex`           CHAR(1) NULL,
    `study_date`            DATE NULL,
    `study_time`            TIME NULL,
    `study_description`     VARCHAR(500) NULL,
    `modalities`            VARCHAR(255) NULL COMMENT 'Lista de modalidades separadas por vírgula',
    `num_series`            INT NOT NULL DEFAULT 0,
    `num_instances`         INT NOT NULL DEFAULT 0,
    `is_stable`             TINYINT(1) NOT NULL DEFAULT 0,
    `last_update_orthanc`   DATETIME NULL,
    `importado_em`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_orthanc_id` (`orthanc_id`),
    INDEX `idx_servidor` (`servidor_id`),
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_institution` (`institution_name`),
    INDEX `idx_study_date` (`study_date`),
    INDEX `idx_patient_id` (`patient_id`),
    CONSTRAINT `fk_est_servidor` FOREIGN KEY (`servidor_id`) REFERENCES `bi_pacs_servidor`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cache de estudos DICOM importados do Orthanc';

-- Log de sincronizações com o Orthanc
CREATE TABLE IF NOT EXISTS `bi_pacs_sync_log` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `servidor_id`       INT UNSIGNED NOT NULL,
    `iniciado_em`       DATETIME NOT NULL,
    `finalizado_em`     DATETIME NULL,
    `status`            ENUM('em_andamento','concluido','erro') NOT NULL DEFAULT 'em_andamento',
    `estudos_novos`     INT NOT NULL DEFAULT 0,
    `estudos_atualizados` INT NOT NULL DEFAULT 0,
    `estudos_roteados`  INT NOT NULL DEFAULT 0,
    `erros`             INT NOT NULL DEFAULT 0,
    `mensagem`          TEXT NULL,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_log_servidor` FOREIGN KEY (`servidor_id`) REFERENCES `bi_pacs_servidor`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Log de sincronizações PACS';

-- Seed: servidor padrão Orthanc
INSERT IGNORE INTO `bi_pacs_servidor` 
    (`id`, `nome`, `url`, `timeout`, `ativo`, `dicom_aet`, `dicom_port`, `status_ping`)
VALUES 
    (1, 'Orthanc VOXEL (Hetzner)', 'http://46.225.51.122:8042', 30, 1, 'ORTHANCPACS', 4242, 'nunca_testado');
