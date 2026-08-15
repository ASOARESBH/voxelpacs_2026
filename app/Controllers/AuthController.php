<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Translator;

class AuthController extends Controller {

        public function showLogin(): void {
        $this->applyLoginLocale();

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
        $this->applyLoginLocale();

        // Gera token CSRF se não existir

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
                        $this->view('auth/login', [
                'title' => 'Login — VOXEL PACS',
                'error' => Translator::t('auth.login.erro_campos_obrigatorios'),

            ], 'auth');
            return;
        }

        if (!Auth::login($email, $password)) {
                        $this->view('auth/login', [
                'title' => 'Login — VOXEL PACS',
                'error' => Translator::t('auth.login.erro_credenciais'),

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

        /**
     * Atualiza a preferência de idioma antes da autenticação.
     * A alteração é protegida por CSRF e limitada aos locais do Translator.
     */
    public function setLoginLocale(): void {
        $csrf = (string) ($_POST['_csrf_token'] ?? '');
        $sessionCsrf = (string) ($_SESSION['csrf_token'] ?? '');
        if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
            $this->redirect('/login');
        }

        $locale = (string) ($_POST['locale'] ?? '');
        if (in_array($locale, Translator::SUPPORTED, true)) {
            $_SESSION['login_locale'] = $locale;
        }

        $this->redirect('/login');
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

        private function applyLoginLocale(): void {
        Translator::setLocale($_SESSION['login_locale'] ?? Translator::FALLBACK);
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
