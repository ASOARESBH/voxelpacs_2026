<?php

namespace App\Services;

use DomainException;
use PDO;

final class ImagiflowApuracaoService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function listar(int $tenantId, array $filters): array
    {
        $inicio = $this->date((string) ($filters['periodo_inicio'] ?? ''));
        $fim = $this->date((string) ($filters['periodo_fim'] ?? ''));
        if ($inicio > $fim || (strtotime($fim) - strtotime($inicio)) > 92 * 86400) {
            throw new DomainException('O período deve ter no máximo 93 dias.');
        }
        $page = max(1, (int) ($filters['pagina'] ?? 1));
        $perPage = max(1, min(500, (int) ($filters['por_pagina'] ?? 100)));
        $where = [
            'r.tenant_id = :tenant_id',
            "r.situacao::text IN ('assinado', 'liberado')",
            'r.assinado_em IS NOT NULL',
            'COALESCE(r.liberado_em, r.assinado_em) >= :inicio',
            'COALESCE(r.liberado_em, r.assinado_em) < (:fim::date + INTERVAL \'1 day\')',
        ];
        $params = [':tenant_id' => $tenantId, ':inicio' => $inicio . ' 00:00:00-03', ':fim' => $fim];

        $crm = preg_replace('/\D+/', '', (string) ($filters['medico_crm'] ?? '')) ?? '';
        if ($crm !== '') {
            $where[] = "REGEXP_REPLACE(COALESCE(m.crm, ''), '\\D', '', 'g') = :medico_crm";
            $params[':medico_crm'] = $crm;
        }
        $unidade = trim((string) ($filters['unidade'] ?? ''));
        if ($unidade !== '') {
            $where[] = 'e.institution_name = :unidade';
            $params[':unidade'] = $unidade;
        }

        $base = ' FROM reports r INNER JOIN bi_pacs_estudos e ON e.id = r.estudo_id AND e.tenant_id = r.tenant_id LEFT JOIN bi_medicos m ON m.usuario_id = e.usuario_responsavel_id AND m.tenant_id = e.tenant_id WHERE ' . implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*)' . $base);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sql = 'SELECT
                    r.id AS report_id,
                    e.id AS estudo_id,
                    e.institution_name AS unidade,
                    COALESCE(NULLIF(e.patient_name_display, \'\'), NULLIF(e.patient_name, \'\'), \'\') AS paciente_nome,
                    COALESCE(e.patient_id, \'\') AS paciente_id,
                    e.modalities AS modalidade,
                    COALESCE(NULLIF(e.study_description, \'\'), NULLIF(e.scheduled_procedure_step_desc, \'\'), NULLIF(e.requested_procedure_desc, \'\'), NULLIF(e.body_part_examined, \'\'), \'SEM DESCRIÇÃO\') AS study_description,
                    COALESCE(e.prioridade, \'ROUTINE\') AS prioridade,
                    e.accession_number,
                    e.study_instance_uid,
                    e.study_date,
                    e.study_time,
                    COALESCE(NULLIF(e.assumido_por, \'\'), m.nome, \'\') AS medico_nome,
                    COALESCE(m.crm, \'\') AS medico_crm,
                    COALESCE(m.crm_uf, \'\') AS medico_crm_uf,
                    r.assinado_em,
                    r.liberado_em,
                    r.situacao::text AS situacao,
                    CASE WHEN e.assumido_em IS NOT NULL THEN GREATEST(0, ROUND(EXTRACT(EPOCH FROM (COALESCE(r.liberado_em, r.assinado_em) - e.assumido_em)) / 60))::int ELSE NULL END AS sla_minutos
                ' . $base . '
                ORDER BY COALESCE(r.liberado_em, r.assinado_em), r.id
                LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) $stmt->bindValue($key, $value);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['source_reference'] = 'voxel:' . $tenantId . ':report:' . $row['report_id'];
            $row['origem'] = 'voxel_pacs';
            $row['data_estudo'] = trim((string) $row['study_date'] . ' ' . (string) $row['study_time']);
            $row['data_conclusao'] = $row['liberado_em'] ?: $row['assinado_em'];
            unset($row['report_id'], $row['study_date'], $row['study_time']);
        }
        unset($row);

        return [
            'periodo_inicio' => $inicio,
            'periodo_fim' => $fim,
            'pagina' => $page,
            'por_pagina' => $perPage,
            'total' => $total,
            'total_paginas' => (int) max(1, ceil($total / $perPage)),
            'itens' => $rows,
        ];
    }

    private function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new DomainException('Informe período_inicio e período_fim no formato YYYY-MM-DD.');
        }
        return $value;
    }
}
