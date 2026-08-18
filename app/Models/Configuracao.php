<?php
namespace App\Models;

use App\Core\Model;
use App\Core\SqlHelper;

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
                $sql = SqlHelper::isPostgres()
            ? "INSERT INTO {$this->table} (tenant_id, chave, valor)
               VALUES (:tenant_id, :chave, :valor)
               ON CONFLICT (tenant_id, chave) DO UPDATE SET valor = EXCLUDED.valor"
            : "INSERT INTO {$this->table} (tenant_id, chave, valor)
               VALUES (:tenant_id, :chave, :valor)
               ON DUPLICATE KEY UPDATE valor = VALUES(valor)";
        $this->pdo->prepare($sql)->execute($params);

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

    /**
     * Recupera somente chaves previamente autorizadas pelo Controller.
     * Evita expor configuração de infraestrutura ao administrador do tenant.
     */
    public function getMany(array $chaves): array {
        $chaves = array_values(array_unique(array_filter($chaves, static fn ($chave): bool => is_string($chave) && $chave !== '')));
        if ($chaves === []) {
            return [];
        }

        $placeholders = [];
        $params = $this->tenantParam();
        foreach ($chaves as $indice => $chave) {
            $placeholder = ':chave_' . $indice;
            $placeholders[] = $placeholder;
            $params[ltrim($placeholder, ':')] = $chave;
        }

        $sql = "SELECT chave, valor FROM {$this->table}"
            . ' WHERE chave IN (' . implode(', ', $placeholders) . ')'
            . $this->tenantWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row->chave] = $row->valor;
        }
        return $result;
    }
}
