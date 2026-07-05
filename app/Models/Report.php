<?php
namespace App\Models;

use App\Core\Model;

class Report extends Model {
    protected string $table = 'reports';
    protected bool $hasTenant = true;

    public function findById(int $id): ?object {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id" . $this->tenantWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(['id' => $id], $this->tenantParam()));
        return $stmt->fetch() ?: null;
    }

    public function findByEstudoId(int $estudoId): ?object {
        $sql = "SELECT * FROM {$this->table} WHERE bi_pacs_estudos_id = :estudo_id" . $this->tenantWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(['estudo_id' => $estudoId], $this->tenantParam()));
        return $stmt->fetch() ?: null;
    }

    public function findByStudyUid(string $studyUid): ?object {
        $sql = "SELECT * FROM {$this->table} WHERE study_instance_uid = :uid" . $this->tenantWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(['uid' => $studyUid], $this->tenantParam()));
        return $stmt->fetch() ?: null;
    }
}
