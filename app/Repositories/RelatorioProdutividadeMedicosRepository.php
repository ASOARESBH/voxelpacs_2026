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

    /** Modalidades disponíveis nos chips da Worklist, inclusive para consultas futuras. */
    private const MODALIDADES_WORKLIST = [
        'CR', 'CT', 'CTG', 'DO', 'DR', 'DX', 'ECG', 'ES', 'MG',
        'MR', 'NM', 'OF', 'OT', 'PT', 'RF', 'US', 'XA',
    ];

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

    /** Prioridade operacional efetiva: override manual auditável, tag DICOM ou legado normalizado. */
    private function prioridadeEfetivaSql(string $alias = 'e'): string
    {
        return "COALESCE(
            NULLIF(BTRIM({$alias}.dicom_priority_override), ''),
            CASE UPPER(BTRIM(COALESCE({$alias}.dicom_priority, '')))
                WHEN 'STAT' THEN 'STAT'
                WHEN 'HIGH' THEN 'HIGH'
                WHEN 'ROUTINE' THEN 'ROUTINE'
                WHEN 'MEDIUM' THEN 'MEDIUM'
                WHEN 'LOW' THEN 'LOW'
                ELSE NULL
            END,
            CASE LOWER(BTRIM(COALESCE({$alias}.prioridade, '')))
                WHEN 'rotina' THEN 'ROUTINE'
                WHEN 'normal' THEN 'ROUTINE'
                WHEN 'urgente' THEN 'HIGH'
                WHEN 'urgência' THEN 'HIGH'
                WHEN 'critico' THEN 'STAT'
                WHEN 'crítico' THEN 'STAT'
                WHEN 'emergencia' THEN 'STAT'
                WHEN 'emergência' THEN 'STAT'
                WHEN 'ambulatorial' THEN 'LOW'
                ELSE NULL
            END,
            'ROUTINE'
        )";
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
        $prioridadeSql = $this->prioridadeEfetivaSql('e');
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT {$prioridadeSql}
               FROM bi_pacs_estudos e
              WHERE tenant_id = :tenant_id
              ORDER BY 1"
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
        // Mantém o conjunto completo da Worklist, ainda que uma modalidade não tenha
        // laudos concluídos no período. Códigos adicionais presentes no tenant entram ao final.
        $catalogo = array_fill_keys(self::MODALIDADES_WORKLIST, true);
        $descobertas = [];
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT modalities
               FROM bi_pacs_estudos
              WHERE tenant_id = :tenant_id
                AND modalities IS NOT NULL
                AND BTRIM(modalities) <> \'\''
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $modalities) {
            foreach (explode('\\', (string) $modalities) as $modality) {
                $modality = strtoupper(trim($modality));
                if ($modality !== '' && !isset($catalogo[$modality])) {
                    $descobertas[$modality] = true;
                }
            }
        }
        $extras = array_keys($descobertas);
        sort($extras);
        return array_merge(self::MODALIDADES_WORKLIST, $extras);
    }

    /**
     * @param array{tenant_id:int,data_de:string,data_ate:string,base_periodo:string,unidade:string,modalidades:array<int,string>,estudo:string,medico_id:?int,pagina:int,por_pagina:int,medico_restrito_id:?int} $filtros
     * @return array{linhas:array<int,array<string,mixed>>,total:int,totalizadores:array<string,int|null>,porMedico:array<int,array<string,mixed>>,resumoLiberados:array{modalidades:array<string,int>,prioridades:array<string,int>}}
     */
    public function buscar(array $filtros): array
    {
        [$where, $params] = $this->where($filtros);
        $prioridadeSql = $this->prioridadeEfetivaSql('e');

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
                ' . $prioridadeSql . ' AS prioridade,
                (NULLIF(BTRIM(e.dicom_priority_override), \'\') IS NOT NULL) AS prioridade_manual,
                COALESCE(NULLIF(BTRIM(e.dicom_priority), \'\'), NULLIF(BTRIM(e.prioridade), \'\'), \'ROUTINE\') AS prioridade_origem,
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
        $resumoLiberados = $this->resumirLiberados($all);

        $total = count($all);
        $porPagina = max(1, min(100, (int) $filtros['por_pagina']));
        $pagina = max(1, (int) $filtros['pagina']);
        $linhas = array_slice($all, ($pagina - 1) * $porPagina, $porPagina);

        return compact('linhas', 'total', 'totalizadores', 'porMedico', 'resumoLiberados');
    }

    /** @param array<string,mixed> $filtros @return array{0:array<int,string>,1:array<string,mixed>} */
    private function where(array $filtros): array
    {
        $prioridadeSql = $this->prioridadeEfetivaSql('e');
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
            $where[] = "{$prioridadeSql} = :prioridade";
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

    /**
     * @param array<int,array<string,mixed>> $linhas
     * @return array{modalidades:array<string,int>,prioridades:array<string,int>}
     */
    private function resumirLiberados(array $linhas): array
    {
        $modalidades = [];
        $prioridades = [];
        $ordemPrioridade = array_flip(array_keys(self::PRIORIDADES_DICOM));

        foreach ($linhas as $linha) {
            if (($linha['situacao_laudo'] ?? '') !== 'liberado') {
                continue;
            }

            $modalidadesDaLinha = [];
            foreach (explode('\\', (string) ($linha['modalities'] ?? '')) as $modalidade) {
                $modalidade = strtoupper(trim($modalidade));
                if ($modalidade !== '') {
                    $modalidadesDaLinha[$modalidade] = true;
                }
            }
            foreach (array_keys($modalidadesDaLinha) as $modalidade) {
                $modalidades[$modalidade] = ($modalidades[$modalidade] ?? 0) + 1;
            }

            $prioridade = strtoupper(trim((string) ($linha['prioridade'] ?? 'ROUTINE')));
            $prioridade = array_key_exists($prioridade, self::PRIORIDADES_DICOM) ? $prioridade : 'ROUTINE';
            $prioridades[$prioridade] = ($prioridades[$prioridade] ?? 0) + 1;
        }

        ksort($modalidades, SORT_NATURAL);
        uksort($prioridades, static fn(string $a, string $b): int => ($ordemPrioridade[$a] ?? PHP_INT_MAX) <=> ($ordemPrioridade[$b] ?? PHP_INT_MAX));

        return ['modalidades' => $modalidades, 'prioridades' => $prioridades];
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
