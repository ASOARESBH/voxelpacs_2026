<?php
namespace App\Controllers\Platform;

use App\Core\Controller;
use App\Core\Database;
use App\Services\ExportService;

class PlatformReportsController extends Controller {
    public function index(): void {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query("
            SELECT t.nome, t.status, p.nome as plano,
                   COUNT(e.id) as total_exames,
                   COALESCE(SUM(e.valor_venda), 0) as receita
            FROM bi_tenants t
            JOIN bi_plans p ON p.id = t.plan_id
            LEFT JOIN bi_exames e ON e.tenant_id = t.id
            GROUP BY t.id
            ORDER BY total_exames DESC
        ");
        $relatorio = $stmt->fetchAll();
        $this->view('platform/reports/index', ['relatorio' => $relatorio], 'platform');
    }

    public function exportar(): void {
        $pdo  = Database::getInstance();
        $stmt = $pdo->query("
            SELECT t.nome, t.status, p.nome as plano, COUNT(e.id) as total_exames
            FROM bi_tenants t
            JOIN bi_plans p ON p.id = t.plan_id
            LEFT JOIN bi_exames e ON e.tenant_id = t.id
            GROUP BY t.id ORDER BY total_exames DESC
        ");
        $dados   = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $colunas = ['Tenant', 'Status', 'Plano', 'Total Exames'];
        (new ExportService())->exportarXlsx($dados, $colunas, 'relatorio_plataforma_' . date('Y-m-d') . '.xlsx');
    }
}
