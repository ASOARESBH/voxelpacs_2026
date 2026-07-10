<?php
namespace App\Models;

use App\Core\Model;

class Medico extends Model {
    protected string $table = 'bi_medicos';
    protected bool $hasTenant = true;

    public function findAll(): array {
        $sql = "SELECT m.*, COUNT(e.id) as total_exames
                FROM {$this->table} m
                LEFT JOIN bi_exames e ON e.medico_id = m.id AND e.tenant_id = m.tenant_id
                WHERE m.ativo = 1" . $this->tenantWhere('m') . "
                GROUP BY m.id ORDER BY m.nome";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->tenantParam());
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?object {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id" . $this->tenantWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(['id' => $id], $this->tenantParam()));
        return $stmt->fetch() ?: null;
    }
}
