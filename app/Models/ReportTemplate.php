<?php
namespace App\Models;

use App\Core\Model;

class ReportTemplate extends Model {
    protected string $table = 'report_templates';
    protected bool $hasTenant = true;

    public function findById(int $id): ?object {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = :id AND ativo = 1");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByModalidade(string $modalidade): array {
        $sql = "SELECT * FROM {$this->table}
                WHERE ativo = 1 AND modalidade = :modalidade
                  AND (tenant_id IS NULL" . ($this->hasTenant && \App\Core\TenantContext::isSet() ? " OR tenant_id = :tenant_id" : "") . ")
                ORDER BY titulo ASC";
        $stmt = $this->pdo->prepare($sql);
        $params = ['modalidade' => $modalidade];
        if ($this->hasTenant && \App\Core\TenantContext::isSet()) {
            $params['tenant_id'] = \App\Core\TenantContext::id();
        }
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
