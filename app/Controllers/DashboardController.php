<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;

class DashboardController extends Controller {
    public function index(): void {
        // VOXEL PACS: redireciona para a worklist de estudos
        $this->redirect('/estudos');
    }
}
