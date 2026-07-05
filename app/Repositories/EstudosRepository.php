<?php
namespace App\Repositories;

use App\Core\Database;

class EstudosRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function buildWhereClause(array $filtros, ?int $tenantId, bool $isAdmin): array
    {
        $where  = ['e.servidor_id = 1'];
        $params = [];

        if (!$isAdmin && $tenantId) {
            $where[]              = 'e.tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        } elseif (!$isAdmin && !$tenantId) {
            $where[] = '1=0';
        }

        if ($filtros['q'] !== '') {
            $like = '%' . $filtros['q'] . '%';
            $where[] = '(e.patient_name LIKE :q1 OR e.patient_id LIKE :q2
                      OR e.study_instance_uid LIKE :q3 OR e.accession_number LIKE :q4
                      OR e.study_description LIKE :q5 OR e.institution_name LIKE :q6)';
            $params[':q1'] = $like; $params[':q2'] = $like;
            $params[':q3'] = $like; $params[':q4'] = $like;
            $params[':q5'] = $like; $params[':q6'] = $like;
        }

        if ($filtros['paciente'] !== '') {
            $where[]        = 'e.patient_name LIKE :pac';
            $params[':pac'] = '%' . $filtros['paciente'] . '%';
        }

        if ($filtros['dt_inicio'] !== '') {
            $where[]              = 'e.study_date >= :dt_inicio';
            $params[':dt_inicio'] = $filtros['dt_inicio'];
        }

        if ($filtros['dt_fim'] !== '') {
            $where[]            = 'e.study_date <= :dt_fim';
            $params[':dt_fim']  = $filtros['dt_fim'];
        }

        if ($filtros['unidade'] !== '') {
            $where[]            = 'e.institution_name LIKE :unidade';
            $params[':unidade'] = '%' . $filtros['unidade'] . '%';
        }

        if ($filtros['modalidade'] !== '') {
            if ($filtros['modalidade'] === 'TODOS') {
                // não filtra modalidade
            } else {
                $where[]               = 'e.modalities LIKE :modalidade';
                $params[':modalidade'] = '%' . $filtros['modalidade'] . '%';
            }
        }

        if ($filtros['especialidade'] !== '') {
            $where[]        = 'e.especialidade LIKE :esp';
            $params[':esp'] = '%' . $filtros['especialidade'] . '%';
        }

        if ($filtros['situacao'] !== '' && $filtros['situacao'] !== 'Todos') {
            $where[]             = "COALESCE(e.situacao,'novo') = :situacao";
            $params[':situacao'] = $filtros['situacao'];
        }

        if ($filtros['prioridade'] !== '' && $filtros['prioridade'] !== 'Todos') {
            $where[]               = 'COALESCE(e.prioridade, \'normal\') = :prioridade';
            $params[':prioridade'] = $filtros['prioridade'];
        }

        if ($filtros['medico'] !== '') {
            $where[]           = 'e.assumido_por LIKE :medico';
            $params[':medico'] = '%' . $filtros['medico'] . '%';
        }

        return ['where' => implode(' AND ', $where), 'params' => $params];
    }

    public function countEstudos(string $whereStr, array $params): int
    {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos e WHERE {$whereStr}");
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $ex) {
            error_log('[EstudosRepository::countEstudos] ' . $ex->getMessage());
            return 0;
        }
    }

    public function getEstudos(string $whereStr, array $params, string $orderCol, string $orderDir, int $limit, int $offset): array
    {
        $limitClause = $limit > 0 ? "LIMIT {$limit} OFFSET {$offset}" : '';
        
        $sql = "
            SELECT
                e.id,
                e.orthanc_id,
                e.study_date,
                e.study_time,
                e.patient_id,
                e.patient_name,
                e.patient_sex,
                e.patient_age,
                e.patient_birth_date,
                e.institution_name,
                e.modalities,
                e.study_description,
                e.accession_number,
                e.referring_physician_name,
                e.performing_physician_name,
                e.num_series,
                e.num_instances,
                e.study_instance_uid,
                e.tenant_id,
                e.manufacturer,
                COALESCE(e.situacao,     'novo')   AS situacao,
                COALESCE(e.especialidade,'')       AS especialidade,
                COALESCE(e.prioridade,   'normal') AS prioridade,
                COALESCE(e.assumido_por, '')       AS assumido_por,
                e.assumido_em,
                e.laudo_assinado_em,
                e.urgente_em,
                e.importado_em,
                e.atualizado_em
            FROM bi_pacs_estudos e
            WHERE {$whereStr}
            ORDER BY {$orderCol} {$orderDir}, e.study_time {$orderDir}
            {$limitClause}
        ";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $ex) {
            error_log('[EstudosRepository::getEstudos] ' . $ex->getMessage());
            return [];
        }
    }

    public function getUnidades(?int $tenantId, bool $isAdmin): array
    {
        try {
            $uW = ['servidor_id = 1', "institution_name IS NOT NULL", "institution_name != ''"];
            if (!$isAdmin && $tenantId) $uW[] = 'tenant_id = ' . (int)$tenantId;
            return $this->pdo->query(
                "SELECT DISTINCT institution_name FROM bi_pacs_estudos WHERE " . implode(' AND ', $uW) . " ORDER BY institution_name"
            )->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $ex) { return []; }
    }

    public function getMedicos(?int $tenantId, bool $isAdmin): array
    {
        try {
            $mW = ['servidor_id = 1', "assumido_por IS NOT NULL", "assumido_por != ''"];
            if (!$isAdmin && $tenantId) $mW[] = 'tenant_id = ' . (int)$tenantId;
            return $this->pdo->query(
                "SELECT DISTINCT assumido_por FROM bi_pacs_estudos WHERE " . implode(' AND ', $mW) . " ORDER BY assumido_por"
            )->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $ex) { return []; }
    }

    public function getEspecialidades(?int $tenantId, bool $isAdmin): array
    {
        try {
            $eW = ['servidor_id = 1', "especialidade IS NOT NULL", "especialidade != ''"];
            if (!$isAdmin && $tenantId) $eW[] = 'tenant_id = ' . (int)$tenantId;
            return $this->pdo->query(
                "SELECT DISTINCT especialidade FROM bi_pacs_estudos WHERE " . implode(' AND ', $eW) . " ORDER BY especialidade"
            )->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $ex) { return []; }
    }

    public function getContadores(?int $tenantId, bool $isAdmin): array
    {
        $contadores = ['novo'=>0,'aberto'=>0,'em_laudo'=>0,'rascunho'=>0,'assinado'=>0,'liberado'=>0,'urgente'=>0];
        try {
            $cW = ['servidor_id = 1'];
            $cP = [];
            if (!$isAdmin && $tenantId) { $cW[] = 'tenant_id = :tid'; $cP[':tid'] = $tenantId; }
            $cBase = implode(' AND ', $cW);
            
            $cStmt = $this->pdo->prepare("SELECT COALESCE(situacao,'novo') AS situacao, COUNT(*) AS total FROM bi_pacs_estudos WHERE {$cBase} GROUP BY situacao");
            $cStmt->execute($cP);
            foreach ($cStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                if (isset($contadores[$r['situacao']])) $contadores[$r['situacao']] = (int)$r['total'];
            }
            
            $uStmt = $this->pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE {$cBase} AND prioridade IN ('urgente','critico')");
            $uStmt->execute($cP);
            $contadores['urgente'] = (int)$uStmt->fetchColumn();
        } catch (\Throwable $ex) {
            error_log('[EstudosRepository::getContadores] ' . $ex->getMessage());
        }
        return $contadores;
    }

    public function getResumoPainel(?int $tenantId, bool $isAdmin, string $today, int $urgentes): array
    {
        $resumo = ['hoje'=>0,'semana'=>0,'mes'=>0,'urgentes'=>$urgentes,'total'=>0];
        try {
            $rW = ['servidor_id = 1'];
            $rP = [];
            if (!$isAdmin && $tenantId) { $rW[] = 'tenant_id = :tid3'; $rP[':tid3'] = $tenantId; }
            $rBase = implode(' AND ', $rW);
            
            $s = $this->pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE {$rBase} AND study_date = :d");
            $s->execute(array_merge($rP, [':d' => $today]));
            $resumo['hoje'] = (int)$s->fetchColumn();
            
            $s = $this->pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE {$rBase} AND study_date >= :d");
            $s->execute(array_merge($rP, [':d' => date('Y-m-d', strtotime('-6 days'))]));
            $resumo['semana'] = (int)$s->fetchColumn();
            
            $s = $this->pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE {$rBase} AND study_date >= :d");
            $s->execute(array_merge($rP, [':d' => date('Y-m-d', strtotime('-29 days'))]));
            $resumo['mes'] = (int)$s->fetchColumn();
            
            $s = $this->pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE {$rBase}");
            $s->execute($rP);
            $resumo['total'] = (int)$s->fetchColumn();
        } catch (\Throwable $ex) {
            error_log('[EstudosRepository::getResumoPainel] ' . $ex->getMessage());
        }
        return $resumo;
    }

    public function getUltimaSincronizacao(): string
    {
        try {
            $s = $this->pdo->query("SELECT MAX(importado_em) FROM bi_pacs_estudos WHERE servidor_id = 1");
            return $s->fetchColumn() ?: '';
        } catch (\Throwable $ex) {
            return '';
        }
    }

    public function getEstudoById(int $id, ?int $tenantId, bool $isAdmin): ?array
    {
        try {
            $where  = 'id = :id AND servidor_id = 1';
            $params = [':id' => $id];
            if (!$isAdmin && $tenantId) {
                $where           .= ' AND tenant_id = :tid';
                $params[':tid']   = $tenantId;
            }
            $stmt = $this->pdo->prepare(
                "SELECT id, orthanc_id, study_instance_uid, patient_name, tenant_id
                 FROM bi_pacs_estudos WHERE {$where} LIMIT 1"
            );
            $stmt->execute($params);
            $estudo = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $estudo ?: null;
        } catch (\Throwable $ex) {
            error_log('[EstudosRepository::getEstudoById] ' . $ex->getMessage());
            return null;
        }
    }
}
