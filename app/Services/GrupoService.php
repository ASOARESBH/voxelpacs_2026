<?php
namespace App\Services;

use App\Core\Logger;
use App\Repositories\GrupoRepository;

/**
 * GrupoService — regras de negócio do módulo de Grupos (Fase 1: CRUD +
 * vínculo de usuários). O Controller chama o Service; o Service chama o
 * Repository. Nenhuma query SQL deve existir aqui.
 *
 * Fora de escopo nesta fase (ver modules/grupos.md): uso de grupos para
 * restringir/conceder acesso, ou para distribuição de relatórios.
 */
class GrupoService
{
    /** Nomes sugeridos como atalho na UI — "nome" continua sendo texto livre no banco. */
    public const SUGESTOES_NOME = ['Médicos', 'Administrativo', 'Secretarias'];

    private GrupoRepository $repo;

    public function __construct()
    {
        $this->repo = new GrupoRepository();
    }

    // -------------------------------------------------------------------------
    // LISTAGEM / BUSCA
    // -------------------------------------------------------------------------

    public function listar(int $tenantId): array
    {
        return $this->repo->findAll($tenantId);
    }

    public function buscarPorId(int $id, int $tenantId): ?array
    {
        return $this->repo->findById($id, $tenantId);
    }

    // -------------------------------------------------------------------------
    // VALIDAÇÃO
    // -------------------------------------------------------------------------

    /** Valida os dados do formulário. Retorna array de erros (vazio = válido). */
    public function validar(array $dados, int $tenantId, int $grupoIdAtual = 0): array
    {
        $erros = [];

        $nome = trim($dados['nome'] ?? '');
        if ($nome === '') {
            $erros[] = 'O campo Nome do Grupo é obrigatório.';
        } elseif (mb_strlen($nome) < 2) {
            $erros[] = 'O nome deve ter no mínimo 2 caracteres.';
        } elseif (mb_strlen($nome) > 200) {
            $erros[] = 'O nome deve ter no máximo 200 caracteres.';
        } elseif ($this->repo->existeNome($nome, $tenantId, $grupoIdAtual)) {
            $erros[] = "Já existe um grupo chamado \"{$nome}\" neste negócio.";
        }

        $descricao = trim($dados['descricao'] ?? '');
        if (mb_strlen($descricao) > 500) {
            $erros[] = 'A descrição deve ter no máximo 500 caracteres.';
        }

        return $erros;
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /** Cadastra um novo grupo. Retorna ['ok' => true, 'id' => X] ou ['ok' => false, 'erros' => [...]]. */
    public function cadastrar(array $post, int $tenantId): array
    {
        $erros = $this->validar($post, $tenantId);
        if ($erros) {
            return ['ok' => false, 'erros' => $erros];
        }

        try {
            $nome      = trim($post['nome']);
            $descricao = trim($post['descricao'] ?? '') ?: null;
            $id        = $this->repo->inserir($tenantId, $nome, $descricao);
            return ['ok' => true, 'id' => $id];
        } catch (\Throwable $e) {
            Logger::error('[GrupoService::cadastrar] EXCEÇÃO: ' . $e->getMessage(), ['tenant_id' => $tenantId]);
            return ['ok' => false, 'erros' => ['Erro interno ao cadastrar grupo. Verifique os logs do servidor.']];
        }
    }

    /** Atualiza um grupo existente. Retorna ['ok' => true] ou ['ok' => false, 'erros' => [...]]. */
    public function atualizar(int $id, array $post, int $tenantId): array
    {
        $erros = $this->validar($post, $tenantId, $id);
        if ($erros) {
            return ['ok' => false, 'erros' => $erros];
        }

        try {
            $nome      = trim($post['nome']);
            $descricao = trim($post['descricao'] ?? '') ?: null;
            $this->repo->atualizar($id, $tenantId, $nome, $descricao);
            return ['ok' => true];
        } catch (\Throwable $e) {
            Logger::error('[GrupoService::atualizar] EXCEÇÃO: ' . $e->getMessage(), ['id' => $id, 'tenant_id' => $tenantId]);
            return ['ok' => false, 'erros' => ['Erro interno ao atualizar grupo. Verifique os logs do servidor.']];
        }
    }

    /** Alterna o status ativo/inativo do grupo (soft delete). */
    public function toggleStatus(int $id, int $tenantId): void
    {
        $grupo = $this->repo->findById($id, $tenantId);
        if (!$grupo) {
            Logger::error('[GrupoService::toggleStatus] Grupo não encontrado', ['id' => $id, 'tenant_id' => $tenantId]);
            return;
        }
        $this->repo->toggleStatus($id, $tenantId);
    }

    // -------------------------------------------------------------------------
    // MEMBROS
    // -------------------------------------------------------------------------

    public function membros(int $grupoId, int $tenantId): array
    {
        return $this->repo->membros($grupoId, $tenantId);
    }

    public function usuariosDisponiveis(int $grupoId, int $tenantId): array
    {
        return $this->repo->usuariosDisponiveis($grupoId, $tenantId);
    }

    /**
     * Adiciona um ou mais usuários ao grupo. Ignora silenciosamente qualquer
     * usuario_id que não pertença ao tenant (guard contra IDOR — nunca
     * confiar em ID vindo de outro negócio).
     */
    public function adicionarMembros(int $grupoId, array $usuarioIds, int $tenantId): void
    {
        $grupo = $this->repo->findById($grupoId, $tenantId);
        if (!$grupo) {
            Logger::error('[GrupoService::adicionarMembros] Grupo não encontrado', ['grupo_id' => $grupoId, 'tenant_id' => $tenantId]);
            return;
        }

        foreach ($usuarioIds as $usuarioId) {
            $usuarioId = (int) $usuarioId;
            if ($usuarioId <= 0) continue;
            if (!$this->repo->usuarioPertenceAoTenant($usuarioId, $tenantId)) {
                Logger::error('[GrupoService::adicionarMembros] Usuário fora do tenant — ignorado', [
                    'usuario_id' => $usuarioId, 'tenant_id' => $tenantId, 'grupo_id' => $grupoId,
                ]);
                continue;
            }
            $this->repo->adicionarMembro($grupoId, $usuarioId, $tenantId);
        }
    }

    public function removerMembro(int $grupoId, int $usuarioId, int $tenantId): void
    {
        $grupo = $this->repo->findById($grupoId, $tenantId);
        if (!$grupo) {
            Logger::error('[GrupoService::removerMembro] Grupo não encontrado', ['grupo_id' => $grupoId, 'tenant_id' => $tenantId]);
            return;
        }
        $this->repo->removerMembro($grupoId, $usuarioId, $tenantId);
    }
}
