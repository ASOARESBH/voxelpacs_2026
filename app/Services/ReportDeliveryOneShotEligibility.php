<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class ReportDeliveryOneShotEligibility
{
    /**
     * @return list<int>
     */
    public static function parseExplicitIds(string $raw): array
    {
        if ($raw === '' || preg_match('/\A[1-9][0-9]*(?:,[1-9][0-9]*)*\z/', $raw) !== 1) {
            throw new InvalidArgumentException('invalid_allow_other_eligible');
        }

        $ids = array_map(static fn(string $value): int => (int) $value, explode(',', $raw));
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('duplicate_allow_other_eligible');
        }

        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * @param list<int> $actualIds
     * @param list<int> $expectedIds
     */
    public static function exactMatch(array $actualIds, array $expectedIds): bool
    {
        $actual = array_map(static fn(int $id): int => $id, $actualIds);
        $expected = array_map(static fn(int $id): int => $id, $expectedIds);
        sort($actual, SORT_NUMERIC);
        sort($expected, SORT_NUMERIC);

        return $actual === $expected;
    }
}
