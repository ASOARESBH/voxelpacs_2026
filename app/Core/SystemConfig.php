<?php

namespace App\Core;

/** Configurações globais administrativas, sem escopo de empresa ou dado clínico. */
final class SystemConfig
{
    /** @return array<string, string> */
    public static function getMany(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys, static fn ($key): bool => is_string($key) && $key !== '')));
        if ($keys === []) {
            return [];
        }

        try {
            $pdo = Database::getInstance();
            if (!SqlHelper::hasTable($pdo, 'bi_system_config')) {
                return [];
            }
            $placeholders = implode(', ', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare("SELECT config_key, config_value FROM bi_system_config WHERE config_key IN ({$placeholders})");
            $stmt->execute($keys);

            $values = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $values[(string) $row['config_key']] = (string) $row['config_value'];
            }
            return $values;
        } catch (\Throwable $e) {
            Logger::warning('[SystemConfig] Falha ao ler configuração global');
            return [];
        }
    }

    /** @param array<string, string> $values */
    public static function setMany(array $values, ?int $updatedByUserId): void
    {
        if ($values === []) {
            return;
        }

        $pdo = Database::getInstance();
        if (!SqlHelper::hasTable($pdo, 'bi_system_config')) {
            throw new \RuntimeException('A tabela de configuração global não está disponível.');
        }

        $sql = SqlHelper::isPostgres()
            ? 'INSERT INTO bi_system_config (config_key, config_value, updated_by_user_id, updated_at) VALUES (?, ?, ?, NOW()) ON CONFLICT (config_key) DO UPDATE SET config_value = EXCLUDED.config_value, updated_by_user_id = EXCLUDED.updated_by_user_id, updated_at = NOW()'
            : 'INSERT INTO bi_system_config (config_key, config_value, updated_by_user_id, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_by_user_id = VALUES(updated_by_user_id), updated_at = NOW()';
        $statement = $pdo->prepare($sql);

        foreach ($values as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $statement->execute([$key, (string) $value, $updatedByUserId]);
        }
    }
}
