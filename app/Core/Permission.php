<?php
namespace App\Core;

class Permission {
    private static array $permissions = [
        'superadmin' => ['*'],
        'admin'      => [
            'manage_users', 'manage_configuracoes', 'manage_pacs', 'manage_sla_regras', 'manage_pedidos',
            'view_dashboard', 'view_exames', 'view_medicos', 'view_unidades',
            'view_financeiro', 'view_sla', 'view_preditivo', 'view_benchmark',
            'view_relatorios', 'importar_dados', 'exportar_dados',
        ],
                'analista'   => [
            'manage_pedidos',
            'view_dashboard', 'view_exames', 'view_medicos', 'view_unidades',
            'view_financeiro', 'view_sla', 'view_preditivo', 'view_benchmark',
            'view_relatorios', 'importar_dados', 'exportar_dados',
        ],
        'viewer'     => [
            'view_dashboard', 'view_exames', 'view_medicos', 'view_unidades',
            'view_financeiro', 'view_sla', 'view_preditivo', 'view_relatorios',
        ],
    ];

    public static function can(string $role, string $permission): bool {
        if (!isset(self::$permissions[$role])) return false;
        $perms = self::$permissions[$role];
        return in_array('*', $perms) || in_array($permission, $perms);
    }
}
