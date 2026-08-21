<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$auth = (string) file_get_contents($root . '/app/Core/Auth.php');
$usersController = (string) file_get_contents($root . '/app/Controllers/UsuariosController.php');
$groupsController = (string) file_get_contents($root . '/app/Controllers/GruposController.php');
$view = (string) file_get_contents($root . '/app/Views/usuarios/index.php');

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require(str_contains($auth, 'public static function canManageTenantUsers(): bool'), 'Auth deve centralizar a permissão administrativa de usuários.');
$require(
    str_contains($auth, "return self::isPlatformAdmin() || self::perfilAtual() === 'admin';"),
    'A administração de usuários deve depender do perfil admin do tenant ou superadmin.'
);
$require(
    str_contains($usersController, 'WHERE (? = 1 OR u.id = ?)')
    && str_contains($usersController, '$canManageUsuarios ? 1 : 0'),
    'A listagem deve limitar perfis comuns ao próprio usuário.'
);
$require(
    substr_count($usersController, 'if (!$this->requireUserManagement()) return;') >= 6,
    'Todas as ações administrativas de usuários devem exigir autorização no backend.'
);
$require(
    str_contains($usersController, 'private function usuarioPertenceAoTenant')
    && str_contains($usersController, 'INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = ?'),
    'Operações direcionadas a um usuário devem manter o escopo do tenant.'
);
$require(
    substr_count($groupsController, 'if (!$this->requireUserManagement()) return;') >= 7,
    'Todo o CRUD e gerenciamento de membros de grupos deve exigir administrador.'
);
$require(
    str_contains($view, '<?php if ($canManageUsuarios): ?>')
    && str_contains($view, "'Consulte os dados e permissões da sua conta de acesso'"),
    'A interface deve ocultar ações administrativas e explicar a visão individual aos perfis comuns.'
);
$require(
    str_contains($view, 'Somente administradores autorizados podem gerenciar outros usuários e grupos.'),
    'A interface deve informar bloqueio de rota administrativa sem revelar dados de terceiros.'
);

if ($failures !== []) {
    fwrite(STDERR, "USUARIOS_ACCESS_SCOPE_STATIC_FALHOU\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "USUARIOS_ACCESS_SCOPE_STATIC_OK\n");
