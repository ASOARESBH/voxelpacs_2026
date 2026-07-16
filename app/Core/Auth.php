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
            SELECT ut.tenant_id, ut.role, t.nome, t.status
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

    public static function setTenant(int $tenantId): void {
        $_SESSION['tenant_id'] = $tenantId;
    }
}
