<?php
namespace App\Services;

use App\Core\Database;

class PreditivoService {
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance();
    }

    /**
     * Regressão linear simples (mínimos quadrados) sobre os últimos N meses
     */
    public function projetarVolume(int $tenantId, ?int $pacsId, int $mesesFuturos = 3): array {
        $where  = "WHERE tenant_id = :tid";
        $params = ['tid' => $tenantId];

        if ($pacsId) {
            $where .= " AND pacs_id = :pacs_id";
            $params['pacs_id'] = $pacsId;
        }

        $stmt = $this->pdo->prepare("
            SELECT periodo_ref, COUNT(*) AS total
            FROM bi_exames {$where}
            GROUP BY periodo_ref
            ORDER BY periodo_ref DESC
            LIMIT 6
        ");
        $stmt->execute($params);
        $historico = array_reverse($stmt->fetchAll());

        if (count($historico) < 2) {
            return ['historico' => $historico, 'projecao' => [], 'tendencia' => 0];
        }

        $n   = count($historico);
        $xs  = range(1, $n);
        $ys  = array_column($historico, 'total');
        $sumX  = array_sum($xs);
        $sumY  = array_sum($ys);
        $sumXY = array_sum(array_map(fn($x, $y) => $x * $y, $xs, $ys));
        $sumX2 = array_sum(array_map(fn($x) => $x * $x, $xs));

        $b = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX ** 2);
        $a = ($sumY - $b * $sumX) / $n;

        $projecao    = [];
        $ultimoPeriodo = end($historico)->periodo_ref;
        [$ano, $mes] = explode('-', $ultimoPeriodo);

        for ($i = 1; $i <= $mesesFuturos; $i++) {
            $mes++;
            if ($mes > 12) { $mes = 1; $ano++; }
            $periodo  = sprintf('%04d-%02d', $ano, $mes);
            $valor    = max(0, round($a + $b * ($n + $i)));
            $projecao[] = ['periodo_ref' => $periodo, 'total' => $valor, 'min' => round($valor * 0.85), 'max' => round($valor * 1.15)];
        }

        return ['historico' => $historico, 'projecao' => $projecao, 'tendencia' => round($b, 2)];
    }

    /**
     * Detecta sazonalidade por dia da semana e hora
     */
    public function analisarSazonalidade(int $tenantId, ?int $pacsId): array {
        $where  = "WHERE tenant_id = :tid AND data_estudo IS NOT NULL";
        $params = ['tid' => $tenantId];

        if ($pacsId) {
            $where .= " AND pacs_id = :pacs_id";
            $params['pacs_id'] = $pacsId;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                DAYOFWEEK(data_estudo) - 1 AS dia_semana,
                HOUR(data_estudo)           AS hora,
                COUNT(*)                    AS total
            FROM bi_exames {$where}
            GROUP BY dia_semana, hora
            ORDER BY dia_semana, hora
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Calcula tendência de crescimento % MoM dos últimos 6 meses
     */
    public function calcularTendencia(int $tenantId, ?int $pacsId): float {
        $dados = $this->projetarVolume($tenantId, $pacsId, 0);
        return $dados['tendencia'] ?? 0.0;
    }

    /**
     * Gera alertas automáticos baseados em anomalias
     */
    public function gerarAlertas(?int $tenantId): array {
        if ($tenantId === null) return [];
        $alertas = [];

        $dados = $this->projetarVolume($tenantId, null, 1);
        if (!empty($dados['projecao'])) {
            $historico = $dados['historico'];
            $medias    = array_column($historico, 'total');
            $media     = array_sum($medias) / count($medias);
            $proximo   = $dados['projecao'][0]['total'];

            if ($proximo > $media * 1.20) {
                $pct = round(($proximo / $media - 1) * 100);
                $alertas[] = [
                    'tipo'     => 'warning',
                    'mensagem' => "Volume projetado para o próximo mês é {$pct}% acima da média histórica. Considere ampliar a capacidade.",
                ];
            }
        }

        // Alerta de SLA degradando
        $stmt = $this->pdo->prepare("
            SELECT periodo_ref, ROUND(AVG(sla_minutos), 0) AS sla_medio
            FROM bi_exames
            WHERE tenant_id = :tid AND sla_minutos IS NOT NULL
            GROUP BY periodo_ref
            ORDER BY periodo_ref DESC
            LIMIT 3
        ");
        $stmt->execute(['tid' => $tenantId]);
        $slaRows = $stmt->fetchAll();

        if (count($slaRows) >= 2 && $slaRows[0]->sla_medio > $slaRows[1]->sla_medio * 1.10) {
            $alertas[] = [
                'tipo'     => 'danger',
                'mensagem' => 'Tempo médio de laudo (SLA) aumentou mais de 10% em relação ao mês anterior. Verifique a capacidade da equipe médica.',
            ];
        }

        return $alertas;
    }

    /**
     * Atualiza os snapshots de KPI (chamado após cada importação)
     */
    public function atualizarSnapshots(int $tenantId, string $periodoRef): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO bi_kpi_snapshots
                (tenant_id, periodo_ref, total_exames, total_urgencia, sla_medio, receita_total, created_at)
            SELECT
                :tid,
                :periodo,
                COUNT(*),
                SUM(CASE WHEN prioridade = 'Urgencia' THEN 1 ELSE 0 END),
                ROUND(AVG(sla_minutos), 0),
                COALESCE(SUM(valor_venda), 0),
                NOW()
            FROM bi_exames
            WHERE tenant_id = :tid2 AND periodo_ref = :periodo2
            ON DUPLICATE KEY UPDATE
                total_exames   = VALUES(total_exames),
                total_urgencia = VALUES(total_urgencia),
                sla_medio      = VALUES(sla_medio),
                receita_total  = VALUES(receita_total)
        ");
        $stmt->execute(['tid' => $tenantId, 'periodo' => $periodoRef, 'tid2' => $tenantId, 'periodo2' => $periodoRef]);
    }
}
