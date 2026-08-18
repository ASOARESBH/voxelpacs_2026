<?php

namespace App\Controllers\Platform;

use App\Core\Controller;
use App\Core\Database;
use App\Core\SqlHelper;
use App\Core\Logger;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Relatórios estratégicos de uso exclusivo da Plataforma.
 * O Router já restringe /platform/* a Auth::isPlatformAdmin().
 */
class PlatformReportsController extends Controller
{
    private const TOTAL_MESES_EVOLUCAO = 12;

    public function index(): void
    {
        $relatorio = [];
        $evolucao = $this->serieVazia();
        $erroRelatorio = null;

        try {
            $relatorio = $this->relatorioPorTenant();
        } catch (\Throwable $e) {
            Logger::error('[PlatformReportsController::index] Falha na visão por negócio', [
                'exception' => $e->getMessage(),
            ]);
            $erroRelatorio = 'Não foi possível carregar todos os indicadores neste momento.';
        }

        try {
            $evolucao = $this->evolucaoMensal();
        } catch (\Throwable $e) {
            Logger::error('[PlatformReportsController::index] Falha na evolução mensal', [
                'exception' => $e->getMessage(),
            ]);
            $erroRelatorio ??= 'Não foi possível carregar todos os indicadores neste momento.';
        }

        $this->view('platform/reports/index', [
            'title' => 'Relatórios Estratégicos — VOXEL PACS',
            'relatorio' => $relatorio,
            'evolucao' => $evolucao,
            'resumo' => $this->resumo($relatorio, $evolucao),
            'erroRelatorio' => $erroRelatorio,
        ], 'platform');
    }

