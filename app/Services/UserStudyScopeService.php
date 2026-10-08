<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\SqlHelper;
use PDO;

/**
 * Política individual de visibilidade de estudos por instituição e modalidade.
 *
 * A ausência da migration/configuração mantém o comportamento legado. Quando a
 * política está ativa, ela é uma allowlist por InstitutionName; '*' significa
 * todas as modalidades daquela instituição. O escopo de grupo continua sendo
 * aplicado em conjunto pelos chamadores existentes.
 */
final class UserStudyScopeService
{
    public const ALL_MODALITIES = '*';

    private PDO $pdo;
    private ?bool $storeAvailable = null;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    public function storeAvailable(): bool
    {
        if ($this->storeAvailable !== null) {
            return $this->storeAvailable;
        }

        try {
            $this->storeAvailable = SqlHelper::hasTable($this->pdo, 'bi_user_study_scope_configs')
                && SqlHelper::hasTable($this->pdo, 'bi_user_study_scope_rules');
        } catch (\Throwable $e) {
            $this->storeAvailable = false;
        }

        return $this->storeAvailable;
    }

    /** @return string[] */
    public function listModalities(int $tenantId): array
    {
        if ($tenantId <= 0) {
            return [];
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT modalities
                 FROM bi_pacs_estudos
                 WHERE tenant_id = ?
                   AND modalities IS NOT NULL
                   AND TRIM(modalities) <> ''"
            );
            $stmt->execute([$tenantId]);
            $found = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $stored) {
                foreach (explode('\\', (string) $stored) as $modality) {
                    $modality = strtoupper(trim($modality));
                    if (preg_match('/^[A-Z0-9]{1,16}$/', $modality)) {
                        $found[$modality] = true;
                    }
                }
            }
            $modalities = array_keys($found);
            sort($modalities, SORT_STRING);
            return $modalities;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Estrutura pronta para a view de criação/edição de usuário.
     * @return array{available:bool,configured:bool,enabled:bool,legacy:bool,migration_pending:bool,institutions:array<int,array<string,mixed>>,modalities:string[]}
     */
    public function formData(int $userId, int $tenantId): array
    {
        $available = $this->storeAvailable();
        $modalities = $this->listModalities($tenantId);
        $policy = $available ? $this->policyForUser($userId, $tenantId) : [
            'configured' => false,
            'enabled' => false,
            'rules' => [],
        ];

        $institutions = InstitutionResolverService::getInstitutionNamesByTenant($tenantId);
        $legacyUnits = $this->legacyUnitsForUser($userId, $tenantId);
        $names = [];
        foreach (array_merge($institutions, $legacyUnits) as $name) {
            $name = trim((string) $name);
            if ($name === '') continue;
            $names[InstitutionResolverService::normalize($name)] = $name;
        }
        $names = array_values($names);
        usort($names, static fn(string $left, string $right): int => strcasecmp($left, $right));

        $rulesByInstitution = [];
        foreach ($policy['rules'] as $rule) {
            $name = trim((string) ($rule['institution_name'] ?? ''));
            if ($name === '') continue;
            $key = InstitutionResolverService::normalize($name);
            $rulesByInstitution[$key][] = strtoupper(trim((string) ($rule['modalidade'] ?? '')));
        }
        $legacyKeys = [];
        foreach ($legacyUnits as $name) {
            $legacyKeys[InstitutionResolverService::normalize((string) $name)] = true;
        }

        $rows = [];
        foreach ($names as $name) {
            $key = InstitutionResolverService::normalize($name);
            $selected = $rulesByInstitution[$key] ?? [];
            $hasAll = in_array(self::ALL_MODALITIES, $selected, true);
            $selectedCodes = array_values(array_unique(array_filter(
                $selected,
                static fn(string $modality): bool => $modality !== self::ALL_MODALITIES
            )));

            $legacy = !$policy['configured'] && isset($legacyKeys[$key]);
            if ($legacy) {
                $hasAll = true;
                $selectedCodes = $modalities;
            }

            $rows[] = [
                'name' => $name,
                'selected' => $hasAll || $selectedCodes !== [],
                'all' => $hasAll,
                'modalities' => $selectedCodes,
                'legacy' => $legacy,
            ];
        }

        return [
            'available' => $available,
            'configured' => (bool) $policy['configured'],
            'enabled' => (bool) $policy['enabled'],
            'legacy' => !$policy['configured'] && $legacyUnits !== [],
            'migration_pending' => !$available,
            'institutions' => $rows,
            'modalities' => $modalities,
        ];
    }

