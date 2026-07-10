<?php
namespace App\Models;

use App\Core\Model;
use App\Core\TenantContext;

class Modalidade extends Model {
    protected string $table = 'bi_modalidades';
    protected bool $hasTenant = false;

    public function findAll(): array {
        $tenantId = TenantContext::id();
        $stmt = $this->pdo->prepare("
            SELECT * FROM {$this->table}
            WHERE (tenant_id IS NULL OR tenant_id = :tid) AND ativo = 1
            ORDER BY nome
        ");
        $stmt->execute(['tid' => $tenantId]);
        return $stmt->fetchAll();
    }
}
