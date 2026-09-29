<?php

declare(strict_types=1);

/**
 * Diagnóstico oficial Philips Non-DICOM de produção.
 *
 * Este arquivo somente lê configuração, banco e estado do systemd local.
 * Não carrega o bootstrap web para evitar sessão, criação de diretórios ou
 * escrita de logs da aplicação. Não executa transporte, worker ou SMB.
 */

namespace App\Diagnostics {

use App\Config\ReportDeliveryRuntimeConfig;
use App\Core\PostgresPdo;
use App\Repositories\ReportDeliveryRepository;
use PDO;
use Throwable;

final class PhilipsNonDicomProductionDiagnostic
{
    private const VERSION = '1.0.0';
    private const DEFAULT_APP_ROOT = '/var/www/voxelpacs/app';
    private const TENANT_ID = 2;
    private const DESTINATION_ID = 7;
    private const REPORT_ID = 348;
    private const REPORT_VERSION = 4;
    private const WORKER_UNIT = 'voxelpacs-report-delivery-worker.service';
    private const BRIDGE_UNITS = [
        'voxelpacs-philips-folder-bridge.service',
        'voxelpacs-report-delivery-bridge.service',
    ];

    /** @var array<string,string> */
    private const FLAG_METHODS = [
        'VOXEL_REPORT_DELIVERY_HUB_ENABLED' => 'hubEnabled',
        'VOXEL_REPORT_DELIVERY_REQUESTS_ENABLED' => 'requestsEnabled',
        'PHILIPS_FOLDER_DELIVERY_ENABLED' => 'folderDeliveryEnabled',
        'PHILIPS_NON_DICOM_DELIVERY_ENABLED' => 'nonDicomDeliveryEnabled',
        'PHILIPS_NON_DICOM_SMB_TEST_ENABLED' => 'smbTestEnabled',
        'PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED' => 'smbReadOnlyTestEnabled',
        'VOXEL_REPORT_DELIVERY_WORKER_KILL_SWITCH' => 'workerKillSwitchEnabled',
    ];

    public function __construct(
        private readonly string $appRoot = self::DEFAULT_APP_ROOT,
        private readonly ?PDO $pdo = null
    ) {
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        $environment = $this->loadCanonicalEnvironment();
        $result = [
            'diagnostic' => 'philips_nondicom_production',
            'version' => self::VERSION,
            'mode' => 'READ_ONLY',
            'overall' => 'BLOCKED',
            'timestamp_utc' => gmdate(DATE_ATOM),
            'hostname' => self::safeHostname((string) gethostname()),
            'php_version' => PHP_VERSION,
            'git_sha' => 'NOT_AVAILABLE_RUNTIME_METADATA',
            'environment' => 'UNKNOWN',
            'runtime' => $this->runtimeFlags($environment),
            'destination_7' => ['status' => 'UNKNOWN'],
            'tenant_pacs' => ['status' => 'UNKNOWN'],
            'queue' => ['status' => 'UNKNOWN'],
            'worker' => $this->workerState(),
            'bridges' => $this->bridgeState(),
            'credential_chain' => ['status' => 'UNKNOWN'],
            'smb' => [
                'status' => 'BLOCKED',
                'readonly_test' => 'NOT_EXECUTED',
                'reason' => 'NO_SAFE_PRODUCTION_READONLY_MECHANISM',
            ],
            'report_candidate' => ['status' => 'UNKNOWN'],
            'transmission_executed' => false,
        ];

        try {
            $pdo = $this->pdo ?? $this->connectReadOnly();
            $repository = new ReportDeliveryRepository($pdo);
            $destination = $repository->findDestination(self::DESTINATION_ID, self::TENANT_ID, false);
            $destinationResult = $this->destinationState($destination);
            $result['destination_7'] = $destinationResult;
            $result['environment'] = $destinationResult['environment'] === 'producao'
                ? 'production'
                : 'UNKNOWN';

            $server = null;
            if (isset($destinationResult['server_pacs_id']) && is_int($destinationResult['server_pacs_id'])) {
                $server = $repository->findTenantPacsServer(
                    self::TENANT_ID,
                    $destinationResult['server_pacs_id']
                );
            }
            $result['tenant_pacs'] = $this->tenantPacsState($destinationResult, $server);
            $destinationResult['task_site_match'] = ($result['tenant_pacs']['task_site_id_match'] ?? 'NO') === 'YES';
            $result['destination_7'] = $destinationResult;
            $result['destination_7']['status'] = self::destinationGate($destinationResult, $server);
            $result['credential_chain'] = $this->credentialState($destinationResult, $environment);
            $result['queue'] = $this->queueState($pdo);
            $result['report_candidate'] = $this->reportCandidateState($pdo);
        } catch (Throwable) {
            $result['error_category'] = 'READONLY_DIAGNOSTIC_UNAVAILABLE';
        }

        $result['gates'] = [
            'runtime' => self::runtimeGate($result['runtime']),
            'destination_7' => $result['destination_7']['status'] ?? 'UNKNOWN',
            'tenant_pacs' => $result['tenant_pacs']['status'] ?? 'UNKNOWN',
            'queue' => $result['queue']['status'] ?? 'UNKNOWN',
            'worker' => self::workerGate($result['worker']),
            'bridges' => self::bridgeGate($result['bridges']),
            'credential_chain' => self::credentialGate($result['credential_chain']),
            'smb' => $result['smb']['status'] ?? 'UNKNOWN',
            'report_candidate' => $result['report_candidate']['status'] ?? 'UNKNOWN',
        ];
        $result['overall'] = self::overall($result['gates']);

        return self::sanitize($result);
    }

