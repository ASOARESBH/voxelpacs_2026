<?php
namespace App\Controllers;

use App\Core\Access\MedicoAccess;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Repositories\RelatorioProdutividadeMedicosRepository;
use App\Services\RelatorioFiltrosService;
use App\Services\RelatorioExportService;

/**
 * Relatório de produtividade médica.
 *
 * Leitura somente: contabiliza apenas reports assinados ou liberados e
 * calcula os tempos a partir da tomada de posse registrada na Worklist.
 */
final class RelatorioMedicosController extends Controller
{
    private function tenantId(): int
    {
        $tenantId = TenantContext::id();
        if (!$tenantId) {
            Logger::error('[RelatorioMedicosController] Acesso sem tenant', ['user_id' => Auth::userId()]);
            $this->redirect('/selecionar-empresa');
            exit;
        }
        return $tenantId;
    }

    public function index(): void
    {
        $tenantId = $this->tenantId();
        $pdo = Database::getInstance();
        $repository = new RelatorioProdutividadeMedicosRepository($pdo);
        $filters = $this->filters($_GET, $tenantId, $repository);
        $result = $repository->buscar($filters);
        $unidades = $repository->unidades($tenantId);
        if (MedicoAccess::isRestricted()) {
            $permitidas = MedicoAccess::allowedInstitutionNames();
            $unidades = array_values(array_filter($unidades, static fn(string $unidade): bool => in_array($unidade, $permitidas, true)));
        }

        $this->view('relatorios/medicos', [
            'title' => 'Relatório de Médicos',
            'filtros' => $filters,
            'opcoes' => [
                'unidades' => $unidades,
                'modalidades' => $repository->modalidades($tenantId),
                'prioridades' => $repository->prioridades($tenantId),
                'medicos' => $repository->medicos($tenantId),
            ],
            'linhas' => $result['linhas'],
            'total' => $result['total'],
            'totalizadores' => $result['totalizadores'],
            'porMedico' => $result['porMedico'],
            'totalPaginas' => (int) ceil($result['total'] / $filters['por_pagina']),
            'medicoRestrito' => MedicoAccess::isRestricted(),
        ]);
    }

    public function exportar(): void
    {
        $tenantId = $this->tenantId();
        $formato = ($_GET['formato'] ?? 'csv') === 'pdf' ? 'pdf' : 'csv';
        if ($formato === 'pdf' && !RelatorioExportService::pdfDisponivel()) {
            http_response_code(503);
            $this->view('relatorios/pdf_indisponivel', [
                'title' => 'Exportação PDF indisponível',
                'urlRetorno' => '/relatorios/medicos',
            ]);
            return;
        }

        $repository = new RelatorioProdutividadeMedicosRepository(Database::getInstance());
        $filters = $this->filters($_GET, $tenantId, $repository);
        $filters['pagina'] = 1;
        $filters['por_pagina'] = 5000;
        $result = $repository->buscar($filters);

        $export = new RelatorioExportService();
        $tenantNome = TenantContext::name() ?: 'VOXEL PACS';
        $usuarioNome = Auth::user()->name ?? '—';
        $resumo = $this->resumoFiltros($filters, $repository);
        $filename = 'RELATORIO_MEDICOS_' . date('Ymd_Hi');

        if ($formato === 'pdf') {
            $export->streamPdf(
                __DIR__ . '/../Views/relatorios/pdf/medicos.php',
                [
                    'linhas' => $result['linhas'],
                    'porMedico' => $result['porMedico'],
                    'totalizadores' => $result['totalizadores'],
                    'resumo' => $resumo,
                    'tenantNome' => $tenantNome,
                    'usuarioNome' => $usuarioNome,
                    'geradoEm' => date('d/m/Y H:i'),
                ],
                $filename . '.pdf'
            );
            return;
        }

        $export->streamCsvMedicos(
            $result['linhas'],
            $result['porMedico'],
            $result['totalizadores'],
            $resumo,
            $tenantNome,
            $usuarioNome,
            $filename . '.csv'
        );
    }

