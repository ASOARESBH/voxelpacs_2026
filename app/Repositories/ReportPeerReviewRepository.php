<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use PDO;
use PDOException;

/**
 * Persistência do ciclo de Peer Review.
 * Nenhum método aceita report/estudo fora do tenant ativo.
 */
class ReportPeerReviewRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function findReportContext(int $reportId): ?object
    {
        $sql = "SELECT r.*, e.situacao AS estudo_situacao
                FROM reports r
                INNER JOIN bi_pacs_estudos e ON e.id = r.estudo_id
                WHERE r.id = :report_id";
        $params = ['report_id' => $reportId];
        $tenantId = TenantContext::id();
        if ($tenantId) {
            $sql .= " AND r.tenant_id = :tenant_id AND e.tenant_id = :tenant_id_e";
            $params['tenant_id'] = $tenantId;
            $params['tenant_id_e'] = $tenantId;
        }
        $sql .= " LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    public function findOpenByReportId(int $reportId): ?object
    {
        $sql = "SELECT * FROM pacs_report_peer_reviews
                WHERE report_id = :report_id AND status = 'aberta'";
        $params = ['report_id' => $reportId];
        $tenantId = TenantContext::id();
        if ($tenantId) {
            $sql .= " AND tenant_id = :tenant_id";
            $params['tenant_id'] = $tenantId;
        }
        $sql .= " ORDER BY id DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    public function findById(int $reviewId): ?object
    {
        $sql = "SELECT * FROM pacs_report_peer_reviews WHERE id = :id";
        $params = ['id' => $reviewId];
        $tenantId = TenantContext::id();
        if ($tenantId) {
            $sql .= " AND tenant_id = :tenant_id";
            $params['tenant_id'] = $tenantId;
        }
        $sql .= " LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    public function nextCycle(int $reportId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(MAX(ciclo), 0) + 1 FROM pacs_report_peer_reviews WHERE report_id = :report_id"
        );
        $stmt->execute(['report_id' => $reportId]);
        return max(1, (int) $stmt->fetchColumn());
    }

    /**
     * Abre o ciclo e grava o snapshot no mesmo commit.
     * O caller deve ter validado o motivo e o estado permitido.
     */
    public function openWithSnapshot(object $report, int $userId, string $motivo, array $secoes, string $hash): object
    {
        $tenantId = (int) ($report->tenant_id ?? TenantContext::id());
        $reportId = (int) $report->id;
        $agora = date('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            // Serializa a abertura no registro do report. O teste anterior no
            // Service evita chamadas comuns duplicadas; este lock cobre corrida.
            $lockSql = "SELECT * FROM reports WHERE id = :report_id";
            $lockParams = ['report_id' => $reportId];
            if ($tenantId) {
                $lockSql .= " AND tenant_id = :tenant_id";
                $lockParams['tenant_id'] = $tenantId;
            }
            $lockSql .= " FOR UPDATE";
            $lockStmt = $this->pdo->prepare($lockSql);
            $lockStmt->execute($lockParams);
            $lockedReport = $lockStmt->fetch();
            if (!$lockedReport) {
                throw new \RuntimeException('report_nao_encontrado');
            }

            $situacaoOriginal = (string) ($lockedReport->situacao ?? $lockedReport->status ?? '');
            if (!in_array($situacaoOriginal, ['assinado', 'liberado'], true)) {
                if ($situacaoOriginal === 'peer_review') {
                    throw new \RuntimeException('peer_review_ja_aberto');
                }
                throw new \RuntimeException('situacao_nao_elegivel');
            }

            $openSql = "SELECT id FROM pacs_report_peer_reviews
                        WHERE report_id = :report_id AND status = 'aberta'";
            $openParams = ['report_id' => $reportId];
            if ($tenantId) {
                $openSql .= " AND tenant_id = :tenant_id";
                $openParams['tenant_id'] = $tenantId;
            }
            $openSql .= " LIMIT 1 FOR UPDATE";
            $openStmt = $this->pdo->prepare($openSql);
            $openStmt->execute($openParams);
            if ($openStmt->fetchColumn()) {
                throw new \RuntimeException('peer_review_ja_aberto');
            }

            $report = $lockedReport;
            $estudoId = (int) ($report->estudo_id ?? $report->bi_pacs_estudos_id ?? 0);
            $ciclo = $this->nextCycle($reportId);
            $versaoOriginal = isset($report->versao_atual) ? (int) $report->versao_atual : null;

            $stmt = $this->pdo->prepare(
                "INSERT INTO pacs_report_peer_reviews
                    (tenant_id, report_id, estudo_id, ciclo, status, motivo,
                     situacao_original, aberto_por, aberto_em)
                 VALUES
                    (:tenant_id, :report_id, :estudo_id, :ciclo, 'aberta', :motivo,
                     :situacao_original, :aberto_por, :aberto_em)"
            );
            $stmt->execute([
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'estudo_id' => $estudoId,
                'ciclo' => $ciclo,
                'motivo' => $motivo,
                'situacao_original' => $situacaoOriginal,
                'aberto_por' => $userId,
                'aberto_em' => $agora,
            ]);
            $reviewId = (int) $this->pdo->lastInsertId();

            $originalPayload = [
                'report_id' => $reportId,
                'estudo_id' => $estudoId,
                'tenant_id' => $tenantId,
                'ciclo' => $ciclo,
                'situacao_original' => $situacaoOriginal,
                'secoes' => $secoes,
                'assinatura_hash' => $report->assinatura_hash ?? null,
                'assinatura_crm' => $report->assinatura_crm ?? null,
                'assinado_em' => $report->assinado_em ?? null,
                'liberado_em' => $report->liberado_em ?? null,
            ];
            $snapshotHash = hash('sha256', json_encode($originalPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $stmt = $this->pdo->prepare(
                "INSERT INTO pacs_report_peer_review_originais
                    (peer_review_id, tenant_id, report_id, estudo_id, ciclo,
                     situacao_original, versao_original, secao_exame, secao_tecnica,
                     secao_achados, secao_conclusao, secao_recomendacao,
                     assinatura_hash, assinatura_crm, assinado_em, liberado_em,
                     snapshot_hash, snapshot_por, snapshot_em)
                 VALUES
                    (:peer_review_id, :tenant_id, :report_id, :estudo_id, :ciclo,
                     :situacao_original, :versao_original, :secao_exame, :secao_tecnica,
                     :secao_achados, :secao_conclusao, :secao_recomendacao,
                     :assinatura_hash, :assinatura_crm, :assinado_em, :liberado_em,
                     :snapshot_hash, :snapshot_por, :snapshot_em)"
            );
            $stmt->execute([
                'peer_review_id' => $reviewId,
                'tenant_id' => $tenantId,
                'report_id' => $reportId,
                'estudo_id' => $estudoId,
                'ciclo' => $ciclo,
                'situacao_original' => $situacaoOriginal,
                'versao_original' => $versaoOriginal,
                'secao_exame' => $secoes['exame'] ?? '',
                'secao_tecnica' => $secoes['tecnica'] ?? '',
                'secao_achados' => $secoes['achados'] ?? '',
                'secao_conclusao' => $secoes['conclusao'] ?? '',
                'secao_recomendacao' => $secoes['recomendacao'] ?? '',
                'assinatura_hash' => $report->assinatura_hash ?? null,
                'assinatura_crm' => $report->assinatura_crm ?? null,
                'assinado_em' => $report->assinado_em ?? null,
                'liberado_em' => $report->liberado_em ?? null,
                'snapshot_hash' => $snapshotHash,
                'snapshot_por' => $userId,
                'snapshot_em' => $agora,
            ]);

            // O report vivo só aponta para o ciclo; o texto original fica no snapshot.
            $stmt = $this->pdo->prepare(
                "UPDATE reports
                 SET situacao = 'peer_review', peer_review_id = :peer_review_id,
                     peer_review_ciclo = :ciclo, peer_review_motivo = :motivo,
                     peer_review_aberto_em = :aberto_em, peer_review_aberto_por = :aberto_por
                 WHERE id = :report_id"
            );
            $stmt->execute([
                'peer_review_id' => $reviewId,
                'ciclo' => $ciclo,
                'motivo' => $motivo,
                'aberto_em' => $agora,
                'aberto_por' => $userId,
                'report_id' => $reportId,
            ]);

            $stmt = $this->pdo->prepare(
                "UPDATE bi_pacs_estudos
                 SET situacao = 'peer_review'
                 WHERE id = :estudo_id AND tenant_id = :tenant_id"
            );
            $stmt->execute(['estudo_id' => $estudoId, 'tenant_id' => $tenantId]);

            $this->pdo->commit();
            return $this->findById($reviewId) ?: (object) [
                'id' => $reviewId, 'ciclo' => $ciclo, 'status' => 'aberta',
                'motivo' => $motivo, 'situacao_original' => $situacaoOriginal,
            ];
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function findOriginal(int $reviewId): ?object
    {
        $sql = "SELECT o.*, r.status AS review_status, r.motivo
                FROM pacs_report_peer_review_originais o
                INNER JOIN pacs_report_peer_reviews r ON r.id = o.peer_review_id
                WHERE o.peer_review_id = :review_id";
        $params = ['review_id' => $reviewId];
        $tenantId = TenantContext::id();
        if ($tenantId) {
            $sql .= " AND o.tenant_id = :tenant_id AND r.tenant_id = :tenant_id_r";
            $params['tenant_id'] = $tenantId;
            $params['tenant_id_r'] = $tenantId;
        }
        $sql .= " LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    public function listForReport(int $reportId): array
    {
        $sql = "SELECT r.*, u.name AS aberto_por_nome, c.name AS concluido_por_nome
                FROM pacs_report_peer_reviews r
                LEFT JOIN bi_users u ON u.id = r.aberto_por
                LEFT JOIN bi_users c ON c.id = r.concluido_por
                WHERE r.report_id = :report_id";
        $params = ['report_id' => $reportId];
        $tenantId = TenantContext::id();
        if ($tenantId) {
            $sql .= " AND r.tenant_id = :tenant_id";
            $params['tenant_id'] = $tenantId;
        }
        $sql .= " ORDER BY r.ciclo DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function close(int $reviewId, int $userId, string $situacaoFinal, ?int $versaoFinal): void
    {
        $sql = "UPDATE pacs_report_peer_reviews
                SET status = 'concluida', concluido_por = :user_id,
                    concluido_em = NOW(), situacao_final = :situacao_final,
                    versao_final = :versao_final
                WHERE id = :id AND status = 'aberta'";
        $params = [
            'user_id' => $userId,
            'situacao_final' => $situacaoFinal,
            'versao_final' => $versaoFinal,
            'id' => $reviewId,
        ];
        $tenantId = TenantContext::id();
        if ($tenantId) {
            $sql .= " AND tenant_id = :tenant_id";
            $params['tenant_id'] = $tenantId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() < 1) {
            throw new \RuntimeException('peer_review_ciclo_nao_aberto');
        }
    }

    public function resetReportPeerReview(int $reportId): void
    {
        $sql = "UPDATE reports
                SET peer_review_id = NULL, peer_review_motivo = NULL,
                    peer_review_aberto_em = NULL, peer_review_aberto_por = NULL
                WHERE id = :report_id";
        $params = ['report_id' => $reportId];
        $tenantId = TenantContext::id();
        if ($tenantId) {
            $sql .= " AND tenant_id = :tenant_id";
            $params['tenant_id'] = $tenantId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}
