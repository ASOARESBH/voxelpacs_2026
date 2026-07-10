<?php
namespace App\Models;

use App\Core\Model;

class Exame extends Model {
    protected string $table = 'bi_exames';
    protected bool $hasTenant = true;

    public function findById(int $id): ?object {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id" . $this->tenantWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(['id' => $id], $this->tenantParam()));
        return $stmt->fetch() ?: null;
    }

    public function paginate(array $filters = [], int $page = 1, int $perPage = 50): array {
        $where  = "WHERE 1=1" . $this->tenantWhere();
        $params = $this->tenantParam();

        if (!empty($filters['pacs_id'])) {
            $where .= " AND pacs_id = :pacs_id";
            $params['pacs_id'] = $filters['pacs_id'];
        }
        if (!empty($filters['modalidade'])) {
            $where .= " AND modalidade = :modalidade";
            $params['modalidade'] = $filters['modalidade'];
        }
        if (!empty($filters['data_inicio'])) {
            $where .= " AND data_estudo >= :data_inicio";
            $params['data_inicio'] = $filters['data_inicio'];
        }
        if (!empty($filters['data_fim'])) {
            $where .= " AND data_estudo <= :data_fim";
            $params['data_fim'] = $filters['data_fim'];
        }
        if (!empty($filters['prioridade'])) {
            $where .= " AND prioridade = :prioridade";
            $params['prioridade'] = $filters['prioridade'];
        }

        $offset   = ($page - 1) * $perPage;
        $stmt     = $this->pdo->prepare("SELECT * FROM {$this->table} {$where} ORDER BY data_estudo DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        $data     = $stmt->fetchAll();

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) as total FROM {$this->table} {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()->total;

        return ['data' => $data, 'total' => $total, 'pages' => (int) ceil($total / $perPage), 'current_page' => $page];
    }

    public function kpiResumo(?int $pacsId = null): object {
        $where  = "WHERE 1=1" . $this->tenantWhere();
        $params = $this->tenantParam();

        if ($pacsId) {
            $where .= " AND pacs_id = :pacs_id";
            $params['pacs_id'] = $pacsId;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*)                                                         AS total_exames,
                SUM(CASE WHEN prioridade = 'Urgencia' THEN 1 ELSE 0 END)        AS total_urgencia,
                COUNT(DISTINCT medico_id)                                        AS medicos_ativos,
                ROUND(AVG(sla_minutos), 0)                                       AS sla_medio,
                COALESCE(SUM(valor_venda), 0)                                    AS valor_total
            FROM {$this->table} {$where}
        ");
        $stmt->execute($params);
        return $stmt->fetch();
    }

    public function evolucaoMensal(?int $pacsId = null): array {
        $where  = "WHERE 1=1" . $this->tenantWhere();
        $params = $this->tenantParam();

        if ($pacsId) {
            $where .= " AND pacs_id = :pacs_id";
            $params['pacs_id'] = $pacsId;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                periodo_ref,
                COUNT(*)                                                        AS total,
                SUM(CASE WHEN prioridade = 'Normal'   THEN 1 ELSE 0 END)       AS normal,
                SUM(CASE WHEN prioridade = 'Urgencia' THEN 1 ELSE 0 END)       AS urgencia,
                COALESCE(SUM(valor_venda), 0)                                   AS receita
            FROM {$this->table} {$where}
            GROUP BY periodo_ref
            ORDER BY periodo_ref DESC
            LIMIT 12
        ");
        $stmt->execute($params);
        return array_reverse($stmt->fetchAll());
    }

    public function topMedicos(int $limit = 5, ?int $pacsId = null): array {
        $where  = "WHERE 1=1" . $this->tenantWhere();
        $params = $this->tenantParam();

        if ($pacsId) {
            $where .= " AND pacs_id = :pacs_id";
            $params['pacs_id'] = $pacsId;
        }

        $stmt = $this->pdo->prepare("
            SELECT medico_nome, COUNT(*) AS total
            FROM {$this->table} {$where}
            GROUP BY medico_nome
            ORDER BY total DESC
            LIMIT {$limit}
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function distribuicaoModalidade(?int $pacsId = null): array {
        $where  = "WHERE 1=1" . $this->tenantWhere();
        $params = $this->tenantParam();

        if ($pacsId) {
            $where .= " AND pacs_id = :pacs_id";
            $params['pacs_id'] = $pacsId;
        }

        $stmt = $this->pdo->prepare("
            SELECT modalidade, COUNT(*) AS total
            FROM {$this->table} {$where}
            GROUP BY modalidade
            ORDER BY total DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function slaStatus(?int $pacsId = null): array {
        $where  = "WHERE sla_status IS NOT NULL" . $this->tenantWhere();
        $params = $this->tenantParam();

        if ($pacsId) {
            $where .= " AND pacs_id = :pacs_id";
            $params['pacs_id'] = $pacsId;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                periodo_ref,
                SUM(CASE WHEN sla_status = 'dentro'    THEN 1 ELSE 0 END) AS dentro,
                SUM(CASE WHEN sla_status = 'fora'      THEN 1 ELSE 0 END) AS fora,
                SUM(CASE WHEN sla_status = 'sem_prazo' THEN 1 ELSE 0 END) AS sem_prazo
            FROM {$this->table} {$where}
            GROUP BY periodo_ref
            ORDER BY periodo_ref DESC
            LIMIT 12
        ");
        $stmt->execute($params);
        return array_reverse($stmt->fetchAll());
    }
}