    /** Mescla somente o estado visual enviado, sem substituir a validação do saveForUser(). */
    public function formDataWithInput(int $userId, int $tenantId, array $input): array
    {
        $data = $this->formData($userId, $tenantId);
        if (!$data['available'] || !array_key_exists('study_scope', $input)) return $data;

        $scope = (array) ($input['study_scope'] ?? []);
        $submitted = [];
        foreach ((array) ($scope['institutions'] ?? []) as $entry) {
            if (!is_array($entry)) continue;
            $key = InstitutionResolverService::normalize((string) ($entry['name'] ?? ''));
            if ($key === '') continue;
            $submitted[$key] = [
                'enabled' => !empty($entry['enabled']),
                'all' => !empty($entry['all']),
                'modalities' => array_values(array_unique(array_map(
                    static fn($value): string => strtoupper(trim((string) $value)),
                    (array) ($entry['modalities'] ?? [])
                ))),
            ];
        }

        foreach ($data['institutions'] as &$institution) {
            $key = InstitutionResolverService::normalize((string) ($institution['name'] ?? ''));
            if (!isset($submitted[$key])) {
                $institution['selected'] = false;
                $institution['all'] = false;
                $institution['modalities'] = [];
                continue;
            }
            $entry = $submitted[$key];
            $institution['selected'] = $entry['enabled'];
            $institution['all'] = $entry['enabled'] && $entry['all'];
            $institution['modalities'] = $entry['enabled'] && !$entry['all']
                ? array_values(array_intersect($entry['modalities'], $data['modalities']))
                : [];
            $institution['legacy'] = false;
        }
        unset($institution);
        $data['configured'] = true;
        $data['enabled'] = !empty($scope['enabled']);
        $data['legacy'] = false;
        return $data;
    }

    /**
     * @return array{configured:bool,enabled:bool,rules:array<int,array{institution_name:string,modalidade:string}>}
     */
    public function policyForUser(int $userId, int $tenantId): array
    {
        $empty = ['configured' => false, 'enabled' => false, 'rules' => []];
        if (!$this->storeAvailable() || $userId <= 0 || $tenantId <= 0) {
            return $empty;
        }

        try {
            $configStmt = $this->pdo->prepare(
                'SELECT ativo FROM bi_user_study_scope_configs WHERE tenant_id = ? AND user_id = ? LIMIT 1'
            );
            $configStmt->execute([$tenantId, $userId]);
            $config = $configStmt->fetchColumn();
            if ($config === false) {
                return $empty;
            }

            $rulesStmt = $this->pdo->prepare(
                'SELECT institution_name, modalidade
                 FROM bi_user_study_scope_rules
                 WHERE tenant_id = ? AND user_id = ?
                 ORDER BY institution_name, modalidade'
            );
            $rulesStmt->execute([$tenantId, $userId]);
            $rules = [];
            foreach ($rulesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rule) {
                $rules[] = [
                    'institution_name' => (string) ($rule['institution_name'] ?? ''),
                    'modalidade' => strtoupper((string) ($rule['modalidade'] ?? '')),
                ];
            }

            return [
                'configured' => true,
                'enabled' => $this->databaseBoolean($config),
                'rules' => $rules,
            ];
        } catch (\Throwable $e) {
            // Falha fechada somente para uma política que já está configurada;
            // ausência de tabelas é tratada como comportamento legado acima.
            return $empty;
        }
    }