    public function exportar(): void
    {
        try {
            if (!class_exists(Spreadsheet::class) || !class_exists(Xlsx::class)) {
                throw new \RuntimeException('Dependência PhpSpreadsheet indisponível para a exportação XLSX.');
            }

            $relatorio = $this->relatorioPorTenant();
            $evolucao = $this->evolucaoMensal();
            $this->exportarXlsx($relatorio, $evolucao);
        } catch (\Throwable $e) {
            Logger::error('[PlatformReportsController::exportar] Falha ao exportar relatório estratégico', [
                'exception' => $e->getMessage(),
            ]);
            $_SESSION['error'] = 'Não foi possível gerar a exportação XLSX. Verifique a disponibilidade do serviço e tente novamente.';
            header('Location: /platform/reports');
            exit;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function relatorioPorTenant(): array
    {
        $sql = "SELECT
                    t.id,
                    t.nome,
                    t.status,
                    p.nome AS plano,
                    COUNT(e.id) AS total_exames,
                    COALESCE(SUM(e.valor_venda), 0) AS receita
                FROM bi_tenants t
                LEFT JOIN bi_plans p ON p.id = t.plan_id
                LEFT JOIN bi_exames e ON e.tenant_id = t.id
                GROUP BY t.id, t.nome, t.status, p.nome
                ORDER BY total_exames DESC, t.nome ASC";

        return Database::getInstance()->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int, array<string, mixed>> */
    private function evolucaoMensal(): array
    {
        $periodos = $this->periodos();
        $inicio = $periodos[0];
        $pdo = Database::getInstance();

        $exames = $pdo->prepare(
            "SELECT periodo_ref AS mes,
                    COUNT(*) AS total_exames,
                    COALESCE(SUM(valor_venda), 0) AS receita
             FROM bi_exames
             WHERE periodo_ref >= :periodo_inicial
               AND periodo_ref IS NOT NULL
               AND periodo_ref <> ''
             GROUP BY periodo_ref
             ORDER BY periodo_ref ASC"
        );
        $exames->execute(['periodo_inicial' => $inicio]);

        $mesSql = SqlHelper::dateFormat('created_at', '%Y-%m');
        $tenants = $pdo->prepare(
            "SELECT {$mesSql} AS mes,
                    COUNT(*) AS novos_negocios
             FROM bi_tenants
             WHERE created_at >= :data_inicial
             GROUP BY {$mesSql}
             ORDER BY mes ASC"
        );
        $tenants->execute(['data_inicial' => $inicio . '-01 00:00:00']);

        $porMes = [];
        foreach ($periodos as $mes) {
            $porMes[$mes] = [
                'mes' => $mes,
                'total_exames' => 0,
                'receita' => 0.0,
                'novos_negocios' => 0,
            ];
        }
        foreach ($exames->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $linha) {
            $mes = (string) ($linha['mes'] ?? '');
            if (isset($porMes[$mes])) {
                $porMes[$mes]['total_exames'] = (int) $linha['total_exames'];
                $porMes[$mes]['receita'] = (float) $linha['receita'];
            }
        }
        foreach ($tenants->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $linha) {
            $mes = (string) ($linha['mes'] ?? '');
            if (isset($porMes[$mes])) {
                $porMes[$mes]['novos_negocios'] = (int) $linha['novos_negocios'];
            }
        }

        return array_values($porMes);
    }

    /** @return array<int, array<string, mixed>> */
    private function serieVazia(): array
    {
        return array_map(static fn(string $mes): array => [
            'mes' => $mes,
            'total_exames' => 0,
            'receita' => 0.0,
            'novos_negocios' => 0,
        ], $this->periodos());
    }

    /** @return array<int, string> */
    private function periodos(): array
    {
        $agora = new DateTimeImmutable('first day of this month');
        $periodos = [];
        for ($i = self::TOTAL_MESES_EVOLUCAO - 1; $i >= 0; $i--) {
            $periodos[] = $agora->modify("-{$i} months")->format('Y-m');
        }
        return $periodos;
    }

    /** @param array<int, array<string, mixed>> $relatorio @param array<int, array<string, mixed>> $evolucao */
    private function resumo(array $relatorio, array $evolucao): array
    {
        return [
            'total_negocios' => count($relatorio),
            'negocios_ativos' => count(array_filter($relatorio, static fn(array $linha): bool => (string) ($linha['status'] ?? '') === 'ativo')),
            'total_exames' => array_sum(array_map(static fn(array $linha): int => (int) ($linha['total_exames'] ?? 0), $relatorio)),
            'receita_total' => array_sum(array_map(static fn(array $linha): float => (float) ($linha['receita'] ?? 0), $relatorio)),
            'novos_negocios_periodo' => array_sum(array_map(static fn(array $linha): int => (int) ($linha['novos_negocios'] ?? 0), $evolucao)),
        ];
    }

    /** @param array<int, array<string, mixed>> $relatorio @param array<int, array<string, mixed>> $evolucao */
    private function exportarXlsx(array $relatorio, array $evolucao): void
    {
        $spreadsheet = new Spreadsheet();
        $porNegocio = $spreadsheet->getActiveSheet();
        $porNegocio->setTitle('Por negócio');
        $porNegocio->fromArray(['Negócio', 'Status', 'Plano', 'Total de exames', 'Receita'], null, 'A1');
        $porNegocio->fromArray(array_map(static fn(array $linha): array => [
            $linha['nome'] ?? '',
            $linha['status'] ?? '',
            $linha['plano'] ?? 'Sem plano',
            (int) ($linha['total_exames'] ?? 0),
            (float) ($linha['receita'] ?? 0),
        ], $relatorio), null, 'A2');
        $porNegocio->getStyle('A1:E1')->getFont()->setBold(true);
        $porNegocio->getStyle('E2:E' . max(2, count($relatorio) + 1))->getNumberFormat()->setFormatCode('R$ #,##0.00');
        foreach (range('A', 'E') as $coluna) {
            $porNegocio->getColumnDimension($coluna)->setAutoSize(true);
        }

        $mensal = $spreadsheet->createSheet();
        $mensal->setTitle('Evolução mensal');
        $mensal->fromArray(['Mês', 'Total de exames', 'Receita', 'Novos negócios'], null, 'A1');
        $mensal->fromArray(array_map(static fn(array $linha): array => [
            $linha['mes'] ?? '',
            (int) ($linha['total_exames'] ?? 0),
            (float) ($linha['receita'] ?? 0),
            (int) ($linha['novos_negocios'] ?? 0),
        ], $evolucao), null, 'A2');
        $mensal->getStyle('A1:D1')->getFont()->setBold(true);
        $mensal->getStyle('C2:C' . max(2, count($evolucao) + 1))->getNumberFormat()->setFormatCode('R$ #,##0.00');
        foreach (range('A', 'D') as $coluna) {
            $mensal->getColumnDimension($coluna)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="relatorio_estrategico_voxel_' . date('Y-m-d') . '.xlsx"');
        header('Cache-Control: max-age=0');
        (new Xlsx($spreadsheet))->save('php://output');
        exit;
    }
}
