-- Paridade MySQL da migration de Informações para ambientes que ainda usam este dialeto.
ALTER TABLE bi_pacs_estudos
    ADD COLUMN IF NOT EXISTS informacoes_manual TEXT NULL,
    ADD COLUMN IF NOT EXISTS informacoes_manual_em DATETIME NULL,
    ADD COLUMN IF NOT EXISTS informacoes_manual_por BIGINT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS bi_pacs_estudos_informacoes_auditoria (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    estudo_id BIGINT UNSIGNED NOT NULL,
    tinha_informacao_anterior TINYINT(1) NOT NULL DEFAULT 0,
    tem_informacao_nova TINYINT(1) NOT NULL DEFAULT 0,
    usuario_id BIGINT UNSIGNED NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bi_pacs_estudos_informacoes_audit_estudo (tenant_id, estudo_id, id),
    KEY idx_bi_pacs_estudos_informacoes_audit_usuario (tenant_id, usuario_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