    /** @param string[] $fallback */
    public function allowedInstitutionNamesForUser(int $userId, int $tenantId, array $fallback): array
    {
        if ($userId <= 0 || $tenantId <= 0) return $fallback;
        $policy = $this->policyForUser($userId, $tenantId);
        if (!$policy['configured'] || !$policy['enabled']) return $fallback;

        $names = [];
        foreach ($policy['rules'] as $rule) {
            $name = trim((string) ($rule['institution_name'] ?? ''));
            if ($name !== '') $names[InstitutionResolverService::normalize($name)] = $name;
        }
        $names = array_values($names);
        sort($names, SORT_STRING);
        return $names;
    }

    /** @param string[] $fallback */
    public function allowedModalitiesForUser(int $userId, int $tenantId, array $fallback): array
    {
        if ($userId <= 0 || $tenantId <= 0) return $fallback;
        $policy = $this->policyForUser($userId, $tenantId);
        if (!$policy['configured'] || !$policy['enabled']) return $fallback;

        $allowed = [];
        foreach ($policy['rules'] as $rule) {
            $modality = strtoupper(trim((string) ($rule['modalidade'] ?? '')));
            if ($modality === self::ALL_MODALITIES) return $fallback;
            if ($modality !== '') $allowed[$modality] = true;
        }
        return array_values(array_filter($fallback, static function ($modality) use ($allowed): bool {
            return isset($allowed[strtoupper(trim((string) $modality))]);
        }));
    }

