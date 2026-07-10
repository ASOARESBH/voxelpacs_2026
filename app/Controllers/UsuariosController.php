<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\TenantContext;

/**
 * UsuariosController
 * Gerencia os usuários vinculados à unidade (tenant) do cliente.
 */
class UsuariosController extends Controller
{
    public function index(): void
    {
        $tenantId = TenantContext::id();
        $pdo      = Database::getInstance();

        $usuarios = [];
        if ($tenantId) {
            $stmt = $pdo->prepare("
                SELECT
                    u.id,
                    u.name,
                    u.email,
                    u.status,
                    u.created_at,
                    u.last_login_at,
                    ut.role AS tenant_role
                FROM bi_users u
                INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = ?
                ORDER BY ut.role ASC, u.name ASC
            ");
            $stmt->execute([$tenantId]);
            $usuarios = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        $this->view('usuarios/index', compact('usuarios'));
    }

    public function create(): void
    {
        $this->view('usuarios/form', ['usuario' => null, 'title' => 'Novo Usuário']);
    }

    public function store(): void
    {
        $pdo      = Database::getInstance();
        $tenantId = TenantContext::id();
        $email    = trim($_POST['email'] ?? '');
        $name     = trim($_POST['name']  ?? '');
        $role     = $_POST['role']     ?? 'viewer';
        $password = $_POST['password'] ?? '';

        if (!$email || !$name || !$password || !$tenantId) {
            $this->redirect('/usuarios?error=campos_obrigatorios');
            return;
        }

        // Verifica duplicata
        $check = $pdo->prepare("SELECT id FROM bi_users WHERE email = ?");
        $check->execute([$email]);
        $existing = $check->fetchColumn();

        if ($existing) {
            // Usuário já existe — apenas vincula ao tenant se não estiver
            $chkTenant = $pdo->prepare("SELECT id FROM bi_user_tenants WHERE user_id = ? AND tenant_id = ?");
            $chkTenant->execute([$existing, $tenantId]);
            if (!$chkTenant->fetchColumn()) {
                $ins = $pdo->prepare("INSERT INTO bi_user_tenants (user_id, tenant_id, role) VALUES (?,?,?)");
                $ins->execute([$existing, $tenantId, $role]);
            }
        } else {
            // Cria novo usuário
            $ins = $pdo->prepare("INSERT INTO bi_users (name, email, password, role, status, created_at) VALUES (?,?,?,?,?,NOW())");
            $ins->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, 'ativo']);
            $userId = $pdo->lastInsertId();
            $ins2 = $pdo->prepare("INSERT INTO bi_user_tenants (user_id, tenant_id, role) VALUES (?,?,?)");
            $ins2->execute([$userId, $tenantId, $role]);
        }

        $this->redirect('/usuarios');
    }

    public function edit(int $id): void
    {
        $pdo      = Database::getInstance();
        $tenantId = TenantContext::id();
        $stmt     = $pdo->prepare("SELECT u.*, ut.role AS tenant_role FROM bi_users u INNER JOIN bi_user_tenants ut ON ut.user_id=u.id AND ut.tenant_id=? WHERE u.id=?");
        $stmt->execute([$tenantId, $id]);
        $usuario = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->view('usuarios/form', ['usuario' => $usuario, 'title' => 'Editar Usuário']);
    }

    public function update(int $id): void
    {
        $pdo      = Database::getInstance();
        $tenantId = TenantContext::id();
        $role     = $_POST['role'] ?? 'viewer';
        $name     = trim($_POST['name'] ?? '');

        if ($name) {
            $pdo->prepare("UPDATE bi_users SET name=? WHERE id=?")->execute([$name, $id]);
        }
        $pdo->prepare("UPDATE bi_user_tenants SET role=? WHERE user_id=? AND tenant_id=?")->execute([$role, $id, $tenantId]);

        $this->redirect('/usuarios');
    }

    public function toggleStatus(int $id): void
    {
        $pdo = Database::getInstance();
        $pdo->prepare("UPDATE bi_users SET status = CASE WHEN status='ativo' THEN 'inativo' ELSE 'ativo' END WHERE id=?")->execute([$id]);
        $this->redirect('/usuarios');
    }
}
