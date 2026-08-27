<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\TenantContext;
use App\Services\RegraAcessoService;

/** Administração tenant-scoped de sessão, origem e horário por usuário. */
final class RegrasAcessoController extends Controller
{
    private RegraAcessoService $service;

    public function __construct()
    {
        $this->service = new RegraAcessoService();
    }

    public function index(): void
    {
        $tenantId = $this->authorize();
        if (!$tenantId) return;
        $users = $this->service->listForTenant($tenantId);
        foreach ($users as &$user) {
            $user['can_edit'] = $this->canManageTarget((int) $user['id'], (string) ($user['perfil'] ?? ''));
        }
        unset($user);
        $this->view('usuarios/regras_acesso_index', [
            'title' => t('usuarios.regras_acesso.titulo'),
            'usuarios' => $users,
            'sucesso' => (string) ($_GET['sucesso'] ?? ''),
            'erro' => (string) ($_GET['erro'] ?? ''),
        ], 'pacs');
    }

    public function editar(int $userId): void
    {
        $tenantId = $this->authorize();
        if (!$tenantId) return;
        $user = $this->service->tenantUser($userId, $tenantId);
        if (!$user || !$this->canManageTarget($userId, (string) ($user['perfil'] ?? ''))) {
            $this->redirect('/usuarios/regras-acesso?erro=nao_autorizado');
        }
        $rule = $this->service->userRule($userId, $tenantId) ?? $this->defaultRule();
        $this->view('usuarios/regras_acesso_form', [
            'title' => t('usuarios.regras_acesso.editar_titulo'),
            'usuario' => $user,
            'regra' => $rule,
            'erro' => (string) ($_GET['erro'] ?? ''),
            'csrf' => $this->accessRuleCsrfToken(),
        ], 'pacs');
    }

    public function salvar(int $userId): void
    {
        $tenantId = $this->authorize();
        if (!$tenantId) return;
        if (!$this->accessRuleCsrfValid()) {
            $this->redirect('/usuarios/regras-acesso/' . $userId . '/editar?erro=csrf');
        }
        $result = $this->service->saveRule($userId, $tenantId, $_POST);
        if (!$result['ok']) {
            $this->redirect('/usuarios/regras-acesso/' . $userId . '/editar?erro=' . rawurlencode((string) ($result['reason'] ?? 'persistencia_falhou')));
        }
        $this->redirect('/usuarios/regras-acesso?sucesso=salvo');
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

    private function canManageTarget(int $targetUserId, string $targetProfile): bool
    {
        if (Auth::isPlatformAdmin()) return true;
        return $targetUserId !== (int) Auth::userId() && strtolower($targetProfile) !== 'admin';
    }

    private function accessRuleCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return (string) $_SESSION['csrf_token'];
    }

    private function accessRuleCsrfValid(): bool
    {
        return !empty($_SESSION['csrf_token'])
            && !empty($_POST['_csrf_token'])
            && hash_equals((string) $_SESSION['csrf_token'], (string) $_POST['_csrf_token']);
    }

    /** @return array<string,mixed> */
    private function defaultRule(): array
    {
        return [
            'sessao_timeout_ativo' => 0,
            'sessao_timeout_minutos' => null,
            'ip_restricao_ativa' => 0,
            'ip_lista_permitida' => null,
            'horario_restricao_ativa' => 0,
            'horario_inicio' => null,
            'horario_fim' => null,
            'horario_dias_semana' => '1,2,3,4,5,6,7',
        ];
    }
}
