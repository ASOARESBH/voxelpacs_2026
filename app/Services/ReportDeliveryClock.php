<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Relógio de calendário do Report Delivery.
 *
 * A data de elegibilidade deve usar o mesmo calendário do banco que grava
 * automatic_dispatch_date; nunca derive essa data do timezone local do PHP.
 */
final class ReportDeliveryClock
{
    public static function currentDatabaseDate(PDO $pdo): string
    {
        $value = $pdo->query('SELECT CURRENT_DATE')->fetchColumn();
        $date = trim((string) $value);
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            throw new RuntimeException('Data corrente do banco inválida para elegibilidade.');
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
            throw new RuntimeException('Data corrente do banco inválida para elegibilidade.');
        }

        return $date;
    }
}
