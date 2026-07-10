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
        $crm           = trim($_POST['crm'] ?? '') ?: null;
        $especialidade = trim($_POST['especialidade'] ?? '') ?: null;

        if (!$nome || !$tenantId) {
            $this->redirect('/medicos/create?error=campos_obrigatorios');
            return;
        }

        Database::getInstance()
            ->prepare("INSERT INTO bi_medicos (tenant_id, nome, crm, especialidade, ativo) VALUES (?,?,?,?,1)")
            ->execute([$tenantId, $nome, $crm, $especialidade]);

        $this->redirect('/medicos');
    }

    public function edit(int $id): void {
        $medico = (new Medico())->findById($id);
        if (!$medico) { http_response_code(404); exit('Médico não encontrado.'); }
        $this->view('medicos/form', ['medico' => $medico, 'title' => 'Editar Médico']);
    }

    public function update(int $id): void {
        $nome          = trim($_POST['nome'] ?? '');
        $crm           = trim($_POST['crm'] ?? '') ?: null;
        $especialidade = trim($_POST['especialidade'] ?? '') ?: null;

        if ($nome) {
            Database::getInstance()
                ->prepare("UPDATE bi_medicos SET nome=?, crm=?, especialidade=? WHERE id=?")
                ->execute([$nome, $crm, $especialidade, $id]);
        }

        $this->redirect('/medicos');
    }

    public function toggleStatus(int $id): void {
        Database::getInstance()
            ->prepare("UPDATE bi_medicos SET ativo = CASE WHEN ativo=1 THEN 0 ELSE 1 END WHERE id=?")
            ->execute([$id]);
        $this->redirect('/medicos');
    }
}
