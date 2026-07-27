<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\PacsSyncService;

/**
 * Endpoint público (sem login) que dispara o ciclo de sincronização automática
 * do(s) servidor(es) PACS a cada 2 minutos. Mesmo padrão de token do
 * SlaRoboController: comparado com hash_equals(), chamado por um cron externo
 * (ex: cron-job.org), já que esta hospedagem compartilhada não tem crontab real.
 *
 * Uma chamada = 1 ciclo que sincroniza TODOS os servidores ativos, cada um
 * exatamente 1 vez (nunca 1 vez por negócio associado).
 */
class PacsSyncRoboController extends Controller
{
    public function executar(): void
    {
        @set_time_limit(110);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $token  = $_GET['token'] ?? '';
        $config = Database::getInstance()->query("SELECT * FROM bi_pacs_sync_robo_config WHERE id = 1")->fetch(\PDO::FETCH_ASSOC);

        if (!$config || empty($config['token']) || !hash_equals((string) $config['token'], (string) $token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token inválido.']);
            exit;
        }
        if ((int) $config['ativo'] !== 1) {
            echo json_encode(['success' => false, 'message' => 'Sincronização automática do Servidor PACS está desativada.']);
            exit;
        }

        $resumo = PacsSyncService::executarParaTodosServidores();

        echo json_encode(['success' => true] + $resumo, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
