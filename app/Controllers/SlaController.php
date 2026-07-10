<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Exame;

class SlaController extends Controller {
    public function index(): void {
        $exameModel = new Exame();
        $slaStatus  = $exameModel->slaStatus();
        $this->view('sla/index', ['slaStatus' => $slaStatus]);
    }
}