    /** @param array<string,mixed> $gates */
    public static function overall(array $gates): string
    {
        foreach ($gates as $gate) {
            if ($gate !== 'PASS') {
                return 'BLOCKED';
            }
        }

        return 'READY';
    }

    public static function normalizeBoolean(?string $value, bool $invalidOn = false): string
    {
        if ($value === null) {
            return 'OFF';
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => 'ON',
            '', '0', 'false', 'no', 'off' => 'OFF',
            default => $invalidOn ? 'ON' : 'UNKNOWN',
        };
    }

    /** @param array<string,mixed> $destination @param array<string,mixed>|null $server */
    public static function destinationGate(array $destination, ?array $server): string
    {
        if ($destination === []) {
            return 'UNKNOWN';
        }
        if ((int) ($destination['id'] ?? 0) !== self::DESTINATION_ID
            || (int) ($destination['tenant_id'] ?? 0) !== self::TENANT_ID
            || (string) ($destination['transport'] ?? '') !== 'philips_non_dicom'
            || (string) ($destination['environment'] ?? '') !== 'producao'
            || (int) ($destination['enabled'] ?? 0) !== 1
            || (int) ($destination['auto_trigger'] ?? 1) !== 0
            || $server === null
            || (int) ($server['id'] ?? 0) !== (int) ($destination['server_pacs_id'] ?? 0)
        ) {
            return 'BLOCKED';
        }
        if ((string) ($destination['delivery_profile'] ?? '') !== 'submission_document'
            || (string) ($destination['transport_protocol'] ?? '') !== 'smb'
            || ($destination['gateway_bridge'] ?? false) !== true
            || ($destination['credential_configured'] ?? false) !== true
            || ($destination['task_site_match'] ?? false) !== true
        ) {
            return 'BLOCKED';
        }

        return 'PASS';
    }

    /** @param array<string,int> $jobs */
    public static function queueGate(array $jobs): string
    {
        $active = ($jobs['queued'] ?? 0) + ($jobs['processing'] ?? 0) + ($jobs['retrying'] ?? 0);

        return $active === 0 ? 'PASS' : 'BLOCKED';
    }

