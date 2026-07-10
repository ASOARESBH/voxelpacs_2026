<?php
namespace App\Models;

use App\Core\Model;

class Unidade extends Model {
    protected string $table = 'bi_unidades';
    protected bool $hasTenant = true;

    public function findAll(): array {
        $sql = "SELECT u.*, COUNT(e.id) as total_exames
                FROM {$this->table} u
                LEFT JOIN bi_exames e ON e.unidade_id = u.id AND e.tenant_id = u.tenant_id
                WHERE u.ativo = 1" . $this->tenantWhere('u') . "
                GROUP BY u.id ORDER BY u.nome";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->tenantParam());
        return $stmt->fetchAll();
    }
}
