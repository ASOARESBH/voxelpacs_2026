<?php
namespace App\Models;

use App\Core\Model;

class PacsConexao extends Model {
    protected string $table = 'bi_pacs_conexoes';
    protected bool $hasTenant = true;

    public function findById(int $id): ?object {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id" . $this->tenantWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(['id' => $id], $this->tenantParam()));
        return $stmt->fetch() ?: null;
    }

    public function findAll(): array {
        $sql = "SELECT p.*, COUNT(e.id) as total_exames
                FROM {$this->table} p
                LEFT JOIN bi_exames e ON e.pacs_id = p.id AND e.tenant_id = p.tenant_id
                WHERE 1=1" . $this->tenantWhere('p') . "
                GROUP BY p.id ORDER BY p.nome";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->tenantParam());
        return $stmt->fetchAll();
    }

    public function create(array $data): int {
        $data = array_merge($data, $this->tenantParam());
        $cols = implode(', ', array_keys($data));
        $vals = ':' . implode(', :', array_keys($data));
        $stmt = $this->pdo->prepare("INSERT INTO {$this->table} ({$cols}) VALUES ({$vals})");
        $stmt->execute($data);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void {
        $sets = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($data)));
        $data['id'] = $id;
        $data = array_merge($data, $this->tenantParam());
        $this->pdo->prepare("UPDATE {$this->table} SET {$sets} WHERE id = :id" . $this->tenantWhere())->execute($data);
    }

    public function delete(int $id): void {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = :id" . $this->tenantWhere());
        $stmt->execute(array_merge(['id' => $id], $this->tenantParam()));
    }
}
