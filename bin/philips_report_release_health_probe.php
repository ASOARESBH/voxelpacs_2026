<?php

declare(strict_types=1);

namespace App\Diagnostics {

use App\Core\PostgresPdo;
use PDO;
use Throwable;

final class PhilipsReportReleaseHealthProbe
{
    private const DEFAULT_APP_ROOT = '/var/www/voxelpacs/app';
    private const AUDITED_ACTIONS = ['report.assinar', 'report.liberar'];
    private const SIGNER_FIELDS = [
        'author_signer_id',
        'author_signer_tenant_id',
        'author_signer_report_id',
        'author_signer_report_version',
        'author_signer_study_id',
        'author_signer_name',
    ];

    public function __construct(
        private readonly string $appRoot = self::DEFAULT_APP_ROOT,
        private readonly ?PDO $pdo = null
    ) {
    }

    /** @return array<string,mixed> */
    public function run(string $since, ?string $until = null): array
    {
        $sinceDate = $this->parseSince($since);
        $untilDate = $until === null ? new \DateTimeImmutable('now') : $this->parseSince($until);
        if ($untilDate <= $sinceDate) {
            throw new \InvalidArgumentException('invalid_interval');
        }
        $result = [
            'probe' => 'philips_report_release_health',
            'mode' => 'READ_ONLY',
            'since_utc' => $sinceDate->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'until_utc' => $untilDate->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'database_changed' => 'NO',
            'audit' => [],
            'outbox_jobs' => [],
            'errors' => $this->emptyErrorCounters(),
            'errors_by_tenant' => [],
            'error_source' => 'application_logs_allowlisted_patterns',
        ];

        $pdo = $this->pdo ?? $this->connectReadOnly();
        $pdo->beginTransaction();
        $pdo->exec('SET TRANSACTION READ ONLY');
        try {
            $result['database_timezone'] = (string) $pdo->query('SHOW timezone')->fetchColumn();
            $result['audit'] = $this->auditCounts($pdo, $sinceDate, $untilDate);
            $result['outbox_jobs'] = $this->outboxJobCounts($pdo, $sinceDate, $untilDate);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $logCounts = $this->applicationLogCounts($sinceDate, $untilDate);
        $result['errors'] = $logCounts['errors'];
        $result['errors_by_tenant'] = $logCounts['by_tenant'];
        return $result;
    }

    /** @return array<string,mixed> */
    private function auditCounts(PDO $pdo, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $stmt = $pdo->prepare(
            "SELECT tenant_id, action, details
               FROM bi_audit_logs
              WHERE created_at >= :since
                AND created_at < :until
                AND action IN ('report.assinar', 'report.liberar')
              ORDER BY created_at ASC"
        );
        $stmt->execute([
            'since' => $since->format('Y-m-d H:i:sP'),
            'until' => $until->format('Y-m-d H:i:sP'),
        ]);

        $counts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tenantId = (int) ($row['tenant_id'] ?? 0);
            $action = (string) ($row['action'] ?? '');
            if ($tenantId <= 0 || !in_array($action, self::AUDITED_ACTIONS, true)) {
                continue;
            }
            $counts[$tenantId][$action] = ($counts[$tenantId][$action] ?? 0) + 1;
            $details = json_decode((string) ($row['details'] ?? ''), true);
            if (!is_array($details)) {
                continue;
            }
            $dimension = $action === 'report.assinar' ? 'mode' : 'origin';
            $value = (string) ($details[$dimension === 'mode' ? 'modo_efetivo' : 'origem'] ?? 'UNKNOWN');
            if (!preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $value)) {
                $value = 'UNKNOWN';
            }
            $key = $dimension . '_' . $value;
            $counts[$tenantId][$key] = ($counts[$tenantId][$key] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /** @return array<string,mixed> */
    private function outboxJobCounts(PDO $pdo, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $stmt = $pdo->prepare(
            "SELECT j.tenant_id, j.destination_id, j.status, j.attempt_count, o.payload_json
               FROM pacs_report_delivery_jobs j
               INNER JOIN pacs_report_delivery_outbox o
                       ON o.id = j.outbox_id
                      AND o.tenant_id = j.tenant_id
              WHERE j.created_at >= :since
                AND j.created_at < :until
                AND j.destination_id IN (6, 7)"
        );
        $stmt->execute([
            'since' => $since->format('Y-m-d H:i:sP'),
            'until' => $until->format('Y-m-d H:i:sP'),
        ]);

        $counts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tenantId = (int) ($row['tenant_id'] ?? 0);
            $destinationId = (int) ($row['destination_id'] ?? 0);
            if ($tenantId <= 0 || !in_array($destinationId, [6, 7], true)) {
                continue;
            }
            $bucket =& $counts[$tenantId][$destinationId];
            $bucket['jobs'] = ($bucket['jobs'] ?? 0) + 1;
            $status = (string) ($row['status'] ?? 'UNKNOWN');
            if (!preg_match('/^[a-z_]{1,40}$/', $status)) {
                $status = 'UNKNOWN';
            }
            $bucket['status_' . $status] = ($bucket['status_' . $status] ?? 0) + 1;
            $bucket['attempts_total'] = ($bucket['attempts_total'] ?? 0) + max(0, (int) ($row['attempt_count'] ?? 0));
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $complete = is_array($payload) && $this->hasCompleteSigner($payload);
            $bucket[$complete ? 'author_signer_complete' : 'author_signer_absent_or_incomplete'] = ($bucket[$complete ? 'author_signer_complete' : 'author_signer_absent_or_incomplete'] ?? 0) + 1;
            unset($bucket);
        }
        ksort($counts);
        foreach ($counts as &$destinations) {
            ksort($destinations);
        }
        unset($destinations);
        return $counts;
    }

    /** @param array<string,mixed> $payload */
    private function hasCompleteSigner(array $payload): bool
    {
        foreach (self::SIGNER_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                return false;
            }
        }
        return $this->positive($payload['author_signer_id'])
            && $this->positive($payload['author_signer_tenant_id'])
            && $this->positive($payload['author_signer_report_id'])
            && $this->positive($payload['author_signer_report_version'])
            && $this->positive($payload['author_signer_study_id'])
            && is_string($payload['author_signer_name'])
            && trim($payload['author_signer_name']) !== '';
    }

