<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\SlaRulesEngineService;

/**
 * Endpoint público (sem login) que dispara o robô de Regras de SLA.
 * Protegido por token comparado com hash_equals() — mesmo padrão usado
 * historicamente pelo antigo PacsController::cronPing() (ver
 * docs/SYNC_AUTOMATICO_PACS.md), chamado por um cron externo (ex:
 * cron-job.org) já que este ambiente (hospedagem compartilhada) não tem
 * crontab real disponível.
 */
class SlaRoboController extends Controller {

    public function executar(): void {
        @set_time_limit(120);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $token  = $_GET['token'] ?? '';
        $config = Database::getInstance()->query("SELECT * FROM bi_sla_robo_config WHERE id = 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$config || empty($config['token']) || !hash_equals((string) $config['token'], (string) $token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token inválido.']);
            exit;
        }
        if ((int) $config['ativo'] !== 1) {
            echo json_encode(['success' => false, 'message' => 'Robô de Regras de SLA está desativado.']);
            exit;
        }

        $resumo = (new SlaRulesEngineService())->executarParaTodosTenants();

        echo json_encode(['success' => true] + $resumo, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
