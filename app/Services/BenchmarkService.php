<?php
namespace App\Services;

use App\Core\Database;

class BenchmarkService {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance();
    }

    /**
     * Calcula métricas agregadas de todos os tenants (sem identificação)
     */
    public function calcularMediaPlataforma(string $periodoRef): array {
        $stmt = $this->pdo->prepare("
            SELECT
                AVG(total_exames)   AS media_exames,
                AVG(sla_medio)      AS media_sla,
                AVG(receita_total)  AS media_receita,
                COUNT(*)            AS total_tenants
            FROM bi_kpi_snapshots
            WHERE periodo_ref = :periodo
        ");
        $stmt->execute(['periodo' => $periodoRef]);
        return (array) $stmt->fetch();
    }

    /**
     * Calcula o percentil do tenant atual em relação à plataforma
     */
    public function calcularPercentil(int $tenantId, string $metrica, string $periodoRef): float {
        $colMap = [
            'volume'  => 'total_exames',
            'sla'     => 'sla_medio',
            'receita' => 'receita_total',
        ];
        $col = $colMap[$metrica] ?? 'total_exames';

        $stmt = $this->pdo->prepare("
            SELECT {$col} FROM bi_kpi_snapshots WHERE tenant_id = :tid AND periodo_ref = :periodo LIMIT 1
        ");
        $stmt->execute(['tid' => $tenantId, 'periodo' => $periodoRef]);
        $meuValor = $stmt->fetchColumn();

        if ($meuValor === false) return 0.0;

        $stmt2 = $this->pdo->prepare("
            SELECT COUNT(*) FROM bi_kpi_snapshots WHERE periodo_ref = :periodo AND {$col} <= :valor
        ");
        $stmt2->execute(['periodo' => $periodoRef, 'valor' => $meuValor]);
        $abaixo = (int) $stmt2->fetchColumn();

        $stmt3 = $this->pdo->prepare("SELECT COUNT(*) FROM bi_kpi_snapshots WHERE periodo_ref = :periodo");
        $stmt3->execute(['periodo' => $periodoRef]);
        $total = (int) $stmt3->fetchColumn();

        return $total > 0 ? round(($abaixo / $total) * 100, 1) : 0.0;
    }

    /**
     * Retorna distribuição anônima para boxplot de SLA
     */
    public function distribuicaoSla(string $periodoRef): array {
        $stmt = $this->pdo->prepare("
            SELECT sla_medio FROM bi_kpi_snapshots WHERE periodo_ref = :periodo ORDER BY sla_medio
        ");
        $stmt->execute(['periodo' => $periodoRef]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Verifica se o tenant tem permissão de benchmark pelo plano
     */
    public function tenantPodeAcessar(int $tenantId): bool {
        $stmt = $this->pdo->prepare("
            SELECT p.permite_benchmark
            FROM bi_tenants t
            JOIN bi_plans p ON p.id = t.plan_id
            WHERE t.id = :tid LIMIT 1
        ");
        $stmt->execute(['tid' => $tenantId]);
        $row = $stmt->fetch();
        return $row && (bool) $row->permite_benchmark;
    }
}
