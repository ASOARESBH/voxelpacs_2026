<?php
/**
 * RelatorioEstudosRepository — camada de leitura dedicada ao módulo Relatórios.
 *
 * Isolada de propósito: NÃO reaproveita EstudosController (documentado como
 * alto risco/fragilidade — PDO 100% inline num Controller) nem EstudosRepository
 * (usado por ReportsController, outro consumidor paralelo). Toda query daqui é
 * nova, própria deste módulo, e sempre exige tenant_id explícito — nunca lê
 * bi_pacs_estudos sem escopo de tenant.
 *
 * @see SKILL-VOXEL-PACS/modules/relatorios.md
 */
namespace App\Repositories;

use PDO;
use App\Helpers\DicomPersonName;

class RelatorioEstudosRepository
{
    /** Teto de linhas quando buscarEstudos() é chamado sem paginação (SLA/exportação). */
    private const MAX_LINHAS_SEM_PAGINACAO = 5000;

    public function __construct(private PDO $pdo) {}

    // ─────────────────────────────────────────────────────────────────────
    // Opções de filtro (escopadas por tenant)
    // ─────────────────────────────────────────────────────────────────────

    /** Modalidades reais distintas, escopadas por tenant e (opcionalmente) por unidades já filtradas. */
    public function getModalidadesDisponiveis(int $tenantId, array $institutionNames = []): array
    {
        $where  = ['tenant_id = :tenant_id', "modalities IS NOT NULL", "modalities != ''"];
        $params = [':tenant_id' => $tenantId];

        if (!empty($institutionNames)) {
            [$inSql, $inParams] = $this->buildInClause('inst', $institutionNames);
            $where[] = "institution_name IN ({$inSql})";
            $params  = array_merge($params, $inParams);
        }

        $sql  = "SELECT DISTINCT modalities FROM bi_pacs_estudos WHERE " . implode(' AND ', $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $set = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
            foreach (explode('\\', (string) $raw) as $mod) {
                $mod = trim($mod);
                if ($mod !== '') $set[$mod] = true;
            }
        }
        $modalidades = array_keys($set);
        sort($modalidades);
        return $modalidades;
    }

