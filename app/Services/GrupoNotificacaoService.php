<?php

namespace App\Services;

use App\Core\Logger;
use App\Repositories\GrupoNotificacaoRepository;

/** Regras de validação e configuração de notificações e modalidades por grupo. */
final class GrupoNotificacaoService
{
    public const PRIORITIES = ['STAT', 'HIGH', 'ROUTINE', 'MEDIUM', 'LOW'];

    private GrupoNotificacaoRepository $repo;

    public function __construct(?GrupoNotificacaoRepository $repo = null)
    {
        $this->repo = $repo ?? new GrupoNotificacaoRepository();
    }

    public function pageData(int $tenantId, ?int $selectedGroupId = null): array
    {
        $groups = $this->repo->listGroupsWithConfig($tenantId);
        $selected = $selectedGroupId ? $this->repo->findGroup($selectedGroupId, $tenantId) : null;
        if (!$selected && $groups !== []) {
            $selected = $this->repo->findGroup((int) $groups[0]['id'], $tenantId);
        }
        $policy = [
            'ativo' => false, 'canal_email' => true, 'canal_whatsapp' => false, 'canal_telegram' => false,
            'prioridades' => [], 'modalidades_notificacao' => [], 'modalidades_worklist' => [],
        ];
        if ($selected) {
            $policy = array_merge($policy, $this->repo->findConfig((int) $selected['id'], $tenantId));
            $policy['prioridades'] = $this->repo->listPriorities((int) $selected['id'], $tenantId);
            $policy['modalidades_notificacao'] = $this->repo->listModalities((int) $selected['id'], $tenantId, 'notificacao');
            $policy['modalidades_worklist'] = $this->repo->listModalities((int) $selected['id'], $tenantId, 'worklist');
        }
        return [
            'groups' => $groups,
            'selected_group' => $selected,
            'policy' => $policy,
            'modalities' => $this->repo->listAvailableModalities($tenantId),
            'priorities' => self::PRIORITIES,
        ];
    }

    public function save(int $groupId, int $tenantId, array $input): array
    {
        $group = $this->repo->findGroup($groupId, $tenantId);
        if (!$group) return ['ok' => false, 'error' => 'grupo_invalido'];

        $priorities = $this->normalizePriorities($input['prioridades'] ?? []);
        $notificationModalities = $this->normalizeModalities($input['modalidades_notificacao'] ?? []);
        $worklistModalities = $this->normalizeModalities($input['modalidades_worklist'] ?? []);
        $config = [
            'ativo' => !empty($input['ativo']),
            'canal_email' => !empty($input['canal_email']),
            'canal_whatsapp' => !empty($input['canal_whatsapp']),
            'canal_telegram' => !empty($input['canal_telegram']),
        ];
        if ($config['ativo'] && !$config['canal_email'] && !$config['canal_whatsapp'] && !$config['canal_telegram']) {
            return ['ok' => false, 'error' => 'canal_obrigatorio'];
        }
        if ($config['ativo'] && $priorities === []) {
            return ['ok' => false, 'error' => 'prioridade_obrigatoria'];
        }

        $pdo = $this->repo->pdo();
        try {
            $pdo->beginTransaction();
            $this->repo->savePolicy($groupId, $tenantId, $config, $priorities, $notificationModalities, $worklistModalities);
            $pdo->commit();
            return ['ok' => true];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Logger::error('[GrupoNotificacaoService::save] falha', [
                'tenant_id' => $tenantId, 'grupo_id' => $groupId, 'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'persistencia_falhou'];
        }
    }

    private function normalizePriorities(mixed $values): array
    {
        $result = [];
        foreach ((array) $values as $value) {
            $value = strtoupper(trim((string) $value));
            if (in_array($value, self::PRIORITIES, true)) $result[$value] = true;
        }
        return array_keys($result);
    }

    private function normalizeModalities(mixed $values): array
    {
        $result = [];
        foreach ((array) $values as $value) {
            $value = strtoupper(trim((string) $value));
            if (preg_match('/^[A-Z0-9]{1,16}$/', $value)) $result[$value] = true;
        }
        $modalities = array_keys($result);
        sort($modalities);
        return $modalities;
    }
}
