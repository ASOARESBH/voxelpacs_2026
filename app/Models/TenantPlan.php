<?php
namespace App\Models;

use App\Core\Model;

class TenantPlan extends Model {
    protected string $table = 'bi_plans';
    protected bool $hasTenant = false;

    public function findAll(): array {
        return $this->pdo->query("SELECT * FROM bi_plans WHERE ativo = 1 ORDER BY preco_mensal")->fetchAll(\PDO::FETCH_ASSOC);
    }

    // Alias para compatibilidade
    public function all(): array {
        return $this->findAll();
    }

    public function findById(int $id): ?object {
        $stmt = $this->pdo->prepare("SELECT * FROM bi_plans WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO bi_plans (nome, slug, max_usuarios, max_pacs, max_exames_mes, permite_benchmark, permite_preditivo, permite_api, preco_mensal)
            VALUES (:nome, :slug, :max_usuarios, :max_pacs, :max_exames_mes, :permite_benchmark, :permite_preditivo, :permite_api, :preco_mensal)
        ");
        $stmt->execute($data);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void {
        $sets = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($data)));
        $data['id'] = $id;
        $this->pdo->prepare("UPDATE bi_plans SET {$sets} WHERE id = :id")->execute($data);
    }
}
