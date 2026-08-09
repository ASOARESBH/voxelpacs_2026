<?php
namespace App\Core;

class Auth {
    public static function login(string $email, string $password): bool {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM bi_users WHERE email = :email AND status = 'ativo' LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user->password)) {
            return false;
        }

        // Atualiza último login
        $pdo->prepare("UPDATE bi_users SET ultimo_login = NOW() WHERE id = :id")
            ->execute(['id' => $user->id]);

        // Armazena usuário na sessão (sem a senha)
        unset($user->password);
        $_SESSION['user']    = $user;
        $_SESSION['user_id'] = $user->id;

        // Platform admin: não precisa de tenant
        if ($user->role === 'superadmin') {
            $_SESSION['tenant_id'] = null;
            return true;
        }

        // Verifica tenants do usuário
        $stmt2 = $pdo->prepare("
            SELECT ut.tenant_id, ut.role, ut.perfil, t.nome, t.status
            FROM bi_user_tenants ut
            JOIN bi_tenants t ON t.id = ut.tenant_id
            WHERE ut.user_id = :uid AND ut.ativo = 1 AND t.status = 'ativo'
        ");
        $stmt2->execute(['uid' => $user->id]);
        $tenants = $stmt2->fetchAll();

        $_SESSION['user_tenants'] = $tenants;

        if (count($tenants) === 1) {
            $_SESSION['tenant_id'] = $tenants[0]->tenant_id;
        }

        return true;
    }

    public static function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool {
        return isset($_SESSION['user_id']);
    }

    public static function user(): ?object {
        return $_SESSION['user'] ?? null;
    }

    public static function userId(): ?int {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function tenantId(): ?int {
        return isset($_SESSION['tenant_id']) ? (int) $_SESSION['tenant_id'] : null;
    }

    public static function isPlatformAdmin(): bool {
        $user = self::user();
        return $user && $user->role === 'superadmin';
    }

    /**
     * Fonte única de verdade para "superadmin impersonando um Negócio agora".
     * Usada por TenantMiddleware e por Controllers com tenant-scoping próprio
     * (ex: EstudosController) para não duplicar a inferência em dois lugares.
     */
    public static function isImpersonating(): bool {
        return self::isPlatformAdmin() && !empty($_SESSION['impersonating_tenant_id']);
    }

    public static function can(string $permission): bool {
        $user = self::user();
        if (!$user) return false;
        return Permission::can($user->role, $permission);
    }

    public static function userTenants(): array {
        return $_SESSION['user_tenants'] ?? [];
    }

    /**
     * Perfil (bi_user_tenants.perfil: admin/medico/secretaria/analista/viewer)
     * do usuário logado no tenant atualmente ativo (Auth::tenantId()). Lido do
     * cache de sessão gravado em login() — sem query nova por request, já que
     * este helper é chamado a cada render do header (pacs_header.php).
     *
     * Retorna null se não houver tenant ativo (ex: superadmin fora de
     * impersonação) ou se o tenant ativo não estiver em userTenants() (sessão
     * antiga de antes desta coluna existir em cache — refaz login para atualizar).
     */
    public static function perfilAtual(): ?string {
        $tenantId = self::tenantId();
        if (!$tenantId) return null;
        foreach (self::userTenants() as $ut) {
            if ((int) ($ut->tenant_id ?? 0) === $tenantId) {
                return $ut->perfil ?? null;
            }
        }
        return null;
    }

    public static function setTenant(int $tenantId): void {
        $_SESSION['tenant_id'] = $tenantId;
    }

    /**
     * Reautentica o usuário logado por senha (ex.: ReportService::assinar()
     * antes de assinar um laudo). $_SESSION['user'] nunca guarda a senha
     * (login() já faz unset() dela por segurança), por isso busca fresco em
     * bi_users pelo Auth::userId() atual — mesmo password_verify() de login().
     *
     * Regressão corrigida em 2026-08-08: existia (commit ab12376, "Módulo
     * Reports"), foi perdida no commit seguinte (b20630f, "Módulo Estudos
     * v4" — assunto não relacionado, sobrescreveu Auth.php por engano).
     * Confirmado via `git log -S "verifyPassword"`. Ver
     * diagnostics/pendencias-conhecidas.md.
     */
    public static function verifyPassword(string $senha): bool {
        $userId = self::userId();
        if (!$userId) return false;
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT password FROM bi_users WHERE id = :id AND status = 'ativo' LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return $row && password_verify($senha, $row->password);
    }
}
