<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\EstudosRepository;
use App\Repositories\SlaRegrasRepository;

/**
 * Motor de Regras de SLA — o "robô". Avalia as regras ativas de cada tenant
 * e remaneja estudos que ultrapassaram (ou ficaram abaixo, conforme operador)
 * o limiar configurado, reatribuindo o médico responsável de verdade na
 * worklist (bi_pacs_estudos.assumido_por).
 *
 * Disparado por SlaRoboController::executar() (endpoint público com token,
 * chamado por um cron externo — ver docs/SYNC_AUTOMATICO_PACS.md para o
 * padrão de agendamento usado neste projeto, hospedagem sem crontab real).
 */
class SlaRulesEngineService
{
    private const LOCK_TTL_MINUTES = 15;

    private \PDO $pdo;
    private EstudosRepository $estudosRepo;
    private SlaRegrasRepository $regrasRepo;

    public function __construct()
    {
        $this->pdo         = Database::getInstance();
        $this->estudosRepo = new EstudosRepository();
        $this->regrasRepo  = new SlaRegrasRepository();
    }

    /** Ponto de entrada chamado pelo endpoint público do robô. */
    public function executarParaTodosTenants(): array
    {
        if (!$this->adquirirLock()) {
            return [
                'locked'  => true,
                'message' => 'Execução anterior ainda em andamento (lock ocupado).',
            ];
        }

        $resumo = [
            'locked'                => false,
            'tenants_processados'   => 0,
            'regras_avaliadas'      => 0,
            'estudos_remanejados'   => 0,
            'erros'                 => [],
        ];

        try {
            foreach ($this->regrasRepo->findTenantsAtivos() as $tenantId) {
                $tenantId = (int) $tenantId;
                try {
                    $r = $this->executarParaTenant($tenantId);
                    $resumo['tenants_processados']++;
                    $resumo['regras_avaliadas']    += $r['regras_avaliadas'];
                    $resumo['estudos_remanejados'] += $r['estudos_remanejados'];
                } catch (\Throwable $ex) {
                    Logger::error('Erro ao executar Regras de SLA para tenant', [
                        'tenant_id' => $tenantId,
                        'error'     => $ex->getMessage(),
                    ]);
                    $resumo['erros'][] = "tenant {$tenantId}: " . $ex->getMessage();
                }
            }
        } finally {
            $this->liberarLock($resumo);
        }

        return $resumo;
    }

    /** Avalia e aplica todas as regras ativas de UM tenant. */
    private function executarParaTenant(int $tenantId): array
    {
        $regras = $this->regrasRepo->findRegrasAtivas($tenantId);
        // Evita cascata: um estudo já remanejado por uma regra nesta execução
        // não é reavaliado por outra regra de prioridade menor no mesmo ciclo.
        $processadosNesteCiclo = [];
        $estudosRemanejados    = 0;

        foreach ($regras as $regra) {
            $candidatos = $this->estudosRepo->buscarCandidatosSla($tenantId, $regra, $processadosNesteCiclo);

            foreach ($candidatos as $estudo) {
                $usuarioAtualId = !empty($estudo['assumido_por']) ? (int) $estudo['assumido_por'] : null;
                $medicoAlvo     = $this->resolverMedicoAlvo($tenantId, $regra, $usuarioAtualId);

                if (!$medicoAlvo || !$medicoAlvo->usuario_id) {
                    Logger::warning('Regras de SLA: nenhum medico elegivel para a regra', [
                        'tenant_id' => $tenantId,
                        'regra_id'  => $regra->id,
                        'estudo_id' => $estudo['id'],
                    ]);
                    continue;
                }
                if ((int) $medicoAlvo->usuario_id === $usuarioAtualId) {
                    continue; // já é o responsável atual, nada a fazer
                }

                if ($this->aplicarRemanejamento($tenantId, $regra, $estudo, $medicoAlvo)) {
                    $processadosNesteCiclo[] = (int) $estudo['id'];
                    $estudosRemanejados++;
                }
            }
        }

        return ['regras_avaliadas' => count($regras), 'estudos_remanejados' => $estudosRemanejados];
    }

    /** Resolve o médico alvo conforme tipo_acao da regra. Retorna null se não houver candidato elegível. */
    private function resolverMedicoAlvo(int $tenantId, object $regra, ?int $usuarioAtualId): ?object
    {
        $unidadeFiltro = $regra->filtro_institution_name ?: null;

        switch ($regra->tipo_acao) {
            case 'especifico':
                if (!$regra->medico_especifico_id) return null;
                $medico = $this->regrasRepo->getMedicoPorId($tenantId, (int) $regra->medico_especifico_id);
                if (!$medico || !(int) $medico->ativo || !$medico->usuario_id) return null;
                return $medico;

            case 'aleatorio':
                return $this->regrasRepo->resolverMedicoAleatorio($tenantId, $unidadeFiltro, $usuarioAtualId);

            case 'menor_carga':
            default:
                return $this->regrasRepo->resolverMedicoMenorCarga($tenantId, $unidadeFiltro, $usuarioAtualId);
        }
    }

    /** Aplica a reatribuição + grava histórico. Retorna sucesso. */
    private function aplicarRemanejamento(int $tenantId, object $regra, array $estudo, object $medicoAlvo): bool
    {
        $sucesso = $this->estudosRepo->reatribuirPorRobo((int) $estudo['id'], (int) $medicoAlvo->usuario_id, $tenantId);
        if (!$sucesso) return false;

        $this->regrasRepo->registrarExecucao([
            'tenant_id'                  => $tenantId,
            'regra_id'                   => $regra->id,
            'regra_nome_snapshot'        => $regra->nome,
            'estudo_id'                  => $estudo['id'],
            'medico_anterior_usuario_id' => $estudo['assumido_por'] ?: null,
            'medico_novo_id'             => $medicoAlvo->medico_id,
            'medico_novo_usuario_id'     => $medicoAlvo->usuario_id,
            'metrica'                    => $regra->metrica,
            'minutos_decorridos'         => $estudo['minutos_decorridos'],
        ]);

        Logger::info('Regras de SLA: estudo remanejado pelo robo', [
            'tenant_id'              => $tenantId,
            'regra_id'               => $regra->id,
            'estudo_id'              => $estudo['id'],
            'medico_novo_usuario_id' => $medicoAlvo->usuario_id,
        ]);

        return true;
    }

    /** Lock simples de concorrência: só avança se ninguém segurar o lock, ou se o lock estiver "preso" há mais que o TTL. */
    private function adquirirLock(): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE bi_sla_robo_config
            SET lock_adquirido_em = NOW()
            WHERE id = 1
              AND (lock_adquirido_em IS NULL
                   OR TIMESTAMPDIFF(MINUTE, lock_adquirido_em, NOW()) > :ttl)
        ");
        $stmt->execute(['ttl' => self::LOCK_TTL_MINUTES]);
        return $stmt->rowCount() === 1;
    }

    private function liberarLock(array $resumo): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE bi_sla_robo_config
            SET lock_adquirido_em = NULL, ultima_execucao_em = NOW(), ultima_execucao_resumo = :resumo
            WHERE id = 1
        ");
        $stmt->execute(['resumo' => json_encode($resumo, JSON_UNESCAPED_UNICODE)]);
    }
}
