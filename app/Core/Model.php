<?php
namespace App\Core;

use PDO;

abstract class Model {
    protected PDO $pdo;
    protected string $table = '';
    protected bool $hasTenant = true;

    public function __construct() {
        $this->pdo = Database::getInstance();
    }

    /**
     * Retorna cláusula AND tenant_id = X para uso em WHERE
     */
    protected function tenantWhere(string $alias = ''): string {
        if (!$this->hasTenant || !TenantContext::isSet()) {
            return '';
        }
        $col = $alias ? "{$alias}.tenant_id" : 'tenant_id';
        return " AND {$col} = :tenant_id";
    }

    /**
     * Retorna array ['tenant_id' => X] para uso em execute()
     */
    protected function tenantParam(): array {
        return ($this->hasTenant && TenantContext::isSet())
            ? ['tenant_id' => TenantContext::id()]
            : [];
    }
}