    /** @param array<string,mixed> $filtros @return array<string,string> */
    private function resumoFiltros(array $filtros, RelatorioProdutividadeMedicosRepository $repository): array
    {
        $base = [
            'assinatura' => 'Data da assinatura',
            'liberacao' => 'Data da liberação',
            'estudo' => 'Data do estudo',
        ];
        $resumo = [
            'Período' => $filtros['data_de'] . ' a ' . $filtros['data_ate'],
            'Apurar por' => $base[$filtros['base_periodo']] ?? 'Data da assinatura',
            'Unidade' => $filtros['unidade'] ?: 'Todas as unidades',
            'Modalidades' => $filtros['modalidades'] ? implode(', ', $filtros['modalidades']) : 'Todas',
            'Prioridade' => $filtros['prioridade'] !== '' ? ucfirst($filtros['prioridade']) : 'Todas',
            'Estudo' => $filtros['estudo'] ?: 'Todos',
        ];
        if ($filtros['medico_restrito_id'] !== null) {
            $resumo['Médico'] = 'Próprio médico';
            return $resumo;
        }
        if ($filtros['medico_id'] !== null) {
            foreach ($repository->medicos((int) $filtros['tenant_id']) as $medico) {
                if ((int) $medico['id'] === (int) $filtros['medico_id']) {
                    $resumo['Médico'] = $medico['nome'];
                    return $resumo;
                }
            }
        }
        $resumo['Médico'] = 'Todos os médicos';
        return $resumo;
    }

    /** @return array<string,mixed> */
    private function filters(array $get, int $tenantId, RelatorioProdutividadeMedicosRepository $repository): array
    {
        // Reutiliza a validação de período e a autorização deny-by-default das Unidades.
        $getComPeriodoExplicito = $get;
        $getComPeriodoExplicito['periodo'] = 'personalizado';
        $base = (new RelatorioFiltrosService(new \App\Repositories\RelatorioEstudosRepository(Database::getInstance())))
            ->parse($getComPeriodoExplicito, $tenantId);

        $basePeriodo = in_array($get['base_periodo'] ?? '', ['assinatura', 'liberacao', 'estudo'], true)
            ? $get['base_periodo']
            : 'assinatura';
        $estudo = trim((string) ($get['estudo'] ?? ''));
        $estudo = mb_substr($estudo, 0, 180, 'UTF-8');
        $prioridade = trim((string) ($get['prioridade'] ?? ''));
        $prioridadesValidas = $repository->prioridades($tenantId);
        if ($prioridade !== '' && !in_array($prioridade, $prioridadesValidas, true)) {
            $prioridade = '';
        }

        $medicoId = filter_var($get['medico_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $medicosValidos = array_column($repository->medicos($tenantId), 'id');
        if ($medicoId !== null && !in_array($medicoId, array_map('intval', $medicosValidos), true)) {
            $medicoId = null;
        }

        $restrito = MedicoAccess::isRestricted();
        if ($restrito) {
            $permitidas = MedicoAccess::allowedInstitutionNames();
            if ($base['unidade'] !== '' && !in_array($base['unidade'], $permitidas, true)) {
                $base['unidade'] = '';
            }
        }
        $medicoRestritoId = $restrito ? MedicoAccess::currentMedicoId() : null;
        if ($restrito && (!$medicoRestritoId || $medicoRestritoId <= 0)) {
            // Falha fechada: um perfil médico sem vínculo não vê a produtividade de terceiros.
            $medicoRestritoId = -1;
        }

        return [
            'tenant_id' => $tenantId,
            'data_de' => $base['data_de'],
            'data_ate' => $base['data_ate'],
            'periodo' => $base['periodo'],
            'unidade' => $base['unidade'],
            'modalidades' => $base['modalidades'],
            'base_periodo' => $basePeriodo,
            'estudo' => $estudo,
            'prioridade' => $prioridade,
            'medico_id' => $medicoId,
            'medico_restrito_id' => $medicoRestritoId,
            'pagina' => max(1, (int) ($get['pagina'] ?? 1)),
            'por_pagina' => 25,
        ];
    }
}