    /** @param array<int,array<string,mixed>> $jobs */
    public static function queueGateFromRows(array $jobs, int $destinationId): string
    {
        $counts = [];
        foreach ($jobs as $job) {
            if ((int) ($job['destination_id'] ?? 0) !== $destinationId) {
                continue;
            }
            $status = strtolower(trim((string) ($job['status'] ?? '')));
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return self::queueGate($counts);
    }

    /** @param array<string,mixed> $runtime */
    public static function runtimeGate(array $runtime): string
    {
        $required = [
            'VOXEL_REPORT_DELIVERY_HUB_ENABLED' => 'ON',
            'VOXEL_REPORT_DELIVERY_REQUESTS_ENABLED' => 'ON',
            'PHILIPS_NON_DICOM_DELIVERY_ENABLED' => 'ON',
            'PHILIPS_NON_DICOM_SMB_TEST_ENABLED' => 'OFF',
            'PHILIPS_NON_DICOM_SMB_READONLY_TEST_ENABLED' => 'OFF',
            'VOXEL_REPORT_DELIVERY_WORKER_KILL_SWITCH' => 'OFF',
        ];
        foreach ($required as $name => $expected) {
            if (($runtime[$name]['effective'] ?? 'UNKNOWN') !== $expected) {
                return 'BLOCKED';
            }
        }

        return 'PASS';
    }

    /** @param array<string,mixed> $worker */
    public static function workerGate(array $worker): string
    {
        return ($worker['enabled'] ?? 'UNKNOWN') === 'YES'
            && ($worker['active'] ?? 'UNKNOWN') === 'NO'
            && ($worker['process_count'] ?? -1) === 0
            ? 'PASS'
            : 'BLOCKED';
    }

    /** @param array<string,mixed> $bridges */
    public static function bridgeGate(array $bridges): string
    {
        return ($bridges['multiple_related_units'] ?? 'UNKNOWN') === 'NO'
            ? 'PASS'
            : 'BLOCKED';
    }

    /** @param array<string,mixed> $credential */
    public static function credentialGate(array $credential): string
    {
        return ($credential['reference'] ?? 'UNKNOWN') === 'CONFIGURED'
            && ($credential['target_reference'] ?? 'UNKNOWN') === 'CONFIGURED'
            && ($credential['secret_resolution'] ?? 'UNKNOWN') === 'AVAILABLE'
            ? 'PASS'
            : 'BLOCKED';
    }

    /** @param array<string,mixed> $data */
    public static function sanitize(array $data): array
    {
        $blocked = '/password|secret|token|private|authorization|cookie|dsn|connection|xml|pdf|dicom|patient|cpf|accession|laudo|payload|body|content/i';
        $walk = static function (mixed $value) use (&$walk, $blocked): mixed {
            if (is_array($value)) {
                $clean = [];
                foreach ($value as $key => $item) {
                    $keyText = (string) $key;
                    if (preg_match($blocked, $keyText) === 1
                        && !in_array($keyText, ['transmission_executed'], true)
                    ) {
                        continue;
                    }
                    $clean[$key] = $walk($item);
                }
                return $clean;
            }
            if (is_string($value)) {
                if (preg_match($blocked, $value) === 1) {
                    return 'REDACTED';
                }
                return self::safeText($value);
            }
            return $value;
        };

        $clean = $walk($data);
        if (!is_array($clean)) {
            return ['overall' => 'BLOCKED', 'transmission_executed' => false];
        }
        $clean['transmission_executed'] = false;
        return $clean;
    }

    private function connectReadOnly(): PDO
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
            $dsn = "pgsql:host={$host};port={$port};dbname={$database};options='--search_path={$schema},public'";
            return new PostgresPdo($dsn, $username, $password, $options);
        }
        if ($driver === 'mysql') {
            return new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $username, $password, $options);
        }

