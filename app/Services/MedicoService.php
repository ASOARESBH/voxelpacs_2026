<?php
namespace App\Services;

use App\Core\Logger;
use App\Core\Estados;
use App\Repositories\MedicoRepository;
use App\Repositories\EstudosRepository;

/**
 * MedicoService — regras de negócio do módulo de Médicos.
 * O Controller chama o Service; o Service chama o Repository.
 * Nenhuma query SQL deve existir aqui.
 */
class MedicoService
{
    private MedicoRepository $repo;

    public function __construct()
    {
        $this->repo = new MedicoRepository();
    }

    // -------------------------------------------------------------------------
    // LISTAGEM
    // -------------------------------------------------------------------------

    public function listar(int $tenantId, string $busca = '', int $pagina = 1, int $porPagina = 20, ?int $onlyMedicoId = null): array
    {
        return $this->repo->findAll($tenantId, $busca, $pagina, $porPagina, $onlyMedicoId);
    }

    public function total(int $tenantId, string $busca = '', ?int $onlyMedicoId = null): int
    {
        return $this->repo->count($tenantId, $busca, $onlyMedicoId);
    }

    // -------------------------------------------------------------------------
    // FORMULÁRIO (dados para popular selects)
    // -------------------------------------------------------------------------

    public function dadosFormulario(int $tenantId, ?int $medicoId = null): array
    {
        return [
            'usuarios'         => $this->repo->findUsuariosVinculaveis($tenantId),
            'unidades'         => (new EstudosRepository())->getUnidades($tenantId, false),
            'unidadesMarcadas' => $medicoId ? $this->repo->getUnidades($medicoId) : [],
        ];
    }

    // -------------------------------------------------------------------------
    // BUSCA POR ID
    // -------------------------------------------------------------------------

    public function buscarPorId(int $id, int $tenantId): ?array
    {
        return $this->repo->findById($id, $tenantId);
    }

    // -------------------------------------------------------------------------
    // VALIDAÇÃO
    // -------------------------------------------------------------------------

