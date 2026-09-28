<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Leitura centralizada das flags operacionais do Report Delivery.
 *
 * A classe não persiste configuração, não acessa banco e não conhece segredos.
 * O bootstrap já carrega os arquivos de ambiente aprovados antes de os
 * consumidores consultarem estas flags.
 */
final class ReportDeliveryRuntimeConfig
{
    public const HUB_ENABLED = 'VOXEL_REPORT_DELIVERY_HUB_ENABLED';
    public const REQUESTS_ENABLED = 'VOXEL_REPORT_DELIVERY_REQUESTS_ENABLED';
    public const PHILIPS_FOLDER_ENABLED = 'PHILIPS_FOLDER_DELIVERY_ENABLED';
    public const PHILIPS_NON_DICOM_ENABLED = 'PHILIPS_NON_DICOM_DELIVERY_ENABLED';
    public const PHILIPS_NON_DICOM_SMB_TEST_ENABLED = 'PHILIPS_NON_DICOM_SMB_TEST_ENABLED';
    public const PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED = 'PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED';
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

    /**
     * Valor inválido bloqueia o Worker, preservando fail-closed em emergência.
     */
    public static function workerKillSwitchEnabled(): bool
    {
        return self::flag(self::WORKER_KILL_SWITCH, true);
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

    private static function rawValue(string $name): ?string
    {
        if (array_key_exists($name, $_ENV) && $_ENV[$name] !== null && $_ENV[$name] !== '') {
            return (string) $_ENV[$name];
        }
        if (array_key_exists($name, $_SERVER) && $_SERVER[$name] !== null && $_SERVER[$name] !== '') {
            return (string) $_SERVER[$name];
        }
        $value = getenv($name);
        return $value === false ? null : (string) $value;
    }
}
