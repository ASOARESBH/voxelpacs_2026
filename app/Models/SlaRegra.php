<?php
namespace App\Models;

use App\Core\Model;

class SlaRegra extends Model {
    protected string $table = 'bi_sla_regras';
    protected bool $hasTenant = true;

    public function findAll(): array {
        $sql = "SELECT r.*, m.nome AS medico_especifico_nome
                FROM {$this->table} r
                LEFT JOIN bi_medicos m ON m.id = r.medico_especifico_id
                WHERE 1=1" . $this->tenantWhere('r') . "
                ORDER BY r.prioridade ASC, r.nome ASC";
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
