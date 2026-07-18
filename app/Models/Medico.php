<?php
namespace App\Models;

use App\Core\Model;
use App\Core\TenantContext;

class Medico extends Model {
    protected string $table = 'bi_medicos';
    protected bool $hasTenant = true;

    public function findAll(): array {
        $sql = "SELECT m.*, COUNT(e.id) as total_exames, u.name AS usuario_nome
                FROM {$this->table} m
                LEFT JOIN bi_exames e ON e.medico_id = m.id AND e.tenant_id = m.tenant_id
                LEFT JOIN bi_users u ON u.id = m.usuario_id
                WHERE m.ativo = 1" . $this->tenantWhere('m') . "
                GROUP BY m.id ORDER BY m.nome";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->tenantParam());
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?object {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id" . $this->tenantWhere();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(['id' => $id], $this->tenantParam()));
        return $stmt->fetch() ?: null;
    }

    /** Usuários ativos do tenant elegíveis para vincular a um médico (fonte do select do form). */
    public function findUsuariosVinculaveis(): array {
        $sql = "SELECT u.id, u.name
                FROM bi_users u
                INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = :tenant_id
                WHERE u.status = 'ativo'
                ORDER BY u.name";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['tenant_id' => TenantContext::id()]);
        return $stmt->fetchAll();
    }

    /** Unidades (institution_name) vinculadas a um médico. */
    public function getUnidadesVinculadas(int $medicoId): array {
        $stmt = $this->pdo->prepare("SELECT institution_name FROM bi_medico_unidades WHERE medico_id = :id");
        $stmt->execute(['id' => $medicoId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** Substitui o conjunto de unidades vinculadas a um médico (delete + insert simples). */
    public function sincronizarUnidades(int $medicoId, int $tenantId, array $institutionNames): void {
        $this->pdo->prepare("DELETE FROM bi_medico_unidades WHERE medico_id = :id")->execute(['id' => $medicoId]);
        if (!$institutionNames) return;
        $ins = $this->pdo->prepare(
            "INSERT IGNORE INTO bi_medico_unidades (tenant_id, medico_id, institution_name) VALUES (:tenant_id, :medico_id, :institution_name)"
        );
        foreach ($institutionNames as $nome) {
            $nome = trim((string) $nome);
            if ($nome === '') continue;
            $ins->execute(['tenant_id' => $tenantId, 'medico_id' => $medicoId, 'institution_name' => $nome]);
        }
    }
}
