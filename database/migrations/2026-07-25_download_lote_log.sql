-- =============================================================================
-- Migration: 2026-07-25_download_lote_log.sql
-- Descrição:  Tabela de auditoria de downloads em lote de estudos DICOM.
--             Registra usuário, estudos baixados, job Orthanc e timestamp
--             para fins de compliance (dado de saúde sensível).
-- Ambiente:   MySQL 5.7 / MariaDB — HostGator compartilhado
-- Charset:    utf8 / utf8_unicode_ci (NÃO utf8mb4)
-- Idempotente: SIM
-- =============================================================================

SET @dbname  = DATABASE();
SET @tblname = 'bi_download_lote_log';

SET @tbl_exists = (
    SELECT COUNT(*)
    FROM   INFORMATION_SCHEMA.TABLES
    WHERE  TABLE_SCHEMA = @dbname
      AND  TABLE_NAME   = @tblname
);

SET @sql_create = IF(
    @tbl_exists = 0,
    'CREATE TABLE `bi_download_lote_log` (
        `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `tenant_id`       INT UNSIGNED NOT NULL              COMMENT ''Tenant (negócio) do usuário'',
        `usuario_id`      INT UNSIGNED NOT NULL              COMMENT ''bi_users.id do usuário que baixou'',
        `usuario_nome`    VARCHAR(120) NOT NULL DEFAULT ''''  COMMENT ''Nome snapshot para auditoria'',
        `estudo_ids`      TEXT         NOT NULL              COMMENT ''JSON array de bi_pacs_estudos.id'',
        `orthanc_ids`     TEXT         NOT NULL              COMMENT ''JSON array de orthanc_id usados'',
        `orthanc_job_id`  VARCHAR(64)  NULL                  COMMENT ''Job ID retornado pelo Orthanc'',
        `status`          ENUM(''iniciado'',''concluido'',''erro'') NOT NULL DEFAULT ''iniciado'',
        `erro_msg`        TEXT         NULL                  COMMENT ''Mensagem de erro se status=erro'',
        `ip`              VARCHAR(45)  NULL                  COMMENT ''IP do cliente'',
        `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `concluido_at`    DATETIME     NULL,
        PRIMARY KEY (`id`),
        INDEX `idx_dll_tenant`    (`tenant_id`),
        INDEX `idx_dll_usuario`   (`usuario_id`),
        INDEX `idx_dll_job`       (`orthanc_job_id`),
        INDEX `idx_dll_created`   (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci
      COMMENT=''Auditoria de downloads em lote de estudos DICOM''',
    'SELECT ''Tabela bi_download_lote_log já existe — nenhuma alteração necessária'' AS info'
);

PREPARE stmt_tbl FROM @sql_create;
EXECUTE stmt_tbl;
DEALLOCATE PREPARE stmt_tbl;
