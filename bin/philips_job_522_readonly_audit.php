<?php

declare(strict_types=1);

/**
 * VOXEL PACS — auditoria read-only do Job 522.
 *
 * O comando possui escopo fixo e somente consulta o banco/configuração local.
 * Não cria Request, Outbox, Job ou attempt; não reclama Job; não gera
 * PDF/XML; não chama Worker, Bridge, SMB ou DICOM/C-STORE.
 */

namespace App\Diagnostics {

use App\Config\ReportDeliveryRuntimeConfig;
use App\Core\PostgresPdo;
use App\Core\SqlHelper;
use PDO;
use Throwable;

final class PhilipsJob522ReadonlyAudit
{
    private const VERSION = '1.0.0';
    private const APP_ROOT = '/var/www/voxelpacs/app';
    private const TENANT_ID = 2;
    private const JOB_ID = 522;
    private const DESTINATION_ID = 7;
    private const WORKER_UNIT = 'voxelpacs-report-delivery-worker.service';

    /** @var array<string,string> */
    private const FLAG_METHODS = [
        'VOXEL_REPORT_DELIVERY_HUB_ENABLED' => 'hubEnabled',
        'VOXEL_REPORT_DELIVERY_REQUESTS_ENABLED' => 'requestsEnabled',
        'PHILIPS_NON_DICOM_DELIVERY_ENABLED' => 'nonDicomDeliveryEnabled',
        'VOXEL_REPORT_DELIVERY_WORKER_KILL_SWITCH' => 'workerKillSwitchEnabled',
    ];