    private function positive(mixed $value): bool
    {
        return (is_int($value) && $value > 0)
            || (is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value)) === 1);
    }

    /** @return array<string,int> */
    private function emptyErrorCounters(): array
    {
        return [
            'http500_sign' => 0,
            'http500_release' => 0,
            'atomic_rollback_sign' => 0,
            'atomic_rollback_release' => 0,
            'blocked_given' => 0,
            'blocked_accession' => 0,
            'release_compatibility_unavailable' => 0,
        ];
    }

    /** @return array{errors:array<string,int>,by_tenant:array<string,array<string,int>>} */
    private function applicationLogCounts(\DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $counts = $this->emptyErrorCounters();
        $sinceLocal = $since->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:s');
        $untilLocal = $until->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:s');
        $byTenant = [];
        foreach (glob($this->appRoot . '/storage/logs/app-*.log') ?: [] as $file) {
            $handle = @fopen($file, 'rb');
            if (!is_resource($handle)) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $lineTimestamp = substr($line, 1, 19);
                if (strlen($line) < 21 || $lineTimestamp < $sinceLocal || $lineTimestamp >= $untilLocal) {
                    continue;
                }
                $context = $this->logContext($line);
                $tenantId = is_int($context['tenant_id'] ?? null)
                    ? (int) $context['tenant_id']
                    : (is_string($context['tenant_id'] ?? null) && preg_match('/^[1-9][0-9]*$/', (string) $context['tenant_id']) === 1
                        ? (int) $context['tenant_id']
                        : null);
                if (str_contains($line, 'ReportsController::sign error')) {
                    $this->incrementError($counts, $byTenant, 'http500_sign', $tenantId);
                }
                if (str_contains($line, 'ReportsController::liberar error')) {
                    $this->incrementError($counts, $byTenant, 'http500_release', $tenantId);
                }
                if (str_contains($line, '[ReportService::assinar] Persistência atômica falhou')) {
                    $this->incrementError($counts, $byTenant, 'atomic_rollback_sign', $tenantId);
                }
                if (str_contains($line, '[ReportService::liberarAssinado] Persistência atômica falhou')) {
                    $this->incrementError($counts, $byTenant, 'atomic_rollback_release', $tenantId);
                }
                if (str_contains($line, '[ReportDeliveryCompatibility] Liberação bloqueada') && str_contains($line, 'patient_name_given_required')) {
                    $this->incrementError($counts, $byTenant, 'blocked_given', $tenantId);
                }
                if (str_contains($line, '[ReportDeliveryCompatibility] Liberação bloqueada') && str_contains($line, 'accession_number_required')) {
                    $this->incrementError($counts, $byTenant, 'blocked_accession', $tenantId);
                }
                if (str_contains($line, '[ReportDeliveryCompatibility] Não foi possível avaliar a liberação')) {
                    $this->incrementError($counts, $byTenant, 'release_compatibility_unavailable', $tenantId);
                }
            }
            fclose($handle);
        }
        ksort($byTenant);
        foreach ($byTenant as &$tenantCounts) {
            ksort($tenantCounts);
        }
        unset($tenantCounts);
        return ['errors' => $counts, 'by_tenant' => $byTenant];
    }

    /** @param array<string,int> $counts @param array<string,array<string,int>> $byTenant */
    private function incrementError(array &$counts, array &$byTenant, string $category, ?int $tenantId): void
    {
        $counts[$category] = ($counts[$category] ?? 0) + 1;
        $tenantKey = $tenantId !== null && $tenantId > 0 ? (string) $tenantId : 'UNKNOWN';
        $byTenant[$tenantKey][$category] = ($byTenant[$tenantKey][$category] ?? 0) + 1;
    }

    /** @return array<string,mixed> */
    private function logContext(string $line): array
    {
        if (preg_match('/(\{.*\})\s*$/', $line, $matches) !== 1) {
            return [];
        }
        $context = json_decode($matches[1], true);
        return is_array($context) ? $context : [];
    }

    private function parseSince(string $since): \DateTimeImmutable
    {
        try {
            $date = new \DateTimeImmutable($since);
        } catch (Throwable) {
            throw new \InvalidArgumentException('invalid_since');
        }
        return $date;
    }

    private function connectReadOnly(): PDO
    {
        $env = $this->loadEnvironment();
        $driver = strtolower($env['DB_DRIVER'] ?? 'pgsql');
        if ($driver !== 'pgsql') {
            throw new \RuntimeException('unsupported_database_driver');
        }
        $schema = preg_replace('/[^a-zA-Z0-9_]/', '', $env['DB_SCHEMA'] ?? 'public') ?: 'public';
        $dsn = sprintf(
            "pgsql:host=%s;port=%s;dbname=%s;options='--search_path=%s,public'",
            $env['DB_HOST'] ?? 'localhost',
            $env['DB_PORT'] ?? '5432',
            $env['DB_DATABASE'] ?? 'voxel_bi',
            $schema
        );
        return new PostgresPdo(
            $dsn,
            $env['DB_USERNAME'] ?? 'root',
            $env['DB_PASSWORD'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    /** @return array<string,string> */
    private function loadEnvironment(): array
    {
        $values = [];
        $path = $this->appRoot . '/.env';
        if (!is_readable($path)) {
            return $values;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2 || preg_match('/^[A-Z0-9_]+$/', trim($parts[0])) !== 1) {
                continue;
            }
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            if (preg_match('/^"(.*)"$/s', $value, $match) === 1 || preg_match("/^'(.*)'$/s", $value, $match) === 1) {
                $value = $match[1];
            }
            $values[$name] = $value;
        }
        return $values;
    }
}
}

namespace {
    if (defined('PHILIPS_REPORT_RELEASE_HEALTH_PROBE_LIBRARY')) {
        return;
    }
    $root = '/var/www/voxelpacs/app';
    require_once $root . '/app/autoload.php';
    $since = null;
    $until = null;
    foreach (array_slice($argv ?? [], 1) as $argument) {
        if (str_starts_with($argument, '--since=')) {
            $since = substr($argument, strlen('--since='));
        }
        if (str_starts_with($argument, '--until=')) {
            $until = substr($argument, strlen('--until='));
        }
    }
    if ($since === null || $since === '') {
        echo "PROBE=philips_report_release_health\nMODE=READ_ONLY\nERROR=since_required\nDATABASE_CHANGED=NO\n";
        exit(64);
    }
    try {
        $probe = new \App\Diagnostics\PhilipsReportReleaseHealthProbe($root);
        echo json_encode($probe->run($since, $until), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } catch (Throwable $error) {
        echo "PROBE=philips_report_release_health\nMODE=READ_ONLY\nERROR=probe_unavailable\nDATABASE_CHANGED=NO\n";
        exit(1);
    }
}
