<?php
namespace App\Models;

use App\Core\Model;

class Tenant extends Model {
    protected string $table = 'bi_tenants';
    protected bool $hasTenant = false;

    // Alias para compatibilidade com NegociosController
    public function find(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM bi_tenants WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function all(): array {
        return $this->findAll();
    }

    public function findById(int $id): ?object {
        $stmt = $this->pdo->prepare("SELECT t.*, p.slug as plano FROM bi_tenants t JOIN bi_plans p ON p.id = t.plan_id WHERE t.id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findAll(): array {
        $stmt = $this->pdo->query("SELECT t.*, p.nome as plano_nome FROM bi_tenants t JOIN bi_plans p ON p.id = t.plan_id ORDER BY t.nome");
        return $stmt->fetchAll();
    }

    public function create(array $data): int {
        // Filtra os dados para incluir apenas as chaves que existem no array
        $fields = array_keys($data);
        $placeholders = array_map(fn($f) => ":{$f}", $fields);
        
        $sql = "INSERT INTO bi_tenants (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void {
        $sets = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($data)));
        $data['id'] = $id;
        $this->pdo->prepare("UPDATE bi_tenants SET {$sets} WHERE id = :id")->execute($data);
    }
}
