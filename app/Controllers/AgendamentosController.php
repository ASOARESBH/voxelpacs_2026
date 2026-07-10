<?php
namespace App\Controllers;

use App\Core\Controller;

class AgendamentosController extends Controller {
    public function index(): void {
        $this->view('agendamentos/index', [
            'title' => 'Agendamentos — VOXEL PACS',
        ], 'pacs');
    }
}
