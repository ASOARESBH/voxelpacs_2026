<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Templates personalizados de impressão por Unidade.
 * Todo acesso recebe tenant, origem e unidade validados pelo Controller.
 */
final class ReportCustomTemplateService
{
    public const SOURCE_INSTITUTION = 'institution_name';
    public const SOURCE_UNIDADE = 'unidade';
    public const STATUS_DRAFT = 'rascunho';
    public const STATUS_PUBLISHED = 'publicado';

    private const MODES = ['texto', 'html'];
    private const SOURCES = [self::SOURCE_INSTITUTION, self::SOURCE_UNIDADE];
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'span', 'div',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'style',
    ];
    private const ALLOWED_ATTRIBUTES = ['style', 'class', 'colspan', 'rowspan', 'width', 'height'];

    public function isSourceValid(string $source): bool
    {
        return in_array($source, self::SOURCES, true);
    }

    public function normalizarPayload(array $input): array
    {
        $payload = [];
        foreach (['header', 'body', 'footer'] as $section) {
            $mode = (string) ($input[$section . '_mode'] ?? 'texto');
            $payload[$section . '_mode'] = in_array($mode, self::MODES, true) ? $mode : 'texto';
            $payload[$section . '_content'] = self::sanitizeHtml((string) ($input[$section . '_content'] ?? ''));
        }
        return $payload;
    }

    public function getDraft(int $tenantId, string $source, int $unitId): ?array
    {
        if (!$this->isSourceValid($source) || $tenantId <= 0 || $unitId <= 0) {
            return null;
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM report_custom_templates
             WHERE tenant_id = :tenant_id AND unit_source = :unit_source AND unit_id = :unit_id
               AND status = :status
             ORDER BY updated_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'unit_source' => $source,
            'unit_id' => $unitId,
            'status' => self::STATUS_DRAFT,
        ]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function getPublished(int $tenantId, string $source, int $unitId): ?array
    {
        if (!$this->isSourceValid($source) || $tenantId <= 0 || $unitId <= 0) {
            return null;
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM report_custom_templates
             WHERE tenant_id = :tenant_id AND unit_source = :unit_source AND unit_id = :unit_id
               AND status = :status
             ORDER BY version DESC, id DESC LIMIT 1'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'unit_source' => $source,
            'unit_id' => $unitId,
            'status' => self::STATUS_PUBLISHED,
        ]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function getById(int $templateId, int $tenantId): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM report_custom_templates
             WHERE id = :id AND tenant_id = :tenant_id AND status = :status LIMIT 1'
        );
        $stmt->execute(['id' => $templateId, 'tenant_id' => $tenantId, 'status' => self::STATUS_PUBLISHED]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function saveDraft(int $tenantId, string $source, int $unitId, array $payload, int $userId): array
    {
        if (!$this->isSourceValid($source)) {
            throw new \InvalidArgumentException('Origem de unidade inválida.');
        }
        $payload = $this->normalizarPayload($payload);
        $pdo = Database::getInstance();
        $draft = $this->getDraft($tenantId, $source, $unitId);
        $params = $payload + [
            'tenant_id' => $tenantId,
            'unit_source' => $source,
            'unit_id' => $unitId,
            'updated_by' => $userId,
        ];

        if ($draft) {
            $params['id'] = (int) $draft['id'];
            $pdo->prepare(
                'UPDATE report_custom_templates SET
                    header_mode = :header_mode, header_content = :header_content,
                    body_mode = :body_mode, body_content = :body_content,
                    footer_mode = :footer_mode, footer_content = :footer_content,
                    updated_by = :updated_by
                 WHERE id = :id AND tenant_id = :tenant_id AND status = \'rascunho\''
            )->execute($params);
            return $this->getDraft($tenantId, $source, $unitId) ?? $draft;
        }

        $params['created_by'] = $userId;
        $pdo->prepare(
            'INSERT INTO report_custom_templates
                (tenant_id, unit_source, unit_id, status, version,
                 header_mode, header_content, body_mode, body_content, footer_mode, footer_content,
                 created_by, updated_by)
             VALUES
                (:tenant_id, :unit_source, :unit_id, \'rascunho\', 0,
                 :header_mode, :header_content, :body_mode, :body_content, :footer_mode, :footer_content,
                 :created_by, :updated_by)'
        )->execute($params);

        return $this->getDraft($tenantId, $source, $unitId) ?? [];
    }

    public function publishDraft(int $tenantId, string $source, int $unitId, int $userId): ?array
    {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $draft = $this->getDraft($tenantId, $source, $unitId);
            if (!$draft) {
                $pdo->rollBack();
                return null;
            }

            $versionStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(version), 0) FROM report_custom_templates
                 WHERE tenant_id = :tenant_id AND unit_source = :unit_source AND unit_id = :unit_id
                   AND status = \'publicado\' FOR UPDATE'
            );
            $versionStmt->execute(['tenant_id' => $tenantId, 'unit_source' => $source, 'unit_id' => $unitId]);
            $nextVersion = ((int) $versionStmt->fetchColumn()) + 1;

            $stmt = $pdo->prepare(
                'INSERT INTO report_custom_templates
                    (tenant_id, unit_source, unit_id, status, version,
                     header_mode, header_content, body_mode, body_content, footer_mode, footer_content,
                     created_by, updated_by, published_by, published_at)
                 VALUES
                    (:tenant_id, :unit_source, :unit_id, \'publicado\', :version,
                     :header_mode, :header_content, :body_mode, :body_content, :footer_mode, :footer_content,
                     :created_by, :updated_by, :published_by, NOW())'
            );
            $stmt->execute([
                'tenant_id' => $tenantId,
                'unit_source' => $source,
                'unit_id' => $unitId,
                'version' => $nextVersion,
                'header_mode' => $draft['header_mode'],
                'header_content' => $draft['header_content'],
                'body_mode' => $draft['body_mode'],
                'body_content' => $draft['body_content'],
                'footer_mode' => $draft['footer_mode'],
                'footer_content' => $draft['footer_content'],
                'created_by' => $draft['created_by'],
                'updated_by' => $userId,
                'published_by' => $userId,
            ]);
            $publishedId = (int) $pdo->lastInsertId();
            $pdo->commit();
            return $this->getById($publishedId, $tenantId);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('[ReportCustomTemplateService::publishDraft] ' . $e->getMessage(), [
                'tenant_id' => $tenantId, 'unit_source' => $source, 'unit_id' => $unitId,
            ]);
            throw $e;
        }
    }

    /** Preview usa somente este contexto fictício, sem IDs ou dados clínicos reais. */
    public function mockContext(): array
    {
        return [
            'unidade.nome' => 'Clínica Exemplo VOXEL',
            'unidade.cnpj' => '12.345.678/0001-90',
            'unidade.endereco' => 'Av. Exemplo, 1000 — Centro, Belo Horizonte/MG',
            'unidade.logo' => '<span class="voxel-placeholder-image">LOGO DA UNIDADE</span>',
            'unidade.qrcode' => $this->buildInstitutionalQrMarkup('https://exemplo.voxelpacs.com.br'),
            'unidade.site' => $this->buildInstitutionalLinkMarkup('https://exemplo.voxelpacs.com.br', 'Site institucional'),
            'unidade.instagram' => $this->buildInstitutionalLinkMarkup('https://instagram.com/voxelpacs', 'Instagram'),
            'unidade.facebook' => $this->buildInstitutionalLinkMarkup('https://facebook.com/voxelpacs', 'Facebook'),
            'paciente.nome' => 'PACIENTE DE EXEMPLO',
            'paciente.data_nascimento' => '15/04/1980',
            'paciente.id' => 'PRONT-000123',
            'exame.modalidade' => 'TC',
            'exame.data' => '18/08/2026',
            'exame.descricao' => 'TC DE CRÂNIO SEM CONTRASTE',
            'exame.acesso' => 'ACC-2026-000123',
            'exame.prontuario' => 'PRONT-000123',
            'medico.nome' => 'DRA. MÉDICA EXEMPLO',
            'medico.crm' => 'CRM-MG 12345',
            'medico_solicitante.nome' => 'DR. SOLICITANTE EXEMPLO',
            'laudo.titulo' => 'TC DE CRÂNIO SEM CONTRASTE',
            'laudo.corpo' => '<p>Conteúdo clínico de demonstração para pré-visualização do template.</p>',
            'laudo.tecnica' => 'Técnica de demonstração.',
            'laudo.achados' => 'Achados de demonstração.',
            'laudo.impressao' => 'Impressão diagnóstica de demonstração.',
            'laudo.data_emissao' => '18/08/2026 10:30',
            'laudo.token_validacao' => 'a1b2c3d4e5f6',
            'qrcode' => '<span class="voxel-placeholder-image">QR DE VALIDAÇÃO</span>',
            'assinatura.imagem' => '<span class="voxel-placeholder-image">ASSINATURA</span>',
            'assinatura.data' => '18/08/2026 10:30',
        ];
    }

    public function renderPreview(array $payload): string
    {
        return $this->renderDocument($this->normalizarPayload($payload), $this->mockContext());
    }

    public function renderReport(array $template, array $report, string $corpoLaudo, string $tituloLaudo): string
    {
        $unitName = trim((string) ($report['unidade_nome_fantasia'] ?? ''));
        if ($unitName === '') {
            $unitName = trim((string) ($report['unidade_razao_social'] ?? $report['tenant_nome'] ?? 'Clínica'));
        }
        $formatDate = static function (?string $value, bool $time = false): string {
            if (!$value) return '—';
            $timestamp = strtotime($value);
            return $timestamp ? date($time ? 'd/m/Y H:i' : 'd/m/Y', $timestamp) : '—';
        };
        $logoPath = ltrim((string) ($report['unidade_logo_path'] ?? ''), '/');
        $logo = $logoPath !== '' && str_starts_with($logoPath, 'uploads/unidades/')
            ? '<img src="/' . htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') . '" alt="Logo da unidade" class="voxel-unit-logo">'
            : '';
        $signature = !empty($report['assinatura_caminho_arquivo']) && !empty($report['public_token'])
            ? '<img src="/reports/r/' . rawurlencode((string) $report['public_token']) . '/assinatura" alt="Assinatura médica" class="voxel-signature-image">'
            : '';
        $institutionalQr = !empty($report['unidade_personalizado_qrcode_habilitado'])
            ? $this->buildInstitutionalQrMarkup((string) ($report['unidade_personalizado_qrcode_url'] ?? '')) : '';
        $institutionalSite = !empty($report['unidade_personalizado_site_habilitado'])
            ? $this->buildInstitutionalLinkMarkup((string) ($report['unidade_personalizado_site_url'] ?? ''), 'Site institucional') : '';
        $institutionalInstagram = !empty($report['unidade_personalizado_instagram_habilitado'])
            ? $this->buildInstitutionalLinkMarkup((string) ($report['unidade_personalizado_instagram_url'] ?? ''), 'Instagram') : '';
        $institutionalFacebook = !empty($report['unidade_personalizado_facebook_habilitado'])
            ? $this->buildInstitutionalLinkMarkup((string) ($report['unidade_personalizado_facebook_url'] ?? ''), 'Facebook') : '';
        $context = [
            'unidade.nome' => $unitName,
            'unidade.cnpj' => (string) ($report['unidade_cnpj'] ?? ''),
            'unidade.endereco' => trim(implode(', ', array_filter([
                $report['unidade_logradouro'] ?? '', $report['unidade_numero'] ?? '',
                $report['unidade_complemento'] ?? '', $report['unidade_bairro'] ?? '',
                $report['unidade_cidade'] ?? '', $report['unidade_estado'] ?? '',
            ]))),
            'unidade.logo' => $logo,
            'unidade.qrcode' => $institutionalQr,
            'unidade.site' => $institutionalSite,
            'unidade.instagram' => $institutionalInstagram,
            'unidade.facebook' => $institutionalFacebook,
            'paciente.nome' => (string) ($report['patient_name_display'] ?? $report['patient_name'] ?? ''),
            'paciente.data_nascimento' => $formatDate($report['patient_birth_date'] ?? null),
            'paciente.id' => (string) ($report['patient_id'] ?? ''),
            'exame.modalidade' => (string) ($report['modalities'] ?? ''),
            'exame.data' => $formatDate($report['study_date'] ?? null),
            'exame.descricao' => (string) ($report['study_description'] ?? ''),
            'exame.acesso' => (string) ($report['accession_number'] ?? ''),
            'exame.prontuario' => (string) ($report['patient_id'] ?? ''),
            'medico.nome' => (string) ($report['medico_nome'] ?? ''),
            'medico.crm' => trim('CRM ' . (string) ($report['medico_crm'] ?? '')),
            'medico_solicitante.nome' => (string) ($report['referring_physician_name'] ?? ''),
            'laudo.titulo' => $tituloLaudo,
            'laudo.corpo' => self::sanitizeHtml($corpoLaudo),
            'laudo.tecnica' => trim(strip_tags((string) ($report['secao_tecnica'] ?? ''))),
            'laudo.achados' => trim(strip_tags((string) ($report['secao_achados'] ?? ''))),
            'laudo.impressao' => trim(strip_tags((string) ($report['secao_conclusao'] ?? ''))),
            'laudo.data_emissao' => $formatDate($report['assinado_em'] ?? $report['created_at'] ?? null, true),
            'laudo.token_validacao' => (string) ($report['assinatura_hash'] ?? ''),
            'qrcode' => '<span class="voxel-placeholder-image">VALIDAR: ' . htmlspecialchars(substr((string) ($report['assinatura_hash'] ?? ''), 0, 16), ENT_QUOTES, 'UTF-8') . '</span>',
            'assinatura.imagem' => $signature,
            'assinatura.data' => $formatDate($report['assinado_em'] ?? null, true),
        ];
        return $this->renderDocument($template, $context);
    }

    public function renderDocument(array $template, array $context): string
    {
        $header = $this->replaceVariables((string) ($template['header_content'] ?? ''), $context);
        $body = $this->replaceVariables((string) ($template['body_content'] ?? ''), $context);
        $footer = $this->replaceVariables((string) ($template['footer_content'] ?? ''), $context);

        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<style>html,body{margin:0;padding:0;background:#f1f5f9;color:#1f2937;font-family:Arial,Helvetica,sans-serif}.voxel-custom-page{width:210mm;min-height:297mm;margin:12px auto;background:#fff;padding:16mm 15mm 22mm;position:relative}.voxel-custom-header{min-height:12mm}.voxel-custom-body{margin-top:7mm;line-height:1.55}.voxel-custom-footer{margin-top:12mm;border-top:1px solid #cbd5e1;padding-top:5mm;color:#475569;font-size:10px}.voxel-unit-logo{max-width:180px;max-height:70px;object-fit:contain}.voxel-signature-image{max-width:220px;max-height:70px;object-fit:contain}.voxel-institutional-qr{width:78px;height:78px;display:inline-block}.voxel-institutional-link{color:#1d4ed8;text-decoration:underline}.voxel-placeholder-image{display:inline-block;border:1px dashed #94a3b8;padding:8px;color:#64748b;font-size:10px}@media print{body{background:#fff}.voxel-custom-page{width:auto;min-height:0;margin:0;box-shadow:none;padding:14mm 14mm 20mm}.voxel-custom-header{position:fixed;top:8mm;left:14mm;right:14mm}.voxel-custom-body{margin-top:28mm}.voxel-custom-footer{position:fixed;bottom:8mm;left:14mm;right:14mm}}</style>'
            . '</head><body><main class="voxel-custom-page"><header class="voxel-custom-header">' . $header
            . '</header><section class="voxel-custom-body">' . $body
            . '</section><footer class="voxel-custom-footer">' . $footer
            . '</footer></main></body></html>';
    }

    private function replaceVariables(string $html, array $context): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+(?:\.[a-z_]+)*)\s*\}\}/i', static function (array $match) use ($context): string {
            $key = strtolower($match[1]);
            if (!array_key_exists($key, $context)) {
                return '';
            }
            $value = (string) $context[$key];
            if (in_array($key, ['unidade.logo', 'unidade.qrcode', 'unidade.site', 'unidade.instagram', 'unidade.facebook', 'assinatura.imagem', 'laudo.corpo', 'qrcode'], true)) {
                return $value;
            }
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, self::sanitizeHtml($html)) ?? '';
    }

    private function buildInstitutionalLinkMarkup(string $url, string $label): string
    {
        $url = $this->safeHttpsUrl($url);
        if ($url === '') return '';
        return '<a class="voxel-institutional-link" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" rel="noopener noreferrer" target="_blank">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    private function buildInstitutionalQrMarkup(string $url): string
    {
        $url = $this->safeHttpsUrl($url);
        if ($url === '' || !class_exists(QRCode::class) || !class_exists(QROptions::class)) return '';
        try {
            $options = new QROptions([
                'outputType' => QRCode::OUTPUT_MARKUP_SVG,
                'imageBase64' => true,
                'scale' => 3,
            ]);
            $dataUri = (new QRCode($options))->render($url);
            return '<img class="voxel-institutional-qr" src="' . htmlspecialchars($dataUri, ENT_QUOTES, 'UTF-8') . '" alt="QR Code institucional">';
        } catch (\Throwable $e) {
            Logger::warning('[ReportCustomTemplateService] QR institucional indisponível', ['error' => $e->getMessage()]);
            return '';
        }
    }

    private function safeHttpsUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return '';
        $parts = parse_url($url);
        return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? $url : '';
    }

    public static function sanitizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '';
        if (!class_exists('DOMDocument')) {
            return strip_tags($html, '<p><br><strong><b><em><i><u><span><div><h1><h2><h3><h4><h5><h6><ul><ol><li><table><thead><tbody><tr><th><td><hr><style>');
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $wrapped = '<div id="voxel-template-root">' . $html . '</div>';
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) return '';
        $root = $dom->getElementById('voxel-template-root');
        if (!$root) return '';

        $walk = static function (\DOMNode $node) use (&$walk, $dom): void {
            foreach (iterator_to_array($node->childNodes) as $child) {
                if (!$child instanceof \DOMElement) continue;
                $tag = strtolower($child->tagName);
                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attribute) {
                    $name = strtolower($attribute->name);
                    if (!in_array($name, self::ALLOWED_ATTRIBUTES, true) || str_starts_with($name, 'on')) {
                        $child->removeAttribute($attribute->name);
                        continue;
                    }
                    if ($name === 'style') {
                        $child->setAttribute('style', self::sanitizeCss($attribute->value));
                    }
                }
                if ($tag === 'style') {
                    $child->nodeValue = self::sanitizeCss($child->textContent);
                }
                $walk($child);
            }
        };
        $walk($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }
        return trim($output);
    }

    private static function sanitizeCss(string $css): string
    {
        $css = preg_replace('/@(?:import|namespace|font-face)[^;{}]*(?:;|\{[^}]*\})/iu', '', $css) ?? '';
        $css = preg_replace('/(?:url\s*\(|expression\s*\(|behavior\s*:|-moz-binding\s*:|javascript\s*:|data\s*:)/iu', '', $css) ?? '';
        return trim($css);
    }
}
