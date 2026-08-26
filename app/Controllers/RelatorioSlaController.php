<?php
/**
 * RelatorioSlaController — Relatório "SLA Médicos" (menu Relatórios).
 *
 * Somente leitura. O cálculo de SLA em si vive em RelatorioSlaCalcService;
 * este controller só orquestra filtros → repositório → cálculo → view/export.
 * Não usa EstudosController nem EstudosRepository (ver
 * SKILL-VOXEL-PACS/modules/relatorios.md para a decisão de arquitetura).
 */
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Audit\AuditLogger;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Repositories\RelatorioEstudosRepository;
use App\Services\RelatorioExportService;
use App\Services\RelatorioFiltrosService;
use App\Services\RelatorioPermissaoService;
use App\Services\RelatorioSlaCalcService;

class RelatorioSlaController extends Controller
{
    private function tenantId(): int
    {
        $id = TenantContext::id();
        if (!$id) {
            Logger::error('[RelatorioSlaController] Acesso sem tenant_id', ['user_id' => Auth::userId()]);
            $this->redirect('/selecionar-empresa');
            exit;
        }
        return $id;
    }

    public function index(): void
    {
        $tenantId  = $this->tenantId();
        (new RelatorioPermissaoService())->exigir('sla_medicos');
        $pdo       = Database::getInstance();
        $repo      = new RelatorioEstudosRepository($pdo);
        $filtroSvc = new RelatorioFiltrosService($repo);
        $calcSvc   = new RelatorioSlaCalcService();

        $filtros = $filtroSvc->parse($_GET, $tenantId);
        $opcoes  = $filtroSvc->opcoes($tenantId, $filtros['unidade']);

        $bruto      = $repo->buscarEstudos($filtros, paginar: false);
        $regras     = $repo->getRegrasSlaAtivas($tenantId);
        $resultado  = $calcSvc->processar($bruto['linhas'], $regras, $filtros);

        AuditLogger::log('relatorio.sla_medicos_visualizado', 'relatorio_sla', null, [
            'periodo' => [$filtros['data_de'], $filtros['data_ate']],
            'modalidades_filtradas' => count($filtros['modalidades'] ?? []),
            'prioridades_filtradas' => count($filtros['prioridades'] ?? []),
        ], $tenantId, 'acesso');

        $this->view('relatorios/sla_medicos', [
            'title'        => 'Relatório SLA Médicos',
            'filtros'      => $filtros,
            'opcoes'       => $opcoes,
            'linhas'       => $resultado['linhas'],
            'total'        => $resultado['total'],
            'agregado'     => $resultado['agregado_por_medico'],
            'totalPaginas' => (int) ceil($resultado['total'] / $filtros['por_pagina']),
            'atencaoThresholdPct' => RelatorioSlaCalcService::ATENCAO_THRESHOLD_PCT,
        ]);
    }

    public function exportar(): void
    {
        $tenantId  = $this->tenantId();
        (new RelatorioPermissaoService())->exigir('sla_medicos');
        $formato   = ($_GET['formato'] ?? 'xlsx') === 'pdf' ? 'pdf' : 'xlsx';

        if ($formato === 'pdf' && !RelatorioExportService::pdfDisponivel()) {
            Logger::error('[RelatorioSlaController::exportar] Dompdf indisponível', [
                'tenant_id' => $tenantId,
                'user_id'   => Auth::userId(),
            ]);
            http_response_code(503);
            $this->view('relatorios/pdf_indisponivel', [
                'title'      => 'Exportação PDF indisponível',
                'urlRetorno' => '/relatorios/sla',
            ]);
            return;
        }

        $pdo       = Database::getInstance();
        $repo      = new RelatorioEstudosRepository($pdo);
        $filtroSvc = new RelatorioFiltrosService($repo);
        $calcSvc   = new RelatorioSlaCalcService();
        $filtros   = $filtroSvc->parse($_GET, $tenantId);

        $bruto     = $repo->buscarEstudos($filtros, paginar: false);
        $regras    = $repo->getRegrasSlaAtivas($tenantId);
        // Export não pagina — mostra o conjunto inteiro já calculado/ordenado.
        $filtrosSemPagina               = $filtros;
        $filtrosSemPagina['por_pagina'] = 999999;
        $resultado = $calcSvc->processar($bruto['linhas'], $regras, $filtrosSemPagina);

        $exportSvc   = new RelatorioExportService();
        $tenantNome  = TenantContext::name() ?: 'VOXEL PACS';
        $usuarioNome = Auth::user()->name ?? '—';
        $resumo      = $this->resumoFiltros($filtros);
        $filename    = 'RELATORIO_SLA_MEDICOS_' . date('Ymd_Hi');

        AuditLogger::log('relatorio.sla_medicos_exportado', 'relatorio_sla', null, [
            'formato' => $formato,
            'periodo' => [$filtros['data_de'], $filtros['data_ate']],
            'modalidades_filtradas' => count($filtros['modalidades'] ?? []),
            'prioridades_filtradas' => count($filtros['prioridades'] ?? []),
            'total_linhas' => (int) $resultado['total'],
        ], $tenantId, 'acesso');

        if ($formato === 'pdf') {
            $exportSvc->streamPdf(
                __DIR__ . '/../Views/relatorios/pdf/sla_medicos.php',
                [
                    'linhas'      => $resultado['linhas'],
                    'agregado'    => $resultado['agregado_por_medico'],
                    'resumo'      => $resumo,
                    'tenantNome'  => $tenantNome,
                    'usuarioNome' => $usuarioNome,
                    'geradoEm'    => date('d/m/Y H:i'),
                ],
                $filename . '.pdf'
            );
            return;
        }

        $exportSvc->streamXlsxSla($resultado['linhas'], $resultado['agregado_por_medico'], $resumo, $tenantNome, $usuarioNome, $filename . '.xlsx');
    }

    private function resumoFiltros(array $filtros): array
    {
        $labelRelatorioPor = [
            'conclusao' => 'Data Conclusão do Laudo',
            'estudo'    => 'Data do Estudo',
            'registro'  => 'Data Registro do Estudo',
        ];
        $resumo = [
            'Período'        => $filtros['data_de'] . ' a ' . $filtros['data_ate'],
            'Unidade'        => $filtros['unidade'] ?: 'Todas as Unidades',
            'Relatório por'  => $labelRelatorioPor[$filtros['relatorio_por']] ?? $filtros['relatorio_por'],
        ];
        if (!empty($filtros['modalidades'])) $resumo['Modalidades'] = implode(', ', $filtros['modalidades']);
        if (!empty($filtros['prioridades'])) $resumo['Prioridades'] = implode(', ', $filtros['prioridades']);
        if (!empty($filtros['situacoes']))   $resumo['Situações']   = implode(', ', $filtros['situacoes']);
        if ($filtros['tempo_valor'] !== null) $resumo['Tempo maior que'] = $filtros['tempo_valor'] . ' ' . $filtros['tempo_unidade'];
        if (!empty($filtros['pessoa']))      $resumo[$filtros['medico_ou_solicitante'] === 'solicitante' ? 'Solicitante' : 'Médico'] = $filtros['pessoa'];
        return $resumo;
    }
}
