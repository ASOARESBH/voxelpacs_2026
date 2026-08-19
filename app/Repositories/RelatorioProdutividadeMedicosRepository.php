<?php
namespace App\Repositories;

use PDO;

/**
 * Consulta de leitura para produtividade médica.
 *
 * O escopo clínico é deliberadamente ancorado em reports assinados ou
 * liberados, pois estes são os laudos efetivamente realizados pelo médico.
 */
final class RelatorioProdutividadeMedicosRepository
{
    private const MAX_LINHAS = 5000;

    /** @var array<string,string> */
    private const PRIORIDADES_DICOM = [
        'STAT' => 'Emergência (STAT)',
        'HIGH' => 'Urgência (HIGH)',
        'ROUTINE' => 'Rotina (ROUTINE)',
        'MEDIUM' => 'Rotina (MEDIUM)',
        'LOW' => 'Ambulatorial (LOW)',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int,array{id:int,nome:string}> */
    public function medicos(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nome
               FROM bi_medicos
              WHERE tenant_id = :tenant_id
                AND ativo = 1
              ORDER BY nome'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,string> */
    public function unidades(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT institution_name
               FROM bi_pacs_estudos
              WHERE tenant_id = :tenant_id
                AND institution_name IS NOT NULL
                AND BTRIM(institution_name) <> \'\'
              ORDER BY institution_name'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<string,string> value => rótulo */
    public function prioridades(int $tenantId): array
    {
        // O catálogo DICOM permanece visível mesmo quando um tenant ainda não recebeu
        // estudos de determinada prioridade. Valores legados desconhecidos são preservados.
        $opcoes = self::PRIORIDADES_DICOM;
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT UPPER(BTRIM(prioridade))
               FROM bi_pacs_estudos
              WHERE tenant_id = :tenant_id
                AND prioridade IS NOT NULL
                AND BTRIM(prioridade) <> \'\'
              ORDER BY 1'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $prioridade) {
            $prioridade = (string) $prioridade;
            $normalizada = match ($prioridade) {
                'ROTINA', 'NORMAL' => 'ROUTINE',
                'URGENTE', 'URGÊNCIA' => 'HIGH',
                'CRITICO', 'CRÍTICO', 'EMERGENCIA', 'EMERGÊNCIA' => 'STAT',
                'AMBULATORIAL' => 'LOW',
                default => $prioridade,
            };
            if (!isset($opcoes[$normalizada])) {
                $opcoes[$normalizada] = ucfirst(mb_strtolower($prioridade, 'UTF-8'));
            }
        }
        return $opcoes;
    }

    public static function prioridadeLabel(string $prioridade): string
    {
        $prioridade = strtoupper(trim($prioridade));
        return self::PRIORIDADES_DICOM[$prioridade] ?? ucfirst(mb_strtolower($prioridade, 'UTF-8'));
    }

    /** @return array<int,string> */
    public function modalidades(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT modalities
               FROM bi_pacs_estudos
              WHERE tenant_id = :tenant_id
                AND modalities IS NOT NULL
                AND BTRIM(modalities) <> \'\''
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        $set = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $modalities) {
            foreach (explode('\\', (string) $modalities) as $modality) {
                $modality = trim($modality);
                if ($modality !== '') {
                    $set[$modality] = true;
                }
            }
        }
        $items = array_keys($set);
        sort($items);
        return $items;
    }

    /**
     * @param array{tenant_id:int,data_de:string,data_ate:string,base_periodo:string,unidade:string,modalidades:array<int,string>,estudo:string,medico_id:?int,pagina:int,por_pagina:int,medico_restrito_id:?int} $filtros
     * @return array{linhas:array<int,array<string,mixed>>,total:int,totalizadores:array<string,int|null>,por_medico:array<int,array<string,mixed>>}
     */
    public function buscar(array $filtros): array
    {
        [$where, $params] = $this->where($filtros);

        $peerReviews = '
            SELECT estudo_id,
                   COUNT(*) AS peer_reviews,
                   COUNT(*) FILTER (WHERE status = \'concluida\') AS peer_reviews_concluidos
              FROM pacs_report_peer_reviews
             WHERE tenant_id = :peer_tenant_id
             GROUP BY estudo_id
        ';
        $params[':peer_tenant_id'] = $filtros['tenant_id'];

        $sql = '
            SELECT
                e.id AS estudo_id,
                e.study_date,
                e.study_time,
                COALESCE(NULLIF(e.patient_name_display, \'\'), NULLIF(e.patient_name, \'\'), \'—\') AS paciente,
                COALESCE(e.patient_id, \'—\') AS patient_id,
                e.institution_name AS unidade,
                e.modalities,
                COALESCE(
                    CASE LOWER(COALESCE(e.prioridade, \'\'))
                        WHEN \'rotina\' THEN \'ROUTINE\'
                        WHEN \'normal\' THEN \'ROUTINE\'
                        WHEN \'urgente\' THEN \'HIGH\'
                        WHEN \'urgência\' THEN \'HIGH\'
                        WHEN \'critico\' THEN \'STAT\'
                        WHEN \'crítico\' THEN \'STAT\'
                        WHEN \'emergencia\' THEN \'STAT\'
                        WHEN \'emergência\' THEN \'STAT\'
                        WHEN \'ambulatorial\' THEN \'LOW\'
                        ELSE UPPER(NULLIF(e.prioridade, \'\'))
                    END,
                    \'ROUTINE\'
                ) AS prioridade,
                COALESCE(
                    NULLIF(e.study_description, \'\'),
                    NULLIF(e.scheduled_procedure_step_desc, \'\'),
                    NULLIF(e.requested_procedure_desc, \'\'),
                    NULLIF(e.body_part_examined, \'\'),
                    \'SEM DESCRIÇÃO\'
                ) AS descricao_estudo,
                COALESCE(NULLIF(e.assumido_por, \'\'), m.nome, \'Não informado\') AS medico_nome,
                e.usuario_responsavel_id AS medico_usuario_id,
                e.assumido_em,
                r.assinado_em,
                r.liberado_em,
                r.situacao::text AS situacao_laudo,
                COALESCE(pr.peer_reviews, 0)::int AS peer_reviews,
                COALESCE(pr.peer_reviews_concluidos, 0)::int AS peer_reviews_concluidos,
                CASE
                    WHEN e.assumido_em IS NOT NULL AND r.assinado_em IS NOT NULL
                    THEN GREATEST(0, ROUND(EXTRACT(EPOCH FROM (r.assinado_em - e.assumido_em)) / 60))::int
                    ELSE NULL
                END AS tempo_assinatura_min,
                CASE
                    WHEN e.assumido_em IS NOT NULL AND COALESCE(r.liberado_em, r.assinado_em) IS NOT NULL
                    THEN GREATEST(0, ROUND(EXTRACT(EPOCH FROM (COALESCE(r.liberado_em, r.assinado_em) - e.assumido_em)) / 60))::int
                    ELSE NULL
                END AS tempo_conclusao_min
            FROM reports r
            INNER JOIN bi_pacs_estudos e
                ON e.id = r.estudo_id
               AND e.tenant_id = r.tenant_id
            LEFT JOIN bi_medicos m
                ON m.usuario_id = e.usuario_responsavel_id
               AND m.tenant_id = e.tenant_id
            LEFT JOIN (' . $peerReviews . ') pr ON pr.estudo_id = e.id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY COALESCE(r.liberado_em, r.assinado_em) DESC, e.id DESC
            LIMIT ' . self::MAX_LINHAS;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalizadores = $this->totalizar($all);
        $porMedico = $this->agruparPorMedico($all);

        $total = count($all);
        $porPagina = max(1, min(100, (int) $filtros['por_pagina']));
        $pagina = max(1, (int) $filtros['pagina']);
        $linhas = array_slice($all, ($pagina - 1) * $porPagina, $porPagina);

        return compact('linhas', 'total', 'totalizadores', 'porMedico');
    }

    /** @param array<string,mixed> $filtros @return array{0:array<int,string>,1:array<string,mixed>} */
    private function where(array $filtros): array
    {
        $where = [
            'r.tenant_id = :tenant_id',
            "r.situacao::text IN ('assinado', 'liberado')",
            'r.assinado_em IS NOT NULL',
        ];
        $params = [
            ':tenant_id' => $filtros['tenant_id'],
            ':data_de' => $filtros['data_de'] . ' 00:00:00-03',
            ':data_ate' => $filtros['data_ate'] . ' 23:59:59.999999-03',
        ];

        $periodoColuna = match ($filtros['base_periodo']) {
            'estudo' => 'e.study_date',
            'liberacao' => 'COALESCE(r.liberado_em, r.assinado_em)',
            default => 'r.assinado_em',
        };
        $where[] = $periodoColuna . ' >= :data_de';
        $where[] = $periodoColuna . ' <= :data_ate';

        if ($filtros['unidade'] !== '') {
            $where[] = 'e.institution_name = :unidade';
            $params[':unidade'] = $filtros['unidade'];
        }

        if ($filtros['prioridade'] !== '') {
            $where[] = "COALESCE(
                CASE LOWER(COALESCE(e.prioridade, ''))
                    WHEN 'rotina' THEN 'ROUTINE'
                    WHEN 'normal' THEN 'ROUTINE'
                    WHEN 'urgente' THEN 'HIGH'
                    WHEN 'urgência' THEN 'HIGH'
                    WHEN 'critico' THEN 'STAT'
                    WHEN 'crítico' THEN 'STAT'
                    WHEN 'emergencia' THEN 'STAT'
                    WHEN 'emergência' THEN 'STAT'
                    WHEN 'ambulatorial' THEN 'LOW'
                    ELSE UPPER(NULLIF(e.prioridade, ''))
                END,
                'ROUTINE'
            ) = :prioridade";
            $params[':prioridade'] = $filtros['prioridade'];
        }

        if (!empty($filtros['modalidades'])) {
            $or = [];
            foreach (array_values($filtros['modalidades']) as $index => $modality) {
                $key = ':modalidade_' . $index;
                $or[] = 'e.modalities LIKE ' . $key;
                $params[$key] = '%' . $modality . '%';
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }

        if ($filtros['estudo'] !== '') {
            $where[] = '(COALESCE(e.study_description, \'\') ILIKE :estudo
                OR COALESCE(e.scheduled_procedure_step_desc, \'\') ILIKE :estudo
                OR COALESCE(e.requested_procedure_desc, \'\') ILIKE :estudo
                OR COALESCE(e.accession_number, \'\') ILIKE :estudo
                OR COALESCE(e.study_instance_uid, \'\') ILIKE :estudo)';
            $params[':estudo'] = '%' . $filtros['estudo'] . '%';
        }

        $medicoId = $filtros['medico_restrito_id'] ?? $filtros['medico_id'];
        if ($medicoId !== null && $medicoId > 0) {
            $where[] = 'm.id = :medico_id';
            $params[':medico_id'] = $medicoId;
        }

        return [$where, $params];
    }

    /** @param array<int,array<string,mixed>> $linhas @return array<string,int|null> */
    private function totalizar(array $linhas): array
    {
        $conclusoes = array_values(array_filter(array_column($linhas, 'tempo_conclusao_min'), static fn($v) => $v !== null));
        return [
            'laudos' => count($linhas),
            'assinados' => count(array_filter($linhas, static fn(array $linha): bool => $linha['situacao_laudo'] === 'assinado')),
            'liberados' => count(array_filter($linhas, static fn(array $linha): bool => $linha['situacao_laudo'] === 'liberado')),
            'peer_reviews' => count(array_filter($linhas, static fn(array $linha): bool => (int) $linha['peer_reviews'] > 0)),
            'sla_medio_min' => $conclusoes ? (int) round(array_sum($conclusoes) / count($conclusoes)) : null,
            'sla_total_min' => $conclusoes ? (int) array_sum($conclusoes) : null,
        ];
    }

    /** @param array<int,array<string,mixed>> $linhas @return array<int,array<string,mixed>> */
    private function agruparPorMedico(array $linhas): array
    {
        $groups = [];
        foreach ($linhas as $linha) {
            $key = (string) ($linha['medico_usuario_id'] ?: $linha['medico_nome']);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'medico' => $linha['medico_nome'],
                    'laudos' => 0,
                    'assinados' => 0,
                    'liberados' => 0,
                    'peer_reviews' => 0,
                    'sla_total_min' => 0,
                    'sla_medio_min' => null,
                    '_tempos' => [],
                ];
            }
            $groups[$key]['laudos']++;
            $groups[$key]['assinados'] += $linha['situacao_laudo'] === 'assinado' ? 1 : 0;
            $groups[$key]['liberados'] += $linha['situacao_laudo'] === 'liberado' ? 1 : 0;
            $groups[$key]['peer_reviews'] += (int) $linha['peer_reviews'] > 0 ? 1 : 0;
            if ($linha['tempo_conclusao_min'] !== null) {
                $groups[$key]['sla_total_min'] += (int) $linha['tempo_conclusao_min'];
                $groups[$key]['_tempos'][] = (int) $linha['tempo_conclusao_min'];
            }
        }
        foreach ($groups as &$group) {
            $group['sla_medio_min'] = $group['_tempos']
                ? (int) round(array_sum($group['_tempos']) / count($group['_tempos']))
                : null;
            unset($group['_tempos']);
        }
        unset($group);

        $groups = array_values($groups);
        usort($groups, static fn(array $a, array $b): int => $b['laudos'] <=> $a['laudos']);
        return $groups;
    }
}
