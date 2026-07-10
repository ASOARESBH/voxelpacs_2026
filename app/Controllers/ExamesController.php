<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Exame;
use App\Services\ExportService;

class ExamesController extends Controller {
    public function index(): void {
        $filters = [
            'pacs_id'      => $_GET['pacs_id']      ?? null,
            'modalidade'   => $_GET['modalidade']   ?? null,
            'data_inicio'  => $_GET['data_inicio']  ?? null,
            'data_fim'     => $_GET['data_fim']      ?? null,
            'prioridade'   => $_GET['prioridade']   ?? null,
        ];
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $result = (new Exame())->paginate($filters, $page);
        $this->view('exames/index', array_merge($result, ['filters' => $filters]));
    }

    public function detalhe(int $id): void {
        $exame = (new Exame())->findById($id);
        if (!$exame) { http_response_code(404); exit('Exame não encontrado.'); }
        $this->view('exames/detalhe', ['exame' => $exame]);
    }

    public function apiDados(): void {
        $filters = ['pacs_id' => $_GET['pacs_id'] ?? null];
        $this->json((new Exame())->paginate($filters, (int) ($_GET['page'] ?? 1)));
    }

    public function exportar(): void {
        $exames = (new Exame())->paginate([], 1, 99999)['data'];
        $colunas = ['ID', 'Unidade', 'Médico', 'Modalidade', 'Data Estudo', 'SLA (min)', 'Status SLA', 'Valor'];
        $dados   = array_map(fn($e) => [
            $e->id, $e->unidade, $e->medico_nome, $e->modalidade,
            $e->data_estudo, $e->sla_minutos, $e->sla_status, $e->valor_venda,
        ], $exames);
        (new ExportService())->exportarXlsx($dados, $colunas, 'exames_' . date('Y-m-d') . '.xlsx');
    }
}
