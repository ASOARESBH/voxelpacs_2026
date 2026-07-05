<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Services\OrthancService;

class PacsController extends Controller {

    // ── Endpoint público: status do Orthanc para o badge de login ──
    public function pingPublic(): void {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        // Orthanc configurado diretamente (sem tenant) para o badge público
        $orthanc = new OrthancService(
            'http://46.225.51.122:8042',
            null, null, 5
        );

        $ping = $orthanc->ping();

        if ($ping['success']) {
            $total = $orthanc->countStudies();
            echo json_encode([
                'online'        => true,
                'total_studies' => $total,
                'version'       => $ping['data']['Version'] ?? null,
            ]);
        } else {
            echo json_encode(['online' => false]);
        }
        exit;
    }

    // ── Listagem de conexões PACS (autenticado) ──
    public function index(): void {
        $this->view('servidor/index', ['title' => 'Servidores PACS — VOXEL PACS']);
    }

    public function create(): void {
        $this->view('servidor/form', ['title' => 'Nova Conexão PACS']);
    }

    public function store(): void {
        $this->redirect('/pacs');
    }

    public function edit(int $id): void {
        $this->view('servidor/form', ['title' => 'Editar Conexão PACS', 'id' => $id]);
    }

    public function update(int $id): void {
        $this->redirect('/pacs');
    }

    public function sincronizar(int $id): void {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    public function testar(int $id): void {
        header('Content-Type: application/json');
        $orthanc = new OrthancService('http://46.225.51.122:8042', null, null, 5);
        $result  = $orthanc->ping();
        echo json_encode($result);
        exit;
    }

    public function deletar(int $id): void {
        $this->redirect('/pacs');
    }
}
