<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Medico;

class MedicosController extends Controller {
    public function index(): void {
        $medicos = (new Medico())->findAll();
        $this->view('medicos/index', ['medicos' => $medicos]);
    }

    public function detalhe(int $id): void {
        $medico = (new Medico())->findById($id);
        if (!$medico) { http_response_code(404); exit('Médico não encontrado.'); }
        $this->view('medicos/detalhe', ['medico' => $medico]);
    }
}
