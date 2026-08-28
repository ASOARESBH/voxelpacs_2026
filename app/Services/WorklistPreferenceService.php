<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\SqlHelper;
use App\Core\SystemConfig;

/**
 * Resolve somente a apresentação da Worklist. Não amplia filtros, tenant,
 * unidades, modalidades ou posse clínica do estudo.
 */
final class WorklistPreferenceService
{
    public const SORT_RECENTES = 'recentes';
    public const SORT_PRIORIDADE = 'prioridade';
    public const SORT_SITUACAO_MEDICA = 'situacao_medica';
    public const PRIORIDADE_URGENCIA = 'urgencia_primeiro';
    public const PRIORIDADE_ROTINA = 'rotina_primeiro';

    /** @var list<string> */
    public const MEDICAL_STATUS_CODES = ['pendente', 'a_laudar', 'em_laudo', 'rascunho', 'assinado', 'peer_review'];

    /** @return array{enabled:bool,source:string,sort_mode:string,priority_order:string,medical_status_order:list<string>} */
    public function resolveForUser(int $userId, ?int $tenantId, bool $isMedical): array
    {
        $global = $this->globalDefaults($isMedical);
        if ($userId <= 0 || !$tenantId) {
            return $global;
        }

        try {
            $pdo = Database::getInstance();
            if (!SqlHelper::hasTable($pdo, 'bi_user_worklist_preferences')) {
                return $global;
            }
            $stmt = $pdo->prepare(
                'SELECT preference_enabled, sort_mode, priority_order, medical_status_order
                 FROM bi_user_worklist_preferences WHERE tenant_id = ? AND user_id = ? LIMIT 1'
            );
            $stmt->execute([$tenantId, $userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || !$this->asBool($row['preference_enabled'] ?? false)) {
                return $global;
            }

            return $this->normalize([
                'enabled' => true,
                'sort_mode' => $row['sort_mode'] ?? self::SORT_RECENTES,
                'priority_order' => $row['priority_order'] ?? self::PRIORIDADE_URGENCIA,
                'medical_status_order' => $row['medical_status_order'] ?? '',
            ], $isMedical, 'usuario');
        } catch (\Throwable) {
            return $global;
        }
    }

    /** @return array{enabled:bool,source:string,sort_mode:string,priority_order:string,medical_status_order:list<string>} */
    public function globalDefaults(bool $isMedical = true): array
    {
        $stored = SystemConfig::getMany([
            'estudos_worklist_ordem_padrao',
            'estudos_worklist_prioridade_padrao',
            'estudos_worklist_situacoes_medico_padrao',
        ]);

        return $this->normalize([
            'enabled' => true,
            'sort_mode' => $stored['estudos_worklist_ordem_padrao'] ?? self::SORT_RECENTES,
            'priority_order' => $stored['estudos_worklist_prioridade_padrao'] ?? self::PRIORIDADE_URGENCIA,
            'medical_status_order' => $stored['estudos_worklist_situacoes_medico_padrao'] ?? implode(',', self::MEDICAL_STATUS_CODES),
        ], $isMedical, 'global');
    }

    /** @return array{enabled:bool,source:string,sort_mode:string,priority_order:string,medical_status_order:list<string>} */
    public function normalize(array $input, bool $isMedical, string $source = 'usuario'): array
    {
        $sortMode = (string) ($input['sort_mode'] ?? self::SORT_RECENTES);
        if (!in_array($sortMode, [self::SORT_RECENTES, self::SORT_PRIORIDADE, self::SORT_SITUACAO_MEDICA], true)) {
            $sortMode = self::SORT_RECENTES;
        }
        if (!$isMedical && $sortMode === self::SORT_SITUACAO_MEDICA) {
            $sortMode = self::SORT_RECENTES;
        }

        $priorityOrder = (string) ($input['priority_order'] ?? self::PRIORIDADE_URGENCIA);
        if (!in_array($priorityOrder, [self::PRIORIDADE_URGENCIA, self::PRIORIDADE_ROTINA], true)) {
            $priorityOrder = self::PRIORIDADE_URGENCIA;
        }

        return [
            'enabled' => $this->asBool($input['enabled'] ?? $input['preference_enabled'] ?? false),
            'source' => $source,
            'sort_mode' => $sortMode,
            'priority_order' => $priorityOrder,
            'medical_status_order' => $this->normalizeStatusOrder($input['medical_status_order'] ?? []),
        ];
    }

    /** @return list<string> */
    public function normalizeStatusOrder(mixed $value): array
    {
        $raw = is_array($value) ? $value : explode(',', (string) $value);
        $normalized = [];
        foreach ($raw as $status) {
            $status = trim((string) $status);
            if (in_array($status, self::MEDICAL_STATUS_CODES, true) && !in_array($status, $normalized, true)) {
                $normalized[] = $status;
            }
        }
        foreach (self::MEDICAL_STATUS_CODES as $status) {
            if (!in_array($status, $normalized, true)) {
                $normalized[] = $status;
            }
        }
        return $normalized;
    }

    /** @return array{enabled:bool,source:string,sort_mode:string,priority_order:string,medical_status_order:list<string>} */
    public function saveForUser(int $userId, int $tenantId, array $submitted, bool $isMedical, ?int $changedByUserId): array
    {
        $preference = $this->normalize($submitted, $isMedical, 'usuario');
        $pdo = Database::getInstance();
        if (!SqlHelper::hasTable($pdo, 'bi_user_worklist_preferences')) {
            throw new \RuntimeException('As preferências de Worklist ainda não estão disponíveis.');
        }

        $values = [
            $tenantId,
            $userId,
            $preference['enabled'] ? 1 : 0,
            $preference['sort_mode'],
            $preference['priority_order'],
            implode(',', $preference['medical_status_order']),
            $changedByUserId,
        ];
        $sql = SqlHelper::isPostgres()
            ? 'INSERT INTO bi_user_worklist_preferences (tenant_id, user_id, preference_enabled, sort_mode, priority_order, medical_status_order, updated_by_user_id, updated_at) VALUES (?,?,?,?,?,?,?,NOW()) ON CONFLICT (tenant_id, user_id) DO UPDATE SET preference_enabled = EXCLUDED.preference_enabled, sort_mode = EXCLUDED.sort_mode, priority_order = EXCLUDED.priority_order, medical_status_order = EXCLUDED.medical_status_order, updated_by_user_id = EXCLUDED.updated_by_user_id, updated_at = NOW()'
            : 'INSERT INTO bi_user_worklist_preferences (tenant_id, user_id, preference_enabled, sort_mode, priority_order, medical_status_order, updated_by_user_id, updated_at) VALUES (?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE preference_enabled = VALUES(preference_enabled), sort_mode = VALUES(sort_mode), priority_order = VALUES(priority_order), medical_status_order = VALUES(medical_status_order), updated_by_user_id = VALUES(updated_by_user_id), updated_at = NOW()';
        $pdo->prepare($sql)->execute($values);
        return $preference;
    }

    /** @return array<string,string> */
    public function globalValues(array $submitted): array
    {
        $preference = $this->normalize($submitted, true, 'global');
        return [
            'estudos_worklist_ordem_padrao' => $preference['sort_mode'],
            'estudos_worklist_prioridade_padrao' => $preference['priority_order'],
            'estudos_worklist_situacoes_medico_padrao' => implode(',', $preference['medical_status_order']),
        ];
    }

    /** Retorna somente SQL estático baseado em opções previamente normalizadas. */
    public function orderBySql(array $preference, bool $isMedical): string
    {
        $priority = ($preference['priority_order'] ?? '') === self::PRIORIDADE_ROTINA
            ? "CASE COALESCE(e.prioridade, 'normal') WHEN 'normal' THEN 0 WHEN 'urgente' THEN 1 WHEN 'critico' THEN 2 ELSE 3 END ASC"
            : "CASE COALESCE(e.prioridade, 'normal') WHEN 'critico' THEN 0 WHEN 'urgente' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END ASC";

        if (($preference['sort_mode'] ?? '') === self::SORT_PRIORIDADE) {
            return "{$priority}, e.study_date DESC, e.study_time DESC";
        }

        if ($isMedical && ($preference['sort_mode'] ?? '') === self::SORT_SITUACAO_MEDICA) {
            $parts = [];
            foreach ($this->normalizeStatusOrder($preference['medical_status_order'] ?? []) as $index => $status) {
                $parts[] = "WHEN '{$status}' THEN {$index}";
            }
            $statusSql = "CASE COALESCE(e.situacao, 'novo') " . implode(' ', $parts) . ' ELSE 99 END ASC';
            return "{$statusSql}, {$priority}, e.study_date DESC, e.study_time DESC";
        }

        return 'e.study_date DESC, e.study_time DESC';
    }

    private function asBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }
}
