-- Campo único de informação administrativa útil ao médico.
-- Rollback não remove colunas ou auditoria automaticamente: a reversão deve preservar
-- rastreabilidade e ser executada apenas por migration corretiva aprovada.
SET search_path TO voxelpacs_mysql_source, public;

ALTER TABLE bi_pacs_estudos
    ADD COLUMN IF NOT EXISTS informacoes_manual TEXT,
    ADD COLUMN IF NOT EXISTS informacoes_manual_em TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS informacoes_manual_por BIGINT;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'chk_bi_pacs_estudos_informacoes_manual_tamanho'
           AND conrelid = 'bi_pacs_estudos'::regclass
    ) THEN
        ALTER TABLE bi_pacs_estudos
            ADD CONSTRAINT chk_bi_pacs_estudos_informacoes_manual_tamanho
            CHECK (informacoes_manual IS NULL OR char_length(informacoes_manual) BETWEEN 3 AND 1000);
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS bi_pacs_estudos_informacoes_auditoria (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    estudo_id BIGINT NOT NULL,
    tinha_informacao_anterior BOOLEAN NOT NULL DEFAULT FALSE,
    tem_informacao_nova BOOLEAN NOT NULL DEFAULT FALSE,
    usuario_id BIGINT NOT NULL,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_bi_pacs_estudos_informacoes_audit_estudo
    ON bi_pacs_estudos_informacoes_auditoria (tenant_id, estudo_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_bi_pacs_estudos_informacoes_audit_usuario
    ON bi_pacs_estudos_informacoes_auditoria (tenant_id, usuario_id, criado_em DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON bi_pacs_estudos_informacoes_auditoria TO voxelpacs_homolog;
GRANT USAGE, SELECT ON SEQUENCE bi_pacs_estudos_informacoes_auditoria_id_seq TO voxelpacs_homolog;

COMMENT ON COLUMN bi_pacs_estudos.informacoes_manual IS
    'Informação administrativa única para ciência do médico; exibida somente a médico autorizado no Laudário.';
