<?php
namespace App\Models;

use App\Core\Model;

class Importacao extends Model {
    protected string $table = 'bi_importacoes';
    protected bool $hasTenant = true;

    public function findAll(): array {
        $sql = "SELECT i.*, p.nome as pacs_nome
                FROM {$this->table} i
                LEFT JOIN bi_pacs_conexoes p ON p.id = i.pacs_id
                WHERE 1=1" . $this->tenantWhere('i') . "
                ORDER BY i.created_at DESC LIMIT 20";
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

    public function updateStatus(int $id, string $status, array $extra = []): void {
        $extra['status'] = $status;
        $sets = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($extra)));
        $extra['id'] = $id;
        $this->pdo->prepare("UPDATE {$this->table} SET {$sets} WHERE id = :id")->execute($extra);
    }
}
