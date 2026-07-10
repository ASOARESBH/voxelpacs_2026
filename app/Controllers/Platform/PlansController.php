<?php
namespace App\Controllers\Platform;

use App\Core\Controller;
use App\Models\TenantPlan;

class PlansController extends Controller {
    public function index(): void {
        $planos = (new TenantPlan())->findAll();
        $this->view('platform/plans/index', ['planos' => $planos], 'platform');
    }

    public function create(): void {
        $this->view('platform/plans/form', ['plano' => null, 'title' => 'Novo Plano'], 'platform');
    }

    public function store(): void {
        $data = [
            'nome'               => $_POST['nome']               ?? '',
            'slug'               => $_POST['slug']               ?? '',
            'max_usuarios'       => (int) ($_POST['max_usuarios']       ?? 5),
            'max_pacs'           => (int) ($_POST['max_pacs']           ?? 1),
            'max_exames_mes'     => (int) ($_POST['max_exames_mes']     ?? 10000),
            'permite_benchmark'  => (int) ($_POST['permite_benchmark']  ?? 0),
            'permite_preditivo'  => (int) ($_POST['permite_preditivo']  ?? 0),
            'permite_api'        => (int) ($_POST['permite_api']        ?? 0),
            'preco_mensal'       => (float) ($_POST['preco_mensal']     ?? 0),
        ];
        (new TenantPlan())->create($data);
        $this->redirect('/platform/plans');
    }

    public function edit(int $id): void {
        $plano = (new TenantPlan())->findById($id);
        $this->view('platform/plans/form', ['plano' => $plano, 'title' => 'Editar Plano'], 'platform');
    }

    public function update(int $id): void {
        $data = [
            'nome'              => $_POST['nome']              ?? '',
            'max_usuarios'      => (int) ($_POST['max_usuarios']      ?? 5),
            'preco_mensal'      => (float) ($_POST['preco_mensal']    ?? 0),
            'permite_benchmark' => (int) ($_POST['permite_benchmark'] ?? 0),
            'permite_preditivo' => (int) ($_POST['permite_preditivo'] ?? 0),
        ];
        (new TenantPlan())->update($id, $data);
        $this->redirect('/platform/plans');
    }
}
