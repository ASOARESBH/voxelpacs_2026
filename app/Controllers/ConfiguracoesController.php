<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Models\Configuracao;

class ConfiguracoesController extends Controller
{
    /**
     * Administração do negócio: pode gerir somente os dados institucionais do
     * tenant ativo. Superadmin também pode usar este caminho.
     */
    private function canManageCompanySettings(): bool
    {
        return Auth::check()
            && (Auth::isPlatformAdmin() || Auth::can('manage_configuracoes'));
    }

    private function denyConfigurationAccess(string $scope): void
    {
        $user = Auth::user();
        Logger::warning('Tentativa negada de acesso a Configurações do Sistema', [
            'user_id'   => Auth::userId(),
            'role'      => $user?->role,
            'tenant_id' => Auth::tenantId(),
            'scope'     => $scope,
            'method'    => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        ]);

        http_response_code(403);
        exit('Acesso negado: permissão insuficiente para esta configuração.');
    }

    /**
     * Orthanc, URL do Viewer e visualizadores desktop são infraestrutura PACS.
     * Esses campos nunca podem ser alterados por administrador de tenant.
     */
    private function guardSuperadminOnly(): void
    {
        if (Auth::check() && Auth::isPlatformAdmin()) {
            return;
        }

        $this->denyConfigurationAccess('infraestrutura_pacs');
    }

    private function guardCompanySettings(): void
    {
        if ($this->canManageCompanySettings()) {
            return;
        }

        $this->denyConfigurationAccess('dados_empresa');
    }

    /**
     * Configurações são sempre vinculadas a um negócio. Superadmin deve
     * impersonar o tenant antes de consultar ou alterar seus dados.
     */
    private function guardTenantConfigurationContext(): void
    {
        if (Auth::tenantId() && TenantContext::isSet()) {
            return;
        }

        Logger::warning('Tentativa de acessar Configurações sem contexto de tenant', [
            'user_id' => Auth::userId(),
            'role' => Auth::user()?->role,
            'scope' => 'configuracoes_tenant',
        ]);
        http_response_code(422);
        exit('Selecione um negócio antes de acessar as configurações.');
    }

    private function guardCsrf(string $scope): void
    {
        $provided = (string) ($_POST['_csrf_token'] ?? '');
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        if ($provided !== '' && $expected !== '' && hash_equals($expected, $provided)) {
            return;
        }

        Logger::warning('Tentativa negada de gravação em Configurações por CSRF inválido', [
            'user_id'   => Auth::userId(),
            'role'      => Auth::user()?->role,
            'tenant_id' => Auth::tenantId(),
            'scope'     => $scope,
        ]);
        http_response_code(403);
        exit('Acesso negado: token de segurança inválido.');
    }

    private function salvarCampos(Configuracao $configModel, array $campos, bool $ignorarVazios = false): void
    {
        foreach ($campos as $campo) {
            if (!isset($_POST[$campo]) || !is_scalar($_POST[$campo])) {
                continue;
            }

            $valor = trim((string) $_POST[$campo]);
            if ($ignorarVazios && $valor === '') {
                continue;
            }
            $configModel->set($campo, $valor);
        }
    }

    public function index(): void
    {
        $this->guardCompanySettings();
        $this->guardTenantConfigurationContext();

        $configModel = new Configuracao();
        $podeGerenciarInfraestrutura = Auth::isPlatformAdmin();
        $camposEmpresa = ['empresa_nome', 'empresa_cnpj', 'empresa_email', 'empresa_telefone'];
        $config = $podeGerenciarInfraestrutura
            ? $configModel->getAll()
            : $configModel->getMany($camposEmpresa);

        // Config de visualizadores desktop (RadiAnt/Weasis) é infraestrutura
        // e somente é consultada para superadmin.
        $viewerDesktopConfig = ['radiant' => null, 'weasis' => null];
        $tenantId = Auth::tenantId();
        if ($podeGerenciarInfraestrutura && $tenantId) {
            $stmt = Database::getInstance()->prepare("
                SELECT viewer, host, porta, ae_title, calling_ae, ativo
                FROM bi_viewer_desktop_config
                WHERE tenant_id = :tenant_id
            ");
            $stmt->execute([':tenant_id' => $tenantId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $viewerDesktopConfig[$row['viewer']] = $row;
            }
        }

        $this->view('configuracoes/index', [
            'config'                       => $config,
            'viewerDesktopConfig'          => $viewerDesktopConfig,
            'podeGerenciarInfraestrutura'  => $podeGerenciarInfraestrutura,
            'csrfToken'                    => $this->csrfToken(),
        ]);
    }

    /**
     * Mantém a rota legada e decide pelo grupo explicitamente permitido.
     * Um POST forjado com grupo=empresa não consegue salvar campos Orthanc.
     */
    public function salvar(): void
    {
        $grupo = (string) ($_POST['grupo'] ?? '');
        $configModel = new Configuracao();

        if ($grupo === 'empresa') {
            $this->guardCompanySettings();
            $this->guardTenantConfigurationContext();
            $this->guardCsrf('dados_empresa');
            $this->salvarCampos($configModel, [
                'empresa_nome', 'empresa_cnpj', 'empresa_email', 'empresa_telefone',
            ]);
            $this->redirect('/configuracoes?salvo=empresa');
        }

        if ($grupo === 'infraestrutura') {
            $this->guardSuperadminOnly();
            $this->guardTenantConfigurationContext();
            $this->guardCsrf('infraestrutura_pacs');
            $this->salvarCampos($configModel, [
                'orthanc_url', 'orthanc_user', 'viewer_url',
            ]);
            // Senha vazia significa manter o segredo já armazenado.
            $this->salvarCampos($configModel, ['orthanc_pass'], true);
            $this->redirect('/configuracoes?salvo=infraestrutura');
        }

        $this->guardCompanySettings();
        $this->guardCsrf('grupo_desconhecido');
        http_response_code(422);
        exit('Configuração inválida.');
    }

    public function salvarViewerDesktop(): void
    {
        $this->guardSuperadminOnly();
        $this->guardTenantConfigurationContext();
        $this->guardCsrf('visualizadores_desktop');

        $tenantId = Auth::tenantId();

        $viewer = $_POST['viewer'] ?? '';
        if (!in_array($viewer, ['radiant', 'weasis'], true)) {
            $this->redirect('/configuracoes');
        }

        Database::getInstance()->prepare("
            INSERT INTO bi_viewer_desktop_config
                (tenant_id, viewer, host, porta, ae_title, calling_ae, ativo)
            VALUES (:tenant_id, :viewer, :host, :porta, :ae_title, :calling_ae, :ativo)
            ON DUPLICATE KEY UPDATE
                host = VALUES(host), porta = VALUES(porta),
                ae_title = VALUES(ae_title), calling_ae = VALUES(calling_ae),
                ativo = VALUES(ativo)
        ")->execute([
            ':tenant_id'  => $tenantId,
            ':viewer'     => $viewer,
            ':host'       => trim((string) ($_POST['host'] ?? '')) ?: null,
            ':porta'      => trim((string) ($_POST['porta'] ?? '')) ?: null,
            ':ae_title'   => trim((string) ($_POST['ae_title'] ?? '')) ?: null,
            ':calling_ae' => trim((string) ($_POST['calling_ae'] ?? '')) ?: null,
            ':ativo'      => isset($_POST['ativo']) ? 1 : 0,
        ]);

        $this->redirect('/configuracoes?salvo=visualizador');
    }
}
