<?php

declare(strict_types=1);

/**
 * VOXEL PACS — seletor read-only B6.1 do primeiro Job Philips Non-DICOM.
 *
 * O diagnóstico consulta somente Jobs do tenant 2/Destination 7, exclui o
 * Job 522 e nunca modifica banco, fila, artefato, serviço ou transporte.
 * A proveniência da PR #70 só seria aceita com evidência formal persistida;
 * data, ID ou schema_version não são prova e o estado atual falha fechado.
 */

namespace App\Diagnostics {

use App\Core\PostgresPdo;
use PDO;
use Throwable;

final class PhilipsNonDicomB6Selector
{
    private const VERSION = '1.0.0';
    private const APP_ROOT = '/var/www/voxelpacs/app';
    private const TENANT_ID = 2;
    private const DESTINATION_ID = 7;
    private const EXCLUDED_JOB_ID = 522;
    private const TRANSPORT = 'philips_non_dicom';
    private const PROFILE = 'submission_document';
    private const ENVIRONMENT = 'producao';
    private const DISPATCH_MODE = 'automatic_production';
    private const PR70_SHA = '1473783905d1ede4ef897fef909fdf6f8c29accf';

    /** @var list<string> */
    private const EXCLUSION_KEYS = [
        'EXCLUDED_STATUS',
        'EXCLUDED_ATTEMPTS',
        'EXCLUDED_LOCK',
        'EXCLUDED_CLAIM',
        'EXCLUDED_TENANT',
        'EXCLUDED_DESTINATION',
        'EXCLUDED_TRANSPORT',
        'EXCLUDED_PROFILE',
        'EXCLUDED_DISPATCH_MODE',
        'EXCLUDED_NEXT_ATTEMPT',
        'EXCLUDED_WORKER_ELIGIBILITY',
        'EXCLUDED_AUTOMATIC_DATE',
        'EXCLUDED_DESTINATION_DISABLED',
        'EXCLUDED_OUTBOX',
        'EXCLUDED_PAYLOAD_PARSE',
        'EXCLUDED_REPORT',
        'EXCLUDED_VERSION',
        'EXCLUDED_STUDY',
        'EXCLUDED_PAYLOAD_REFERRING_PHYSICIAN',
        'EXCLUDED_PR70_PROVENANCE',
    ];

