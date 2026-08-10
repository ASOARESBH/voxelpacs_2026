<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Services\ReportChatService;

class ReportChatController extends Controller
{
    private ReportChatService $service;

    public function __construct()
    {
        $this->service = new ReportChatService();
    }

    public function context(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401);
            return;
        }
        $tenantId = $this->tenantId();
        $reportId = (int) ($_GET['report_id'] ?? 0);
        if ($reportId <= 0) {
            $this->json(['ok' => false, 'msg' => 'report_id obrigatório.'], 422);
            return;
        }

        try {
            $context = $this->service->context($reportId, $tenantId, (int) Auth::userId());
            if (!$context) {
                $this->json(['ok' => false, 'msg' => 'Laudo não encontrado.'], 404);
                return;
            }
            $this->json(['ok' => true, 'chat' => $context]);
        } catch (\Throwable $e) {
            Logger::error('[ReportChatController::context] ' . $e->getMessage(), [
                'report_id' => $reportId, 'tenant_id' => $tenantId,
            ]);
            $this->json(['ok' => false, 'msg' => 'Não foi possível carregar o CHAT.'], 500);
        }
    }

    public function send(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401);
            return;
        }
        $input = $this->getJsonInput();
        if (!$this->validarCsrf($input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            $this->json(['ok' => false, 'msg' => 'Token inválido.'], 403);
            return;
        }
        $tenantId = $this->tenantId();
        $reportId = (int) ($input['report_id'] ?? 0);
        if ($reportId <= 0) {
            $this->json(['ok' => false, 'msg' => 'report_id obrigatório.'], 422);
            return;
        }

        try {
            $result = $this->service->send($reportId, $tenantId, (int) Auth::userId(), $input);
            if (!$result['ok']) {
                $this->json(['ok' => false, 'msg' => $this->message($result['error'] ?? '')], 422);
                return;
            }
            $this->json($result);
        } catch (\Throwable $e) {
            Logger::error('[ReportChatController::send] ' . $e->getMessage(), [
                'report_id' => $reportId, 'tenant_id' => $tenantId, 'user_id' => Auth::userId(),
            ]);
            $this->json(['ok' => false, 'msg' => 'Não foi possível registrar a interação.'], 500);
        }
    }

    public function complete(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'msg' => 'Não autenticado.'], 401);
            return;
        }
        $input = $this->getJsonInput();
        if (!$this->validarCsrf($input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            $this->json(['ok' => false, 'msg' => 'Token inválido.'], 403);
            return;
        }
        $tenantId = $this->tenantId();
        $reportId = (int) ($input['report_id'] ?? 0);
        if ($reportId <= 0) {
            $this->json(['ok' => false, 'msg' => 'report_id obrigatório.'], 422);
            return;
        }

        try {
            $result = $this->service->complete($reportId, $tenantId, (int) Auth::userId());
            if (!$result['ok']) {
                $this->json(['ok' => false, 'msg' => $this->message($result['error'] ?? '')], 422);
                return;
            }
            $this->json($result);
        } catch (\Throwable $e) {
            Logger::error('[ReportChatController::complete] ' . $e->getMessage(), [
                'report_id' => $reportId, 'tenant_id' => $tenantId, 'user_id' => Auth::userId(),
            ]);
            $this->json(['ok' => false, 'msg' => 'Não foi possível concluir o CHAT.'], 500);
        }
    }

    private function tenantId(): int
    {
        $tenantId = TenantContext::id();
        if (!$tenantId) {
            $this->json(['ok' => false, 'msg' => 'Tenant não identificado.'], 403);
            exit;
        }
        return (int) $tenantId;
    }

    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        return is_array($data) ? $data : [];
    }

    private function validarCsrf(string $token): bool
    {
        return $token !== '' && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token);
    }

    private function message(string $error): string
    {
        return match ($error) {
            'mensagem_obrigatoria' => 'Digite a interação antes de enviar.',
            'mensagem_muito_longa' => 'A interação deve ter no máximo 5.000 caracteres.',
            'destinatario_invalido' => 'O destinatário não pertence ao tenant atual ou está inativo.',
            'destinatario_autor' => 'Escolha outro usuário para receber a interação.',
            'estudo_finalizado' => 'Este estudo já foi finalizado e não aceita novas pendências.',
            'chat_sem_pendencia' => 'Não há pendência aberta para concluir.',
            'persistencia_falhou' => 'Não foi possível salvar a interação. Verifique a migration do CHAT.',
            'report_nao_encontrado' => 'Laudo não encontrado.',
            default => 'Não foi possível concluir a operação do CHAT.',
        };
    }
}