        throw new \RuntimeException('unsupported_database_driver');
    }

    /** @return array{source:string,keys:array<string,bool>} */
    private function loadCanonicalEnvironment(): array
    {
        $path = $this->appRoot . '/.env';
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

    /** @param array{source:string,keys:array<string,bool>} $environment @return array<string,array<string,string>> */
    private function runtimeFlags(array $environment): array
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', $this->appRoot);
        }
        $flags = [];
        foreach (self::FLAG_METHODS as $name => $method) {
            $configured = ($environment['keys'][$name] ?? false) ? 'YES' : 'NO';
            try {
                $effective = ReportDeliveryRuntimeConfig::$method() ? 'ON' : 'OFF';
            } catch (Throwable) {
                $effective = 'UNKNOWN';
            }
            $flags[$name] = [
                'configured' => $configured,
                'effective' => $effective,
                'source_precedence' => $environment['source'],
            ];
        }

        return $flags;
    }

    /** @return array<string,mixed> */
    private function destinationState(?array $destination): array
    {
        if ($destination === null) {
            return ['status' => 'UNKNOWN'];
        }
        $configuration = json_decode((string) ($destination['configuration_json'] ?? ''), true);
        if (!is_array($configuration)) {
            $configuration = [];
        }
        $submission = is_array($configuration['philips_submission'] ?? null)
            ? $configuration['philips_submission']
            : [];
        $serverId = (int) ($destination['servidor_pacs_id'] ?? 0);
        $taskSite = trim((string) ($submission['task_site_id'] ?? ''));
        $share = trim((string) ($configuration['smb_share'] ?? $configuration['share'] ?? ''));
        return [
            'id' => (int) ($destination['id'] ?? 0),
            'tenant_id' => (int) ($destination['tenant_id'] ?? 0),
            'transport' => (string) ($destination['transport'] ?? ''),
            'environment' => (string) ($destination['ambiente'] ?? ''),
            'enabled' => (int) ($destination['enabled'] ?? 0),
            'auto_trigger' => (int) ($destination['disparar_na_liberacao'] ?? 1),
            'server_pacs_id' => $serverId,
            'credential_configured' => ((int) ($destination['credential_configured'] ?? 0)) === 1,
            'delivery_profile' => (string) ($configuration['delivery_profile'] ?? ''),
            'gateway_bridge' => filter_var($configuration['gateway_bridge'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'transport_protocol' => (string) ($configuration['transport_protocol'] ?? ''),
            'smb_share_configured' => $share !== '',
            'task_site_value_present' => $taskSite !== '',
            'task_site_value' => $taskSite,
        ];
    }

    /** @param array<string,mixed> $destination @param array<string,mixed>|null $server @return array<string,mixed> */
    private function tenantPacsState(array $destination, ?array $server): array
    {
        $serverId = (int) ($destination['server_pacs_id'] ?? 0);
        if ($serverId <= 0) {
            return [
                'status' => 'BLOCKED',
                'tenant_id' => self::TENANT_ID,
                'server_pacs_id' => 0,
                'server_pacs_authorized' => 'NO',
                'task_site_id_match' => 'UNKNOWN',
            ];
        }
        if ($server === null) {
            return [
                'status' => 'BLOCKED',
                'tenant_id' => self::TENANT_ID,
                'server_pacs_id' => $serverId,
                'server_pacs_authorized' => 'NO',
                'task_site_id_match' => 'UNKNOWN',
            ];
        }
        $serverName = trim((string) ($server['nome'] ?? ''));
        $taskSite = trim((string) ($destination['task_site_value'] ?? ''));
        $match = $serverName !== '' && $taskSite !== '' && $serverName === $taskSite;
        return [
            'status' => $match ? 'PASS' : 'BLOCKED',
            'tenant_id' => self::TENANT_ID,
            'server_pacs_id' => $serverId,
            'server_pacs_active' => 'YES',
            'server_pacs_authorized' => 'YES',
            'task_site_id_match' => $match ? 'YES' : 'NO',
        ];
    }

    /** @param array<string,mixed> $destination @param array{source:string,keys:array<string,bool>} $environment */
    private function credentialState(array $destination, array $environment): array
    {
        return [
            'reference' => ($destination['credential_configured'] ?? false) === true ? 'CONFIGURED' : 'UNKNOWN',
            'target_reference' => ($destination['gateway_bridge'] ?? false) === true
                && ($destination['transport_protocol'] ?? '') === 'smb'
                && ($destination['smb_share_configured'] ?? false) === true
                ? 'CONFIGURED'
                : 'UNKNOWN',
            'secret_resolution' => 'UNKNOWN',
            'bridge_client_reference' => ($environment['source'] ?? '') === 'canonical_env'
                ? 'UNKNOWN_REMOTE_BRIDGE'
                : 'UNKNOWN',
        ];
    }

    /** @return array<string,mixed> */
    private function queueState(PDO $pdo): array
    {
        $outbox = $this->statusCounts($pdo, 'pacs_report_delivery_outbox', 'tenant_id', self::TENANT_ID);
        $jobs = $this->destinationJobStatusCounts($pdo, self::DESTINATION_ID);
        return [
            'status' => self::queueGate($jobs),
            'scope' => 'destination_7_jobs_only',
            'job_destination_id' => self::DESTINATION_ID,
            'outbox_scope' => 'tenant_2_observed_only',
            'outbox_destination_filter' => 'not_available_in_schema',
            'outbox_pending' => (int) ($outbox['queued'] ?? 0),
            'outbox_processing' => (int) ($outbox['processing'] ?? 0),
            'outbox_failed' => (int) ($outbox['failed'] ?? 0),
            'outbox_dead_letter' => (int) ($outbox['dead_letter'] ?? 0),
            'outbox_cancelled' => (int) ($outbox['cancelled'] ?? 0),
            'jobs_pending' => (int) ($jobs['queued'] ?? 0),
            'jobs_processing' => (int) ($jobs['processing'] ?? 0),
            'jobs_retrying' => (int) ($jobs['retrying'] ?? 0),
            'jobs_delivered' => (int) ($jobs['delivered'] ?? 0),
            'jobs_failed' => (int) ($jobs['failed'] ?? 0),
            'jobs_dead_letter' => (int) ($jobs['dead_letter'] ?? 0),
            'jobs_cancelled' => (int) ($jobs['cancelled'] ?? 0),
        ];
    }

    /** @return array<string,int> */
    private function destinationJobStatusCounts(PDO $pdo, int $destinationId): array
    {
        $stmt = $pdo->prepare(
            'SELECT status, COUNT(*) AS total
               FROM pacs_report_delivery_jobs
              WHERE tenant_id = :tenant_id
                AND destination_id = :destination_id
              GROUP BY status'
        );
        $stmt->execute([':tenant_id' => self::TENANT_ID, ':destination_id' => $destinationId]);
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            $counts[$status] = (int) ($row['total'] ?? 0);
        }

        return $counts;
    }

    /** @return array<string,int> */
    private function statusCounts(PDO $pdo, string $table, string $tenantColumn, int $tenantId): array
    {
        $allowedTables = [
            'pacs_report_delivery_outbox' => true,
            'pacs_report_delivery_jobs' => true,
        ];
        if (!isset($allowedTables[$table])) {
            throw new \InvalidArgumentException('unsupported_readonly_table');
        }
        $stmt = $pdo->prepare(
            "SELECT status, COUNT(*) AS total FROM {$table} WHERE {$tenantColumn} = :tenant_id GROUP BY status"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            $counts[$status] = (int) ($row['total'] ?? 0);
        }
        return $counts;
    }

    /** @return array<string,mixed> */
    private function reportCandidateState(PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT r.id, r.tenant_id, r.estudo_id, r.situacao,
                    CASE WHEN EXISTS (
                        SELECT 1 FROM report_versions rv
                         WHERE rv.report_id = r.id AND rv.versao = :report_version
                    ) THEN 1 ELSE 0 END AS version_present
               FROM reports r
              WHERE r.id = :report_id AND r.tenant_id = :tenant_id
              LIMIT 1'
        );
        $stmt->execute([
            ':report_id' => self::REPORT_ID,
            ':report_version' => self::REPORT_VERSION,
            ':tenant_id' => self::TENANT_ID,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [
                'status' => 'UNKNOWN',
                'report_id' => self::REPORT_ID,
                'report_version' => self::REPORT_VERSION,
            ];
        }
        $eligible = (int) ($row['tenant_id'] ?? 0) === self::TENANT_ID
            && (int) ($row['id'] ?? 0) === self::REPORT_ID
            && (int) ($row['estudo_id'] ?? 0) > 0
            && (string) ($row['situacao'] ?? '') === 'liberado'
            && (int) ($row['version_present'] ?? 0) === 1;
        return [
            'status' => $eligible ? 'PASS' : 'BLOCKED',
            'report_id' => self::REPORT_ID,
            'report_version' => self::REPORT_VERSION,
            'tenant_id' => self::TENANT_ID,
            'study_linked' => (int) ($row['estudo_id'] ?? 0) > 0 ? 'YES' : 'NO',
            'released' => (string) ($row['situacao'] ?? '') === 'liberado' ? 'YES' : 'NO',
            'version_present' => (int) ($row['version_present'] ?? 0) === 1 ? 'YES' : 'NO',
            'structural_eligibility' => $eligible ? 'PASS' : 'BLOCKED',
        ];
    }

    /** @return array<string,mixed> */
    private function workerState(): array
    {
        $enabled = $this->systemdState(['is-enabled', self::WORKER_UNIT]);
        $active = $this->systemdState(['is-active', self::WORKER_UNIT]);
        $mainPid = $this->systemdMainPid(self::WORKER_UNIT);
        return [
            'unit' => self::WORKER_UNIT,
            'enabled' => $enabled,
            'active' => $active,
            'main_pid' => $mainPid,
            'process_count' => is_int($mainPid) ? ($mainPid > 0 ? 1 : 0) : -1,
        ];
    }

    /** @return array<string,mixed> */
    private function bridgeState(): array
    {
        $units = [];
        foreach (self::BRIDGE_UNITS as $unit) {
            $mainPid = $this->systemdMainPid($unit);
            $units[] = [
                'unit' => $unit,
                'enabled' => $this->systemdState(['is-enabled', $unit]),
                'active' => $this->systemdState(['is-active', $unit]),
                'main_pid' => $mainPid,
            ];
        }
        return [
            'scope' => 'LOCAL_HOST_ONLY',
            'units' => $units,
            'multiple_related_units' => 'UNKNOWN_REMOTE_GATEWAY',
        ];
    }

    private function systemdState(array $arguments): string
    {
        $result = $this->runFixedCommand(array_merge(['/usr/bin/systemctl'], $arguments));
        if ($result['exit'] !== 0) {
            $stdout = strtolower(trim($result['stdout']));
            if (in_array($stdout, ['disabled', 'masked', 'inactive', 'failed', 'dead', 'not-found'], true)) {
                return in_array($stdout, ['disabled', 'masked'], true) ? 'NO' : 'NO';
            }
            return 'UNKNOWN';
        }
        $stdout = strtolower(trim($result['stdout']));
        if ($arguments[0] === 'is-enabled') {
            return $stdout === 'enabled' ? 'YES' : ($stdout === '' ? 'UNKNOWN' : 'NO');
        }
        if ($arguments[0] === 'is-active') {
            return $stdout === 'active' ? 'YES' : ($stdout === '' ? 'UNKNOWN' : 'NO');
        }
        return 'UNKNOWN';
    }

    private function systemdMainPid(string $unit): int|string
    {
        $result = $this->runFixedCommand(['/usr/bin/systemctl', 'show', '--property=MainPID', '--value', $unit]);
        if ($result['exit'] !== 0 || preg_match('/^\d+$/', trim($result['stdout'])) !== 1) {
            return 'UNKNOWN';
        }
        return (int) trim($result['stdout']);
    }

    /** @return array{exit:int,stdout:string} */
    private function runFixedCommand(array $command): array
    {
        if (($command[0] ?? '') !== '/usr/bin/systemctl') {
            return ['exit' => 126, 'stdout' => ''];
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
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

    private function environmentValue(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? null;
        if ($value !== null && $value !== '') {
            return (string) $value;
        }
        $value = $_SERVER[$key] ?? null;
        if ($value !== null && $value !== '') {
            return (string) $value;
        }
        $value = getenv($key);
        return $value === false || $value === '' ? $default : (string) $value;
    }

    private static function safeHostname(string $hostname): string
    {
        $hostname = preg_replace('/[^A-Za-z0-9._-]/', '_', trim($hostname)) ?? '';
        return $hostname === '' ? 'UNKNOWN' : substr($hostname, 0, 120);
    }

    private static function safeText(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_.:;=+\-\/ ]/', '_', trim($value)) ?? '';
        return substr($value, 0, 240);
    }
}
}

namespace {
    if (defined('PHILIPS_NON_DICOM_PRODUCTION_DIAGNOSTIC_LIBRARY')) {
        return;
    }
    $root = '/var/www/voxelpacs/app';
    require_once $root . '/app/autoload.php';
    $diagnostic = new \App\Diagnostics\PhilipsNonDicomProductionDiagnostic($root);
    $arguments = array_slice($argv ?? [], 1);
    if ($arguments !== []) {
        echo json_encode([
            'diagnostic' => 'philips_nondicom_production',
            'version' => '1.0.0',
            'mode' => 'READ_ONLY',
            'overall' => 'BLOCKED',
            'error_category' => 'ARGUMENTS_NOT_ALLOWED',
            'transmission_executed' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(64);
    }
    echo json_encode($diagnostic->run(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