    /**
     * Persiste a política enviada pelo formulário. Retorna erro de validação sem
     * alterar nada quando a migration está presente e o payload é inválido.
     * @return array{ok:bool,available:bool,changed:bool,error?:string}
     */
    public function saveForUser(
        int $userId,
        int $tenantId,
        ?array $input,
        string $targetProfile,
        ?int $updatedByUserId = null
    ): array {
        if ($input === null || !array_key_exists('study_scope', $input)) {
            return ['ok' => true, 'available' => $this->storeAvailable(), 'changed' => false];
        }
        if (!$this->storeAvailable()) {
            return ['ok' => true, 'available' => false, 'changed' => false];
        }
        if ($userId <= 0 || $tenantId <= 0) {
            return ['ok' => false, 'available' => true, 'changed' => false, 'error' => 'invalid_target'];
        }
        // A política é independente do perfil: o RBAC continua governando
        // módulos e ações, enquanto esta allowlist governa estudos visíveis.

        $scope = (array) ($input['study_scope'] ?? []);
        $enabled = !empty($scope['enabled']);
        if (!$enabled) {
            $ownsTransaction = !$this->pdo->inTransaction();
            try {
                if ($ownsTransaction) $this->pdo->beginTransaction();
                $this->upsertConfig($tenantId, $userId, false, $updatedByUserId);
                if ($ownsTransaction) $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $e;
            }
            return ['ok' => true, 'available' => true, 'changed' => true];
        }

        $availableInstitutions = array_merge(
            InstitutionResolverService::getInstitutionNamesByTenant($tenantId),
            $this->legacyUnitsForUser($userId, $tenantId)
        );
        $canonicalByNormalized = [];
        foreach ($availableInstitutions as $institution) {
            $institution = trim((string) $institution);
            if ($institution !== '') {
                $canonicalByNormalized[InstitutionResolverService::normalize($institution)] = $institution;
            }
        }
        $availableModalities = array_fill_keys($this->listModalities($tenantId), true);
        $submittedInstitutions = (array) ($scope['institutions'] ?? []);
        $newRules = [];
        $seenInstitutions = [];

        foreach ($submittedInstitutions as $entry) {
            if (!is_array($entry)) continue;
            $candidate = trim((string) ($entry['name'] ?? ''));
            if ($candidate === '') continue;
            $normalized = InstitutionResolverService::normalize($candidate);
            $canonical = $canonicalByNormalized[$normalized] ?? null;
            if ($canonical === null) {
                return ['ok' => false, 'available' => true, 'changed' => false, 'error' => 'invalid_institution'];
            }
            if (isset($seenInstitutions[$normalized])) continue;
            $seenInstitutions[$normalized] = true;
            if (empty($entry['enabled'])) continue;

            if (!empty($entry['all'])) {
                $newRules[] = ['institution_name' => $canonical, 'modalidade' => self::ALL_MODALITIES];
                continue;
            }

            $modalities = array_values(array_unique(array_map(
                static fn($value): string => strtoupper(trim((string) $value)),
                (array) ($entry['modalities'] ?? [])
            )));
            $modalities = array_values(array_filter($modalities, static fn(string $modality): bool => $modality !== ''));
            if ($modalities === []) {
                return ['ok' => false, 'available' => true, 'changed' => false, 'error' => 'empty_modalities'];
            }
            foreach ($modalities as $modality) {
                if (!preg_match('/^[A-Z0-9]{1,16}$/', $modality) || !isset($availableModalities[$modality])) {
                    return ['ok' => false, 'available' => true, 'changed' => false, 'error' => 'invalid_modality'];
                }
                $newRules[] = ['institution_name' => $canonical, 'modalidade' => $modality];
            }
        }

        if ($newRules === []) {
            return ['ok' => false, 'available' => true, 'changed' => false, 'error' => 'empty_scope'];
        }

        // Regras antigas para InstitutionNames que deixaram de estar no cadastro
        // oficial não são apagadas silenciosamente durante uma edição legítima.
        $currentPolicy = $this->policyForUser($userId, $tenantId);
        foreach ($currentPolicy['rules'] as $oldRule) {
            $oldName = trim((string) ($oldRule['institution_name'] ?? ''));
            $oldKey = InstitutionResolverService::normalize($oldName);
            if ($oldName !== '' && !isset($canonicalByNormalized[$oldKey])) {
                $newRules[] = [
                    'institution_name' => $oldName,
                    'modalidade' => strtoupper((string) ($oldRule['modalidade'] ?? '')),
                ];
            }
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        try {
            if ($ownsTransaction) $this->pdo->beginTransaction();
            $this->upsertConfig($tenantId, $userId, true, $updatedByUserId);
            $this->pdo->prepare(
                'DELETE FROM bi_user_study_scope_rules WHERE tenant_id = ? AND user_id = ?'
            )->execute([$tenantId, $userId]);
            $this->insertRules($tenantId, $userId, $newRules);
            $this->syncLegacyDoctorUnits($userId, $tenantId, $newRules);
            if ($ownsTransaction) $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'available' => true, 'changed' => true];
    }

    /** Acrescenta o filtro individual à query de estudos da Worklist. */
    public function appendStudyScope(array &$where, array &$params, int $userId, int $tenantId, string $column = 'e.modalities', string $institutionColumn = 'e.institution_name'): void
    {
        if ($userId <= 0 || $tenantId <= 0) {
            return;
        }
        if (!preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $column)
            || !preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $institutionColumn)) {
            return;
        }

