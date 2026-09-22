-- Grants mínimos para o runtime da aplicação usar o control-plane Delivery Request.
-- Não cria dados nem altera o desenho de tabelas; é idempotente e reversível.
SET search_path TO voxelpacs_mysql_source, public;

GRANT USAGE ON SCHEMA voxelpacs_mysql_source TO voxelpacs_homolog;
GRANT SELECT, INSERT, UPDATE, DELETE
    ON TABLE pacs_report_delivery_requests
    TO voxelpacs_homolog;
GRANT USAGE, SELECT
    ON SEQUENCE pacs_report_delivery_requests_id_seq
    TO voxelpacs_homolog;

-- Rollback: executar somente se o control-plane permanecer desativado e após
-- confirmar que o role não recebeu estes grants por outro mecanismo.
-- REVOKE USAGE, SELECT ON SEQUENCE pacs_report_delivery_requests_id_seq FROM voxelpacs_homolog;
-- REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLE pacs_report_delivery_requests FROM voxelpacs_homolog;
-- REVOKE USAGE ON SCHEMA voxelpacs_mysql_source FROM voxelpacs_homolog;
