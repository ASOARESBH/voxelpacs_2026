<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Relógio de calendário do Report Delivery.
 *
 * A data de elegibilidade é uma data clínica em America/Sao_Paulo. Ela é
 * calculada no banco para que o Worker, o runOne e a expiração compartilhem
 * exatamente o mesmo calendário, sem depender do timezone da sessão PHP ou do
 * timezone padrão do servidor de banco.
 */
final class ReportDeliveryClock
{
    public const CLINICAL_TIMEZONE = 'America/Sao_Paulo';

    public static function currentClinicalDate(PDO $pdo): string
    {
        $sql = \App\Core\SqlHelper::isPostgres()
            ? "SELECT (CURRENT_TIMESTAMP AT TIME ZONE 'America/Sao_Paulo')::date"
            : "SELECT DATE(CONVERT_TZ(UTC_TIMESTAMP(), 'UTC', 'America/Sao_Paulo'))";
        $value = $pdo->query($sql)->fetchColumn();
        $date = trim((string) $value);
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            throw new RuntimeException('Data clínica inválida para elegibilidade.');
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
            throw new RuntimeException('Data clínica inválida para elegibilidade.');
        }

        return $date;
    }

    /**
     * @deprecated Use currentClinicalDate(). Mantido para compatibilidade de
     * integrações internas durante a migração do nome do contrato.
     */
    public static function currentDatabaseDate(PDO $pdo): string
    {
        return self::currentClinicalDate($pdo);
    }
}
