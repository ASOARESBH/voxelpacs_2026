<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Configuracao;

class ConfiguracoesController extends Controller {
    public function index(): void {
        $config = (new Configuracao())->getAll();
        $this->view('configuracoes/index', ['config' => $config]);
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
}
