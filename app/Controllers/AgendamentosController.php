<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\TenantContext;
use App\Services\AgendamentoService;

/**
 * MVP de Agendamentos: UI tenant-scoped sem ativar conexão ou consulta DICOM.
 */
final class AgendamentosController extends Controller
{
    public function index(): void
    {
        $tenantId = $this->tenantId();
        $service = new AgendamentoService();
        $this->view('agendamentos/index', [
            'title' => t('agendamentos.titulo'),
            'agendamentos' => $service->listar($tenantId),
            'unidades' => $service->listarUnidades($tenantId),
            'modalidades' => $service->listarModalidades($tenantId),
            'csrf_token' => $this->csrfToken(),
            'success' => $this->flash('success'),
            'error' => $this->flash('error'),
        ], 'pacs');
    }

    public function store(): void
    {
        $this->assertCsrf();
        try {
            (new AgendamentoService())->criar($this->tenantId(), (int) Auth::userId(), $_POST);
            $_SESSION['agendamentos.success'] = t('agendamentos.sucesso_criar');
        } catch (\InvalidArgumentException $exception) {
            $_SESSION['agendamentos.error'] = t($exception->getMessage());
        } catch (\Throwable) {
            $_SESSION['agendamentos.error'] = t('agendamentos.erro_salvar');
        }
        $this->redirect('/agendamentos');
    }

    public function cancelar(int $id): void
    {
        $this->assertCsrf();
        $cancelado = (new AgendamentoService())->cancelar($this->tenantId(), (int) Auth::userId(), $id);
        $_SESSION['agendamentos.' . ($cancelado ? 'success' : 'error')] = t(
            $cancelado ? 'agendamentos.sucesso_cancelar' : 'agendamentos.erro_cancelar'
        );
        $this->redirect('/agendamentos');
    }

    private function tenantId(): int
    {
        $tenantId = (int) (TenantContext::id() ?? Auth::tenantId() ?? 0);
        if ($tenantId <= 0) {
            $this->redirect('/selecionar-empresa');
        }
        return $tenantId;
    }

    private function assertCsrf(): void
    {
        $token = (string) ($_POST['_csrf_token'] ?? '');
        if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
            http_response_code(403);
            exit(t('agendamentos.erro_csrf'));
        }
    }

    private function flash(string $type): ?string
    {
        $key = 'agendamentos.' . $type;
        $message = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return is_string($message) ? $message : null;
    }
}
