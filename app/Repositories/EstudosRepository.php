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
                e.recebido_em,
                e.usuario_responsavel_id,
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
            // Unidades cadastradas na tabela de unidades DICOM do tenant
            $fromCadastro = [];
            try {
                $uCad = $this->pdo->prepare("
                    SELECT DISTINCT institution_name
                    FROM bi_tenant_unidades_dicom
                    WHERE status = 'ativo'
                      AND institution_name IS NOT NULL
                      AND institution_name != ''
                      " . (!$isAdmin && $tenantId ? 'AND tenant_id = ' . (int)$tenantId : '') . "
                    ORDER BY institution_name
                ");
                $uCad->execute();
                $fromCadastro = $uCad->fetchAll(\PDO::FETCH_COLUMN);
            } catch (\Throwable $ex2) { /* tabela pode nao existir ainda */ }

            // Unidades que já aparecem nos estudos PACS
            $uW = ['servidor_id = 1', "institution_name IS NOT NULL", "institution_name != ''"];
            if (!$isAdmin && $tenantId) $uW[] = 'tenant_id = ' . (int)$tenantId;
            $fromEstudos = $this->pdo->query(
                "SELECT DISTINCT institution_name FROM bi_pacs_estudos WHERE " . implode(' AND ', $uW) . " ORDER BY institution_name"
            )->fetchAll(\PDO::FETCH_COLUMN);

            // Mescla e ordena, sem duplicatas
            $merged = array_values(array_unique(array_merge($fromCadastro, $fromEstudos)));
            sort($merged);
            return $merged;
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

    // ──────────────────────────────────────────────────────────────────────────
    // Métodos adicionados pelo módulo Reports (2026-07-05)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Busca um estudo pelo StudyInstanceUID
     */
    public function findByStudyUid(string $studyUid, ?int $tenantId, bool $isAdmin): ?array
    {
        try {
            $where  = 'e.study_instance_uid = :uid AND e.servidor_id = 1';
            $params = [':uid' => $studyUid];
            if (!$isAdmin && $tenantId) {
                $where           .= ' AND e.tenant_id = :tid';
                $params[':tid']   = $tenantId;
            }
            $stmt = $this->pdo->prepare(
                "SELECT e.id, e.orthanc_id, e.study_instance_uid, e.tenant_id,
                        e.patient_name, e.patient_name_display, e.patient_id,
                        e.patient_birth_date, e.patient_sex, e.patient_age,
                        e.patient_weight, e.patient_size,
                        e.study_date, e.study_time, e.study_description,
                        e.accession_number, e.modalities, e.institution_name,
                        e.referring_physician_name, e.num_instances, e.num_series,
                        e.manufacturer, e.station_name,
                        COALESCE(e.situacao,'novo') AS situacao,
                        COALESCE(e.prioridade,'normal') AS prioridade,
                        COALESCE(e.especialidade,'') AS especialidade,
                        COALESCE(e.assumido_por,'') AS assumido_por,
                        e.assumido_em
                 FROM bi_pacs_estudos e
                 WHERE {$where}
                 LIMIT 1"
            );
            $stmt->execute($params);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $ex) {
            \App\Core\Logger::error('Erro ao buscar estudo por StudyUID', [
                'study_uid' => $studyUid,
                'error'     => $ex->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Atualiza o status/situação de um estudo na worklist
     */
    public function atualizarStatus(int $estudoId, string $situacao, ?int $usuarioId = null): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE bi_pacs_estudos SET
                    situacao     = :situacao,
                    assumido_por = CASE WHEN :situacao2 = 'em_laudo' THEN :usuario_id ELSE assumido_por END,
                    assumido_em  = CASE WHEN :situacao3 = 'em_laudo' THEN NOW() ELSE assumido_em END
                 WHERE id = :id"
            );
            return $stmt->execute([
                ':situacao'   => $situacao,
                ':situacao2'  => $situacao,
                ':situacao3'  => $situacao,
                ':usuario_id' => $usuarioId,
                ':id'         => $estudoId
            ]);
        } catch (\Throwable $ex) {
            \App\Core\Logger::error('Erro ao atualizar status do estudo', [
                'estudo_id' => $estudoId,
                'situacao'  => $situacao,
                'error'     => $ex->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Registra o médico que assumiu o estudo (botão Assumir na worklist)
     */
    public function assumirEstudo(int $estudoId, int $usuarioId): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE bi_pacs_estudos SET
                    situacao               = 'em_laudo',
                    assumido_por           = :usuario_id,
                    assumido_em            = NOW(),
                    usuario_responsavel_id = :usuario_id2
                 WHERE id = :id AND COALESCE(situacao,'novo') IN ('novo','aberto')"
            );
            return $stmt->execute([
                ':usuario_id'  => $usuarioId,
                ':usuario_id2' => $usuarioId,
                ':id'          => $estudoId
            ]);
        } catch (\Throwable $ex) {
            \App\Core\Logger::error('Erro ao assumir estudo', [
                'estudo_id'  => $estudoId,
                'usuario_id' => $usuarioId,
                'error'      => $ex->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Estudos candidatos a uma regra de Regras de SLA: dentro do filtro de
     * unidade/modalidade da regra, ainda não finalizados, e que já ultrapassam
     * (ou ficam abaixo, conforme operador) o limiar da métrica configurada.
     * $regra precisa ter: metrica ('sla_medico'|'sla_estudo'), operador
     * ('maior'|'menor'), limite_minutos, filtro_institution_name, filtro_modalidade.
     * $excluirIds evita reprocessar estudo já remanejado no mesmo ciclo do robô.
     */
    public function buscarCandidatosSla(int $tenantId, object $regra, array $excluirIds = []): array
    {
        // metrica/operador nunca vêm de input livre do usuário final na query —
        // resolvidos aqui por whitelist fixa, nunca concatenados diretamente.
        $campoOrigem = $regra->metrica === 'sla_medico' ? 'assumido_em' : 'recebido_em';
        $operadorSql = $regra->operador === 'menor' ? '<' : '>';

        $where  = [
            'tenant_id = :tenant_id',
            "situacao NOT IN ('assinado','liberado')",
            "`{$campoOrigem}` IS NOT NULL",
            "TIMESTAMPDIFF(MINUTE, `{$campoOrigem}`, NOW()) {$operadorSql} :limite",
        ];
        $params = [
            'tenant_id' => $tenantId,
            'limite'    => $regra->limite_minutos,
        ];

        if (!empty($excluirIds)) {
            $where[] = 'id NOT IN (' . implode(',', array_map('intval', $excluirIds)) . ')';
        }
        if (!empty($regra->filtro_institution_name)) {
            $where[] = 'institution_name = :inst';
            $params['inst'] = $regra->filtro_institution_name;
        }
        if (!empty($regra->filtro_modalidade)) {
            $where[] = 'modalities LIKE :modalidade';
            $params['modalidade'] = '%' . $regra->filtro_modalidade . '%';
        }

        try {
            $sql = "SELECT id, assumido_por, situacao, institution_name, modalities,
                           TIMESTAMPDIFF(MINUTE, `{$campoOrigem}`, NOW()) AS minutos_decorridos
                    FROM bi_pacs_estudos
                    WHERE " . implode(' AND ', $where);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $ex) {
            \App\Core\Logger::error('Erro ao buscar candidatos de Regras de SLA', [
                'tenant_id' => $tenantId,
                'error'     => $ex->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Reatribuição feita pelo robô de Regras de SLA. Mesma semântica de colunas
     * de assumirEstudo(), mas sem a restrição situacao IN ('novo','aberto') —
     * o robô também precisa poder trocar um estudo que já está 'em_laudo'
     * (é justamente o caso de estouro de SLA Médico). Bloqueia apenas estudos
     * já finalizados.
     */
    public function reatribuirPorRobo(int $estudoId, int $novoUsuarioId, int $tenantId): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE bi_pacs_estudos SET
                    situacao               = 'em_laudo',
                    assumido_por           = :usuario_id,
                    assumido_em            = NOW(),
                    usuario_responsavel_id = :usuario_id2
                 WHERE id = :id AND tenant_id = :tenant_id
                   AND situacao NOT IN ('assinado','liberado')"
            );
            return $stmt->execute([
                ':usuario_id'  => $novoUsuarioId,
                ':usuario_id2' => $novoUsuarioId,
                ':id'          => $estudoId,
                ':tenant_id'   => $tenantId,
            ]);
        } catch (\Throwable $ex) {
            \App\Core\Logger::error('Erro ao reatribuir estudo pelo robo de Regras de SLA', [
                'estudo_id'  => $estudoId,
                'usuario_id' => $novoUsuarioId,
                'error'      => $ex->getMessage(),
            ]);
            return false;
        }
    }
}