    /**
     * Valida os dados do formulário.
     * Retorna array de erros (vazio = dados válidos).
     */
    public function validar(array $dados, int $tenantId, int $medicoIdAtual = 0): array
    {
        $erros = [];

        // Nome
        $nome = trim($dados['nome'] ?? '');
        if ($nome === '') {
            $erros[] = 'O campo Nome Completo é obrigatório.';
        } elseif (mb_strlen($nome) < 3) {
            $erros[] = 'O nome deve ter no mínimo 3 caracteres.';
        } elseif (mb_strlen($nome) > 200) {
            $erros[] = 'O nome deve ter no máximo 200 caracteres.';
        }

        // CRM
        $crm   = trim($dados['crm'] ?? '');
        $crmUf = strtoupper(trim($dados['crm_uf'] ?? ''));
        if ($crm !== '') {
            $crmNumeros = preg_replace('/\D/', '', $crm);
            if (!$crmNumeros || strlen($crmNumeros) < 4 || strlen($crmNumeros) > 8) {
                $erros[] = 'CRM inválido. Informe apenas os números (4 a 8 dígitos).';
            }
            if ($crmUf === '' || !isset(Estados::LISTA[$crmUf])) {
                $erros[] = 'Informe a UF do CRM.';
            }
            if (!$erros && $this->repo->existeCrm($crmNumeros, $crmUf, $tenantId, $medicoIdAtual)) {
                $erros[] = "Já existe um médico cadastrado com o CRM {$crmNumeros}/{$crmUf}.";
            }
        }

        // E-mail
        $email = trim($dados['email'] ?? '');
        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $erros[] = 'O e-mail informado não é válido.';
            } elseif ($this->repo->existeEmail($email, $tenantId, $medicoIdAtual)) {
                $erros[] = "Já existe um médico cadastrado com o e-mail {$email}.";
            }
        }

        // Telefone (apenas verifica formato mínimo se preenchido)
        $telefone = preg_replace('/\D/', '', $dados['telefone'] ?? '');
        if ($telefone !== '' && (strlen($telefone) < 10 || strlen($telefone) > 11)) {
            $erros[] = 'Telefone inválido. Informe DDD + número (10 ou 11 dígitos).';
        }

        // CEP
        $cep = preg_replace('/\D/', '', $dados['cep'] ?? '');
        if ($cep !== '' && strlen($cep) !== 8) {
            $erros[] = 'CEP inválido. Informe 8 dígitos.';
        }

        return $erros;
    }

    // -------------------------------------------------------------------------
    // NORMALIZAÇÃO DOS DADOS DO POST
    // -------------------------------------------------------------------------

    public function normalizarPost(array $post): array
    {
        $str = fn($k) => trim($post[$k] ?? '') ?: null;
        $uf  = fn($k) => (function($v) { $v = strtoupper(trim($v)); return isset(Estados::LISTA[$v]) ? $v : null; })($post[$k] ?? '');

        // Normaliza CRM: apenas números
        $crm = $str('crm');
        if ($crm) $crm = preg_replace('/\D/', '', $crm) ?: null;

        // Normaliza telefone: apenas números
        $telefone = $str('telefone');
        if ($telefone) $telefone = preg_replace('/\D/', '', $telefone) ?: null;

        // Normaliza CEP: apenas números
        $cep = $str('cep');
        if ($cep) $cep = preg_replace('/\D/', '', $cep) ?: null;

        return [
            'nome'          => trim($post['nome'] ?? ''),
            'crm'           => $crm,
            'crm_uf'        => $uf('crm_uf'),
            'especialidade' => $str('especialidade'),
            'email'         => $str('email') ? strtolower(trim($post['email'])) : null,
            'telefone'      => $telefone,
            'cep'           => $cep,
            'logradouro'    => $str('logradouro'),
            'numero'        => $str('numero'),
            'complemento'   => $str('complemento'),
            'bairro'        => $str('bairro'),
            'cidade'        => $str('cidade'),
            'estado'        => $uf('estado'),
        ];
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * Cadastra um novo médico.
     * Retorna ['ok' => true, 'id' => X] ou ['ok' => false, 'erros' => [...]]
     */
    public function cadastrar(array $post, int $tenantId, bool $podeGerenciarUnidades = false): array
    {
        $dados  = $this->normalizarPost($post);
        $erros  = $this->validar($dados, $tenantId);

        if ($erros) {
            Logger::error('[MedicoService::cadastrar] Validação falhou', ['erros' => $erros, 'tenant_id' => $tenantId]);
            return ['ok' => false, 'erros' => $erros];
        }

        $usuarioId = $this->repo->resolverUsuarioId($tenantId, (int) ($post['usuario_id'] ?? 0));
        $dados['tenant_id']  = $tenantId;
        $dados['usuario_id'] = $usuarioId;

        try {
            $id = $this->repo->inserir($dados);
            if ($podeGerenciarUnidades) {
                $unidades = array_filter((array) ($post['unidades'] ?? []), fn($u) => trim($u) !== '');
                if ($unidades) {
                    $this->repo->sincronizarUnidades($id, $tenantId, $unidades);
                }
            }
            Logger::error('[MedicoService::cadastrar] Médico cadastrado com sucesso', ['id' => $id, 'nome' => $dados['nome']]);
            return ['ok' => true, 'id' => $id];
        } catch (\Throwable $e) {
            Logger::error('[MedicoService::cadastrar] EXCEÇÃO ao inserir: ' . $e->getMessage(), [
                'tenant_id' => $tenantId,
                'nome'      => $dados['nome'],
                'sql_code'  => $e->getCode(),
            ]);
            return ['ok' => false, 'erros' => ['Erro interno ao cadastrar médico. Verifique os logs do servidor.']];
        }
    }

    /**
     * Atualiza os dados de um médico.
     * Retorna ['ok' => true] ou ['ok' => false, 'erros' => [...]]
     */
    public function atualizar(int $id, array $post, int $tenantId, bool $podeGerenciarUnidades = false): array
    {
        $dados = $this->normalizarPost($post);
        $erros = $this->validar($dados, $tenantId, $id);

        if ($erros) {
            Logger::error('[MedicoService::atualizar] Validação falhou', ['id' => $id, 'erros' => $erros]);
            return ['ok' => false, 'erros' => $erros];
        }

        $usuarioId = $this->repo->resolverUsuarioId($tenantId, (int) ($post['usuario_id'] ?? 0), $id);
        $dados['usuario_id'] = $usuarioId;

        try {
            $this->repo->atualizar($id, $tenantId, $dados);
            if ($podeGerenciarUnidades) {
                $unidades = array_filter((array) ($post['unidades'] ?? []), fn($u) => trim($u) !== '');
                $this->repo->sincronizarUnidades($id, $tenantId, $unidades);
            }
            return ['ok' => true];
        } catch (\Throwable $e) {
            Logger::error('[MedicoService::atualizar] EXCEÇÃO ao atualizar: ' . $e->getMessage(), [
                'id'        => $id,
                'tenant_id' => $tenantId,
            ]);
            return ['ok' => false, 'erros' => ['Erro interno ao atualizar médico. Verifique os logs do servidor.']];
        }
    }

    /**
     * Alterna o status ativo/inativo do médico.
     * Se o médico possuir estudos vinculados, usa soft delete (inativo) ao invés de excluir.
     */
    public function toggleStatus(int $id, int $tenantId): void
    {
        $medico = $this->repo->findById($id, $tenantId);
        if (!$medico) {
            Logger::error('[MedicoService::toggleStatus] Médico não encontrado', ['id' => $id, 'tenant_id' => $tenantId]);
            return;
        }
        $this->repo->toggleStatus($id, $tenantId);
    }
}
