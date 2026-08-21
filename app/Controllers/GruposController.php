<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Services\GrupoService;

/**
 * GruposController — Sistema > Usuários > Grupos (Fase 1: CRUD de grupos +
 * vínculo de usuários). Enxuto, sem SQL — toda lógica está em GrupoService,
 * toda SQL está em GrupoRepository (mesmo padrão de MedicosController, a
 * referência de CRUD do projeto — ver modules/medicos.md).
 *
 * Fora de escopo nesta fase (ver modules/grupos.md): uso de grupos para
 * restringir/conceder acesso, ou para distribuição de relatórios.
 */
class GruposController extends Controller
{
    private GrupoService $service;

    public function __construct()
    {
        $this->service = new GrupoService();
    }

    // -------------------------------------------------------------------------
    // Guard de tenant: garante que o controller nunca opera sem tenant_id
    // -------------------------------------------------------------------------
    private function tenantId(): int
    {
        $id = TenantContext::id();
        if (!$id) {
            Logger::error('[GruposController] Acesso sem tenant_id — redirecionando', [
                'user_id'  => Auth::userId(),
                'is_admin' => Auth::isPlatformAdmin(),
                'uri'      => $_SERVER['REQUEST_URI'] ?? '',
            ]);
            $this->redirect('/selecionar-empresa');
            exit;
        }
        return $id;
    }

    private function requireUserManagement(): bool
    {
        if (Auth::canManageTenantUsers()) {
            return true;
        }

        Logger::error('[GruposController] Operação administrativa negada', [
            'user_id' => Auth::userId(),
            'tenant_id' => TenantContext::id(),
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
        ]);
        $this->redirect('/usuarios?error=acesso_negado');
        return false;
    }

    // -------------------------------------------------------------------------
    // READ — Listagem
    // -------------------------------------------------------------------------

    public function index(): void
    {
        if (!$this->requireUserManagement()) return;

        $tenantId = $this->tenantId();
        $grupos   = $this->service->listar($tenantId);

        $this->view('grupos/index', [
            'title'    => 'Grupos',
            'grupos'   => $grupos,
            'sucesso'  => $_GET['sucesso'] ?? '',
            'error'    => $_GET['error']   ?? '',
        ]);
    }

    // -------------------------------------------------------------------------
    // CREATE — Formulário + Persistência
    // -------------------------------------------------------------------------

    public function novo(): void
    {
        if (!$this->requireUserManagement()) return;

        $this->tenantId();

        $erros     = $_SESSION['grupo_form_erros'] ?? [];
        $formDados = $_SESSION['grupo_form_dados'] ?? [];
        unset($_SESSION['grupo_form_erros'], $_SESSION['grupo_form_dados']);

        $this->view('grupos/form', [
            'title'      => 'Novo Grupo',
            'grupo'      => $formDados ?: null,
            'erros'      => $erros,
            'sugestoes'  => GrupoService::SUGESTOES_NOME,
        ]);
    }

    public function store(): void
    {
        if (!$this->requireUserManagement()) return;

        $tenantId = $this->tenantId();

        $resultado = $this->service->cadastrar($_POST, $tenantId);

        if (!$resultado['ok']) {
            $_SESSION['grupo_form_erros'] = $resultado['erros'];
            $_SESSION['grupo_form_dados'] = $_POST;
            $this->redirect('/usuarios/grupos/novo?error=validacao');
            return;
        }

        $_SESSION['success'] = 'Grupo criado com sucesso!';
        Logger::error('[GruposController::store] Grupo criado', ['tenant_id' => $tenantId, 'grupo_id' => $resultado['id']]);
        $this->redirect('/usuarios/grupos?sucesso=grupo_criado');
    }

    // -------------------------------------------------------------------------
    // READ — Edição (dados do grupo + painel de membros)
    // -------------------------------------------------------------------------

    public function editar(int $id): void
    {
        if (!$this->requireUserManagement()) return;

        $tenantId = $this->tenantId();
        $grupo    = $this->service->buscarPorId($id, $tenantId);

        if (!$grupo) {
            Logger::error('[GruposController::editar] Grupo não encontrado ou tenant inválido', ['id' => $id, 'tenant_id' => $tenantId]);
            http_response_code(404);
            exit('Grupo não encontrado.');
        }

        $erros     = $_SESSION['grupo_form_erros'] ?? [];
        $formDados = $_SESSION['grupo_form_dados'] ?? [];
        unset($_SESSION['grupo_form_erros'], $_SESSION['grupo_form_dados']);
        if ($formDados) {
            $grupo = array_merge($grupo, $formDados);
        }

        $this->view('grupos/form', [
            'title'               => 'Editar Grupo',
            'grupo'               => $grupo,
            'erros'               => $erros,
            'sugestoes'           => GrupoService::SUGESTOES_NOME,
            'membros'             => $this->service->membros($id, $tenantId),
            'usuariosDisponiveis' => $this->service->usuariosDisponiveis($id, $tenantId),
            'sucesso'             => $_GET['sucesso'] ?? '',
        ]);
    }

    // -------------------------------------------------------------------------
    // UPDATE — Persistência da edição
    // -------------------------------------------------------------------------

    public function atualizar(int $id): void
    {
        if (!$this->requireUserManagement()) return;

        $tenantId = $this->tenantId();

        $resultado = $this->service->atualizar($id, $_POST, $tenantId);

        if (!$resultado['ok']) {
            $_SESSION['grupo_form_erros'] = $resultado['erros'];
            $_SESSION['grupo_form_dados'] = $_POST;
            $this->redirect("/usuarios/grupos/{$id}/editar?error=validacao");
            return;
        }

        $_SESSION['success'] = 'Grupo atualizado com sucesso!';
        $this->redirect("/usuarios/grupos/{$id}/editar?sucesso=grupo_atualizado");
    }

    // -------------------------------------------------------------------------
    // DELETE — Soft delete (ativo = 0)
    // -------------------------------------------------------------------------

    public function excluir(int $id): void
    {
        if (!$this->requireUserManagement()) return;

        $tenantId = $this->tenantId();
        $this->service->toggleStatus($id, $tenantId);
        $this->redirect('/usuarios/grupos?sucesso=grupo_status_alterado');
    }

    // -------------------------------------------------------------------------
    // MEMBROS — Vincular / Desvincular usuários
    // -------------------------------------------------------------------------

    public function adicionarUsuarios(int $id): void
    {
        if (!$this->requireUserManagement()) return;

        $tenantId   = $this->tenantId();
        $usuarioIds = $_POST['usuario_ids'] ?? [];
        if (!is_array($usuarioIds)) {
            $usuarioIds = [$usuarioIds];
        }

        $this->service->adicionarMembros($id, $usuarioIds, $tenantId);
        $this->redirect("/usuarios/grupos/{$id}/editar?sucesso=membros_adicionados");
    }

    public function removerUsuario(int $id, int $usuarioId): void
    {
        if (!$this->requireUserManagement()) return;

        $tenantId = $this->tenantId();
        $this->service->removerMembro($id, $usuarioId, $tenantId);
        $this->redirect("/usuarios/grupos/{$id}/editar?sucesso=membro_removido");
    }
}
