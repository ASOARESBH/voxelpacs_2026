-- VOXEL PACS — layout-base por versão de template de laudo
-- Execute manualmente após backup lógico. Não faz parte do deploy automático.
-- Compatível com PostgreSQL.

ALTER TABLE report_custom_templates
    ADD COLUMN IF NOT EXISTS layout_code VARCHAR(60);

UPDATE report_custom_templates
   SET layout_code = 'personalizado'
 WHERE layout_code IS NULL OR BTRIM(layout_code) = '';

ALTER TABLE report_custom_templates
    ALTER COLUMN layout_code SET DEFAULT 'personalizado';

ALTER TABLE report_custom_templates
    ALTER COLUMN layout_code SET NOT NULL;

CREATE INDEX IF NOT EXISTS idx_rct_tenant_unit_layout_status
    ON report_custom_templates (tenant_id, unit_source, unit_id, layout_code, status);

-- Validação:
-- SELECT layout_code, status, COUNT(*)
-- FROM report_custom_templates
-- GROUP BY layout_code, status
-- ORDER BY layout_code, status;

-- Rollback controlado, somente após confirmar que nenhum snapshot depende da coluna:
-- DROP INDEX IF EXISTS idx_rct_tenant_unit_layout_status;
-- ALTER TABLE report_custom_templates DROP COLUMN IF EXISTS layout_code;
