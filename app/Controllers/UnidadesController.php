<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Unidade;

class UnidadesController extends Controller {
    public function index(): void {
        $unidades = (new Unidade())->findAll();
        $this->view('unidades/index', ['unidades' => $unidades]);
    }
}
