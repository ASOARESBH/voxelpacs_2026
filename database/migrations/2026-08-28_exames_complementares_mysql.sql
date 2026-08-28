-- Anexo complementar privado por estudo. Migration aditiva e idempotente para MySQL/MariaDB.
CREATE TABLE IF NOT EXISTS `bi_pacs_estudos_exames_complementares` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `estudo_id` BIGINT UNSIGNED NOT NULL,
    `nome_original` VARCHAR(255) NOT NULL,
    `nome_arquivo` VARCHAR(180) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `extensao` VARCHAR(12) NOT NULL,
    `tamanho_bytes` BIGINT UNSIGNED NOT NULL,
    `hash_sha256` CHAR(64) NOT NULL,
    `caminho_arquivo` VARCHAR(500) NOT NULL,
    `usuario_id` BIGINT UNSIGNED NOT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_exame_comp_tenant_estudo` (`tenant_id`, `estudo_id`),
    KEY `idx_exame_comp_tenant_estudo` (`tenant_id`, `estudo_id`, `id`),
    KEY `idx_exame_comp_hash` (`hash_sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Anexo complementar privado vinculado ao estudo DICOM';
