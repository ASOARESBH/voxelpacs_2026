<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Services\GrupoNotificacaoService;

/** Administração tenant-scoped das políticas de alerta e modalidade de grupos. */
final class GrupoNotificacoesController extends Controller
{
    private GrupoNotificacaoService $service;

    public function __construct()
    {
        $this->service = new GrupoNotificacaoService();
    }

    private function authorize(): ?int
    {
        if (!Auth::canManageTenantUsers()) {
            $this->redirect('/usuarios?error=acesso_negado');
            return null;
        }
        $tenantId = (int) TenantContext::id();
        if ($tenantId <= 0) {
            $this->redirect('/selecionar-empresa');
            return null;
        }
        return $tenantId;
    }

    public function index(): void
    {
        $tenantId = $this->authorize();
        if (!$tenantId) return;
        $groupId = max(0, (int) ($_GET['grupo'] ?? 0));
        $this->view('grupos/notificacoes', array_merge([
            'title' => t('usuarios.notificacoes.titulo'),
            'sucesso' => $_GET['sucesso'] ?? '',
            'erro' => $_GET['erro'] ?? '',
        ], $this->service->pageData($tenantId, $groupId ?: null)));
    }

    public function salvar(int $grupoId): void
    {
        $tenantId = $this->authorize();
        if (!$tenantId) return;
        $result = $this->service->save($grupoId, $tenantId, $_POST);
        if (!$result['ok']) {
            Logger::warning('[GrupoNotificacoesController::salvar] política recusada', [
                'tenant_id' => $tenantId, 'grupo_id' => $grupoId, 'erro' => $result['error'] ?? 'desconhecido',
            ]);
            $this->redirect('/usuarios/notificacoes?grupo=' . $grupoId . '&erro=' . rawurlencode((string) ($result['error'] ?? 'persistencia_falhou')));
            return;
        }
        $this->redirect('/usuarios/notificacoes?grupo=' . $grupoId . '&sucesso=salvo');
    }
}