    /** Médicos ativos do tenant (cadastro oficial bi_medicos — mesma fonte que a worklist usa hoje). */
    public function getMedicosAtivos(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, nome, usuario_id FROM bi_medicos WHERE tenant_id = :tenant_id AND ativo = 1 ORDER BY nome"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Solicitantes distintos já cadastrados nos estudos deste tenant.
     * Mesma fonte/coluna (`especialidade`) e mesma limitação já documentada em
     * modules/worklist-estudos.md (o campo raramente é preenchido) — não é bug
     * novo desta tarefa, é o mesmo comportamento da worklist reaproveitado aqui.
     */
    public function getSolicitantesDistintos(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT especialidade FROM bi_pacs_estudos
             WHERE tenant_id = :tenant_id AND especialidade IS NOT NULL AND especialidade != ''
             ORDER BY especialidade"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Regras de SLA ativas do tenant — usadas pelo RelatorioSlaCalcService para resolver o "SLA alvo". */
    public function getRegrasSlaAtivas(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, metrica, operador, limite_minutos, filtro_institution_name, filtro_modalidade, prioridade
             FROM bi_sla_regras
             WHERE tenant_id = :tenant_id AND ativo = 1
             ORDER BY prioridade ASC"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Consulta principal — usada pelos dois relatórios (Exames lê tudo;
    // SLA Médicos lê os mesmos campos + o LEFT JOIN de reports.assinado_em)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array $filtros ver contrato documentado em RelatorioFiltrosService::parse()
     * @return array{linhas: array, total: int}
     */
    public function buscarEstudos(array $filtros, bool $paginar = true): array
    {
        [$whereSql, $params] = $this->buildWhere($filtros);

        $sqlBase = "
            FROM bi_pacs_estudos e
            LEFT JOIN reports r ON r.estudo_id = e.id AND r.tenant_id = e.tenant_id
            WHERE {$whereSql}
        ";

        $total = 0;
        if ($paginar) {
            $stmtCount = $this->pdo->prepare("SELECT COUNT(*) {$sqlBase}");
            $stmtCount->execute($params);
            $total = (int) $stmtCount->fetchColumn();
        }

        $orderSql = " ORDER BY e.study_date DESC, e.study_time DESC ";
        if ($paginar) {
            $porPagina  = max(1, (int) ($filtros['por_pagina'] ?? 25));
            $pagina     = max(1, (int) ($filtros['pagina'] ?? 1));
            $offset     = ($pagina - 1) * $porPagina;
            $limitSql   = " LIMIT {$porPagina} OFFSET {$offset} ";
        } else {
            // Sem paginação (usado pelo cálculo de SLA e pelas exportações,
            // que precisam do conjunto completo antes de ordenar/agregar) —
            // ainda assim um teto de segurança contra tenants muito grandes.
            $limitSql = ' LIMIT ' . self::MAX_LINHAS_SEM_PAGINACAO;
        }

        $sql = "
            SELECT
                e.id, e.patient_name, e.patient_name_display, e.tags_raw, e.study_date, e.study_time,
                e.institution_name, e.modalities,
                COALESCE(e.prioridade, 'normal')   AS prioridade,
                COALESCE(e.situacao,   'novo')     AS situacao,
                COALESCE(e.assumido_por, '')       AS assumido_por,
                e.usuario_responsavel_id,
                COALESCE(e.especialidade, '')      AS especialidade,
                COALESCE(e.referring_physician_name, '') AS referring_physician_name,
                COALESCE(e.recebido_em, e.importado_em)  AS recebido_em,
                e.assumido_em,
                e.study_instance_uid,
                r.situacao   AS reports_situacao,
                r.assinado_em
            {$sqlBase}
            {$orderSql}
            {$limitSql}
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($linhas as &$linha) {
            $linha['patient_name'] = DicomPersonName::displayFromStudy($linha) ?: '';
        }
        unset($linha);

        return ['linhas' => $linhas, 'total' => $paginar ? $total : count($linhas)];
    }

    // ─────────────────────────────────────────────────────────────────────
    // WHERE compartilhado — sempre com tenant_id e, quando houver unidades
    // autorizadas resolvidas via InstitutionResolverService, institution_name
    // restrito a essa lista (deny-by-default, nunca aceita unidade arbitrária).
    // ─────────────────────────────────────────────────────────────────────
    private function buildWhere(array $filtros): array
    {
        $where  = ['e.tenant_id = :tenant_id'];
        $params = [':tenant_id' => (int) $filtros['tenant_id']];

        // Unidades autorizadas do tenant (sempre aplicado, mesmo sem filtro
        // explícito de unidade — é o deny-by-default de InstitutionResolverService)
        if (!empty($filtros['institution_names_autorizadas'])) {
            [$inSql, $inParams] = $this->buildInClause('auth_inst', $filtros['institution_names_autorizadas']);
            $where[] = "e.institution_name IN ({$inSql})";
            $params  = array_merge($params, $inParams);
        }

        if (!empty($filtros['unidade'])) {
            $where[] = 'e.institution_name = :unidade';
            $params[':unidade'] = $filtros['unidade'];
        }

        // Coluna de data usada pelo filtro de período — whitelist fixa (nunca
        // aceita nome de coluna vindo do request). Exames sempre filtra por
        // study_date; SLA Médicos resolve via "Relatório por" (ver Controller).
        $colunaData = match ($filtros['relatorio_por'] ?? 'estudo') {
            'conclusao' => 'r.assinado_em',
            'registro'  => 'e.recebido_em',
            default     => 'e.study_date',
        };
        if (!empty($filtros['data_de'])) {
            $where[] = "{$colunaData} >= :data_de";
            $params[':data_de'] = $filtros['data_de'];
        }
        if (!empty($filtros['data_ate'])) {
            $where[] = "{$colunaData} <= :data_ate" . ($colunaData === 'e.study_date' ? '' : ' + INTERVAL 1 DAY');
            $params[':data_ate'] = $filtros['data_ate'];
        }

        if (!empty($filtros['modalidades'])) {
            $ors = [];
            foreach (array_values($filtros['modalidades']) as $i => $mod) {
                $key = ":mod{$i}";
                $ors[] = "e.modalities LIKE {$key}";
                $params[$key] = '%' . $mod . '%';
            }
            $where[] = '(' . implode(' OR ', $ors) . ')';
        }

        if (!empty($filtros['prioridades'])) {
            [$inSql, $inParams] = $this->buildInClause('prio', $filtros['prioridades']);
            $where[] = "COALESCE(e.prioridade, 'normal') IN ({$inSql})";
            $params  = array_merge($params, $inParams);
        }

        if (!empty($filtros['situacoes'])) {
            [$inSql, $inParams] = $this->buildInClause('sit', $filtros['situacoes']);
            $where[] = "COALESCE(e.situacao, 'novo') IN ({$inSql})";
            $params  = array_merge($params, $inParams);
        }

        if (($filtros['medico_ou_solicitante'] ?? '') === 'medico' && !empty($filtros['pessoa'])) {
            $where[] = 'e.assumido_por LIKE :pessoa';
            $params[':pessoa'] = '%' . $filtros['pessoa'] . '%';
        } elseif (($filtros['medico_ou_solicitante'] ?? '') === 'solicitante' && !empty($filtros['pessoa'])) {
            $where[] = 'e.especialidade LIKE :pessoa';
            $params[':pessoa'] = '%' . $filtros['pessoa'] . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    private function buildInClause(string $prefix, array $values): array
    {
        $params = [];
        $keys   = [];
        foreach (array_values($values) as $i => $v) {
            $k = ":{$prefix}{$i}";
            $keys[]   = $k;
            $params[$k] = $v;
        }
        return [implode(',', $keys), $params];
    }
}
