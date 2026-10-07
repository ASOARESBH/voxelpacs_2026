<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Services\ReportCustomTemplateService;
use App\Services\ReportLayoutService;

final class ReportCustomTemplateController extends Controller
{
    private \PDO $pdo;
    private ReportCustomTemplateService $service;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->service = new ReportCustomTemplateService();
    }

    private function guardAdmin(): void
    {
        if (Auth::check() && (Auth::isPlatformAdmin() || Auth::can('manage_configuracoes'))) {
            return;
        }
        Logger::warning('[ReportCustomTemplateController] acesso negado ao editor de layout personalizado', [
            'user_id' => Auth::userId(), 'tenant_id' => Auth::tenantId(), 'role' => Auth::user()?->role,
        ]);
        http_response_code(403);
        exit('Acesso negado: somente administradores podem configurar o layout de laudo.');
    }

    private function tenantId(): int
    {
        $tenantId = (int) (TenantContext::id() ?? Auth::tenantId() ?? 0);
        if ($tenantId <= 0) {
            http_response_code(422);
            exit('Selecione um negócio antes de configurar o template.');
        }
        return $tenantId;
    }

    private function guardCsrf(bool $json = false): void
    {
        $provided = (string) ($_POST['_csrf_token'] ?? '');
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        if ($provided !== '' && $expected !== '' && hash_equals($expected, $provided)) {
            return;
        }
        Logger::warning('[ReportCustomTemplateController] CSRF inválido', [
            'user_id' => Auth::userId(), 'tenant_id' => Auth::tenantId(),
        ]);
        if ($json) {
            $this->json(['ok' => false, 'message' => 'Token de segurança inválido.'], 403);
        }
        http_response_code(403);
        exit('Token de segurança inválido.');
    }

    private function sourceFromRoute(string $source): string
    {
        if (!$this->service->isSourceValid($source)) {
            http_response_code(404);
            exit('Origem de unidade inválida.');
        }
        return $source;
    }

    private function findUnit(string $source, int $unitId, int $tenantId): array
    {
        if ($source === ReportCustomTemplateService::SOURCE_INSTITUTION) {
            $stmt = $this->pdo->prepare(
                'SELECT id, descricao AS nome, institution_name, report_layout_template_id FROM bi_negocio_institution_names
                 WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id, COALESCE(NULLIF(nome_fantasia, \'\'), razao_social, \'Unidade\') AS nome, NULL AS institution_name, report_layout_template_id
                 FROM bi_unidades WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
            );
        }
        $stmt->execute(['id' => $unitId, 'tenant_id' => $tenantId]);
        $unit = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$unit) {
            http_response_code(404);
            exit('Unidade não encontrada.');
        }
        return $unit;
    }

    private function layoutCustomId(): int
    {
        $id = (int) $this->pdo->query("SELECT id FROM report_layout_templates WHERE codigo = 'personalizado' AND ativo = 1 LIMIT 1")->fetchColumn();
        if ($id <= 0) {
            throw new \RuntimeException('Catálogo Personalizado indisponível. Execute a migration antes de publicar.');
        }
        return $id;
    }

    private function selecionarLayoutPersonalizado(string $source, int $unitId, int $tenantId): void
    {
        $templateId = $this->layoutCustomId();
        if ($source === ReportCustomTemplateService::SOURCE_INSTITUTION) {
            $stmt = $this->pdo->prepare(
                'UPDATE bi_negocio_institution_names SET report_layout_template_id = :template_id
                 WHERE id = :id AND tenant_id = :tenant_id'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE bi_unidades SET report_layout_template_id = :template_id
                 WHERE id = :id AND tenant_id = :tenant_id'
            );
        }
        $stmt->execute(['template_id' => $templateId, 'id' => $unitId, 'tenant_id' => $tenantId]);
    }

    private function editor(string $source, int $unitId): void
    {
        $this->guardAdmin();
        $source = $this->sourceFromRoute($source);
        $tenantId = $this->tenantId();
        $unit = $this->findUnit($source, $unitId, $tenantId);
        $layoutService = new ReportLayoutService();
        $selectedLayoutCode = $layoutService->resolverCodigo((int) ($unit['report_layout_template_id'] ?? 0));
        $layoutCode = $this->service->normalizeLayoutCode($_GET['layout'] ?? $selectedLayoutCode);
        $draft = $this->service->getDraft($tenantId, $source, $unitId, $layoutCode);
        if ($draft === null) {
            $draft = $this->service->defaultPayloadForLayout($layoutCode);
        }
        $published = $this->service->getPublished($tenantId, $source, $unitId, $layoutCode);
        $this->view('unidades/template_personalizado', [
            'unit' => $unit,
            'unitId' => $unitId,
            'unitSource' => $source,
            'draft' => $draft,
            'published' => $published,
            'layoutCode' => $layoutCode,
            'selectedLayoutCode' => $selectedLayoutCode,
            'layoutCatalog' => $layoutService->listarCatalogo(),
            'csrfToken' => $this->csrfToken(),
            'includeQuill' => true,
            'includeTemplateCustomEditor' => true,
        ]);
    }

    public function editorInstitution(int $id): void
    {
        $this->editor(ReportCustomTemplateService::SOURCE_INSTITUTION, $id);
    }

    public function editorUnidade(int $id): void
    {
        $this->editor(ReportCustomTemplateService::SOURCE_UNIDADE, $id);
    }

    private function save(string $source, int $unitId): void
    {
        $this->guardAdmin();
        $this->guardCsrf();
        $source = $this->sourceFromRoute($source);
        $tenantId = $this->tenantId();
        $this->findUnit($source, $unitId, $tenantId);
        $layoutCode = $this->service->normalizeLayoutCode($_POST['layout_code'] ?? null);
        try {
            $this->service->saveDraft($tenantId, $source, $unitId, $_POST, (int) Auth::userId(), $layoutCode);
            $_SESSION['success'] = 'Rascunho do template do layout salvo.';
        } catch (\Throwable $e) {
            Logger::error('[ReportCustomTemplateController] erro ao salvar rascunho', [
                'tenant_id' => $tenantId, 'unit_id' => $unitId, 'source' => $source, 'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Não foi possível salvar o rascunho do layout.';
        }
        header('Location: ' . $this->editorUrl($source, $unitId, $layoutCode));
        exit;
    }

    private function publish(string $source, int $unitId): void
    {
        $this->guardAdmin();
        $this->guardCsrf();
        $source = $this->sourceFromRoute($source);
        $tenantId = $this->tenantId();
        $this->findUnit($source, $unitId, $tenantId);
        $layoutCode = $this->service->normalizeLayoutCode($_POST['layout_code'] ?? null);
        try {
            $this->service->saveDraft($tenantId, $source, $unitId, $_POST, (int) Auth::userId(), $layoutCode);
            $published = $this->service->publishDraft($tenantId, $source, $unitId, (int) Auth::userId(), $layoutCode);
            if (!$published) {
                throw new \RuntimeException('Rascunho do layout não encontrado para publicação.');
            }
            if ($layoutCode === 'personalizado') {
                $this->selecionarLayoutPersonalizado($source, $unitId, $tenantId);
            }
            $_SESSION['success'] = 'Template do layout publicado na versão ' . (int) $published['version'] . '.';
        } catch (\Throwable $e) {
            Logger::error('[ReportCustomTemplateController] erro ao publicar template', [
                'tenant_id' => $tenantId, 'unit_id' => $unitId, 'source' => $source, 'error' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Não foi possível publicar o layout.';
        }
        header('Location: ' . $this->editorUrl($source, $unitId, $layoutCode));
        exit;
    }

    private function preview(string $source, int $unitId): void
    {
        $this->guardAdmin();
        $this->guardCsrf(true);
        $source = $this->sourceFromRoute($source);
        $tenantId = $this->tenantId();
        $this->findUnit($source, $unitId, $tenantId);
        try {
            $this->json(['ok' => true, 'html' => $this->service->renderPreview($_POST)]);
        } catch (\Throwable $e) {
            Logger::error('[ReportCustomTemplateController] erro de preview', [
                'tenant_id' => $tenantId, 'unit_id' => $unitId, 'source' => $source, 'error' => $e->getMessage(),
            ]);
            $this->json(['ok' => false, 'message' => 'Não foi possível atualizar a pré-visualização.'], 422);
        }
    }

    private function editorUrl(string $source, int $unitId, ?string $layoutCode = null): string
    {
        $url = $source === ReportCustomTemplateService::SOURCE_INSTITUTION
            ? '/unidades/' . $unitId . '/template-personalizado'
            : '/unidades/' . $unitId . '/editar/template-personalizado';
        return $url . '?layout=' . rawurlencode($this->service->normalizeLayoutCode($layoutCode));
    }

    public function saveInstitution(int $id): void { $this->save(ReportCustomTemplateService::SOURCE_INSTITUTION, $id); }
    public function publishInstitution(int $id): void { $this->publish(ReportCustomTemplateService::SOURCE_INSTITUTION, $id); }
    public function previewInstitution(int $id): void { $this->preview(ReportCustomTemplateService::SOURCE_INSTITUTION, $id); }
    public function saveUnidade(int $id): void { $this->save(ReportCustomTemplateService::SOURCE_UNIDADE, $id); }
    public function publishUnidade(int $id): void { $this->publish(ReportCustomTemplateService::SOURCE_UNIDADE, $id); }
    public function previewUnidade(int $id): void { $this->preview(ReportCustomTemplateService::SOURCE_UNIDADE, $id); }
}