    /** @return array<string,mixed> */
    public function run(): array
    {
        $environment = $this->loadCanonicalEnvironment();
        $result = [
            'diagnostic' => 'philips_job_522_readonly_audit',
            'version' => self::VERSION,
            'mode' => 'READ_ONLY',
            'tenant_id' => self::TENANT_ID,
            'job_id' => self::JOB_ID,
            'database' => 'NOT_CONNECTED',
            'runtime' => $this->runtimeFlags($environment),
            'job' => ['status' => 'UNKNOWN'],
            'outbox' => ['status' => 'UNKNOWN'],
            'request' => ['status' => 'NOT_APPLICABLE'],
            'destination_7' => ['status' => 'UNKNOWN'],
            'author' => ['status' => 'UNKNOWN'],
            'report' => ['status' => 'UNKNOWN'],
            'attempts' => ['status' => 'UNKNOWN'],
            'worker' => $this->workerState(),
            'job_claimed' => 'NO',
            'attempt_created' => 'NO',
            'bridge_called' => 'NO',
            'smb_called' => 'NO',
            'dicom_cstore_called' => 'NO',
            'transmission' => 'NO',
            'database_changed' => 'NO',
            'audit_status' => 'BLOCKED',
        ];

        $transactionStarted = false;
        try {
            $pdo = $this->connectReadOnly($environment);
            $result['database'] = 'CONNECTED_READ_ONLY';
            $pdo->beginTransaction();
            $transactionStarted = true;
            $pdo->exec('SET TRANSACTION READ ONLY');

            $job = $this->findJob($pdo);
            if ($job === null) {
                throw new \RuntimeException('JOB_522_NOT_FOUND');
            }

            $result['job'] = $this->jobState($job);
            $result['outbox'] = $this->outboxState($job);
            $result['request'] = $this->requestState($job);
            $result['destination_7'] = $this->destinationState($job);
            $result['author'] = $this->authorState($pdo, $job);
            $result['report'] = $this->reportState($pdo, $job);
            $result['attempts'] = $this->attemptState($pdo);

            $this->assertReadOnlyIdentity($job, $result);
            $result['audit_status'] = $this->auditStatus($result);
        } catch (Throwable $error) {
            $result['database'] = $result['database'] === 'CONNECTED_READ_ONLY'
                ? 'READ_ONLY_QUERY_FAILED'
                : 'CONNECTION_FAILED';
            $result['failure_category'] = $this->failureCategory($error);
        } finally {
            if ($transactionStarted && isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        return self::sanitize($result);
    }

    /** @param array<string,mixed> $result */
    private function auditStatus(array $result): string
    {
        foreach (['job', 'outbox', 'destination_7', 'author', 'report', 'attempts'] as $key) {
            if (($result[$key]['status'] ?? 'BLOCKED') !== 'PASS') {
                return 'BLOCKED';
            }
        }
        if (!in_array(($result['request']['status'] ?? 'BLOCKED'), ['PASS', 'NOT_APPLICABLE'], true)) {
            return 'BLOCKED';
        }
        return 'PASS';
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $result */
    private function assertReadOnlyIdentity(array $job, array $result): void
    {
        if (($result['job']['status'] ?? '') !== 'PASS'
            || (int) ($job['tenant_id'] ?? 0) !== self::TENANT_ID
            || (int) ($job['destination_id'] ?? 0) !== self::DESTINATION_ID
            || (string) ($job['transport'] ?? '') !== 'philips_non_dicom'
            || (string) ($job['delivery_profile'] ?? '') !== 'submission_document'
            || (string) ($job['environment'] ?? '') !== 'producao') {
            throw new \RuntimeException('JOB_IDENTITY_MISMATCH');
        }
    }

    /** @return array<string,mixed>|null */
    private function findJob(PDO $pdo): ?array
    {
        $aliasSelect = $this->hasColumn($pdo, 'pacs_report_delivery_destinations', 'task_site_id_alias')
            ? 'd.task_site_id_alias'
            : 'NULL AS task_site_id_alias';
        $requestsAvailable = SqlHelper::hasTable($pdo, 'pacs_report_delivery_requests');
        $requestSelect = $requestsAvailable
            ? 'r.id AS request_id, r.status AS request_status, r.destination_id AS request_destination_id,
               r.transport AS request_transport, r.ambiente AS request_environment,
               r.delivery_profile AS request_profile, r.dispatch_mode AS request_dispatch_mode,
               r.authorized_snapshot_digest, r.destination_config_digest'
            : 'NULL AS request_id, NULL AS request_status, NULL AS request_destination_id,
               NULL AS request_transport, NULL AS request_environment,
               NULL AS request_profile, NULL AS request_dispatch_mode,
               NULL AS authorized_snapshot_digest, NULL AS destination_config_digest';
        $requestJoin = $requestsAvailable
            ? 'LEFT JOIN pacs_report_delivery_requests r
                 ON r.id = o.delivery_request_id AND r.tenant_id = j.tenant_id'
            : '';

        $statement = $pdo->prepare(
            "SELECT j.id AS job_id, j.outbox_id, j.destination_id, j.tenant_id,
                    j.transport, j.delivery_profile, j.status AS job_status,
                    j.attempt_count, j.locked_at, j.locked_by,
                    o.delivery_request_id, o.report_id, o.report_version, o.estudo_id,
                    o.payload_json, d.ambiente AS environment,
                    d.transport AS destination_transport, d.enabled,
                    d.disparar_na_liberacao, {$aliasSelect}, d.configuration_json,
                    {$requestSelect}
               FROM pacs_report_delivery_jobs j
               INNER JOIN pacs_report_delivery_outbox o
                       ON o.id = j.outbox_id AND o.tenant_id = j.tenant_id
               INNER JOIN pacs_report_delivery_destinations d
                       ON d.id = j.destination_id AND d.tenant_id = j.tenant_id
               {$requestJoin}
              WHERE j.id = :job_id AND j.tenant_id = :tenant_id
              LIMIT 1"
        );
        $statement->execute([
            ':job_id' => self::JOB_ID,
            ':tenant_id' => self::TENANT_ID,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function jobState(array $job): array
    {
        $locked = trim((string) ($job['locked_at'] ?? '')) !== ''
            || trim((string) ($job['locked_by'] ?? '')) !== '';
        $status = (int) ($job['job_id'] ?? 0) === self::JOB_ID
            && (int) ($job['tenant_id'] ?? 0) === self::TENANT_ID
            && (int) ($job['destination_id'] ?? 0) === self::DESTINATION_ID
            && (string) ($job['transport'] ?? '') === 'philips_non_dicom'
            && (string) ($job['delivery_profile'] ?? '') === 'submission_document'
            && (string) ($job['environment'] ?? '') === 'producao'
            && (string) ($job['job_status'] ?? '') === 'queued'
            && (int) ($job['attempt_count'] ?? -1) === 0
            && !$locked
            ? 'PASS'
            : 'BLOCKED';
        return [
            'status' => $status,
            'job_id' => self::JOB_ID,
            'tenant_id' => (int) ($job['tenant_id'] ?? 0),
            'destination_id' => (int) ($job['destination_id'] ?? 0),
            'transport' => (string) ($job['transport'] ?? ''),
            'profile' => (string) ($job['delivery_profile'] ?? ''),
            'environment' => (string) ($job['environment'] ?? ''),
            'state' => (string) ($job['job_status'] ?? 'UNKNOWN'),
            'attempt_count' => (int) ($job['attempt_count'] ?? -1),
            'locked' => $locked ? 'YES' : 'NO',
            'dispatch_mode' => $this->dispatchMode($job),
        ];
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function outboxState(array $job): array
    {
        $payload = $this->jsonObject($job['payload_json'] ?? null);
        $valid = (int) ($job['outbox_id'] ?? 0) > 0
            && (int) ($job['tenant_id'] ?? 0) === self::TENANT_ID
            && (int) ($job['report_id'] ?? 0) > 0
            && (int) ($job['report_version'] ?? 0) > 0
            && (int) ($job['estudo_id'] ?? 0) > 0
            && $payload !== null;
        return [
            'status' => $valid ? 'PASS' : 'BLOCKED',
            'outbox_id' => (int) ($job['outbox_id'] ?? 0),
            'tenant_id' => (int) ($job['tenant_id'] ?? 0),
            'report_id' => (int) ($job['report_id'] ?? 0),
            'report_version' => (int) ($job['report_version'] ?? 0),
            'study_linked' => (int) ($job['estudo_id'] ?? 0) > 0 ? 'YES' : 'NO',
            'payload_json' => $payload !== null ? 'VALID_OBJECT' : 'INVALID',
            'payload_dispatch_mode' => is_array($payload) && is_string($payload['dispatch_mode'] ?? null)
                ? (string) $payload['dispatch_mode']
                : 'UNKNOWN',
        ];
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function requestState(array $job): array
    {
        $requestId = (int) ($job['request_id'] ?? $job['delivery_request_id'] ?? 0);
        if ($requestId <= 0) {
            return [
                'status' => 'NOT_APPLICABLE',
                'request_id' => null,
                'scope' => 'AUTOMATIC_OUTBOX_WITHOUT_REQUEST',
            ];
        }
        $digestValid = preg_match('/^[a-f0-9]{64}$/i', (string) ($job['authorized_snapshot_digest'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/i', (string) ($job['destination_config_digest'] ?? '')) === 1;
        $valid = (int) ($job['request_destination_id'] ?? 0) === self::DESTINATION_ID
            && (string) ($job['request_transport'] ?? '') === 'philips_non_dicom'
            && (string) ($job['request_profile'] ?? '') === 'submission_document'
            && (string) ($job['request_environment'] ?? '') === 'producao'
            && $digestValid;
        return [
            'status' => $valid ? 'PASS' : 'BLOCKED',
            'request_id' => $requestId,
            'request_status' => (string) ($job['request_status'] ?? 'UNKNOWN'),
            'destination_id' => (int) ($job['request_destination_id'] ?? 0),
            'dispatch_mode' => (string) ($job['request_dispatch_mode'] ?? 'UNKNOWN'),
            'digests' => $digestValid ? 'VALID_FORMAT' : 'INVALID_OR_MISSING',
        ];
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function destinationState(array $job): array
    {
        $configuration = $this->jsonObject($job['configuration_json'] ?? null) ?? [];
        $submission = is_array($configuration['philips_submission'] ?? null)
            ? $configuration['philips_submission']
            : [];
        $alias = trim((string) ($job['task_site_id_alias'] ?? ''));
        $valid = (int) ($job['destination_id'] ?? 0) === self::DESTINATION_ID
            && (int) ($job['tenant_id'] ?? 0) === self::TENANT_ID
            && (string) ($job['destination_transport'] ?? '') === 'philips_non_dicom'
            && (string) ($job['environment'] ?? '') === 'producao'
            && (int) ($job['enabled'] ?? 0) === 1
            && (string) ($job['delivery_profile'] ?? '') === 'submission_document'
            && preg_match('/^[A-Za-z0-9._-]{1,120}$/', $alias) === 1
            && (string) ($submission['task_author_source'] ?? '') === 'bi_medicos';
        return [
            'status' => $valid ? 'PASS' : 'BLOCKED',
            'destination_id' => self::DESTINATION_ID,
            'tenant_id' => self::TENANT_ID,
            'transport' => (string) ($job['destination_transport'] ?? ''),
            'environment' => (string) ($job['environment'] ?? ''),
            'enabled' => (int) ($job['enabled'] ?? 0) === 1 ? 'YES' : 'NO',
            'profile' => (string) ($job['delivery_profile'] ?? ''),
            'task_site_alias' => preg_match('/^[A-Za-z0-9._-]{1,120}$/', $alias) === 1 ? 'VALID' : 'INVALID',
            'author_source' => (string) ($submission['task_author_source'] ?? 'MISSING'),
            'automatic_trigger' => (int) ($job['disparar_na_liberacao'] ?? 0) === 1 ? 'ON' : 'OFF',
        ];
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function authorState(PDO $pdo, array $job): array
    {
        $configuration = $this->jsonObject($job['configuration_json'] ?? null) ?? [];
        $submission = is_array($configuration['philips_submission'] ?? null)
            ? $configuration['philips_submission']
            : [];
        $source = trim((string) ($submission['task_author_source'] ?? ''));
        $authorId = $submission['task_author_id'] ?? null;
        $authorIdValid = is_int($authorId) && $authorId > 0
            || is_string($authorId) && preg_match('/^[1-9][0-9]*$/', trim($authorId)) === 1;
        if ($source !== 'bi_medicos' || !$authorIdValid || $this->dispatchMode($job) !== 'automatic_production') {
            return [
                'status' => 'BLOCKED',
                'source' => $source === '' ? 'MISSING' : $source,
                'task_author_id' => $authorIdValid ? 'POSITIVE_INTEGER' : 'INVALID_OR_MISSING',
                'lookup' => 'NOT_EXECUTED',
            ];
        }

        $statement = $pdo->prepare(
            'SELECT id, tenant_id, ativo
               FROM bi_medicos
              WHERE id = :author_id
                AND tenant_id = :tenant_id
                AND ativo = 1
                AND NULLIF(TRIM(nome), \'\') IS NOT NULL
              LIMIT 1'
        );
        $statement->execute([
            ':author_id' => (int) $authorId,
            ':tenant_id' => self::TENANT_ID,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $resolved = is_array($row)
            && (int) ($row['id'] ?? 0) === (int) $authorId
            && (int) ($row['tenant_id'] ?? 0) === self::TENANT_ID
            && (int) ($row['ativo'] ?? 0) === 1;
        return [
            'status' => $resolved ? 'PASS' : 'BLOCKED',
            'source' => 'BI_MEDICOS',
            'scope' => 'TENANT_SCOPED',
            'task_author_id' => 'POSITIVE_INTEGER',
            'lookup' => $resolved ? 'RESOLVED' : 'NOT_FOUND_OR_INACTIVE',
            'name_materialized' => 'NO',
        ];
    }

    /** @return array<string,mixed> */
    private function reportState(PDO $pdo, array $job): array
    {
        $reportId = (int) ($job['report_id'] ?? 0);
        $version = (int) ($job['report_version'] ?? 0);
        $studyId = (int) ($job['estudo_id'] ?? 0);
        if ($reportId <= 0 || $version <= 0 || $studyId <= 0) {
            return ['status' => 'BLOCKED', 'report_id' => $reportId, 'report_version' => $version];
        }
        $statement = $pdo->prepare(
            'SELECT r.id, r.tenant_id, r.estudo_id, r.situacao,
                    CASE WHEN EXISTS (
                        SELECT 1 FROM report_versions rv
                         WHERE rv.report_id = r.id AND rv.versao = :report_version
                    ) THEN 1 ELSE 0 END AS version_present,
                    CASE WHEN EXISTS (
                        SELECT 1 FROM bi_pacs_estudos e
                         WHERE e.id = r.estudo_id AND e.tenant_id = r.tenant_id
                    ) THEN 1 ELSE 0 END AS study_present
               FROM reports r
              WHERE r.id = :report_id AND r.tenant_id = :tenant_id
              LIMIT 1'
        );
        $statement->execute([
            ':report_id' => $reportId,
            ':report_version' => $version,
            ':tenant_id' => self::TENANT_ID,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $valid = is_array($row)
            && (int) ($row['id'] ?? 0) === $reportId
            && (int) ($row['tenant_id'] ?? 0) === self::TENANT_ID
            && (int) ($row['estudo_id'] ?? 0) === $studyId
            && (string) ($row['situacao'] ?? '') === 'liberado'
            && (int) ($row['version_present'] ?? 0) === 1
            && (int) ($row['study_present'] ?? 0) === 1;
        return [
            'status' => $valid ? 'PASS' : 'BLOCKED',
            'report_id' => $reportId,
            'report_version' => $version,
            'study_id' => $studyId,
            'released' => is_array($row) && (string) ($row['situacao'] ?? '') === 'liberado' ? 'YES' : 'NO',
            'version_present' => is_array($row) && (int) ($row['version_present'] ?? 0) === 1 ? 'YES' : 'NO',
            'study_linked' => is_array($row) && (int) ($row['study_present'] ?? 0) === 1 ? 'YES' : 'NO',
        ];
    }

    /** @return array<string,mixed> */
    private function attemptState(PDO $pdo): array
    {
        if (!SqlHelper::hasTable($pdo, 'pacs_report_delivery_attempts')) {
            return ['status' => 'BLOCKED', 'attempts' => 'TABLE_UNAVAILABLE'];
        }
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM pacs_report_delivery_attempts WHERE job_id = :job_id'
        );
        $statement->execute([':job_id' => self::JOB_ID]);
        $count = (int) $statement->fetchColumn();
        return [
            'status' => $count === 0 ? 'PASS' : 'BLOCKED',
            'attempts' => $count,
            'job_claimed' => $count === 0 ? 'NO' : 'UNKNOWN_HISTORY_PRESENT',
        ];
    }

    /** @param array{source:string,keys:array<string,bool>} $environment */
    private function connectReadOnly(array $environment): PDO
    {
        $driver = strtolower($this->environmentValue('DB_DRIVER', 'mysql'));
        $host = $this->environmentValue('DB_HOST', 'localhost');
        $database = $this->environmentValue('DB_DATABASE', 'voxel_bi');
        $username = $this->environmentValue('DB_USERNAME', 'root');
        $password = $this->environmentValue('DB_PASSWORD', '');
        $port = $this->environmentValue('DB_PORT', $driver === 'pgsql' ? '5432' : '3306');
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if ($driver === 'pgsql') {
            $schema = preg_replace('/[^a-zA-Z0-9_]/', '', $this->environmentValue('DB_SCHEMA', 'public')) ?: 'public';
            return new PostgresPdo("pgsql:host={$host};port={$port};dbname={$database};options='--search_path={$schema},public'", $username, $password, $options);
        }
        if ($driver === 'mysql') {
            return new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $username, $password, $options);
        }
        throw new \RuntimeException('UNSUPPORTED_DATABASE_DRIVER');
    }

    /** @param array{source:string,keys:array<string,bool>} $environment @return array<string,array<string,string>> */
    private function runtimeFlags(array $environment): array
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', self::APP_ROOT);
        }
        $flags = [];
        foreach (self::FLAG_METHODS as $name => $method) {
            try {
                $effective = ReportDeliveryRuntimeConfig::$method() ? 'ON' : 'OFF';
            } catch (Throwable) {
                $effective = 'UNKNOWN';
            }
            $flags[$name] = [
                'configured' => ($environment['keys'][$name] ?? false) ? 'YES' : 'NO',
                'effective' => $effective,
            ];
        }
        return $flags;
    }

    /** @return array<string,mixed> */
    private function workerState(): array
    {
        $enabled = $this->systemdState(['is-enabled', self::WORKER_UNIT]);
        $active = $this->systemdState(['is-active', self::WORKER_UNIT]);
        $pid = $this->systemdPid(self::WORKER_UNIT);
        return [
            'unit' => self::WORKER_UNIT,
            'enabled' => $enabled,
            'active' => $active,
            'process_count' => is_int($pid) ? ($pid > 0 ? 1 : 0) : -1,
        ];
    }

    private function dispatchMode(array $job): string
    {
        $payload = $this->jsonObject($job['payload_json'] ?? null);
        $mode = is_array($payload) ? trim((string) ($payload['dispatch_mode'] ?? '')) : '';
        if ($mode !== '') {
            return $mode;
        }
        return trim((string) ($job['request_dispatch_mode'] ?? '')) ?: 'UNKNOWN';
    }

    /** @return array<string,mixed>|null */
    private function jsonObject(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        try {
            return SqlHelper::hasColumn($pdo, $table, $column);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array{source:string,keys:array<string,bool>} $environment */
    private function loadCanonicalEnvironment(): array
    {
        $path = self::APP_ROOT . '/.env';
        $keys = [];
        if (!is_readable($path)) {
            return ['source' => 'canonical_env_unavailable', 'keys' => $keys];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return ['source' => 'canonical_env_unavailable', 'keys' => $keys];
        }
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $name = trim($parts[0]);
            if ($name === '' || preg_match('/^[A-Z0-9_]+$/', $name) !== 1) {
                continue;
            }
            $keys[$name] = true;
            if (array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER) || getenv($name) !== false) {
                continue;
            }
            $value = trim($parts[1]);
            if ((preg_match('/^"(.*)"$/s', $value, $match) === 1)
                || (preg_match("/^'(.*)'$/s", $value, $match) === 1)) {
                $value = $match[1];
            }
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
        return ['source' => 'canonical_env', 'keys' => $keys];
    }

    private function environmentValue(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return $value === false || $value === null || $value === '' ? $default : (string) $value;
    }

    /** @param list<string> $arguments */
    private function systemdState(array $arguments): string
    {
        $result = $this->runFixedCommand(array_merge(['/usr/bin/systemctl'], $arguments));
        if ($result['exit'] !== 0) {
            return 'NO';
        }
        $stdout = strtolower(trim($result['stdout']));
        return in_array($stdout, ['enabled', 'active'], true) ? 'YES' : 'NO';
    }

    private function systemdPid(string $unit): int|string
    {
        $result = $this->runFixedCommand(['/usr/bin/systemctl', 'show', '--property=MainPID', '--value', $unit]);
        return $result['exit'] === 0 && preg_match('/^\d+$/', trim($result['stdout'])) === 1
            ? (int) trim($result['stdout'])
            : 'UNKNOWN';
    }

    /** @param list<string> $command @return array{exit:int,stdout:string} */
    private function runFixedCommand(array $command): array
    {
        if (($command[0] ?? '') !== '/usr/bin/systemctl') {
            return ['exit' => 126, 'stdout' => ''];
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return ['exit' => 126, 'stdout' => ''];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return ['exit' => is_int($exit) ? $exit : 126, 'stdout' => trim((string) $stdout)];
    }

    private function failureCategory(Throwable $error): string
    {
        $message = $error->getMessage();
        return preg_match('/^[A-Z0-9_]{1,80}$/', $message) === 1 ? $message : 'READONLY_AUDIT_FAILED';
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function sanitize(array $data): array
    {
        $blocked = '/password|secret|token|private|authorization|cookie|dsn|connection|xml|pdf|dicom|patient|cpf|accession|payload|body|content|nome/i';
        $walk = static function (mixed $value) use (&$walk, $blocked): mixed {
            if (is_array($value)) {
                $clean = [];
                foreach ($value as $key => $item) {
                    $keyText = (string) $key;
                    if (preg_match($blocked, $keyText) === 1) {
                        continue;
                    }
                    $clean[$key] = $walk($item);
                }
                return $clean;
            }
            if (is_string($value)) {
                return preg_replace('/[^A-Za-z0-9_.:;=+\-\/ ]/', '_', trim($value)) ?: '';
            }
            return $value;
        };
        $clean = $walk($data);
        if (!is_array($clean)) {
            $clean = [];
        }
        $clean['job_claimed'] = 'NO';
        $clean['attempt_created'] = 'NO';
        $clean['bridge_called'] = 'NO';
        $clean['smb_called'] = 'NO';
        $clean['dicom_cstore_called'] = 'NO';
        $clean['transmission'] = 'NO';
        $clean['database_changed'] = 'NO';
        return $clean;
    }
}
}

namespace {
    if (defined('PHILIPS_JOB_522_READONLY_AUDIT_LIBRARY')) {
        return;
    }
    $root = '/var/www/voxelpacs/app';
    if (($argv ?? []) !== array_slice($argv ?? [], 0, 1)) {
        echo json_encode([
            'diagnostic' => 'philips_job_522_readonly_audit',
            'mode' => 'READ_ONLY',
            'audit_status' => 'BLOCKED',
            'failure_category' => 'ARGUMENTS_NOT_ALLOWED',
            'transmission' => 'NO',
            'database_changed' => 'NO',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(64);
    }
    require_once $root . '/app/autoload.php';
    $audit = new \App\Diagnostics\PhilipsJob522ReadonlyAudit();
    echo json_encode($audit->run(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