    /** @return array<string,mixed> */
    public function run(): array
    {
        $result = $this->baseResult();
        $environment = $this->loadCanonicalEnvironment();
        $pdo = null;
        $transactionStarted = false;

        try {
            $pdo = $this->connectReadOnly($environment);
            $result['DATABASE'] = 'CONNECTED_READ_ONLY';
            $pdo->beginTransaction();
            $transactionStarted = true;
            $pdo->exec('SET TRANSACTION READ ONLY');

            foreach ($this->findScopedJobs($pdo) as $job) {
                $result['TOTAL_INSPECTED']++;
                $jobId = (int) ($job['job_id'] ?? 0);
                if ($jobId === self::EXCLUDED_JOB_ID) {
                    $result['EXCLUDED_JOB_522'] = 'YES';
                    continue;
                }

                $decision = $this->evaluateJob($pdo, $job);
                if ($decision['candidate'] === true) {
                    $result['CANDIDATE_FOUND'] = 'YES';
                    $result['candidate'] = $decision['candidate_result'];
                    break;
                }
                $result[$decision['reason']]++;
            }

            if ($result['CANDIDATE_FOUND'] === 'YES') {
                $result['READY_FOR_SINGLE_RUN'] = 'YES';
                $result['PR70_PAYLOAD_FIX'] = 'CONFIRMED';
            } else {
                $result['READY_FOR_SINGLE_RUN'] = 'NO';
            }
            $result['SELECTION_STATUS'] = 'PASS';
        } catch (Throwable $error) {
            $result['SELECTION_STATUS'] = 'BLOCKED';
            $result['FAILURE_CATEGORY'] = $this->failureCategory($error);
        } finally {
            if ($transactionStarted && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        return self::sanitize($result);
    }

    /** @return array<string,mixed> */
    private function baseResult(): array
    {
        $result = [
            'DIAGNOSTIC' => 'philips_nondicom_b6_selector',
            'VERSION' => self::VERSION,
            'MODE' => 'single_test',
            'EXECUTION_MODE' => 'READ_ONLY',
            'TENANT_ID' => self::TENANT_ID,
            'DESTINATION_ID' => self::DESTINATION_ID,
            'TRANSPORT' => self::TRANSPORT,
            'PROFILE' => self::PROFILE,
            'ENVIRONMENT' => self::ENVIRONMENT,
            'DISPATCH_MODE' => self::DISPATCH_MODE,
            'EXCLUDED_JOB_522' => 'NO',
            'TOTAL_INSPECTED' => 0,
            'CANDIDATE_FOUND' => 'NO',
            'DATABASE' => 'NOT_CONNECTED',
            'PR70_PAYLOAD_FIX' => 'NOT_PROVEN',
            'READY_FOR_SINGLE_RUN' => 'NO',
            'DATABASE_CHANGED' => 'NO',
            'JOB_CHANGED' => 'NO',
            'OUTBOX_CHANGED' => 'NO',
            'REPORT_CHANGED' => 'NO',
            'VERSION_CHANGED' => 'NO',
            'JOB_CLAIMED' => 'NO',
            'ATTEMPT_CREATED' => 'NO',
            'WORKER' => 'NOT_STARTED',
            'BRIDGE_CALLED' => 'NO',
            'SMB' => 'NOT_EXECUTED',
            'TRANSMISSION' => 'NO',
            'DICOM_CSTORE' => 'NOT_CALLED',
            'DESTINATION_CHANGED' => 'NO',
            'ALLOWLIST_CHANGED' => 'NO',
            'SERVICE_RELOAD' => 'NOT_PERFORMED',
        ];
        foreach (self::EXCLUSION_KEYS as $key) {
            $result[$key] = 0;
        }
        return $result;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function findScopedJobs(PDO $pdo): array
    {
        $statement = $pdo->prepare(
            'SELECT j.id AS job_id, j.outbox_id, j.destination_id, j.tenant_id,
                    j.transport, j.delivery_profile, j.status AS job_status,
                    j.attempt_count, j.next_attempt_at, j.worker_eligible_at,
                    j.automatic_dispatch_date, j.locked_at, j.locked_by,
                    j.created_at,
                    o.id AS outbox_row_id, o.tenant_id AS outbox_tenant_id,
                    o.report_id, o.report_version, o.estudo_id,
                    o.delivery_profile AS outbox_delivery_profile,
                    o.payload_json, d.id AS destination_row_id,
                    d.tenant_id AS destination_tenant_id,
                    d.transport AS destination_transport,
                    d.ambiente AS destination_environment,
                    d.enabled AS destination_enabled,
                    d.disparar_na_liberacao AS destination_auto_trigger
               FROM pacs_report_delivery_jobs j
               LEFT JOIN pacs_report_delivery_outbox o
                      ON o.id = j.outbox_id AND o.tenant_id = j.tenant_id
               LEFT JOIN pacs_report_delivery_destinations d
                      ON d.id = j.destination_id AND d.tenant_id = j.tenant_id
              WHERE j.tenant_id = :tenant_id
                AND j.destination_id = :destination_id
              ORDER BY j.created_at ASC, j.id ASC'
        );
        $statement->execute([
            ':tenant_id' => self::TENANT_ID,
            ':destination_id' => self::DESTINATION_ID,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /** @param array<string,mixed> $job @return array{candidate:bool,reason:string,candidate_result?:array<string,mixed>} */
    private function evaluateJob(PDO $pdo, array $job): array
    {
        $checks = [
            ['ok' => in_array((string) ($job['job_status'] ?? ''), ['queued', 'retrying'], true), 'reason' => 'EXCLUDED_STATUS'],
            ['ok' => (int) ($job['attempt_count'] ?? -1) === 0, 'reason' => 'EXCLUDED_ATTEMPTS'],
            ['ok' => trim((string) ($job['locked_at'] ?? '')) === '', 'reason' => 'EXCLUDED_LOCK'],
            ['ok' => trim((string) ($job['locked_by'] ?? '')) === '', 'reason' => 'EXCLUDED_CLAIM'],
            ['ok' => (int) ($job['tenant_id'] ?? 0) === self::TENANT_ID, 'reason' => 'EXCLUDED_TENANT'],
            ['ok' => (int) ($job['destination_id'] ?? 0) === self::DESTINATION_ID, 'reason' => 'EXCLUDED_DESTINATION'],
            ['ok' => (string) ($job['transport'] ?? '') === self::TRANSPORT, 'reason' => 'EXCLUDED_TRANSPORT'],
            ['ok' => (string) ($job['delivery_profile'] ?? '') === self::PROFILE, 'reason' => 'EXCLUDED_PROFILE'],
        ];
        foreach ($checks as $check) {
            if (!$check['ok']) {
                return ['candidate' => false, 'reason' => $check['reason']];
            }
        }

        $payload = $this->decodeObject($job['payload_json'] ?? null);
        if ($payload === null) {
            return ['candidate' => false, 'reason' => 'EXCLUDED_PAYLOAD_PARSE'];
        }
        if ((string) ($payload['dispatch_mode'] ?? '') !== self::DISPATCH_MODE) {
            return ['candidate' => false, 'reason' => 'EXCLUDED_DISPATCH_MODE'];
        }
        if (!$this->dateIsEligible($job['next_attempt_at'] ?? null)) {
            return ['candidate' => false, 'reason' => 'EXCLUDED_NEXT_ATTEMPT'];
        }
        if (!$this->dateIsEligible($job['worker_eligible_at'] ?? null)) {
            return ['candidate' => false, 'reason' => 'EXCLUDED_WORKER_ELIGIBILITY'];
        }
        if (!$this->automaticDateIsEligible($job['automatic_dispatch_date'] ?? null)) {
            return ['candidate' => false, 'reason' => 'EXCLUDED_AUTOMATIC_DATE'];
        }
        if ((int) ($job['destination_row_id'] ?? 0) !== self::DESTINATION_ID
            || (int) ($job['destination_tenant_id'] ?? 0) !== self::TENANT_ID
            || (int) ($job['destination_enabled'] ?? 0) !== 1
            || (string) ($job['destination_transport'] ?? '') !== self::TRANSPORT
            || (string) ($job['destination_environment'] ?? '') !== self::ENVIRONMENT
            || (int) ($job['destination_auto_trigger'] ?? 0) !== 1
        ) {
            return ['candidate' => false, 'reason' => 'EXCLUDED_DESTINATION_DISABLED'];
        }
        if ((int) ($job['outbox_row_id'] ?? 0) <= 0
            || (int) ($job['outbox_tenant_id'] ?? 0) !== self::TENANT_ID
            || (int) ($job['outbox_row_id'] ?? 0) !== (int) ($job['outbox_id'] ?? 0)
            || (string) ($job['outbox_delivery_profile'] ?? '') !== self::PROFILE
        ) {
            return ['candidate' => false, 'reason' => 'EXCLUDED_OUTBOX'];
        }
        if (!array_key_exists('referring_physician_name', $payload)) {
            return ['candidate' => false, 'reason' => 'EXCLUDED_PAYLOAD_REFERRING_PHYSICIAN'];
        }
        if ($this->provenanceFromPayload($payload) !== 'CONFIRMED') {
            return ['candidate' => false, 'reason' => 'EXCLUDED_PR70_PROVENANCE'];
        }

        $reportState = $this->reportState($pdo, $job);
        if ($reportState === 'REPORT_MISSING') {
            return ['candidate' => false, 'reason' => 'EXCLUDED_REPORT'];
        }
        if ($reportState === 'VERSION_MISSING') {
            return ['candidate' => false, 'reason' => 'EXCLUDED_VERSION'];
        }
        if ($reportState === 'STUDY_MISSING') {
            return ['candidate' => false, 'reason' => 'EXCLUDED_STUDY'];
        }

        return [
            'candidate' => true,
            'reason' => '',
            'candidate_result' => [
                'JOB_ID' => (int) ($job['job_id'] ?? 0),
                'REPORT_ID' => (int) ($job['report_id'] ?? 0),
                'REPORT_VERSION' => (int) ($job['report_version'] ?? 0),
                'JOB_STATUS' => (string) ($job['job_status'] ?? ''),
                'ATTEMPT_COUNT' => 0,
                'LOCKED' => 'NO',
                'CLAIMED' => 'NO',
                'OUTBOX' => 'PASS',
                'REPORT' => 'PASS',
                'VERSION' => 'PASS',
                'RELEASED' => 'YES',
                'VERSION_PRESENT' => 'YES',
                'STUDY_LINKED' => 'YES',
                'PAYLOAD_PARSE' => 'PASS',
                'PAYLOAD_REFERRING_PHYSICIAN_PRESENT' => 'YES',
                'AUTHOR_INPUT' => 'AVAILABLE',
            ],
        ];
    }

    private function reportState(PDO $pdo, array $job): string
    {
        $statement = $pdo->prepare(
            'SELECT r.id, r.tenant_id, r.estudo_id, r.situacao,
                    rv.versao AS version_value,
                    e.id AS study_id, e.tenant_id AS study_tenant_id
               FROM reports r
               LEFT JOIN report_versions rv
                      ON rv.report_id = r.id AND rv.versao = :report_version
               LEFT JOIN bi_pacs_estudos e
                      ON e.id = r.estudo_id AND e.tenant_id = r.tenant_id
              WHERE r.id = :report_id AND r.tenant_id = :tenant_id
              LIMIT 1'
        );
        $statement->execute([
            ':report_id' => (int) ($job['report_id'] ?? 0),
            ':report_version' => (int) ($job['report_version'] ?? 0),
            ':tenant_id' => self::TENANT_ID,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || (int) ($row['id'] ?? 0) !== (int) ($job['report_id'] ?? 0)
            || (int) ($row['tenant_id'] ?? 0) !== self::TENANT_ID
            || (string) ($row['situacao'] ?? '') !== 'liberado'
        ) {
            return 'REPORT_MISSING';
        }
        if ((int) ($row['version_value'] ?? 0) !== (int) ($job['report_version'] ?? 0)) {
            return 'VERSION_MISSING';
        }
        if ((int) ($row['estudo_id'] ?? 0) !== (int) ($job['estudo_id'] ?? 0)
            || (int) ($row['study_id'] ?? 0) !== (int) ($job['estudo_id'] ?? 0)
            || (int) ($row['study_tenant_id'] ?? 0) !== self::TENANT_ID
        ) {
            return 'STUDY_MISSING';
        }
        return 'PASS';
    }

    private function provenanceFromPayload(array $payload): string
    {
        // O schema atual não persiste a SHA do produtor por Outbox. Não usar
        // data, ID ou schema_version como substituto de proveniência.
        return 'NOT_PROVEN';
    }

    private function dateIsEligible(mixed $value): bool
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return true;
        }
        $timestamp = strtotime($value);
        return $timestamp !== false && $timestamp <= time();
    }

    private function automaticDateIsEligible(mixed $value): bool
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' || $value === date('Y-m-d');
    }

    /** @return array{source:string,keys:array<string,bool>} */
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
            if (preg_match('/^"(.*)"$/s', $value, $match) === 1
                || preg_match("/^'(.*)'$/s", $value, $match) === 1
            ) {
                $value = $match[1];
            }
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
        return ['source' => 'canonical_env', 'keys' => $keys];
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

    private function environmentValue(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return $value === false || $value === null || $value === '' ? $default : (string) $value;
    }

    private function decodeObject(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function failureCategory(Throwable $error): string
    {
        $message = $error->getMessage();
        return preg_match('/^[A-Z0-9_]{1,80}$/', $message) === 1
            ? $message
            : 'READONLY_SELECTOR_FAILED';
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function sanitize(array $data): array
    {
        $blocked = '/password|secret|token|private|authorization|cookie|dsn|connection|xml|pdf|dicom|patient|cpf|accession|payload|body|content|nome|physician/i';
        $walk = static function (mixed $value) use (&$walk, $blocked): mixed {
            if (is_array($value)) {
                $clean = [];
                foreach ($value as $key => $item) {
                    if (!in_array((string) $key, [
                        'PAYLOAD_REFERRING_PHYSICIAN_PRESENT',
                        'EXCLUDED_PAYLOAD_REFERRING_PHYSICIAN',
                    ], true)
                        && preg_match($blocked, (string) $key) === 1) {
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
        $clean['DATABASE_CHANGED'] = 'NO';
        $clean['JOB_CHANGED'] = 'NO';
        $clean['OUTBOX_CHANGED'] = 'NO';
        $clean['REPORT_CHANGED'] = 'NO';
        $clean['VERSION_CHANGED'] = 'NO';
        $clean['JOB_CLAIMED'] = 'NO';
        $clean['ATTEMPT_CREATED'] = 'NO';
        $clean['WORKER'] = 'NOT_STARTED';
        $clean['BRIDGE_CALLED'] = 'NO';
        $clean['SMB'] = 'NOT_EXECUTED';
        $clean['TRANSMISSION'] = 'NO';
        $clean['DICOM_CSTORE'] = 'NOT_CALLED';
        $clean['DESTINATION_CHANGED'] = 'NO';
        $clean['ALLOWLIST_CHANGED'] = 'NO';
        $clean['SERVICE_RELOAD'] = 'NOT_PERFORMED';
        return $clean;
    }
}
}

namespace {
    if (defined('PHILIPS_NON_DICOM_B6_SELECTOR_LIBRARY')) {
        return;
    }
    $root = '/var/www/voxelpacs/app';
    $arguments = array_slice($argv ?? [], 1);
    if ($arguments !== []) {
        fwrite(STDOUT, "CANDIDATE_FOUND=NO\nSELECTION_STATUS=BLOCKED\nFAILURE_CATEGORY=ARGUMENTS_NOT_ALLOWED\nREADY_FOR_SINGLE_RUN=NO\nDATABASE_CHANGED=NO\nTRANSMISSION=NO\n");
        exit(64);
    }
    require_once $root . '/app/autoload.php';
    $result = (new \App\Diagnostics\PhilipsNonDicomB6Selector())->run();
    foreach ($result as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $nestedKey => $nestedValue) {
                fwrite(STDOUT, $nestedKey . '=' . (is_scalar($nestedValue) ? (string) $nestedValue : 'SANITIZED') . "\n");
            }
            continue;
        }
        fwrite(STDOUT, (string) $key . '=' . (is_scalar($value) ? (string) $value : 'SANITIZED') . "\n");
    }
    exit(($result['SELECTION_STATUS'] ?? 'BLOCKED') === 'PASS' ? 0 : 1);
}
