<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\Logger;

/**
 * MedicoRepository — toda a SQL do módulo de Médicos centralizada aqui.
 * Nenhuma query de médico deve existir fora desta classe.
 */
class MedicoRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // -------------------------------------------------------------------------
    // READ
    // -------------------------------------------------------------------------

    /**
     * Lista todos os médicos ativos do tenant com contagem de exames e nome do usuário vinculado.
     * Suporta busca por nome/CRM/especialidade e paginação.
     */
    public function findAll(int $tenantId, string $busca = '', int $pagina = 1, int $porPagina = 20): array
    {
        $offset = ($pagina - 1) * $porPagina;
        $params = ['tenant_id' => $tenantId];
        $where  = 'WHERE m.tenant_id = :tenant_id';

        if ($busca !== '') {
            $where   .= " AND (m.nome LIKE :busca OR m.crm LIKE :busca OR m.especialidade LIKE :busca OR m.email LIKE :busca)";
            $params['busca'] = '%' . $busca . '%';
        }

        $sql = "
            SELECT m.*,
                   u.name AS usuario_nome,
                   (SELECT COUNT(*) FROM bi_exames e WHERE e.medico_id = m.id AND e.tenant_id = m.tenant_id) AS total_exames
            FROM bi_medicos m
            LEFT JOIN bi_users u ON u.id = m.usuario_id
            {$where}
            ORDER BY m.nome ASC
            LIMIT :limit OFFSET :offset
        ";
        $params['limit']  = $porPagina;
        $params['offset'] = $offset;

        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $type = is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
                $stmt->bindValue(':' . $key, $value, $type);
            }
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::error('[MedicoRepository::findAll] ' . $e->getMessage(), [
                'tenant_id' => $tenantId,
                'busca'     => $busca,
                'pagina'    => $pagina,
            ]);
            return [];
        }
    }

    /** Total de médicos do tenant para paginação. */
    public function count(int $tenantId, string $busca = ''): int
    {
        $params = ['tenant_id' => $tenantId];
        $where  = 'WHERE m.tenant_id = :tenant_id';

        if ($busca !== '') {
            $where   .= " AND (m.nome LIKE :busca OR m.crm LIKE :busca OR m.especialidade LIKE :busca OR m.email LIKE :busca)";
            $params['busca'] = '%' . $busca . '%';
        }

        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM bi_medicos m {$where}");
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            Logger::error('[MedicoRepository::count] ' . $e->getMessage());
            return 0;
        }
    }

    /** Busca um médico por ID garantindo isolamento de tenant. */
    public function findById(int $id, int $tenantId): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM bi_medicos WHERE id = :id AND tenant_id = :tenant_id LIMIT 1");
            $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            Logger::error('[MedicoRepository::findById] ' . $e->getMessage(), ['id' => $id, 'tenant_id' => $tenantId]);
            return null;
        }
    }

    /**
     * Resolve o registro bi_medicos vinculado a uma conta de usuário (Auth::userId()).
     * Usado por ReportService::assinar() pra achar "qual médico é o usuário logado"
     * antes de buscar a assinatura visual ativa dele.
     */
    public function findByUsuarioId(int $usuarioId, int $tenantId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM bi_medicos WHERE usuario_id = :usuario_id AND tenant_id = :tenant_id AND ativo = 1 LIMIT 1"
            );
            $stmt->execute(['usuario_id' => $usuarioId, 'tenant_id' => $tenantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            Logger::error('[MedicoRepository::findByUsuarioId] ' . $e->getMessage(), ['usuario_id' => $usuarioId, 'tenant_id' => $tenantId]);
            return null;
        }
    }

    /** Verifica se já existe médico com o mesmo CRM+UF no tenant (prevenção de duplicata). */
    public function existeCrm(string $crm, string $crmUf, int $tenantId, int $excluirId = 0): bool
    {
        if (!$crm) return false;
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM bi_medicos
                WHERE tenant_id = :tenant_id AND crm = :crm AND crm_uf = :crm_uf AND id != :excluir_id
            ");
            $stmt->execute(['tenant_id' => $tenantId, 'crm' => $crm, 'crm_uf' => $crmUf, 'excluir_id' => $excluirId]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            Logger::error('[MedicoRepository::existeCrm] ' . $e->getMessage());
            return false;
        }
    }

    /** Verifica se já existe médico com o mesmo e-mail no tenant. */
    public function existeEmail(string $email, int $tenantId, int $excluirId = 0): bool
    {
        if (!$email) return false;
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM bi_medicos
                WHERE tenant_id = :tenant_id AND email = :email AND id != :excluir_id
            ");
            $stmt->execute(['tenant_id' => $tenantId, 'email' => $email, 'excluir_id' => $excluirId]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            Logger::error('[MedicoRepository::existeEmail] ' . $e->getMessage());
            return false;
        }
    }

    /** Verifica se o médico possui estudos vinculados (para impedir exclusão física). */
    public function possuiEstudos(int $medicoId): bool
    {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM bi_exames WHERE medico_id = :id LIMIT 1");
            $stmt->execute(['id' => $medicoId]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            Logger::error('[MedicoRepository::possuiEstudos] ' . $e->getMessage(), ['medico_id' => $medicoId]);
            return true; // conservador: assume que tem estudos para não excluir
        }
    }

    // -------------------------------------------------------------------------
    // WRITE
    // -------------------------------------------------------------------------

    /** Insere um novo médico e retorna o ID gerado. */
    public function inserir(array $dados): int
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO bi_medicos
                    (tenant_id, nome, crm, crm_uf, especialidade, usuario_id,
                     email, telefone, cep, logradouro, numero, complemento,
                     bairro, cidade, estado, ativo)
                VALUES
                    (:tenant_id, :nome, :crm, :crm_uf, :especialidade, :usuario_id,
                     :email, :telefone, :cep, :logradouro, :numero, :complemento,
                     :bairro, :cidade, :estado, 1)
            ");
            $stmt->execute($dados);
            $id = (int) $this->pdo->lastInsertId();
            Logger::error('[MedicoRepository::inserir] Médico criado com sucesso', ['id' => $id, 'nome' => $dados['nome']]);
            return $id;
        } catch (\Throwable $e) {
            Logger::error('[MedicoRepository::inserir] ERRO ao inserir médico: ' . $e->getMessage(), [
                'dados'     => array_diff_key($dados, ['tenant_id' => 1]),
                'sql_code'  => $e->getCode(),
            ]);
            throw $e;
        }
    }

    /** Atualiza os dados de um médico garantindo isolamento de tenant. */
    public function atualizar(int $id, int $tenantId, array $dados): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE bi_medicos SET
                    nome=:nome, crm=:crm, crm_uf=:crm_uf, especialidade=:especialidade,
                    usuario_id=:usuario_id, email=:email, telefone=:telefone,
                    cep=:cep, logradouro=:logradouro, numero=:numero,
                    complemento=:complemento, bairro=:bairro, cidade=:cidade, estado=:estado
                WHERE id=:id AND tenant_id=:tenant_id
            ");
            $dados['id']        = $id;
            $dados['tenant_id'] = $tenantId;
            $stmt->execute($dados);
            Logger::error('[MedicoRepository::atualizar] Médico atualizado', ['id' => $id, 'nome' => $dados['nome']]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            Logger::error('[MedicoRepository::atualizar] ERRO ao atualizar médico: ' . $e->getMessage(), [
                'id'        => $id,
                'tenant_id' => $tenantId,
                'sql_code'  => $e->getCode(),
            ]);
            throw $e;
        }
    }

    /** Alterna o status ativo/inativo de um médico (soft delete). */
    public function toggleStatus(int $id, int $tenantId): void
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE bi_medicos
                SET ativo = CASE WHEN ativo = 1 THEN 0 ELSE 1 END
                WHERE id = :id AND tenant_id = :tenant_id
            ");
            $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
            Logger::error('[MedicoRepository::toggleStatus] Status alternado', ['id' => $id]);
        } catch (\Throwable $e) {
            Logger::error('[MedicoRepository::toggleStatus] ERRO: ' . $e->getMessage(), ['id' => $id]);
        }
    }

    // -------------------------------------------------------------------------
    // UNIDADES
    // -------------------------------------------------------------------------

    /** Retorna as unidades (institution_name) vinculadas ao médico. */
    public function getUnidades(int $medicoId): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT institution_name FROM bi_medico_unidades WHERE medico_id = :id");
            $stmt->execute(['id' => $medicoId]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            Logger::error('[MedicoRepository::getUnidades] ' . $e->getMessage(), ['medico_id' => $medicoId]);
            return [];
        }
    }

    /** Substitui as unidades vinculadas ao médico (delete + insert). */
    public function sincronizarUnidades(int $medicoId, int $tenantId, array $nomes): void
    {
        try {
            $this->pdo->prepare("DELETE FROM bi_medico_unidades WHERE medico_id = :id")->execute(['id' => $medicoId]);
            if (!$nomes) return;
            $ins = $this->pdo->prepare("
                INSERT IGNORE INTO bi_medico_unidades (tenant_id, medico_id, institution_name)
                VALUES (:tenant_id, :medico_id, :institution_name)
            ");
            foreach ($nomes as $nome) {
                $nome = trim((string) $nome);
                if ($nome === '') continue;
                $ins->execute(['tenant_id' => $tenantId, 'medico_id' => $medicoId, 'institution_name' => $nome]);
            }
        } catch (\Throwable $e) {
            Logger::error('[MedicoRepository::sincronizarUnidades] ERRO: ' . $e->getMessage(), [
                'medico_id' => $medicoId,
                'nomes'     => $nomes,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // USUÁRIOS VINCULÁVEIS
    // -------------------------------------------------------------------------

    /** Usuários ativos do tenant elegíveis para vínculo com médico. */
    public function findUsuariosVinculaveis(int $tenantId): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT u.id, u.name
                FROM bi_users u
                INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = :tenant_id
                WHERE u.status = 'ativo'
                ORDER BY u.name
            ");
            $stmt->execute(['tenant_id' => $tenantId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::error('[MedicoRepository::findUsuariosVinculaveis] ' . $e->getMessage());
            return [];
        }
    }

    /** Valida se o usuário pertence ao tenant e não está vinculado a outro médico. */
    public function resolverUsuarioId(int $tenantId, int $usuarioId, int $medicoIdAtual = 0): ?int
    {
        if (!$usuarioId) return null;
        try {
            $stmt = $this->pdo->prepare("
                SELECT u.id FROM bi_users u
                INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = :tenant_id
                WHERE u.id = :usuario_id AND u.status = 'ativo'
            ");
            $stmt->execute(['tenant_id' => $tenantId, 'usuario_id' => $usuarioId]);
            if (!$stmt->fetchColumn()) return null;

            $stmtDup = $this->pdo->prepare("
                SELECT id FROM bi_medicos WHERE tenant_id = :tenant_id AND usuario_id = :usuario_id AND id != :medico_id
            ");
            $stmtDup->execute(['tenant_id' => $tenantId, 'usuario_id' => $usuarioId, 'medico_id' => $medicoIdAtual]);
            if ($stmtDup->fetchColumn()) {
                Logger::error('[MedicoRepository::resolverUsuarioId] Usuário já vinculado a outro médico', [
                    'tenant_id'  => $tenantId,
                    'usuario_id' => $usuarioId,
                ]);
                return null;
            }
            return $usuarioId;
        } catch (\Throwable $e) {
            Logger::error('[MedicoRepository::resolverUsuarioId] ' . $e->getMessage());
            return null;
        }
    }
}
