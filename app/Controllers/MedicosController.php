<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\TenantContext;
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

    public function create(): void {
        $this->view('medicos/form', ['medico' => null, 'title' => 'Novo Médico']);
    }

    public function store(): void {
        $tenantId      = TenantContext::id();
        $nome          = trim($_POST['nome'] ?? '');
        $crm           = trim($_POST['crm'] ?? '');
        $especialidade = trim($_POST['especialidade'] ?? '');

        if (!$nome || !$tenantId) {
            $this->redirect('/medicos?error=campos_obrigatorios');
            return;
        }

        $pdo = Database::getInstance();
        $pdo->prepare("
            INSERT INTO bi_medicos (tenant_id, nome, crm, especialidade, ativo, created_at)
            VALUES (?, ?, ?, ?, 1, NOW())
        ")->execute([$tenantId, $nome, $crm ?: null, $especialidade ?: null]);

        $this->redirect('/medicos');
    }

    public function edit(int $id): void {
        $medico = (new Medico())->findById($id);
        if (!$medico) { http_response_code(404); exit('Médico não encontrado.'); }
        $this->view('medicos/form', ['medico' => $medico, 'title' => 'Editar Médico']);
    }

    public function update(int $id): void {
        $tenantId      = TenantContext::id();
        $nome          = trim($_POST['nome'] ?? '');
        $crm           = trim($_POST['crm'] ?? '');
        $especialidade = trim($_POST['especialidade'] ?? '');

        if ($nome) {
            $pdo = Database::getInstance();
            $pdo->prepare("
                UPDATE bi_medicos SET nome = ?, crm = ?, especialidade = ?
                WHERE id = ? AND tenant_id = ?
            ")->execute([$nome, $crm ?: null, $especialidade ?: null, $id, $tenantId]);
        }

        $this->redirect('/medicos');
    }

    public function toggleStatus(int $id): void {
        $tenantId = TenantContext::id();
        $pdo = Database::getInstance();
        $pdo->prepare("
            UPDATE bi_medicos SET ativo = IF(ativo = 1, 0, 1)
            WHERE id = ? AND tenant_id = ?
        ")->execute([$id, $tenantId]);

        $this->redirect('/medicos');
    }
}
