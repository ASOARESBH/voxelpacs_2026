<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Models\Configuracao;

class ConfiguracoesController extends Controller {
    /**
     * Configurações de infraestrutura são exclusivas do superadmin da plataforma.
     * Quando o dado for de um tenant, o superadmin deve operar via impersonação.
     */
    private function guardSuperadminOnly(): void {
        if (Auth::check() && Auth::isPlatformAdmin()) {
            return;
        }

        $user = Auth::user();
        Logger::warning('Tentativa negada de acesso a Configurações do Sistema', [
            'user_id'   => Auth::userId(),
            'role'      => $user?->role,
            'tenant_id' => Auth::tenantId(),
            'method'    => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        ]);

        http_response_code(403);
        exit('Acesso negado: esta área é exclusiva de administradores da plataforma.');
    }

    public function index(): void {
        $this->guardSuperadminOnly();
        $config = (new Configuracao())->getAll();

        // Config de visualizadores desktop (RadiAnt/Weasis) do tenant atual
        $viewerDesktopConfig = ['radiant' => null, 'weasis' => null];
        $tenantId = Auth::tenantId();
        if ($tenantId) {
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
            'config' => $config,
            'viewerDesktopConfig' => $viewerDesktopConfig,
        ]);
    }

    public function salvar(): void {
        $this->guardSuperadminOnly();
        $configModel = new Configuracao();
        $campos = ['sla_urgencia_minutos', 'sla_rotina_minutos', 'notif_email', 'cor_primaria'];
        foreach ($campos as $campo) {
            if (isset($_POST[$campo])) {
                $configModel->set($campo, $_POST[$campo]);
            }
        }
        $this->redirect('/configuracoes?salvo=1');
    }

    public function salvarViewerDesktop(): void {
        $this->guardSuperadminOnly();
        $tenantId = Auth::tenantId();
        if (!$tenantId) {
            $this->redirect('/configuracoes');
            return;
        }

        $viewer = $_POST['viewer'] ?? '';
        if (!in_array($viewer, ['radiant', 'weasis'], true)) {
            $this->redirect('/configuracoes');
            return;
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
            ':host'       => trim($_POST['host'] ?? '') ?: null,
            ':porta'      => trim($_POST['porta'] ?? '') ?: null,
            ':ae_title'   => trim($_POST['ae_title'] ?? '') ?: null,
            ':calling_ae' => trim($_POST['calling_ae'] ?? '') ?: null,
            ':ativo'      => isset($_POST['ativo']) ? 1 : 0,
        ]);

        $this->redirect('/configuracoes?salvo=1');
    }
}
