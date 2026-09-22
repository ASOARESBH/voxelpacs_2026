<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\SqlHelper;
use PDO;
use RuntimeException;

/**
 * Monta o contexto visual imutável usado pelo PDF de delivery.
 *
 * O transporte non-DICOM deve usar o mesmo dispatcher/layout do viewer, mas
 * sem depender de sessão HTTP ou de assets remotos. O conteúdo clínico vem da
 * report_version ligada ao job; dados institucionais são resolvidos somente no
 * tenant do report e os assets são incorporados pelo ReportPdfService.
 */
final class ReportPdfDeliveryContextService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    public function build(array $job): array
    {
        [$tenantId, $reportId, $studyId, $version] = $this->validateJob($job);
        $report = $this->loadVisualReport($tenantId, $reportId, $studyId);
        $report = $this->applyVersionContent($report, $tenantId, $reportId, $version);
        if (!ReportClinicalContentService::hasReportContent($report)) {
            throw new RuntimeException('Versão visual do PDF sem conteúdo clínico válido.');
        }

        return $this->buildVisualContext($report, $tenantId);
    }

    /**
     * Constrói contexto somente para uma revisão operacional explicitamente
     * autorizada. A fonte clínica é o report atual já liberado; a versão
     * histórica não é alterada nem usada como fallback silencioso.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public function buildFromCurrentReport(array $job): array
    {
        [$tenantId, $reportId, $studyId] = $this->validateJob($job);
        $report = $this->loadVisualReport($tenantId, $reportId, $studyId);
        if (strtolower(trim((string) ($report['situacao'] ?? ''))) !== 'liberado') {
            throw new RuntimeException('Substituição operacional exige report liberado.');
        }
        if (!ReportClinicalContentService::hasReportContent($report)) {
            throw new RuntimeException('Report atual sem conteúdo clínico válido.');
        }

        return $this->buildVisualContext($report, $tenantId);
    }

    /** @param array<string,mixed> $job @return array{0:int,1:int,2:int,3:int} */
    private function validateJob(array $job): array
    {
        $tenantId = (int) ($job['tenant_id'] ?? 0);
        $reportId = (int) ($job['report_id'] ?? 0);
        $studyId = (int) ($job['estudo_id'] ?? 0);
        $version = (int) ($job['report_version'] ?? 0);
        if ($tenantId <= 0 || $reportId <= 0 || $studyId <= 0 || $version <= 0) {
            throw new RuntimeException('Contexto visual do PDF incompleto.');
        }
        return [$tenantId, $reportId, $studyId, $version];
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private function buildVisualContext(array $report, int $tenantId): array
    {
        $report = $this->applyMask($report, $tenantId);
        $report = $this->applyInstitutionalChannels($report, $tenantId);
        $report = $this->applyCompanyRegistration($report, $tenantId);

        $layoutService = new ReportLayoutService();
        $templateCodigo = $layoutService->resolverCodigo(
            (int) ($report['report_layout_template_id'] ?? 0)
        );
        $customTemplate = null;
        if ($templateCodigo === 'personalizado') {
            $customService = new ReportCustomTemplateService();
            $snapshotId = (int) ($report['report_custom_template_id'] ?? 0);
            if ($snapshotId > 0) {
                $customTemplate = $customService->getById($snapshotId, $tenantId);
            }
            if ($customTemplate === null) {
                $source = (int) ($report['institution_report_layout_id'] ?? 0)
                    === (int) ($report['report_layout_template_id'] ?? 0)
                    ? ReportCustomTemplateService::SOURCE_INSTITUTION
                    : ReportCustomTemplateService::SOURCE_UNIDADE;
                $unitId = $source === ReportCustomTemplateService::SOURCE_INSTITUTION
                    ? (int) ($report['institution_unit_id'] ?? 0)
                    : (int) ($report['rich_unit_id'] ?? 0);
                if ($unitId > 0) {
                    $customTemplate = $customService->getPublished($tenantId, $source, $unitId);
                }
            }
            if ($customTemplate === null) {
                $templateCodigo = ReportLayoutService::PADRAO;
            }
        }

        return [
            'report' => $report,
            'template_codigo' => $templateCodigo,
            'custom_template' => $customTemplate,
        ];
    }

    /** @return array<string,mixed> */
    private function loadVisualReport(int $tenantId, int $reportId, int $studyId): array
    {
        $institutionJoinSql = SqlHelper::caseInsensitiveEquals('bnin.institution_name', 'e.institution_name');
        $stmt = $this->pdo->prepare(
            "SELECT r.*, e.patient_name_display, e.patient_name, e.tags_raw, e.patient_id,
                    e.patient_birth_date, e.patient_sex, e.patient_age,
                    e.study_date, e.study_time, e.study_description,
                    e.scheduled_procedure_step_desc, e.requested_procedure_desc,
                    e.body_part_examined, e.accession_number, e.modalities, e.institution_name,
                    e.study_instance_uid, e.issuer_of_patient_id,
                    COALESCE(NULLIF(e.medico_solicitante_manual, ''), e.referring_physician_name) AS referring_physician_name,
                    e.medico_solicitante_manual, e.num_instances, e.num_series,
                    COALESCE(m.nome, u.name) AS medico_nome,
                    m.crm AS medico_crm, m.crm_uf AS medico_crm_uf, m.especialidade AS medico_especialidade,
                    t.nome AS tenant_nome, t.cnpj AS tenant_cnpj,
                    bnin.id AS institution_unit_id, un.id AS rich_unit_id,
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
              WHERE r.id = :report_id AND r.tenant_id = :tenant_id AND e.id = :study_id
              LIMIT 1"
        );
        $stmt->execute(['report_id' => $reportId, 'tenant_id' => $tenantId, 'study_id' => $studyId]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($report)) {
            throw new RuntimeException('Contexto visual do PDF não encontrado.');
        }
        return $report;
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private function applyVersionContent(array $report, int $tenantId, int $reportId, int $version): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT rv.corpo_laudo, rv.secao_exame, rv.secao_tecnica, rv.secao_achados,
                        rv.secao_conclusao, rv.secao_recomendacao
                   FROM report_versions rv
                   INNER JOIN reports r ON r.id = rv.report_id AND r.tenant_id = :tenant_id
                  WHERE rv.report_id = :report_id AND rv.versao = :version
                  LIMIT 1'
            );
            $stmt->execute(['tenant_id' => $tenantId, 'report_id' => $reportId, 'version' => $version]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $report['corpo_laudo'] = (string) ($row['corpo_laudo'] ?? '');
                foreach (['exame', 'tecnica', 'achados', 'conclusao', 'recomendacao'] as $section) {
                    $report['secao_' . $section] = (string) ($row['secao_' . $section] ?? '');
                }
                return $report;
            }
            throw new RuntimeException('Versão visual do PDF não encontrada.');
        } catch (\Throwable) {
            // Schema legado: tenta o conteúdo serializado abaixo.
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT conteudo FROM report_versions
                  INNER JOIN reports r ON r.id = report_versions.report_id AND r.tenant_id = :tenant_id
                  WHERE report_versions.report_id = :report_id AND report_versions.versao_numero = :version
                  LIMIT 1'
            );
            $stmt->execute(['tenant_id' => $tenantId, 'report_id' => $reportId, 'version' => $version]);
            $content = $stmt->fetchColumn();
            if (!is_string($content) || trim($content) === '') {
                throw new RuntimeException('Versão visual do PDF não encontrada.');
            }
            if (is_string($content) && $content !== '') {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $sections = is_array($decoded['secoes'] ?? null) ? $decoded['secoes'] : $decoded;
                    $applied = false;
                    if (isset($decoded['corpo']) && is_string($decoded['corpo'])) {
                        $report['corpo_laudo'] = $decoded['corpo'];
                        $applied = true;
                    }
                    foreach (['exame', 'tecnica', 'achados', 'conclusao', 'recomendacao'] as $section) {
                        if (array_key_exists($section, $sections)) {
                            $report['secao_' . $section] = (string) $sections[$section];
                            $applied = true;
                        }
                    }
                    if (!$applied) {
                        throw new RuntimeException('Versão visual do PDF sem conteúdo serializado.');
                    }
                } else {
                    $report['corpo_laudo'] = $content;
                }
            }
        } catch (\Throwable) {
            throw new RuntimeException('Versão visual do PDF não encontrada.');
        }
        return $report;
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private function applyMask(array $report, int $tenantId): array
    {
        $templateId = (int) ($report['template_id'] ?? 0);
        if ($templateId <= 0) {
            return $report;
        }
        $where = 'WHERE id = :id AND tenant_id = :tenant_id LIMIT 1';
        $queries = [
            "SELECT id, nome, modalidade, conteudo_livre, secao_exame, secao_tecnica, secao_achados, secao_conclusao, secao_recomendacao FROM report_templates {$where}",
            "SELECT id, titulo AS nome, modalidade, conteudo_livre, secao_exame, secao_tecnica, secao_achados, secao_conclusao, secao_recomendacao FROM report_templates {$where}",
            "SELECT id, nome, modalidade, conteudo FROM report_templates {$where}",
            "SELECT id, titulo AS nome, modalidade, conteudo FROM report_templates {$where}",
        ];
        foreach ($queries as $sql) {
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(['id' => $templateId, 'tenant_id' => $tenantId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    return $report;
                }
                $sections = [];
                $decoded = json_decode((string) ($row['conteudo'] ?? ''), true);
                $jsonSections = is_array($decoded) && is_array($decoded['secoes'] ?? null) ? $decoded['secoes'] : (is_array($decoded) ? $decoded : []);
                foreach (['exame', 'tecnica', 'achados', 'conclusao', 'recomendacao'] as $section) {
                    $column = 'secao_' . $section;
                    $sections[$section] = array_key_exists($column, $row) && $row[$column] !== null
                        ? (string) $row[$column]
                        : (string) ($jsonSections[$section] ?? '');
                }
                $report['mascara_secoes'] = $sections;
                $report['mascara_conteudo_livre'] = trim((string) ($row['conteudo_livre'] ?? '')) !== '';
                return $report;
            } catch (\PDOException) {
                continue;
            }
        }
        return $report;
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private function applyInstitutionalChannels(array $report, int $tenantId): array
    {
        foreach (['qrcode', 'site', 'instagram', 'facebook'] as $channel) {
            $report['unidade_personalizado_' . $channel . '_habilitado'] = 0;
            $report['unidade_personalizado_' . $channel . '_url'] = null;
        }
        $institution = trim((string) ($report['institution_name'] ?? ''));
        if ($institution === '') return $report;
        try {
            $institutionSql = SqlHelper::caseInsensitiveEquals('bnin.institution_name', ':institution_name');
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(bnin.personalizado_qrcode_habilitado, un.personalizado_qrcode_habilitado, 0) AS qrcode_habilitado,
                        COALESCE(NULLIF(bnin.personalizado_qrcode_url, ''), un.personalizado_qrcode_url) AS qrcode_url,
                        COALESCE(bnin.personalizado_site_habilitado, un.personalizado_site_habilitado, 0) AS site_habilitado,
                        COALESCE(NULLIF(bnin.personalizado_site_url, ''), un.personalizado_site_url) AS site_url,
                        COALESCE(bnin.personalizado_instagram_habilitado, un.personalizado_instagram_habilitado, 0) AS instagram_habilitado,
                        COALESCE(NULLIF(bnin.personalizado_instagram_url, ''), un.personalizado_instagram_url) AS instagram_url,
                        COALESCE(bnin.personalizado_facebook_habilitado, un.personalizado_facebook_habilitado, 0) AS facebook_habilitado,
                        COALESCE(NULLIF(bnin.personalizado_facebook_url, ''), un.personalizado_facebook_url) AS facebook_url
                   FROM bi_negocio_institution_names bnin
                   LEFT JOIN bi_unidades un ON un.id = bnin.unidade_id AND un.tenant_id = bnin.tenant_id
                  WHERE bnin.tenant_id = :tenant_id AND {$institutionSql}
                  LIMIT 1"
            );
            $stmt->execute(['tenant_id' => $tenantId, 'institution_name' => $institution]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach (['qrcode', 'site', 'instagram', 'facebook'] as $channel) {
                $report['unidade_personalizado_' . $channel . '_habilitado'] = (int) ($row[$channel . '_habilitado'] ?? 0);
                $report['unidade_personalizado_' . $channel . '_url'] = $row[$channel . '_url'] ?? null;
            }
        } catch (\Throwable) {
            // Canais opcionais: ausência não deve inventar conteúdo visual.
        }
        return $report;
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private function applyCompanyRegistration(array $report, int $tenantId): array
    {
        $report['registro_crm_uf'] = null;
        $report['registro_crm_numero'] = null;
        try {
            $stmt = $this->pdo->prepare('SELECT registro_crm_uf, registro_crm_numero FROM bi_tenants WHERE id = :tenant_id LIMIT 1');
            $stmt->execute(['tenant_id' => $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $report['registro_crm_uf'] = $row['registro_crm_uf'] ?? null;
            $report['registro_crm_numero'] = $row['registro_crm_numero'] ?? null;
        } catch (\Throwable) {
            // Campos opcionais permanecem vazios, como no viewer.
        }
        return $report;
    }
}
