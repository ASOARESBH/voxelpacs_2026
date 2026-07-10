<?php
namespace App\Services;

use App\Core\Database;
use App\Core\TenantContext;

class KpiService {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance();
    }

    public function resumoDashboard(?int $pacsId = null): array {
        $tid    = TenantContext::id();
        $where  = "WHERE tenant_id = :tid";
        $params = ['tid' => $tid];

        if ($pacsId) {
            $where .= " AND pacs_id = :pacs_id";
            $params['pacs_id'] = $pacsId;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*)                                                          AS total_exames,
                SUM(CASE WHEN prioridade = 'Urgencia' THEN 1 ELSE 0 END)         AS total_urgencia,
                COUNT(DISTINCT medico_id)                                         AS medicos_ativos,
                ROUND(AVG(sla_minutos), 0)                                        AS sla_medio,
                COALESCE(SUM(valor_venda), 0)                                     AS valor_total,
                ROUND(
                    SUM(CASE WHEN sla_status = 'dentro' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*),0),
                    1
                )                                                                 AS sla_pct_dentro
            FROM bi_exames {$where}
        ");
        $stmt->execute($params);
        return (array) $stmt->fetch();
    }

    public function crescimentoMoM(?int $pacsId = null): float {
        $tid    = TenantContext::id();
        $where  = "WHERE tenant_id = :tid";
        $params = ['tid' => $tid];

        if ($pacsId) {
            $where .= " AND pacs_id = :pacs_id";
            $params['pacs_id'] = $pacsId;
        }

        $stmt = $this->pdo->prepare("
            SELECT periodo_ref, COUNT(*) AS total
            FROM bi_exames {$where}
            GROUP BY periodo_ref
            ORDER BY periodo_ref DESC
            LIMIT 2
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if (count($rows) < 2 || (int) $rows[1]->total === 0) return 0.0;

        return round((($rows[0]->total - $rows[1]->total) / $rows[1]->total) * 100, 2);
    }
}
