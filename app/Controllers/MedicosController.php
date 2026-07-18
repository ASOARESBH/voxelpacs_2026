<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\TenantContext;
use App\Models\Medico;
use App\Repositories\EstudosRepository;

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
        $medicoModel = new Medico();
        $this->view('medicos/form', [
            'medico'          => null,
            'title'           => 'Novo Médico',
            'usuarios'        => $medicoModel->findUsuariosVinculaveis(),
            'unidades'        => (new EstudosRepository())->getUnidades(TenantContext::id(), false),
            'unidadesMarcadas'=> [],
        ]);
    }

    public function store(): void {
        $tenantId      = TenantContext::id();
        $nome          = trim($_POST['nome'] ?? '');
        $crm           = trim($_POST['crm'] ?? '') ?: null;
        $especialidade = trim($_POST['especialidade'] ?? '') ?: null;
        $usuarioId     = $this->resolverUsuarioId($tenantId, null);
        $unidades      = $_POST['unidades'] ?? [];

        if (!$nome || !$tenantId) {
            $this->redirect('/medicos/create?error=campos_obrigatorios');
            return;
        }

        $pdo = Database::getInstance();
        $pdo->prepare("INSERT INTO bi_medicos (tenant_id, nome, crm, especialidade, usuario_id, ativo) VALUES (?,?,?,?,?,1)")
            ->execute([$tenantId, $nome, $crm, $especialidade, $usuarioId]);

        (new Medico())->sincronizarUnidades((int) $pdo->lastInsertId(), $tenantId, $unidades);

        $this->redirect('/medicos');
    }

    public function edit(int $id): void {
        $medicoModel = new Medico();
        $medico = $medicoModel->findById($id);
        if (!$medico) { http_response_code(404); exit('Médico não encontrado.'); }
        $this->view('medicos/form', [
            'medico'          => $medico,
            'title'           => 'Editar Médico',
            'usuarios'        => $medicoModel->findUsuariosVinculaveis(),
            'unidades'        => (new EstudosRepository())->getUnidades(TenantContext::id(), false),
            'unidadesMarcadas'=> $medicoModel->getUnidadesVinculadas($id),
        ]);
    }

    public function update(int $id): void {
        $tenantId      = TenantContext::id();
        $nome          = trim($_POST['nome'] ?? '');
        $crm           = trim($_POST['crm'] ?? '') ?: null;
        $especialidade = trim($_POST['especialidade'] ?? '') ?: null;
        $usuarioId     = $this->resolverUsuarioId($tenantId, $id);
        $unidades      = $_POST['unidades'] ?? [];

        if ($nome) {
            Database::getInstance()
                ->prepare("UPDATE bi_medicos SET nome=?, crm=?, especialidade=?, usuario_id=? WHERE id=?")
                ->execute([$nome, $crm, $especialidade, $usuarioId, $id]);
        }

        (new Medico())->sincronizarUnidades($id, $tenantId, $unidades);

        $this->redirect('/medicos');
    }

    /**
     * Resolve o usuario_id enviado pelo form, validando que pertence ao tenant
     * atual e ainda não está vinculado a outro médico (bi_medicos.uq_tenant_usuario).
     * Retorna null se vazio ou inválido, para não quebrar o cadastro por causa do vínculo.
     */
    private function resolverUsuarioId(?int $tenantId, ?int $medicoIdAtual): ?int {
        $usuarioId = (int) ($_POST['usuario_id'] ?? 0);
        if (!$usuarioId || !$tenantId) return null;

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT u.id FROM bi_users u
            INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = ?
            WHERE u.id = ? AND u.status = 'ativo'
        ");
        $stmt->execute([$tenantId, $usuarioId]);
        if (!$stmt->fetchColumn()) return null;

        $stmtDup = $pdo->prepare("SELECT id FROM bi_medicos WHERE tenant_id = ? AND usuario_id = ? AND id != ?");
        $stmtDup->execute([$tenantId, $usuarioId, $medicoIdAtual ?? 0]);
        if ($stmtDup->fetchColumn()) return null;

        return $usuarioId;
    }

    public function toggleStatus(int $id): void {
        Database::getInstance()
            ->prepare("UPDATE bi_medicos SET ativo = CASE WHEN ativo=1 THEN 0 ELSE 1 END WHERE id=?")
            ->execute([$id]);
        $this->redirect('/medicos');
    }
}
