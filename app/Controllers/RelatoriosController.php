<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Exame;
use App\Services\ExportService;

class RelatoriosController extends Controller {
    public function index(): void {
        $this->view('relatorios/index', ['title' => 'Relatórios — VOXEL B.I']);
    }

    public function exportar(): void {
        $tipo    = $_GET['tipo'] ?? 'exames';
        $formato = $_GET['formato'] ?? 'xlsx';

        $exames  = (new Exame())->paginate([], 1, 99999)['data'];
        $colunas = ['ID', 'Unidade', 'Médico', 'Modalidade', 'Data Estudo', 'SLA (min)', 'Status SLA', 'Valor Venda'];
        $dados   = array_map(fn($e) => [
            $e->id, $e->unidade, $e->medico_nome, $e->modalidade,
            $e->data_estudo, $e->sla_minutos, $e->sla_status, $e->valor_venda,
        ], $exames);

        $service = new ExportService();
        if ($formato === 'csv') {
            $service->exportarCsv($dados, $colunas, "relatorio_{$tipo}_" . date('Y-m-d') . '.csv');
        } else {
            $service->exportarXlsx($dados, $colunas, "relatorio_{$tipo}_" . date('Y-m-d') . '.xlsx');
        }
    }
}