        $policy = $this->policyForUser($userId, $tenantId);
        if (!$policy['configured'] || !$policy['enabled']) {
            return;
        }
        $where[] = $this->buildPolicyPredicate($policy['rules'], $params, $column, $institutionColumn);
    }

    /** Variante para repositories que já usam parâmetros nomeados. */
    public function appendStudyScopeNamed(array &$where, array &$params, int $userId, int $tenantId, string $column = 'e.modalities', string $institutionColumn = 'e.institution_name', string $prefix = 'user_scope'): void
    {
        if ($userId <= 0 || $tenantId <= 0) return;
        if (!preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $column)
            || !preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $institutionColumn)
            || !preg_match('/^[a-z][a-z0-9_]*$/', $prefix)) return;

        $policy = $this->policyForUser($userId, $tenantId);
        if (!$policy['configured'] || !$policy['enabled']) return;
        $where[] = $this->buildPolicyPredicateNamed($policy['rules'], $params, $column, $institutionColumn, $prefix);
    }

    /**
     * Segundo gate para endpoints que já carregaram um estudo.
     * @param array<string,mixed>|object $study
     */
    public function allowsStudy(array|object $study, int $userId, int $tenantId): bool
    {
        if ($userId <= 0 || $tenantId <= 0) return false;
        if ($this->isCurrentPlatformAdmin()) return true;

        $studyTenantId = (int) $this->value($study, 'tenant_id', 0);
        if ($studyTenantId > 0 && $studyTenantId !== $tenantId) return false;

        $policy = $this->policyForUser($userId, $tenantId);
        if ($policy['configured'] && $policy['enabled'] && !$this->rulesAllowStudy($policy['rules'], $study)) {
            return false;
        }

        return true;
    }

    /** Atualiza a projeção legada usada pelo motor de SLA, sem apagar a nova fonte. */
    public function syncLegacyDoctorUnits(int $userId, int $tenantId, array $rules): void
    {
        if ($userId <= 0 || $tenantId <= 0) return;
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM bi_medicos WHERE tenant_id = ? AND usuario_id = ? AND ativo = 1 LIMIT 1'
            );
            $stmt->execute([$tenantId, $userId]);
            $medicoId = (int) ($stmt->fetchColumn() ?: 0);
            if ($medicoId <= 0) return;

            $this->pdo->prepare(
                'DELETE FROM bi_medico_unidades WHERE tenant_id = ? AND medico_id = ?'
            )->execute([$tenantId, $medicoId]);

            $names = [];
            foreach ($rules as $rule) {
                $name = trim((string) ($rule['institution_name'] ?? ''));
                if ($name !== '') $names[InstitutionResolverService::normalize($name)] = $name;
            }
            if ($names === []) return;

            $sql = SqlHelper::isPostgres()
                ? 'INSERT INTO bi_medico_unidades (tenant_id, medico_id, institution_name) VALUES (?,?,?) ON CONFLICT DO NOTHING'
                : 'INSERT IGNORE INTO bi_medico_unidades (tenant_id, medico_id, institution_name) VALUES (?,?,?)';
            $insert = $this->pdo->prepare($sql);
            foreach ($names as $name) {
                $insert->execute([$tenantId, $medicoId, $name]);
            }
        } catch (\Throwable $e) {
            // A projeção legada não pode desfazer uma política nova válida.
        }
    }

    /** @param array<int,array{institution_name:string,modalidade:string}> $rules */
    private function buildPolicyPredicate(array $rules, array &$params, string $column, string $institutionColumn): string
    {
        if ($rules === []) return '1=0';

        $byInstitution = [];
        foreach ($rules as $rule) {
            $institution = trim((string) ($rule['institution_name'] ?? ''));
            $modality = strtoupper(trim((string) ($rule['modalidade'] ?? '')));
            if ($institution === '') continue;
            $byInstitution[$institution][] = $modality;
        }
        if ($byInstitution === []) return '1=0';

        $parts = [];
        foreach ($byInstitution as $institution => $modalities) {
            $parts[] = $this->buildInstitutionPredicate($institution, $modalities, $params, $column, $institutionColumn);
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    /** @param array<int,array{institution_name:string,modalidade:string}> $rules */
    private function buildPolicyPredicateNamed(array $rules, array &$params, string $column, string $institutionColumn, string $prefix): string
    {
        if ($rules === []) return '1=0';
        $byInstitution = [];
        foreach ($rules as $rule) {
            $institution = trim((string) ($rule['institution_name'] ?? ''));
            $modality = strtoupper(trim((string) ($rule['modalidade'] ?? '')));
            if ($institution !== '') $byInstitution[$institution][] = $modality;
        }
        if ($byInstitution === []) return '1=0';

        $parts = [];
        $institutionIndex = 0;
        foreach ($byInstitution as $institution => $modalities) {
            $institutionKey = ':' . $prefix . '_inst_' . $institutionIndex++;
            $params[$institutionKey] = $institution;
            $predicate = "({$institutionColumn} = {$institutionKey}";
            if (in_array(self::ALL_MODALITIES, $modalities, true)) {
                $parts[] = SqlHelper::isPostgres()
                    ? $predicate . " AND BTRIM(COALESCE({$column}, '')) <> '')"
                    : $predicate . " AND {$column} IS NOT NULL AND TRIM({$column}) <> '')";
                continue;
            }

            $codes = array_values(array_unique(array_filter(
                array_map(static fn(string $value): string => strtoupper(trim($value)), $modalities),
                static fn(string $value): bool => preg_match('/^[A-Z0-9]{1,16}$/', $value) === 1
            )));
            if ($codes === []) continue;

            if (SqlHelper::isPostgres()) {
                $marks = [];
                foreach ($codes as $codeIndex => $code) {
                    $codeKey = ':' . $prefix . '_mod_' . $institutionIndex . '_' . $codeIndex;
                    $params[$codeKey] = $code;
                    $marks[] = 'CAST(' . $codeKey . ' AS text)';
                }
                $parts[] = $predicate . " AND NOT EXISTS (
                    SELECT 1
                    FROM unnest(regexp_split_to_array(COALESCE({$column}, ''), E'\\\\')) AS modality(code)
                    WHERE UPPER(BTRIM(modality.code)) <> ALL(ARRAY[" . implode(',', $marks) . "])
                ))";
            } else {
                $patternKey = ':' . $prefix . '_pattern_' . $institutionIndex;
                $params[$patternKey] = '(^|\\\\)(' . implode('|', $codes) . ')(\\\\|$)';
                $parts[] = $predicate . " AND {$column} REGEXP {$patternKey})";
            }
        }

        return $parts === [] ? '1=0' : '(' . implode(' OR ', $parts) . ')';
    }

    /** @param string[] $modalities */
    private function buildInstitutionPredicate(string $institution, array $modalities, array &$params, string $column, string $institutionColumn): string
    {
        if (in_array(self::ALL_MODALITIES, $modalities, true)) {
            $params[] = $institution;
            $predicate = "({$institutionColumn} = ?";
            if (SqlHelper::isPostgres()) {
                return $predicate . " AND BTRIM(COALESCE({$column}, '')) <> '')";
            }
            return $predicate . " AND {$column} IS NOT NULL AND TRIM({$column}) <> '')";
        }

        $codes = array_values(array_unique(array_filter(
            array_map(static fn(string $value): string => strtoupper(trim($value)), $modalities),
            static fn(string $value): bool => preg_match('/^[A-Z0-9]{1,16}$/', $value) === 1
        )));
        if ($codes === []) return '1=0';

        $params[] = $institution;
        $predicate = "({$institutionColumn} = ?";

        if (SqlHelper::isPostgres()) {
            $marks = implode(',', array_fill(0, count($codes), '?::text'));
            foreach ($codes as $code) $params[] = $code;
            return $predicate . " AND NOT EXISTS (
                SELECT 1
                FROM unnest(regexp_split_to_array(COALESCE({$column}, ''), E'\\\\')) AS modality(code)
                WHERE UPPER(BTRIM(modality.code)) <> ALL(ARRAY[{$marks}])
            ))";
        }

        // Compatibilidade MySQL: a verificação de detalhe continua sendo
        // all-of; a listagem usa o mesmo predicado de modalidade já existente.
        $marks = [];
        $pattern = '(^|\\\\)(' . implode('|', $codes) . ')(\\\\|$)';
        $marks[] = "{$column} REGEXP ?";
        $params[] = $pattern;
        return $predicate . ' AND (' . implode(' OR ', $marks) . '))';
    }

    /** @param array<int,array{institution_name:string,modalidade:string}> $rules */
    private function rulesAllowStudy(array $rules, array|object $study): bool
    {
        $institution = trim((string) $this->value($study, 'institution_name', ''));
        $stored = trim((string) $this->value($study, 'modalities', ''));
        if ($institution === '' || $stored === '') return false;

        $allowed = [];
        foreach ($rules as $rule) {
            if (InstitutionResolverService::normalize((string) ($rule['institution_name'] ?? '')) !== InstitutionResolverService::normalize($institution)) {
                continue;
            }
            $modality = strtoupper(trim((string) ($rule['modalidade'] ?? '')));
            if ($modality === self::ALL_MODALITIES) return true;
            if (preg_match('/^[A-Z0-9]{1,16}$/', $modality)) $allowed[$modality] = true;
        }
        if ($allowed === []) return false;

        foreach (explode('\\', $stored) as $modality) {
            $modality = strtoupper(trim($modality));
            if ($modality === '' || !isset($allowed[$modality])) return false;
        }
        return true;
    }

    private function upsertConfig(int $tenantId, int $userId, bool $enabled, ?int $updatedByUserId): void
    {
        $sql = SqlHelper::isPostgres()
            ? 'INSERT INTO bi_user_study_scope_configs (tenant_id, user_id, ativo, updated_by_user_id, updated_at)
               VALUES (?,?,?,?,NOW())
               ON CONFLICT (tenant_id, user_id) DO UPDATE SET ativo = EXCLUDED.ativo, updated_by_user_id = EXCLUDED.updated_by_user_id, updated_at = NOW()'
            : 'INSERT INTO bi_user_study_scope_configs (tenant_id, user_id, ativo, updated_by_user_id, updated_at)
               VALUES (?,?,?,?,NOW())
               ON DUPLICATE KEY UPDATE ativo = VALUES(ativo), updated_by_user_id = VALUES(updated_by_user_id), updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(2, $userId, PDO::PARAM_INT);
        $stmt->bindValue(3, $enabled, PDO::PARAM_BOOL);
        if ($updatedByUserId === null) $stmt->bindValue(4, null, PDO::PARAM_NULL);
        else $stmt->bindValue(4, $updatedByUserId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @param array<int,array{institution_name:string,modalidade:string}> $rules */
    private function insertRules(int $tenantId, int $userId, array $rules): void
    {
        $sql = SqlHelper::isPostgres()
            ? 'INSERT INTO bi_user_study_scope_rules (tenant_id, user_id, institution_name, modalidade) VALUES (?,?,?,?) ON CONFLICT DO NOTHING'
            : 'INSERT IGNORE INTO bi_user_study_scope_rules (tenant_id, user_id, institution_name, modalidade) VALUES (?,?,?,?)';
        $stmt = $this->pdo->prepare($sql);
        $seen = [];
        foreach ($rules as $rule) {
            $institution = trim((string) ($rule['institution_name'] ?? ''));
            $modality = strtoupper(trim((string) ($rule['modalidade'] ?? '')));
            if ($institution === '' || ($modality !== self::ALL_MODALITIES && !preg_match('/^[A-Z0-9]{1,16}$/', $modality))) continue;
            $key = InstitutionResolverService::normalize($institution) . "\0" . $modality;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $stmt->execute([$tenantId, $userId, $institution, $modality]);
        }
    }

    /** @return string[] */
    private function legacyUnitsForUser(int $userId, int $tenantId): array
    {
        if ($userId <= 0 || $tenantId <= 0) return [];
        try {
            $stmt = $this->pdo->prepare(
                'SELECT DISTINCT mu.institution_name
                 FROM bi_medico_unidades mu
                 INNER JOIN bi_medicos m ON m.id = mu.medico_id AND m.tenant_id = mu.tenant_id
                 WHERE mu.tenant_id = ? AND m.usuario_id = ? AND m.ativo = 1
                   AND mu.institution_name IS NOT NULL AND TRIM(mu.institution_name) <> ?
                 ORDER BY mu.institution_name'
            );
            $stmt->execute([$tenantId, $userId, '']);
            return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function isCurrentPlatformAdmin(): bool
    {
        return Auth::isPlatformAdmin() && !Auth::isImpersonating();
    }

    private function databaseBoolean(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value) || is_float($value)) return (int) $value === 1;
        return in_array(strtolower(trim((string) $value)), ['1', 't', 'true', 'yes', 'y', 'on'], true);
    }

    private function value(array|object $value, string $key, mixed $default = null): mixed
    {
        if (is_array($value)) return $value[$key] ?? $default;
        return $value->{$key} ?? $default;
    }
}
