<?php
namespace App\Models;

use App\Core\Model;

class Configuracao extends Model {
    protected string $table = 'bi_configuracoes';
    protected bool $hasTenant = true;

    public function get(string $chave): ?string {
        $sql = "SELECT valor FROM {$this->table} WHERE chave = :chave" . $this->tenantWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(['chave' => $chave], $this->tenantParam()));
        $row = $stmt->fetch();
        return $row ? $row->valor : null;
    }

    public function set(string $chave, string $valor): void {
        $params = array_merge(['chave' => $chave, 'valor' => $valor], $this->tenantParam());
        $this->pdo->prepare("
            INSERT INTO {$this->table} (tenant_id, chave, valor)
            VALUES (:tenant_id, :chave, :valor)
            ON DUPLICATE KEY UPDATE valor = :valor
        ")->execute($params);
    }

    public function getAll(): array {
        $sql = "SELECT chave, valor FROM {$this->table} WHERE 1=1" . $this->tenantWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->tenantParam());
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row->chave] = $row->valor;
        }
        return $result;
    }
}
