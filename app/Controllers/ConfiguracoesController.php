<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Models\Configuracao;

class ConfiguracoesController extends Controller {
    public function index(): void {
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
        $tenantId = Auth::tenantId();
        if (!$tenantId || !Auth::can('manage_configuracoes')) {
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
