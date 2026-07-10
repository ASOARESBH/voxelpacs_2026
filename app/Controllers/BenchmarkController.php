<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\TenantContext;
use App\Services\BenchmarkService;

class BenchmarkController extends Controller {
    public function index(): void {
        $service  = new BenchmarkService();
        $tenantId = TenantContext::id();

        if (!$service->tenantPodeAcessar($tenantId)) {
            $this->view('benchmark/index', ['bloqueado' => true]);
            return;
        }

        $periodo    = date('Y-m', strtotime('-1 month'));
        $media      = $service->calcularMediaPlataforma($periodo);
        $percentil  = $service->calcularPercentil($tenantId, 'volume', $periodo);
        $distSla    = $service->distribuicaoSla($periodo);

        $this->view('benchmark/index', compact('media', 'percentil', 'distSla', 'periodo'));
    }

    public function apiDados(): void {
        $service  = new BenchmarkService();
        $tenantId = TenantContext::id();
        $periodo  = $_GET['periodo'] ?? date('Y-m', strtotime('-1 month'));

        $this->json([
            'media'     => $service->calcularMediaPlataforma($periodo),
            'percentil' => $service->calcularPercentil($tenantId, 'volume', $periodo),
            'distSla'   => $service->distribuicaoSla($periodo),
        ]);
    }
}
