-- VOXEL PACS — Pedidos médicos anexados aos estudos
-- Data: 2026-08-08
-- Compatível com MySQL 5.7 / MariaDB / HostGator compartilhado.
-- O arquivo físico fica fora de public/; esta tabela guarda apenas metadados e
-- o caminho relativo privado do documento.
-- ============================================================================
-- Idempotência: CREATE TABLE IF NOT EXISTS, conforme o padrão das migrations
-- recentes de arquivos privados do repositório.

CREATE TABLE IF NOT EXISTS `bi_pacs_estudos_pedidos` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL COMMENT 'Tenant explícito para defesa em profundidade',
    `estudo_id`        INT UNSIGNED NOT NULL COMMENT 'Vínculo funcional com bi_pacs_estudos.id',
    `nome_original`    VARCHAR(255) NOT NULL COMMENT 'Nome exibido ao usuário',
    `nome_arquivo`     VARCHAR(180) NOT NULL COMMENT 'Nome interno aleatório armazenado no disco',
    `mime_type`        VARCHAR(100) NOT NULL COMMENT 'MIME validado pelo conteúdo real do arquivo',
    `extensao`         VARCHAR(12) NOT NULL,
    `tamanho_bytes`    INT UNSIGNED NOT NULL,
    `hash_sha256`      CHAR(64) NOT NULL,
    `caminho_arquivo`  VARCHAR(500) NOT NULL COMMENT 'Path relativo dentro de storage/uploads/pedidos_medicos/',
    `usuario_id`       INT UNSIGNED NOT NULL COMMENT 'Usuário que anexou ou substituiu o documento',
    `criado_em`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pedido_tenant_estudo` (`tenant_id`, `estudo_id`),
    INDEX `idx_pedido_tenant` (`tenant_id`),
    INDEX `idx_pedido_estudo` (`estudo_id`),
    INDEX `idx_pedido_hash` (`hash_sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Pedido médico anexado ao estudo DICOM';

-- VERIFICAÇÃO: DESCRIBE bi_pacs_estudos_pedidos;
-- ROLLBACK: DROP TABLE IF EXISTS `bi_pacs_estudos_pedidos`;
