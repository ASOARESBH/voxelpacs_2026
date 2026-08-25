<?php

namespace App\Services;

use App\Core\SqlHelper;
use App\Repositories\GrupoNotificacaoRepository;

/** Resolve, no servidor, o escopo efetivo de modalidades permitido a um usuário. */
final class GrupoModalidadeService
{
    private GrupoNotificacaoRepository $repo;

    public function __construct(?GrupoNotificacaoRepository $repo = null)
    {
        $this->repo = $repo ?? new GrupoNotificacaoRepository();
    }

    /** @return array{restricted: bool, modalities: array<int, string>} */
    public function scopeForUser(int $userId, int $tenantId): array
    {
        $modalities = $this->repo->listWorklistModalitiesForUser($userId, $tenantId);
        return ['restricted' => $modalities !== [], 'modalities' => $modalities];
    }

    /** Acrescenta um predicado parametrizado para estudos multimodais. */
    public function appendStudyScope(array &$where, array &$params, array $scope, string $column = 'e.modalities'): void
    {
        if (empty($scope['restricted']) || empty($scope['modalities'])) return;
        $codes = [];
        foreach ($scope['modalities'] as $modality) {
            $modality = strtoupper(trim((string) $modality));
            if (preg_match('/^[A-Z0-9]{1,16}$/', $modality)) $codes[] = $modality;
        }
        if ($codes === []) {
            $where[] = '1=0';
            return;
        }
        $pattern = '(^|\\\\)(' . implode('|', $codes) . ')(\\\\|$)';
        $where[] = SqlHelper::isPostgres() ? "{$column} ~ ?" : "{$column} REGEXP ?";
        $params[] = $pattern;
    }

    /** Confere o escopo já resolvido contra ModalitiesInStudy sem consultar dados clínicos adicionais. */
    public function allowsStoredModalities(string $stored, array $scope): bool
    {
        if (empty($scope['restricted'])) return true;
        $allowed = [];
        foreach ((array) ($scope['modalities'] ?? []) as $modality) {
            $modality = strtoupper(trim((string) $modality));
            if (preg_match('/^[A-Z0-9]{1,16}$/', $modality)) $allowed[$modality] = true;
        }
        if ($allowed === []) return false;
        foreach (explode('\\', $stored) as $modality) {
            if (isset($allowed[strtoupper(trim($modality))])) return true;
        }
        return false;
    }
}
