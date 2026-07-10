<?php
namespace App\Controllers\Platform;

use App\Core\Controller;
use App\Core\Database;

class PlatformDashboardController extends Controller {
    public function index(): void {
        $pdo = Database::getInstance();

        $stats = [];
        $queries = [
            'total_negocios'  => "SELECT COUNT(*) FROM bi_tenants",
            'negocios_ativos' => "SELECT COUNT(*) FROM bi_tenants WHERE status = 'ativo'",
            'total_usuarios'  => "SELECT COUNT(*) FROM bi_users WHERE status = 'ativo'",
            'total_estudos'   => "SELECT COUNT(*) FROM pacs_estudos",
            'total_planos'    => "SELECT COUNT(*) FROM bi_plans WHERE ativo = 1",
        ];
        foreach ($queries as $key => $sql) {
            try { $stats[$key] = (int) $pdo->query($sql)->fetchColumn(); }
            catch (\Throwable $e) { $stats[$key] = 0; }
        }

        try {
            $ultimosNegocios = $pdo->query("
                SELECT n.nome, n.status, n.created_at, p.nome as plano
                FROM bi_tenants n
                LEFT JOIN bi_plans p ON p.id = n.plan_id
                ORDER BY n.created_at DESC LIMIT 5
            ")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { $ultimosNegocios = []; }

        try {
            $crescimento = $pdo->query("
                SELECT DATE_FORMAT(created_at,'%Y-%m') AS mes, COUNT(*) AS total
                FROM bi_tenants GROUP BY mes ORDER BY mes DESC LIMIT 12
            ")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { $crescimento = []; }

        $this->view('platform/dashboard', [
            'title'          => 'Dashboard — VOXEL PACS Plataforma',
            'stats'          => $stats,
            'ultimosNegocios'=> $ultimosNegocios,
            'crescimento'    => $crescimento,
        ], 'platform');
    }
}
