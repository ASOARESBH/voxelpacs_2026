<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\TenantContext;
use App\Services\PreditivoService;

class PreditivoController extends Controller {
    public function index(): void {
        $tenantId    = TenantContext::id();
        $pacsId      = !empty($_GET['pacs_id']) ? (int) $_GET['pacs_id'] : null;
        $service     = new PreditivoService();

        $projecao    = $service->projetarVolume($tenantId, $pacsId, 3);
        $sazonalidade = $service->analisarSazonalidade($tenantId, $pacsId);
        $alertas     = $service->gerarAlertas($tenantId);

        $this->view('preditivo/index', compact('projecao', 'sazonalidade', 'alertas'));
    }

    public function apiDados(): void {
        $tenantId = TenantContext::id();
        $pacsId   = !empty($_GET['pacs_id']) ? (int) $_GET['pacs_id'] : null;
        $service  = new PreditivoService();

        $this->json([
            'projecao'     => $service->projetarVolume($tenantId, $pacsId, 3),
            'sazonalidade' => $service->analisarSazonalidade($tenantId, $pacsId),
            'alertas'      => $service->gerarAlertas($tenantId),
        ]);
    }
}
