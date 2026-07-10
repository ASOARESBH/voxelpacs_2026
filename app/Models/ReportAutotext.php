<?php
namespace App\Models;

use App\Core\Model;
use App\Core\TenantContext;

class ReportAutotext extends Model {
    protected string $table = 'report_autotext';
    protected bool $hasTenant = true;

    public function findAtivos(?string $modalidade = null): array {
        $sql = "SELECT * FROM {$this->table}
                WHERE ativo = 1
                  AND (modalidade IS NULL" . ($modalidade ? " OR modalidade = :modalidade" : "") . ")
                  AND (tenant_id IS NULL" . (TenantContext::isSet() ? " OR tenant_id = :tenant_id" : "") . ")
                ORDER BY gatilho ASC";
        $stmt = $this->pdo->prepare($sql);
        $params = [];
        if ($modalidade) $params['modalidade'] = $modalidade;
        if (TenantContext::isSet()) $params['tenant_id'] = TenantContext::id();
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
