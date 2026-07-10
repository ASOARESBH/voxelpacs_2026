<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\TenantContext;

class FinanceiroController extends Controller {
    public function index(): void {
        $pdo    = Database::getInstance();
        $tid    = TenantContext::id();

        $stmt = $pdo->prepare("
            SELECT
                periodo_ref,
                COALESCE(SUM(valor_venda), 0)  AS receita,
                COALESCE(SUM(valor_exame), 0)  AS custo,
                COUNT(*)                        AS total_exames,
                ROUND(AVG(valor_venda), 2)      AS ticket_medio
            FROM bi_exames
            WHERE tenant_id = :tid
            GROUP BY periodo_ref
            ORDER BY periodo_ref DESC
            LIMIT 12
        ");
        $stmt->execute(['tid' => $tid]);
        $financeiro = array_reverse($stmt->fetchAll());

        $this->view('financeiro/index', ['financeiro' => $financeiro]);
    }
}
