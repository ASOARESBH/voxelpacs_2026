<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Services\ReportPeerReviewService;

class ReportPeerReviewController extends Controller
{
    private ReportPeerReviewService $service;

    public function __construct()
    {
        $this->service = new ReportPeerReviewService();
    }

    /** GET /api/reports/peer-review/context?report_id=123 */
    public function context(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401);
            return;
        }

        $reportId = (int) ($_GET['report_id'] ?? 0);
        if ($reportId <= 0) {
            $this->json(['ok' => false, 'msg' => 'Laudo inválido.'], 422);
            return;
        }

        try {
            $this->json(['ok' => true] + $this->service->contexto($reportId));
        } catch (\Throwable $e) {
            Logger::error('ReportPeerReviewController::context error', [
                'report_id' => $reportId, 'error' => $e->getMessage(),
            ]);
            $this->json(['ok' => false, 'msg' => 'Não foi possível carregar o Peer Review.'], 422);
        }
    }

    /** POST /api/reports/peer-review/open */
    public function open(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401);
            return;
        }

        $input = $this->getJsonInput();
        $csrf = $input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$this->validarCsrf($csrf)) {
            $this->json(['ok' => false, 'msg' => 'Token inválido.'], 403);
            return;
        }

        $reportId = (int) ($input['report_id'] ?? 0);
        $motivo = (string) ($input['motivo'] ?? '');
        if ($reportId <= 0) {
            $this->json(['ok' => false, 'msg' => 'Laudo inválido.'], 422);
            return;
        }

        try {
            $result = $this->service->abrir($reportId, $motivo);
            $messages = [
                'motivo_curto' => 'Informe o motivo da revisão com pelo menos 20 caracteres.',
                'report_nao_encontrado' => 'Laudo não encontrado ou sem permissão no tenant atual.',
                'situacao_nao_elegivel' => 'O Peer Review só pode ser aberto para laudos assinados ou liberados.',
                'peer_review_ja_aberto' => 'Já existe uma revisão aberta para este laudo.',
                'medico_nao_vinculado' => 'Sua conta não está vinculada a um médico ativo neste tenant.',
                'peer_review_persistencia_falhou' => 'Não foi possível abrir o Peer Review. Verifique o log e tente novamente.',
                'tenant_ou_usuario_invalido' => 'Tenant ou usuário inválido para esta operação.',
            ];
            $this->json([
                'ok' => (bool) ($result['ok'] ?? false),
                'msg' => $result['ok'] ? 'Laudo liberado para Peer Review.' : ($messages[$result['error'] ?? ''] ?? 'Não foi possível abrir o Peer Review.'),
                'error' => $result['error'] ?? null,
                'situacao' => $result['situacao'] ?? null,
                'peer_review_id' => $result['peer_review_id'] ?? null,
                'ciclo' => $result['ciclo'] ?? null,
            ], $result['ok'] ? 200 : 422);
        } catch (\Throwable $e) {
            Logger::error('ReportPeerReviewController::open error', [
                'report_id' => $reportId, 'usuario_id' => Auth::userId(), 'error' => $e->getMessage(),
            ]);
            $this->json(['ok' => false, 'msg' => 'Erro interno ao abrir o Peer Review.'], 422);
        }
    }

    /** GET /api/reports/peer-review/original?review_id=123 */
    public function original(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401);
            return;
        }

        $reviewId = (int) ($_GET['review_id'] ?? 0);
        if ($reviewId <= 0) {
            $this->json(['ok' => false, 'msg' => 'Revisão inválida.'], 422);
            return;
        }

        try {
            $original = $this->service->original($reviewId);
            if (!$original) {
                $this->json(['ok' => false, 'msg' => 'Snapshot original não encontrado.'], 404);
                return;
            }
            $this->json(['ok' => true, 'original' => $original]);
        } catch (\Throwable $e) {
            Logger::error('ReportPeerReviewController::original error', [
                'review_id' => $reviewId, 'error' => $e->getMessage(),
            ]);
            $this->json(['ok' => false, 'msg' => 'Não foi possível carregar o snapshot original.'], 422);
        }
    }
}
