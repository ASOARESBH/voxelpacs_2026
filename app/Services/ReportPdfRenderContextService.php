<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\SqlHelper;
use PDO;

/**
 * Resolve o mesmo contexto visual usado pelo PDF do viewer para que snapshots
 * clínicos e devolutivas não reconstruam um layout simplificado em outro fluxo.
 */
final class ReportPdfRenderContextService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    /**
     * @return array{report:array<string,mixed>,template_codigo:string,custom_template:array<string,mixed>|null}|null
     */
    public function loadForReport(int $reportId, int $tenantId): ?array
    {
        if ($reportId <= 0 || $tenantId <= 0) {
            return null;
        }

        $institutionJoinSql = SqlHelper::caseInsensitiveEquals('bnin.institution_name', 'e.institution_name');
        $institutionParameterSql = SqlHelper::caseInsensitiveEquals('bnin.institution_name', ':institution_name');
        $stmt = $this->pdo->prepare(
            "SELECT r.*, e.patient_name_display, e.patient_name, e.patient_id,
                    e.patient_birth_date, e.patient_sex, e.patient_age,
                    e.study_date, e.study_time, e.study_description,
                    e.scheduled_procedure_step_desc, e.requested_procedure_desc,
                    e.body_part_examined, e.accession_number, e.modalities, e.institution_name,
                    COALESCE(NULLIF(e.medico_solicitante_manual, ''), e.referring_physician_name) AS referring_physician_name,
                    e.medico_solicitante_manual, e.num_instances, e.num_series,
                    COALESCE(m.nome, u.name) AS medico_nome,
                    m.crm AS medico_crm,
                    m.crm_uf AS medico_crm_uf,
                    m.especialidade AS medico_especialidade,
                    t.nome AS tenant_nome,
                    t.cnpj AS tenant_cnpj,
                    bnin.id AS institution_unit_id,
                    un.id AS rich_unit_id,
                    bnin.report_layout_template_id AS institution_report_layout_id,
                    un.report_layout_template_id AS rich_report_layout_id,
                    COALESCE(bnin.report_layout_template_id, un.report_layout_template_id) AS report_layout_template_id,
                    COALESCE(NULLIF(bnin.nome_fantasia, ''), un.nome_fantasia) AS unidade_nome_fantasia,
                    COALESCE(NULLIF(bnin.razao_social, ''), un.razao_social) AS unidade_razao_social,
                    COALESCE(NULLIF(bnin.cnpj, ''), un.cnpj) AS unidade_cnpj,
                    COALESCE(NULLIF(bnin.logo_path, ''), un.logo_path) AS unidade_logo_path,
                    COALESCE(NULLIF(bnin.telefone, ''), un.telefone) AS unidade_telefone,
                    COALESCE(NULLIF(bnin.email, ''), un.email) AS unidade_email,
                    COALESCE(NULLIF(bnin.logradouro, ''), un.logradouro) AS unidade_logradouro,
                    COALESCE(NULLIF(bnin.numero, ''), un.numero) AS unidade_numero,
                    COALESCE(NULLIF(bnin.complemento, ''), un.complemento) AS unidade_complemento,
                    COALESCE(NULLIF(bnin.bairro, ''), un.bairro) AS unidade_bairro,
                    COALESCE(NULLIF(bnin.cidade, ''), un.cidade) AS unidade_cidade,
                    COALESCE(NULLIF(bnin.estado, ''), un.estado) AS unidade_estado
             FROM reports r
             INNER JOIN bi_pacs_estudos e ON e.id = r.estudo_id AND e.tenant_id = r.tenant_id
             LEFT JOIN bi_users u ON u.id = r.usuario_id
             LEFT JOIN bi_medicos m ON m.usuario_id = r.usuario_id AND m.tenant_id = r.tenant_id
             LEFT JOIN bi_tenants t ON t.id = r.tenant_id
             LEFT JOIN bi_negocio_institution_names bnin
                    ON bnin.tenant_id = r.tenant_id AND {$institutionJoinSql}
             LEFT JOIN bi_unidades un ON un.id = bnin.unidade_id AND un.tenant_id = r.tenant_id
             WHERE r.id = :id AND r.tenant_id = :tenant_id
             LIMIT 1"
        );
        $stmt->execute([':id' => $reportId, ':tenant_id' => $tenantId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($data)) {
            return null;
        }

        foreach (['qrcode', 'site', 'instagram', 'facebook'] as $channel) {
            $data['unidade_personalizado_' . $channel . '_habilitado'] = 0;
            $data['unidade_personalizado_' . $channel . '_url'] = null;
        }
        try {
            $stmtChannels = $this->pdo->prepare(
                "SELECT
                    COALESCE(bnin.personalizado_qrcode_habilitado, un.personalizado_qrcode_habilitado, 0) AS qrcode_habilitado,
                    COALESCE(NULLIF(bnin.personalizado_qrcode_url, ''), un.personalizado_qrcode_url) AS qrcode_url,
                    COALESCE(bnin.personalizado_site_habilitado, un.personalizado_site_habilitado, 0) AS site_habilitado,
                    COALESCE(NULLIF(bnin.personalizado_site_url, ''), un.personalizado_site_url) AS site_url,
                    COALESCE(bnin.personalizado_instagram_habilitado, un.personalizado_instagram_habilitado, 0) AS instagram_habilitado,
                    COALESCE(NULLIF(bnin.personalizado_instagram_url, ''), un.personalizado_instagram_url) AS instagram_url,
                    COALESCE(bnin.personalizado_facebook_habilitado, un.personalizado_facebook_habilitado, 0) AS facebook_habilitado,
                    COALESCE(NULLIF(bnin.personalizado_facebook_url, ''), un.personalizado_facebook_url) AS facebook_url
                 FROM bi_negocio_institution_names bnin
                 LEFT JOIN bi_unidades un ON un.id = bnin.unidade_id AND un.tenant_id = bnin.tenant_id
                 WHERE bnin.tenant_id = :tenant_id
                   AND {$institutionParameterSql}
                 LIMIT 1"
            );
            $stmtChannels->execute([
                ':tenant_id' => $tenantId,
                ':institution_name' => (string) ($data['institution_name'] ?? ''),
            ]);
            $channels = $stmtChannels->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach (['qrcode', 'site', 'instagram', 'facebook'] as $channel) {
                $data['unidade_personalizado_' . $channel . '_habilitado'] = (int) ($channels[$channel . '_habilitado'] ?? 0);
                $data['unidade_personalizado_' . $channel . '_url'] = $channels[$channel . '_url'] ?? null;
            }
        } catch (\Throwable $error) {
            Logger::warning('[ReportPdfRenderContext] canais institucionais indisponíveis', [
                'report_id' => $reportId,
                'tenant_id' => $tenantId,
                'error' => $error->getMessage(),
            ]);
        }

        $data['registro_crm_uf'] = null;
        $data['registro_crm_numero'] = null;
        try {
            $stmtRegistration = $this->pdo->prepare(
                'SELECT registro_crm_uf, registro_crm_numero FROM bi_tenants WHERE id = :id LIMIT 1'
            );
            $stmtRegistration->execute([':id' => $tenantId]);
            $registration = $stmtRegistration->fetch(PDO::FETCH_ASSOC) ?: [];
            $data['registro_crm_uf'] = $registration['registro_crm_uf'] ?? null;
            $data['registro_crm_numero'] = $registration['registro_crm_numero'] ?? null;
        } catch (\Throwable $error) {
            Logger::warning('[ReportPdfRenderContext] registro profissional indisponível', [
                'report_id' => $reportId,
                'tenant_id' => $tenantId,
                'error' => $error->getMessage(),
            ]);
        }

        $mask = $this->loadMask((int) ($data['template_id'] ?? 0), $tenantId);
        if ($mask !== null) {
            $data['mascara_secoes'] = $mask['secoes'];
            $data['mascara_conteudo_livre'] = trim((string) ($mask['conteudo_livre'] ?? '')) !== '';
        }

        $layoutService = new ReportLayoutService();
        $templateCode = $layoutService->resolverCodigo(
            isset($data['report_layout_template_id']) ? (int) $data['report_layout_template_id'] : null
        );
        $customTemplate = null;
        if ($templateCode === 'personalizado') {
            $customService = new ReportCustomTemplateService();
            $snapshotId = (int) ($data['report_custom_template_id'] ?? 0);
            if ($snapshotId > 0) {
                $customTemplate = $customService->getById($snapshotId, $tenantId);
            }
            if ($customTemplate === null) {
                $source = ((int) ($data['institution_report_layout_id'] ?? 0) === (int) ($data['report_layout_template_id'] ?? 0))
                    ? ReportCustomTemplateService::SOURCE_INSTITUTION
                    : ReportCustomTemplateService::SOURCE_UNIDADE;
                $unitId = $source === ReportCustomTemplateService::SOURCE_INSTITUTION
                    ? (int) ($data['institution_unit_id'] ?? 0)
                    : (int) ($data['rich_unit_id'] ?? 0);
                $customTemplate = $customService->getPublished($tenantId, $source, $unitId);
            }
            if ($customTemplate === null) {
                $templateCode = ReportLayoutService::PADRAO;
            }
        }

        return [
            'report' => $data,
            'template_codigo' => $templateCode,
            'custom_template' => $customTemplate,
        ];
    }

    /** @return array<string,mixed>|null */
    private function loadMask(int $templateId, int $tenantId): ?array
    {
        if ($templateId <= 0 || $tenantId <= 0) {
            return null;
        }
        $params = [':id' => $templateId, ':tenant_id' => $tenantId];
        $queries = [
            'SELECT id, nome, modalidade, conteudo_livre, secao_exame, secao_tecnica, secao_achados, secao_conclusao, secao_recomendacao FROM report_templates WHERE id = :id AND tenant_id = :tenant_id LIMIT 1',
            'SELECT id, titulo AS nome, modalidade, conteudo_livre, secao_exame, secao_tecnica, secao_achados, secao_conclusao, secao_recomendacao FROM report_templates WHERE id = :id AND tenant_id = :tenant_id LIMIT 1',
            'SELECT id, nome, modalidade, conteudo FROM report_templates WHERE id = :id AND tenant_id = :tenant_id LIMIT 1',
            'SELECT id, titulo AS nome, modalidade, conteudo FROM report_templates WHERE id = :id AND tenant_id = :tenant_id LIMIT 1',
        ];
        foreach ($queries as $sql) {
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return is_array($row) ? $this->normalizeMask($row) : null;
            } catch (\Throwable) {
                // Tenta o formato histórico seguinte; o fallback não expõe conteúdo clínico.
            }
        }
        return null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeMask(array $row): array
    {
        $sectionsFromJson = [];
        $content = $row['conteudo'] ?? null;
        if (is_string($content) && trim($content) !== '') {
            $decoded = json_decode($content, true);
            $sectionsFromJson = is_array($decoded) ? (is_array($decoded['secoes'] ?? null) ? $decoded['secoes'] : $decoded) : ['exame' => $content];
        }
        $sections = [];
        foreach (['exame', 'tecnica', 'achados', 'conclusao', 'recomendacao'] as $key) {
            $column = 'secao_' . $key;
            $sections[$key] = array_key_exists($column, $row) && $row[$column] !== null
                ? (string) $row[$column]
                : (string) ($sectionsFromJson[$key] ?? '');
        }
        return [
            'conteudo_livre' => trim((string) ($row['conteudo_livre'] ?? '')),
            'secoes' => $sections,
        ];
    }
}
