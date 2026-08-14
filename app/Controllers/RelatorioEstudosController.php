<?php
/**
 * RelatorioEstudosController — Relatório "Exames" (menu Relatórios).
 *
 * Somente leitura, puramente analítico — sem ação de abrir/laudar estudo.
 * Não usa EstudosController nem EstudosRepository: toda consulta passa por
 * RelatorioEstudosRepository, camada nova e isolada (ver
 * SKILL-VOXEL-PACS/modules/relatorios.md).
 */
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Repositories\RelatorioEstudosRepository;
use App\Services\RelatorioExportService;
use App\Services\RelatorioFiltrosService;

class RelatorioEstudosController extends Controller
{
    private function tenantId(): int
    {
        $id = TenantContext::id();
        if (!$id) {
            Logger::error('[RelatorioEstudosController] Acesso sem tenant_id', ['user_id' => Auth::userId()]);
            $this->redirect('/selecionar-empresa');
            exit;
        }
        return $id;
    }

    public function index(): void
    {
        $tenantId = $this->tenantId();
        $pdo      = Database::getInstance();
        $repo     = new RelatorioEstudosRepository($pdo);
        $filtroSvc = new RelatorioFiltrosService($repo);

        $filtros = $filtroSvc->parse($_GET, $tenantId);
        $opcoes  = $filtroSvc->opcoes($tenantId, $filtros['unidade']);

        $resultado = $repo->buscarEstudos($filtros, paginar: true);

        $this->view('relatorios/exames', [
            'title'      => 'Relatório de Exames',
            'filtros'    => $filtros,
            'opcoes'     => $opcoes,
            'linhas'     => $resultado['linhas'],
            'total'      => $resultado['total'],
            'totalPaginas' => (int) ceil($resultado['total'] / $filtros['por_pagina']),
        ]);
    }

    public function exportar(): void
    {
        $tenantId = $this->tenantId();
        $formato  = ($_GET['formato'] ?? 'xlsx') === 'pdf' ? 'pdf' : 'xlsx';

        if ($formato === 'pdf' && !RelatorioExportService::pdfDisponivel()) {
            Logger::error('[RelatorioEstudosController::exportar] Dompdf indisponível', [
                'tenant_id' => $tenantId,
                'user_id'   => Auth::userId(),
            ]);
            http_response_code(503);
            $this->view('relatorios/pdf_indisponivel', [
                'title'      => 'Exportação PDF indisponível',
                'urlRetorno' => '/relatorios/exames',
            ]);
            return;
        }

        $pdo       = Database::getInstance();
        $repo      = new RelatorioEstudosRepository($pdo);
        $filtroSvc = new RelatorioFiltrosService($repo);
        $filtros   = $filtroSvc->parse($_GET, $tenantId);

        $resultado = $repo->buscarEstudos($filtros, paginar: false);
        $exportSvc = new RelatorioExportService();

        $tenantNome  = TenantContext::name() ?: 'VOXEL PACS';
        $usuarioNome = Auth::user()->name ?? '—';
        $resumo      = $this->resumoFiltros($filtros);
        $filename    = 'RELATORIO_EXAMES_' . date('Ymd_Hi');

        if ($formato === 'pdf') {
            $exportSvc->streamPdf(
                __DIR__ . '/../Views/relatorios/pdf/exames.php',
                [
                    'linhas'      => $resultado['linhas'],
                    'resumo'      => $resumo,
                    'tenantNome'  => $tenantNome,
                    'usuarioNome' => $usuarioNome,
                    'geradoEm'    => date('d/m/Y H:i'),
                ],
                $filename . '.pdf'
            );
            return;
        }

        $exportSvc->streamXlsxExames($resultado['linhas'], $resumo, $tenantNome, $usuarioNome, $filename . '.xlsx');
    }

    private function resumoFiltros(array $filtros): array
    {
        $resumo = [
            'Período'  => $filtros['data_de'] . ' a ' . $filtros['data_ate'],
            'Unidade'  => $filtros['unidade'] ?: 'Todas as Unidades',
        ];
        if (!empty($filtros['modalidades']))  $resumo['Modalidades'] = implode(', ', $filtros['modalidades']);
        if (!empty($filtros['prioridades']))  $resumo['Prioridades'] = implode(', ', $filtros['prioridades']);
        if (!empty($filtros['pessoa']))       $resumo[$filtros['medico_ou_solicitante'] === 'solicitante' ? 'Solicitante' : 'Médico'] = $filtros['pessoa'];
        return $resumo;
    }
}
