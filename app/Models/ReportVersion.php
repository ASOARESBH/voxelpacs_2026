<?php
namespace App\Models;

use App\Core\Model;

class ReportVersion extends Model {
    protected string $table = 'report_versions';
    protected bool $hasTenant = false;

    public function findById(int $id): ?object {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findByReportId(int $reportId): array {
        $stmt = $this->pdo->prepare("
            SELECT rv.*, u.name AS user_nome
            FROM {$this->table} rv
            LEFT JOIN bi_users u ON u.id = rv.user_id
            WHERE rv.report_id = :report_id
            ORDER BY rv.versao_numero DESC
        ");
        $stmt->execute(['report_id' => $reportId]);
        return $stmt->fetchAll();
    }
}
