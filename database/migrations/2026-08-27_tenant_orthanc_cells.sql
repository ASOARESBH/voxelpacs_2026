-- =============================================================================
-- MIGRATION: 2026-08-27_tenant_orthanc_cells.sql
-- Objetivo: representar uma célula Orthanc exclusiva por tenant e permitir que
--           o mesmo orthanc_id exista com segurança em servidores distintos.
-- Dialeto: MySQL/MariaDB de produção atual.
--
-- PRÉ-REQUISITOS OPERACIONAIS:
--   1. Backup consistente do banco da aplicação.
--   2. Janela de baixo tráfego: o ALTER TABLE pode bloquear bi_pacs_estudos.
--   3. Validar antes e depois: SELECT servidor_id, orthanc_id, COUNT(*) ...
--   4. Não cadastrar endpoint público: url em bi_pacs_servidor deve ser privado.
--
-- ROLLBACK: remover uma célula apenas após desativar sincronização e confirmar
-- que não há estudos. Não recrie uq_orthanc_id global se houver mais de um
-- servidor, pois IDs internos Orthanc podem colidir entre células independentes.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bi_tenant_orthanc_cells` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`         INT UNSIGNED NOT NULL,
    `servidor_id`       INT UNSIGNED NOT NULL,
    `profile`           ENUM('vpn_mtls','vpn_only','site_router') NOT NULL,
    `gateway_route_key` VARCHAR(64) NOT NULL,
    `viewer_url`        VARCHAR(500) NULL COMMENT 'Origem OHIF exclusiva e segregada do tenant',
    `status`            ENUM('provisioned','active','suspended','retired') NOT NULL DEFAULT 'provisioned',
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cell_tenant` (`tenant_id`),
    UNIQUE KEY `uq_cell_servidor` (`servidor_id`),
    UNIQUE KEY `uq_cell_gateway_route` (`gateway_route_key`),
    CONSTRAINT `fk_cell_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `bi_tenants`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_cell_servidor` FOREIGN KEY (`servidor_id`) REFERENCES `bi_pacs_servidor`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Célula Orthanc exclusiva por tenant, sem credenciais ou PHI';

-- bi_pacs_estudos foi criado com UNIQUE(orthanc_id), válido somente com um
-- Orthanc global. Em modelo multi-célula a identidade é (servidor_id, orthanc_id).
SET @has_old_uq := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'bi_pacs_estudos'
      AND index_name = 'uq_orthanc_id'
);
SET @drop_old_uq_sql := IF(
    @has_old_uq > 0,
    'ALTER TABLE bi_pacs_estudos DROP INDEX uq_orthanc_id',
    'SELECT ''uq_orthanc_id ausente; nenhuma remoção necessária'''
);
PREPARE stmt FROM @drop_old_uq_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_new_uq := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'bi_pacs_estudos'
      AND index_name = 'uq_estudo_servidor_orthanc'
);
SET @add_new_uq_sql := IF(
    @has_new_uq = 0,
    'ALTER TABLE bi_pacs_estudos ADD UNIQUE KEY uq_estudo_servidor_orthanc (servidor_id, orthanc_id)',
    'SELECT ''uq_estudo_servidor_orthanc já existe'''
);
PREPARE stmt FROM @add_new_uq_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_server_tenant_idx := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'bi_pacs_estudos'
      AND index_name = 'idx_estudo_servidor_tenant'
);
SET @add_server_tenant_idx_sql := IF(
    @has_server_tenant_idx = 0,
    'ALTER TABLE bi_pacs_estudos ADD INDEX idx_estudo_servidor_tenant (servidor_id, tenant_id)',
    'SELECT ''idx_estudo_servidor_tenant já existe'''
);
PREPARE stmt FROM @add_server_tenant_idx_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Validação pós-migration (não incluir conteúdo DICOM/PHI em logs):
-- SHOW INDEX FROM bi_pacs_estudos;
-- SELECT servidor_id, orthanc_id, COUNT(*) AS quantidade
-- FROM bi_pacs_estudos GROUP BY servidor_id, orthanc_id HAVING quantidade > 1;
