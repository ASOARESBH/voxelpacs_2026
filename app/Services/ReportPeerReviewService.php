<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Repositories\MedicoRepository;
use App\Repositories\ReportPeerReviewRepository;

class ReportPeerReviewService
{
    public const MIN_MOTIVO_CHARS = 20;

    private ReportPeerReviewRepository $repo;

    public function __construct()
    {
        $this->repo = new ReportPeerReviewRepository();
    }

    public function abrir(int $reportId, string $motivo): array
    {
        $motivo = trim($motivo);
        if ($this->length($motivo) < self::MIN_MOTIVO_CHARS) {
            return [
                'ok' => false,
                'error' => 'motivo_curto',
                'minimo' => self::MIN_MOTIVO_CHARS,
            ];
        }

        $tenantId = (int) (TenantContext::id() ?? 0);
        $userId = (int) Auth::userId();
        if ($tenantId <= 0 || $userId <= 0) {
            return ['ok' => false, 'error' => 'tenant_ou_usuario_invalido'];
        }

        // Peer Review é uma ação clínica: exige vínculo médico no tenant atual.
        $medico = (new MedicoRepository())->findByUsuarioId($userId, $tenantId);
        if (!$medico) {
            Logger::warning('[ReportPeerReviewService::abrir] usuário sem vínculo médico', [
                'report_id' => $reportId, 'tenant_id' => $tenantId, 'usuario_id' => $userId,
            ]);
            return ['ok' => false, 'error' => 'medico_nao_vinculado'];
        }

        $report = $this->repo->findReportContext($reportId);
        if (!$report) {
            return ['ok' => false, 'error' => 'report_nao_encontrado'];
        }

        $situacao = (string) ($report->situacao ?? $report->status ?? '');
        if (!in_array($situacao, ['assinado', 'liberado'], true)) {
            if ($situacao === 'peer_review') {
                return ['ok' => false, 'error' => 'peer_review_ja_aberto'];
            }
            return ['ok' => false, 'error' => 'situacao_nao_elegivel'];
        }

        if ($this->repo->findOpenByReportId($reportId)) {
            return ['ok' => false, 'error' => 'peer_review_ja_aberto'];
        }

        $secoes = $this->normalizarSecoes($report);
        $snapshotPayload = json_encode([
            'report_id' => $reportId,
            'tenant_id' => $tenantId,
            'situacao' => $situacao,
            'secoes' => $secoes,
            'assinatura_hash' => $report->assinatura_hash ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $snapshotHash = hash('sha256', (string) $snapshotPayload);

        try {
            $review = $this->repo->openWithSnapshot($report, $userId, $motivo, $secoes, $snapshotHash);
        } catch (\RuntimeException $e) {
            $known = ['report_nao_encontrado', 'peer_review_ja_aberto', 'situacao_nao_elegivel'];
            if (in_array($e->getMessage(), $known, true)) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
            Logger::error('[ReportPeerReviewService::abrir] falha atômica de negócio', [
                'report_id' => $reportId, 'tenant_id' => $tenantId, 'usuario_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'peer_review_persistencia_falhou'];
        } catch (\Throwable $e) {
            Logger::error('[ReportPeerReviewService::abrir] falha atômica', [
                'report_id' => $reportId, 'tenant_id' => $tenantId, 'usuario_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'peer_review_persistencia_falhou'];
        }

        AuditLogger::log('report.peer_review_aberto', 'reports', $reportId, [
            'peer_review_id' => (int) ($review->id ?? 0),
            'ciclo' => (int) ($review->ciclo ?? 0),
            'situacao_original' => $situacao,
            'motivo_chars' => $this->length($motivo),
            'motivo_hash' => hash('sha256', $motivo),
        ]);

        return [
            'ok' => true,
            'peer_review_id' => (int) ($review->id ?? 0),
            'ciclo' => (int) ($review->ciclo ?? 0),
            'situacao' => 'peer_review',
            'motivo' => $motivo,
        ];
    }

    public function contexto(int $reportId): array
    {
        $reviewAberta = $this->repo->findOpenByReportId($reportId);
        $reviews = $this->repo->listForReport($reportId);
        $original = $reviewAberta ? $this->repo->findOriginal((int) $reviewAberta->id) : null;

        return [
            'pendente' => $reviewAberta !== null,
            'aberta' => $reviewAberta,
            'original' => $original,
            'historico' => $reviews,
        ];
    }

    public function original(int $reviewId): ?object
    {
        return $this->repo->findOriginal($reviewId);
    }

    /**
     * Chamado dentro da transação de assinatura da revisão.
     * Fecha o ciclo apenas depois de a nova assinatura e versão serem gravadas.
     */
    public function concluirNaTransacao(int $reviewId, int $userId, string $situacaoFinal, ?int $versaoFinal): void
    {
        $this->repo->close($reviewId, $userId, $situacaoFinal, $versaoFinal);
        $this->repo->resetReportPeerReview((int) $this->repo->findById($reviewId)->report_id);

        AuditLogger::log('report.peer_review_concluido', 'pacs_report_peer_reviews', $reviewId, [
            'situacao_final' => $situacaoFinal,
            'versao_final' => $versaoFinal,
        ]);
    }

    public function extrairSecoes(object $report): array
    {
        return $this->normalizarSecoes($report);
    }

    private function normalizarSecoes(object $report): array
    {
        $json = [];
        if (isset($report->conteudo) && is_string($report->conteudo) && trim($report->conteudo) !== '') {
            $decoded = json_decode($report->conteudo, true);
            if (is_array($decoded)) {
                $json = is_array($decoded['secoes'] ?? null) ? $decoded['secoes'] : $decoded;
            }
        }

        $secoes = [];
        foreach (['exame', 'tecnica', 'achados', 'conclusao', 'recomendacao'] as $chave) {
            $campo = 'secao_' . $chave;
            $coluna = property_exists($report, $campo) ? ($report->{$campo} ?? null) : null;
            $secoes[$chave] = ($coluna !== null && $coluna !== '')
                ? (string) $coluna
                : (string) ($json[$chave] ?? '');
        }
        return $secoes;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
