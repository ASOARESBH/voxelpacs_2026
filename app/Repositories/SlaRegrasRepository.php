<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\Logger;

/**
 * Acesso a dados do motor de Regras de SLA: regras ativas, resolução de
 * médico elegível por tipo de ação, e histórico de execuções do robô.
 * Não usa App\Core\Model porque o robô processa vários tenants numa mesma
 * execução (não depende de TenantContext/sessão) — o tenant_id é sempre
 * passado explicitamente.
 */
class SlaRegrasRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function findTenantsAtivos(): array
    {
        return $this->pdo->query("SELECT id FROM bi_tenants WHERE status = 'ativo'")->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function findRegrasAtivas(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM bi_sla_regras WHERE tenant_id = :tenant_id AND ativo = 1 ORDER BY prioridade ASC"
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function getMedicoPorId(int $tenantId, int $medicoId): ?object
    {
        $stmt = $this->pdo->prepare(
            "SELECT id AS medico_id, usuario_id, ativo FROM bi_medicos WHERE id = :id AND tenant_id = :tenant_id"
        );
        $stmt->execute(['id' => $medicoId, 'tenant_id' => $tenantId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    /** Médico elegível com menor número de estudos pendentes (balanceamento de carga). */
    public function resolverMedicoMenorCarga(int $tenantId, ?string $institutionName, ?int $excluirUsuarioId): ?object
    {
        [$where, $params] = $this->condicoesElegibilidade($tenantId, $institutionName, $excluirUsuarioId);
        $sql = "SELECT m.id AS medico_id, m.usuario_id, COUNT(e.id) AS pendentes
                FROM bi_medicos m
                LEFT JOIN bi_pacs_estudos e
                       ON e.assumido_por = m.usuario_id
                      AND e.tenant_id    = m.tenant_id
                      AND e.situacao NOT IN ('assinado','liberado')
                WHERE " . implode(' AND ', $where) . "
                GROUP BY m.id, m.usuario_id
                ORDER BY pendentes ASC, RAND()
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    /** Médico elegível aleatório. */
    public function resolverMedicoAleatorio(int $tenantId, ?string $institutionName, ?int $excluirUsuarioId): ?object
    {
        [$where, $params] = $this->condicoesElegibilidade($tenantId, $institutionName, $excluirUsuarioId);
        $sql = "SELECT m.id AS medico_id, m.usuario_id
                FROM bi_medicos m
                WHERE " . implode(' AND ', $where) . "
                ORDER BY RAND()
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    /** Elegibilidade comum: médico ativo, com conta de login vinculada, dentro do tenant, fora do responsável atual e (se a regra tiver filtro) vinculado à unidade. */
    private function condicoesElegibilidade(int $tenantId, ?string $institutionName, ?int $excluirUsuarioId): array
    {
        $where  = ['m.tenant_id = :tenant_id', 'm.ativo = 1', 'm.usuario_id IS NOT NULL'];
        $params = ['tenant_id' => $tenantId];

        if ($excluirUsuarioId) {
            $where[] = 'm.usuario_id != :excluir_usuario_id';
            $params['excluir_usuario_id'] = $excluirUsuarioId;
        }
        if ($institutionName) {
            $where[] = 'm.id IN (SELECT medico_id FROM bi_medico_unidades WHERE institution_name = :inst)';
            $params['inst'] = $institutionName;
        }
        return [$where, $params];
    }

    public function registrarExecucao(array $dados): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO bi_sla_regras_execucoes
                    (tenant_id, regra_id, regra_nome_snapshot, estudo_id, medico_anterior_usuario_id,
                     medico_novo_id, medico_novo_usuario_id, metrica, minutos_decorridos, executado_em)
                VALUES
                    (:tenant_id, :regra_id, :regra_nome_snapshot, :estudo_id, :medico_anterior_usuario_id,
                     :medico_novo_id, :medico_novo_usuario_id, :metrica, :minutos_decorridos, NOW())
            ");
            return $stmt->execute($dados);
        } catch (\Throwable $ex) {
            Logger::error('Erro ao registrar execucao de Regras de SLA', ['error' => $ex->getMessage(), 'dados' => $dados]);
            return false;
        }
    }

    public function listarExecucoes(int $tenantId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare("
            SELECT ex.*, r.nome AS regra_nome_atual
            FROM bi_sla_regras_execucoes ex
            LEFT JOIN bi_sla_regras r ON r.id = ex.regra_id
            WHERE ex.tenant_id = :tenant_id
            ORDER BY ex.executado_em DESC
            LIMIT " . (int) $limit
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }
}
