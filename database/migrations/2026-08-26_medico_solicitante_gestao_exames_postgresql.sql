-- Médico solicitante manual e histórico administrativo da Gestão de Exames.
-- Rollback não remove colunas ou histórico automaticamente: a reversão deve preservar
-- rastreabilidade clínica e ser executada somente após migração corretiva aprovada.
SET search_path TO voxelpacs_mysql_source, public;

ALTER TABLE bi_pacs_estudos
    ADD COLUMN IF NOT EXISTS medico_solicitante_manual VARCHAR(180),
    ADD COLUMN IF NOT EXISTS medico_solicitante_manual_em TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS medico_solicitante_manual_por BIGINT;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'chk_bi_pacs_estudos_solicitante_manual_tamanho'
           AND conrelid = 'bi_pacs_estudos'::regclass
    ) THEN
        ALTER TABLE bi_pacs_estudos
            ADD CONSTRAINT chk_bi_pacs_estudos_solicitante_manual_tamanho
            CHECK (medico_solicitante_manual IS NULL OR char_length(medico_solicitante_manual) BETWEEN 3 AND 180);
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS bi_pacs_estudos_solicitante_auditoria (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    estudo_id BIGINT NOT NULL,
    solicitante_anterior VARCHAR(180),
    solicitante_novo VARCHAR(180),
    usuario_id BIGINT NOT NULL,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_bi_pacs_estudos_solicitante_manual_tenant
    ON bi_pacs_estudos (tenant_id, medico_solicitante_manual)
    WHERE medico_solicitante_manual IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_bi_pacs_estudos_solicitante_audit_estudo
    ON bi_pacs_estudos_solicitante_auditoria (tenant_id, estudo_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_bi_pacs_estudos_solicitante_audit_usuario
    ON bi_pacs_estudos_solicitante_auditoria (tenant_id, usuario_id, criado_em DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON bi_pacs_estudos_solicitante_auditoria TO voxelpacs_homolog;
GRANT USAGE, SELECT ON SEQUENCE bi_pacs_estudos_solicitante_auditoria_id_seq TO voxelpacs_homolog;

COMMENT ON COLUMN bi_pacs_estudos.medico_solicitante_manual IS
    'Sobrescrita administrativa auditável do médico solicitante; preserva a tag DICOM original em referring_physician_name.';
