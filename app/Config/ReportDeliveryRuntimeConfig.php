<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Leitura centralizada das flags operacionais do Report Delivery.
 *
 * A classe não persiste configuração, não acessa banco e não conhece segredos.
 * Para eliminar divergência entre HTTP, PHP-FPM e Worker, as flags gerenciadas
 * são lidas primeiro do .env canônico da raiz do runtime (BASE_PATH/.env).
 * Se a fonte canônica existir e uma chave estiver ausente, o resultado é OFF;
 * isso impede que um EnvironmentFile secundário arme processamento por acidente.
 */
final class ReportDeliveryRuntimeConfig
{
    public const HUB_ENABLED = 'VOXEL_REPORT_DELIVERY_HUB_ENABLED';
    public const REQUESTS_ENABLED = 'VOXEL_REPORT_DELIVERY_REQUESTS_ENABLED';
    public const PHILIPS_FOLDER_ENABLED = 'PHILIPS_FOLDER_DELIVERY_ENABLED';
    public const PHILIPS_NON_DICOM_ENABLED = 'PHILIPS_NON_DICOM_DELIVERY_ENABLED';
    public const PHILIPS_NON_DICOM_SMB_TEST_ENABLED = 'PHILIPS_NON_DICOM_SMB_TEST_ENABLED';
    public const PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED = 'PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED';
    public const PHILIPS_AUTHOR_FALLBACK_ENABLED = 'PHILIPS_AUTHOR_FALLBACK_ENABLED';
    public const PHILIPS_AUTHOR_FALLBACK_FAMILY = 'PHILIPS_AUTHOR_FALLBACK_FAMILY';
    public const PHILIPS_AUTHOR_FALLBACK_GIVEN = 'PHILIPS_AUTHOR_FALLBACK_GIVEN';
    public const PHILIPS_AUTHOR_FALLBACK_MIDDLE = 'PHILIPS_AUTHOR_FALLBACK_MIDDLE';
    public const WORKER_KILL_SWITCH = 'VOXEL_REPORT_DELIVERY_WORKER_KILL_SWITCH';

    public static function hubEnabled(): bool
    {
        return self::flag(self::HUB_ENABLED);
    }

    public static function requestsEnabled(): bool
    {
        return self::flag(self::REQUESTS_ENABLED);
    }

    public static function folderDeliveryEnabled(): bool
    {
        return self::flag(self::PHILIPS_FOLDER_ENABLED);
    }

    public static function nonDicomDeliveryEnabled(): bool
    {
        return self::flag(self::PHILIPS_NON_DICOM_ENABLED);
    }

    public static function smbTestEnabled(): bool
    {
        return self::flag(self::PHILIPS_NON_DICOM_SMB_TEST_ENABLED);
    }

    public static function smbReadOnlyTestEnabled(): bool
    {
        return self::flag(self::PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED);
    }

    public static function philipsAuthorFallbackEnabled(): bool
    {
        return self::flag(self::PHILIPS_AUTHOR_FALLBACK_ENABLED);
    }

    public static function philipsAuthorFallbackFamily(): string
    {
        return self::text(self::PHILIPS_AUTHOR_FALLBACK_FAMILY, 'VOXEL');
    }

    public static function philipsAuthorFallbackGiven(): string
    {
        return self::text(self::PHILIPS_AUTHOR_FALLBACK_GIVEN, 'AUTHOR_MISSING');
    }

    public static function philipsAuthorFallbackMiddle(): string
    {
        return self::text(self::PHILIPS_AUTHOR_FALLBACK_MIDDLE, '');
    }

    /**
     * Valor inválido bloqueia o Worker, preservando fail-closed em emergência.
     */
    public static function workerKillSwitchEnabled(): bool
    {
        return self::flag(self::WORKER_KILL_SWITCH, true);
    }

    /**
     * Expõe somente o caminho lógico para diagnósticos; não lê nem retorna conteúdo.
     */
    public static function canonicalEnvironmentFile(): string
    {
        if (defined('BASE_PATH')) {
            return BASE_PATH . '/.env';
        }

        return dirname(__DIR__, 2) . '/.env';
    }

    private static function flag(string $name, bool $invalidValueDefault = false): bool
    {
        $raw = self::rawValue($name);
        if ($raw === null) {
            return false;
        }

        return match (strtolower(trim($raw))) {
            '1', 'true', 'yes', 'on' => true,
            '', '0', 'false', 'no', 'off' => false,
            default => $invalidValueDefault,
        };
    }

    private static function text(string $name, string $default): string
    {
        $value = self::rawValue($name);
        return $value === null ? $default : trim($value);
    }

    private static function rawValue(string $name): ?string
    {
        $canonicalFile = self::canonicalEnvironmentFile();
        if (is_readable($canonicalFile)) {
            $canonicalValues = self::readCanonicalValues($canonicalFile);
            return array_key_exists($name, $canonicalValues)
                ? $canonicalValues[$name]
                : null;
        }

        // Fallback somente para ambientes de desenvolvimento sem o arquivo canônico.
        if (array_key_exists($name, $_ENV) && $_ENV[$name] !== null && $_ENV[$name] !== '') {
            return (string) $_ENV[$name];
        }
        if (array_key_exists($name, $_SERVER) && $_SERVER[$name] !== null && $_SERVER[$name] !== '') {
            return (string) $_SERVER[$name];
        }
        $value = getenv($name);
        return $value === false ? null : (string) $value;
    }

    /** @return array<string,string> */
    private static function readCanonicalValues(string $path): array
    {
        $values = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return $values;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $key = trim($parts[0]);
            if ($key === '' || array_key_exists($key, $values)) {
                continue;
            }
            $value = trim($parts[1]);
            if (preg_match('/^"(.*)"$/s', $value, $match) === 1) {
                $value = $match[1];
            } elseif (preg_match("/^'(.*)'$/s", $value, $match) === 1) {
                $value = $match[1];
            }
            $values[$key] = $value;
        }

        return $values;
    }
}
