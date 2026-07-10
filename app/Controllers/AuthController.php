<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;

class AuthController extends Controller {

    public function showLogin(): void {
        // Gera token CSRF se não existir
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // Se já está autenticado, redireciona para o destino correto
        if (Auth::check()) {
            if (Auth::isPlatformAdmin()) {
                $this->redirect('/platform/dashboard');
            }
            $this->redirect('/dashboard');
        }

        $this->view('auth/login', ['title' => 'Login — VOXEL PACS'], 'auth');
    }

    public function login(): void {
        // Gera token CSRF se não existir
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            $this->view('auth/login', [
                'title' => 'Login — VOXEL PACS',
                'error' => 'Preencha todos os campos.',
            ], 'auth');
            return;
        }

        if (!Auth::login($email, $password)) {
            $this->view('auth/login', [
                'title' => 'Login — VOXEL PACS',
                'error' => 'E-mail ou senha incorretos.',
            ], 'auth');
            return;
        }

        // Superadmin vai direto para o painel da plataforma
        if (Auth::isPlatformAdmin()) {
            $this->redirect('/platform/dashboard');
        }

        // Usuário comum: verifica tenants
        $tenants = Auth::userTenants();

        if (count($tenants) === 0) {
            Auth::logout();
            $this->redirect('/login?error=sem_acesso');
        } elseif (count($tenants) === 1) {
            $this->redirect('/dashboard');
        } else {
            $this->redirect('/selecionar-empresa');
        }
    }

    public function logout(): void {
        Auth::logout();
        $this->redirect('/login');
    }

    public function selectTenant(): void {
        if (!Auth::check()) {
            $this->redirect('/login');
        }

        // Gera token CSRF se não existir
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $tenants = Auth::userTenants();

        if (empty($tenants)) {
            Auth::logout();
            $this->redirect('/login?error=sem_acesso');
        }

        $this->view('auth/select_tenant', [
            'title'   => 'Selecionar Empresa — VOXEL PACS',
            'tenants' => $tenants,
        ], 'auth');
    }

    public function setTenant(): void {
        if (!Auth::check()) {
            $this->redirect('/login');
        }

        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        $allowed  = array_column(Auth::userTenants(), 'tenant_id');

        if (!$tenantId || !in_array($tenantId, $allowed)) {
            $this->redirect('/selecionar-empresa');
        }

        Auth::setTenant($tenantId);
        $this->redirect('/dashboard');
    }
}
