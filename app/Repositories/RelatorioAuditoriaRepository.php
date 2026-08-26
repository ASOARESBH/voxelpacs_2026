<?php
declare(strict_types=1);

namespace App\Repositories;

final class RelatorioAuditoriaRepository
{
    public function __construct(private \PDO $pdo) {}

    public function opcoes(int $tenantId): array
    {
        $users = $this->pdo->prepare("SELECT DISTINCT u.id, u.name FROM bi_users u INNER JOIN bi_user_tenants ut ON ut.user_id = u.id WHERE ut.tenant_id = ? AND u.status = 'ativo' ORDER BY u.name");
        $users->execute([$tenantId]);
        $groups = $this->pdo->prepare('SELECT id, nome FROM bi_grupos WHERE tenant_id = ? AND ativo = 1 ORDER BY nome');
        $groups->execute([$tenantId]);
        return ['usuarios' => $users->fetchAll(\PDO::FETCH_ASSOC), 'grupos' => $groups->fetchAll(\PDO::FETCH_ASSOC)];
    }

    public function buscar(array $f): array
    {
        $where = ['a.tenant_id = :tenant_id', 'a.created_at >= :data_de', 'a.created_at < (:data_ate::date + INTERVAL \'1 day\')'];
        $params = ['tenant_id' => $f['tenant_id'], 'data_de' => $f['data_de'], 'data_ate' => $f['data_ate']];

        if ($f['usuario_id']) { $where[] = 'a.user_id = :usuario_id'; $params['usuario_id'] = $f['usuario_id']; }
        if ($f['grupo_id']) {
            $where[] = 'EXISTS (SELECT 1 FROM bi_grupo_usuarios gu WHERE gu.tenant_id = a.tenant_id AND gu.grupo_id = :grupo_id AND gu.usuario_id = a.user_id)';
            $params['grupo_id'] = $f['grupo_id'];
        }
        $where[] = match ($f['tipo']) {
            'acesso' => "a.category = 'acesso'",
            'estudos' => "a.category = 'gestao_estudos'",
            'clinica' => "a.category = 'clinica' OR a.action = 'estudo.assumir'",
            default => '1=0',
        };
        $sqlWhere = implode(' AND ', $where);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM bi_audit_logs a WHERE $sqlWhere");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $params['limit'] = $f['por_pagina'];
        $params['offset'] = ($f['pagina'] - 1) * $f['por_pagina'];
        $camposClinicos = '';
        $joinsClinicos = '';
        if ($f['tipo'] === 'clinica') {
            $camposClinicos = ", e.assumido_em,
                CASE WHEN e.assumido_em IS NULL THEN NULL
                     ELSE GREATEST(0, EXTRACT(EPOCH FROM (COALESCE(r.liberado_em, r.assinado_em, a.created_at) - e.assumido_em))::bigint)
                END AS duracao_seg,
                CASE WHEN r.peer_review_id IS NOT NULL OR EXISTS (
                    SELECT 1 FROM pacs_report_peer_reviews pr
                    WHERE pr.tenant_id = a.tenant_id
                      AND ((r.id IS NOT NULL AND pr.report_id = r.id) OR (e.id IS NOT NULL AND pr.estudo_id = e.id))
                ) THEN 1 ELSE 0 END AS possui_peer_review";
            $joinsClinicos = "
               LEFT JOIN reports r ON r.tenant_id = a.tenant_id
                    AND ((a.entity = 'reports' AND r.id = a.entity_id)
                      OR (a.entity = 'bi_pacs_estudos' AND r.estudo_id = a.entity_id)
                      OR (a.entity = 'pacs_report_peer_reviews' AND r.peer_review_id = a.entity_id))
               LEFT JOIN bi_pacs_estudos e ON e.tenant_id = a.tenant_id
                    AND e.id = COALESCE(r.estudo_id, CASE WHEN a.entity = 'bi_pacs_estudos' THEN a.entity_id ELSE NULL END)";
        }
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.user_id, a.created_at, a.action, a.entity, a.entity_id, a.ip, a.region_code, a.region_source, a.details,
                    u.name AS usuario_nome, u.email AS usuario_email$camposClinicos
               FROM bi_audit_logs a
               LEFT JOIN bi_users u ON u.id = a.user_id$joinsClinicos
              WHERE $sqlWhere
              ORDER BY a.created_at DESC, a.id DESC
              LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->execute();
        return ['linhas' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'total' => $total];
    }
}
