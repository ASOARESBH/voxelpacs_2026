<?php
namespace App\Models;

use App\Core\Model;

class ReportSignature extends Model {
    protected string $table = 'report_signatures';
    protected bool $hasTenant = false;

    public function findByReportId(int $reportId): ?object {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE report_id = :report_id ORDER BY id DESC LIMIT 1");
        $stmt->execute(['report_id' => $reportId]);
        return $stmt->fetch() ?: null;
    }
}
