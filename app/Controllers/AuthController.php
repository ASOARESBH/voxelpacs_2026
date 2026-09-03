<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Translator;
use App\Core\Audit\AuditLogger;
use App\Services\TwoFactorService;
use App\Services\RegraAcessoService;
use App\Services\LoginAttemptGuard;

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

        if (!$this->validCsrf()) {
            $this->view('auth/login', [
                'title' => 'Login — VOXEL PACS',
                'error' => Translator::t('auth.login.erro_credenciais'),
            ], 'auth');
            return;
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

        $attempts = new LoginAttemptGuard();
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!$attempts->allows($email, $remoteIp)) {
            $this->view('auth/login', [
                'title' => 'Login — VOXEL PACS',
                'error' => Translator::t('auth.login.erro_credenciais'),
            ], 'auth');
            return;
        }

        $user = Auth::credentials($email, $password);
        if (!$user) {
            $attempts->recordFailure($email, $remoteIp);
            $this->view('auth/login', [
                'title' => 'Login — VOXEL PACS',
                'error' => Translator::t('auth.login.erro_credenciais'),

            ], 'auth');
            return;
        }

        $attempts->clearFailures($email, $remoteIp);

        $ruleResult = (new RegraAcessoService())->checkLoginForUser($user);
        if (!$ruleResult['allowed']) {
            $this->renderAccessRuleLoginError((string) ($ruleResult['reason'] ?? 'configuracao_indisponivel'));
            return;
        }

        if ((new TwoFactorService())->isEnabledForUser((int) $user->id)) {
            $challenge = (new TwoFactorService())->issue($user, false);
            if (!$challenge['ok']) {
                $this->view('auth/login', [
                    'title' => 'Login — VOXEL PACS',
                    'error' => Translator::t('auth.2fa.erro_envio'),
                ], 'auth');
                return;
            }
            $_SESSION['two_factor_pending'] = ['user_id' => (int) $user->id, 'challenge_id' => (int) $challenge['challenge_id']];
            $this->redirect('/login/2fa');
        }

        Auth::completeLogin($user);
        $this->auditLogin('senha');
        $this->redirectAfterLogin();
    }

    public function showTwoFactor(): void {
        $this->applyLoginLocale();
        $this->csrfToken();
        if (Auth::check()) $this->redirectAfterLogin();
        if (empty($_SESSION['two_factor_pending']['user_id']) || empty($_SESSION['two_factor_pending']['challenge_id'])) {
            $this->redirect('/login');
        }
        $this->view('auth/two_factor', ['title' => 'Login — VOXEL PACS', 'error' => $_SESSION['two_factor_error'] ?? '', 'success' => $_SESSION['two_factor_success'] ?? ''], 'auth');
        unset($_SESSION['two_factor_error'], $_SESSION['two_factor_success']);
    }

    public function verifyTwoFactor(): void {
        if (!$this->validCsrf()) $this->redirect('/login/2fa');
        $pending = $_SESSION['two_factor_pending'] ?? [];
        $userId = (int) ($pending['user_id'] ?? 0);
        $challengeId = (int) ($pending['challenge_id'] ?? 0);
        $result = (new TwoFactorService())->verify($challengeId, $userId, trim((string) ($_POST['code'] ?? '')));
        if (!$result['ok']) {
            $_SESSION['two_factor_error'] = Translator::t('auth.2fa.erro_' . ($result['error'] ?? 'interno'));
            $this->redirect('/login/2fa');
        }
        $stmt = \App\Core\Database::getInstance()->prepare("SELECT * FROM bi_users WHERE id = ? AND status = 'ativo' LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $ruleResult = $user ? (new RegraAcessoService())->checkLoginForUser($user) : ['allowed' => false, 'reason' => 'configuracao_indisponivel'];
        if (!$ruleResult['allowed']) {
            unset($_SESSION['two_factor_pending']);
            $this->renderAccessRuleLoginError((string) ($ruleResult['reason'] ?? 'configuracao_indisponivel'));
            return;
        }
        unset($_SESSION['two_factor_pending']);
        session_regenerate_id(true);
        if (!Auth::completeLoginById($userId)) $this->redirect('/login?error=sem_acesso');
        $this->auditLogin('email_2f');
        $this->redirectAfterLogin();
    }

    public function resendTwoFactor(): void {
        if (!$this->validCsrf()) $this->redirect('/login/2fa');
        $pending = $_SESSION['two_factor_pending'] ?? [];
        $userId = (int) ($pending['user_id'] ?? 0);
        $stmt = \App\Core\Database::getInstance()->prepare("SELECT * FROM bi_users WHERE id = ? AND status = 'ativo' LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $result = $user ? (new TwoFactorService())->issue($user, true) : ['ok' => false, 'error' => 'expired'];
        if ($result['ok']) {
            $_SESSION['two_factor_pending']['challenge_id'] = (int) $result['challenge_id'];
            $_SESSION['two_factor_success'] = Translator::t('auth.2fa.reenviado');
        } else {
            $_SESSION['two_factor_error'] = Translator::t('auth.2fa.erro_' . ($result['error'] ?? 'interno'));
        }
        $this->redirect('/login/2fa');
    }

    public function cancelTwoFactor(): void {
        if ($this->validCsrf()) unset($_SESSION['two_factor_pending'], $_SESSION['two_factor_error'], $_SESSION['two_factor_success']);
        $this->redirect('/login');
    }

    private function redirectAfterLogin(): void {

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

    private function validCsrf(): bool {
        $csrf = (string) ($_POST['_csrf_token'] ?? '');
        return $csrf !== '' && !empty($_SESSION['csrf_token']) && hash_equals((string) $_SESSION['csrf_token'], $csrf);
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
        $this->auditLogout();
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

        $ruleResult = (new RegraAcessoService())->checkLoginForTenant((int) Auth::userId(), $tenantId);
        if (!$ruleResult['allowed']) {
            Auth::logout();
            $this->redirect('/login?error=' . rawurlencode((string) ($ruleResult['reason'] ?? 'configuracao_indisponivel')));
        }

        Auth::setTenant($tenantId);
        $this->auditLogin('selecao_tenant');
        $this->redirect('/dashboard');
    }

    private function auditLogin(string $method): void {
        $tenantId = Auth::tenantId();
        $userId = Auth::userId();
        if ($tenantId && $userId) {
            AuditLogger::log('login_success', 'sessao', $userId, ['metodo' => $method], $tenantId, 'acesso');
        }
    }

    private function auditLogout(): void {
        $tenantId = Auth::tenantId();
        $userId = Auth::userId();
        if ($tenantId && $userId) {
            AuditLogger::log('logout', 'sessao', $userId, [], $tenantId, 'acesso');
        }
    }

    private function renderAccessRuleLoginError(string $reason): void {
        $key = match ($reason) {
            'ip_nao_permitido' => 'auth.login.erro_ip_nao_permitido',
            'horario_nao_permitido' => 'auth.login.erro_horario_nao_permitido',
            default => 'auth.login.erro_regra_acesso',
        };
        $this->view('auth/login', [
            'title' => 'Login — VOXEL PACS',
            'error' => Translator::t($key),
        ], 'auth');
    }
}
